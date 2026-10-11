<?php

declare(strict_types=1);

/*
 * The front controller for everything PHP answers (the API, webhooks, cron). The document root should be this folder;
 * on shared hosting the root .htaccess forwards here. The panels (admin/, agent/, assets/) are static files.
 */

use App\Core\Http\ErrorHandler;
use App\Core\Http\Middleware\SecurityHeadersMiddleware;
use App\Core\Http\RequestId;
use App\Core\Logging\Redact;
use App\Modules\Updates\Maintenance;

// No answer names what runs the shop, and no warning (with a server path) prints — even before the app is loaded, on a
// host that ships display_errors on.
header_remove('X-Powered-By');
ini_set('display_errors', '0');

// Under `php -S … public/index.php`, do what public/.htaccess does: real files as they are, any other /admin or /agent
// address that panel's page.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
    // A build file that is not there is a 404, never a page (see public/.htaccess).
    if (str_starts_with($path, '/assets/')) {
        http_response_code(404);

        return true;
    }
    if (preg_match('~^/(admin|agent)(/|$)~', $path, $panel) === 1) {
        $page = __DIR__ . '/' . $panel[1] . '/index.html';
        if (!is_file($page)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "The panels are not built: run `pnpm build` (or open them through `pnpm dev`).\n";

            return true;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile($page);

        return true;
    }
}

/*
 * An answer made without the app: the error shape with the request's id, and every answer's security headers.
 *
 * @param array<string, string> $headers
 */
$answer = static function (string $id, int $status, string $message, array $headers = []): void {
    header_remove('X-Powered-By');
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ([...SecurityHeadersMiddleware::HEADERS, ...$headers] as $name => $value) {
        header("{$name}: {$value}");
    }
    header(RequestId::HEADER . ': ' . $id);
    echo json_encode(['message' => $message, 'request_id' => $id], JSON_UNESCAPED_UNICODE);
};

/*
 * The last resort when the app could not answer (it did not start, or a fatal error ended it): the generic 500, the
 * details in storage/logs/php-errors.log under the request's id. Without vendor/ (an upload cut short) PHP's bare 500
 * stands.
 */
$lastResort = static function (string $why) use ($answer): void {
    if (!class_exists(RequestId::class)) {
        return;
    }
    $id = RequestId::current() ?? RequestId::generate();
    error_log("AmoBot could not answer request {$id}: " . Redact::text($why));
    if (!headers_sent()) {
        $answer($id, 500, ErrorHandler::MESSAGES[500]);
    }
};

// A fatal error is no exception and ends the request where it stands; the memory reserved here lets the answer be made
// when the request ran out of its own.
$reserved = str_repeat(' ', 32768);
register_shutdown_function(static function () use (&$reserved, $lastResort): void {
    $reserved = null;
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    // What the request had written of its answer is not one.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $lastResort(sprintf('%s in %s:%d', $error['message'], $error['file'], $error['line']));
});

try {
    // While an update installs (Maintenance), every other request is told to come back — before the app is loaded,
    // since its files are being replaced.
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        $wait = Maintenance::retryAfter(dirname(__DIR__) . '/' . Maintenance::FLAG, time());
        if ($wait !== null) {
            $answer(RequestId::generate(), 503, Maintenance::MESSAGE, ['Retry-After' => (string) $wait]);

            return;
        }
    }

    $app = require dirname(__DIR__) . '/bootstrap/app.php';
    $app->http()->run();
} catch (\Throwable $e) {
    $lastResort((string) $e);
}
