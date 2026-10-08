<?php

declare(strict_types=1);

/*
 * HTTP front controller: the API, the Telegram webhooks, the cron URL. Point the web server's document root at this
 * folder; if you cannot (shared hosting), the .htaccess in the project root forwards here. The panels — admin/ (the
 * owner's), agent/ (an agent's) and the build files they share in assets/ — are static files the web server serves
 * itself.
 */

use App\Core\Http\ErrorHandler;
use App\Core\Http\Middleware\SecurityHeadersMiddleware;
use App\Core\Http\RequestId;
use App\Core\Logging\Redact;
use App\Modules\Updates\Maintenance;

// Nothing an answer carries tells what runs the shop: not PHP's banner header, and not — before the app is even loaded,
// on a host that ships display_errors on — a warning with a path of the server's in it.
header_remove('X-Powered-By');
ini_set('display_errors', '0');

// `php -S host:port -t public public/index.php` does what the web server would: real files as they are, and any
// other address under /admin or /agent that panel's own page.
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
 * An answer of the front controller's own, made without the app: the one error shape and the request's id `$id`, no
 * details for the caller, the headers every answer of PHP's carries.
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
 * The answer when the app could not give one — it did not start (a broken config.php, a database driver nobody has), or a
 * fatal error ended it (memory, the time limit): the generic 500, the whole story in PHP's error log
 * (storage/logs/php-errors.log), the id beside it. Without the app's classes (vendor/ not there: an upload cut short) it
 * has nothing to answer with, and PHP's bare 500 stands.
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

// A fatal error is no exception: it ends the request where it stands. The memory set aside here is what the answer is
// made with when the request ran out of its own.
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
    // An update being installed holds the shop a moment (Modules\Updates\Maintenance): every request but the one installing
    // it is told to come back — before the app is loaded, since its files are what is being replaced. Without vendor/
    // there is nothing to tell it with: the app's boot fails as it would.
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
