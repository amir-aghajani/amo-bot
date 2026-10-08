<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\Repository as Config;
use App\Core\Session\Session;
use App\Modules\Auth\PanelAuthMiddleware;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\HttpTestCase;

/**
 * A panel's session lasts while it is used: one left idle longer than SESSION_LIFETIME starts empty, whatever the host's
 * collector does (it may never come by) — the browser is signed out. Under a web server its cookie never travels in
 * clear text once the browser speaks HTTPS (or SESSION_SECURE_COOKIE says so), its file goes to the folder set for it,
 * and only a write holds it locked for its whole run: a read lets go at once, so a page's reads run side by side.
 */
final class SessionTest extends HttpTestCase
{
    /**
     * A request as a web server hands it to PHP (the CGI SAPI): the panel's session opened by its middleware, and —
     * AMOBOT_KEEP=1 — something kept in it on the way, as a sign-in does. What the handler found is its answer: the
     * session's id, whether the request still held the session's file locked, whether what it kept could be kept, and
     * what the session held. The suite itself runs on the command line, where the session is an array in memory.
     */
    private const WEB_REQUEST = <<<'PHP'
        <?php

        declare(strict_types=1);

        require (string) getenv('AMOBOT_AUTOLOAD');

        $session = new App\Core\Session\Session((array) json_decode((string) getenv('AMOBOT_SESSION'), true));
        $answer = new class ($session, getenv('AMOBOT_KEEP') === '1') implements Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private readonly App\Core\Session\Session $session, private readonly bool $keep) {}

            public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
            {
                $file = session_save_path() . '/sess_' . session_id();
                $other = is_file($file) ? fopen($file, 'r') : false;
                $held = $other !== false && !flock($other, LOCK_SH | LOCK_NB);
                if ($other !== false) {
                    fclose($other);
                }
                $kept = null;
                if ($this->keep) {
                    try {
                        $this->session->set('signed_in', 'owner');
                        $kept = true;
                    } catch (LogicException) {
                        $kept = false;
                    }
                }
                echo json_encode(['id' => session_id(), 'held' => $held, 'kept' => $kept, 'signed_in' => $this->session->get('signed_in')]);

                return new Slim\Psr7\Response();
            }
        };
        (new App\Core\Http\Middleware\SessionMiddleware($session, new App\Core\Http\RequestOrigin()))->process(Slim\Psr7\Factory\ServerRequestFactory::createFromGlobals(), $answer);
        PHP;

    /** @return iterable<string, array{array<string, string>, bool, bool}> The request's server variables, SESSION_SECURE_COOKIE, a secure cookie */
    public static function webRequests(): iterable
    {
        yield 'plain HTTP' => [[], false, false];
        yield 'HTTPS behind a proxy that says so' => [['HTTP_X_FORWARDED_PROTO' => 'https'], false, true];
        yield 'SESSION_SECURE_COOKIE behind a proxy that hides it' => [[], true, true];
    }

    /** @param array<string, string> $server */
    #[DataProvider('webRequests')]
    public function testUnderAWebServerTheCookieIsSecureWheneverTheBrowserSpeaksHttps(array $server, bool $forced, bool $secure): void
    {
        $config = $this->sessionConfig();
        $config['cookie']['secure'] = $forced;

        [$cookie, $found] = $this->webRequest($server, $config);

        self::assertStringStartsWith("{$config['name']}={$found['id']};", $cookie);
        self::assertSame($secure, str_contains($cookie, '; secure'), $cookie);
        self::assertStringContainsString('; HttpOnly; SameSite=Lax', $cookie, 'never readable by a script, never sent from another site');
        self::assertStringContainsString('signed_in', (string) file_get_contents("{$config['save_path']}/sess_{$found['id']}"), 'kept in the folder set for it');
    }

    /**
     * Every request without a cookie opens a session, and PHP makes its file as it opens it: one that keeps nothing —
     * nobody signed in, or they signed out — leaves no file behind, or anyone could fill a shared host's file quota with
     * request after request; a read as much as a write.
     */
    public function testARequestThatKeepsNothingLeavesNoFileBehind(): void
    {
        $config = $this->sessionConfig();

        [, $found] = $this->webRequest([], $config, keep: false);
        self::assertNotSame('', $found['id'], 'a session was opened');
        self::assertSame([], glob("{$config['save_path']}/sess_*") ?: [], 'and nothing of it stays');

        $this->webRequest([], $config, keep: false, method: 'GET');
        self::assertSame([], glob("{$config['save_path']}/sess_*") ?: [], 'nor of a read\'s');
    }

    /**
     * PHP holds a session's file locked while a request has it open: a read lets go of it before its work begins — so the
     * page's other reads are not kept waiting —, still knowing who is signed in, and a change it tried to keep would be
     * lost, so it is refused. A write (signing in, opening a shop, changing the login) keeps the lock to its end, and
     * what it keeps stays.
     */
    public function testAReadLetsGoOfTheSessionAtOnceAndOnlyAWriteHoldsIt(): void
    {
        $config = $this->sessionConfig();

        [$cookie, $signIn] = $this->webRequest([], $config);
        self::assertTrue($signIn['held'], 'a write holds the session for its whole run');
        self::assertTrue($signIn['kept']);
        $file = "{$config['save_path']}/sess_{$signIn['id']}";
        $stored = (string) file_get_contents($file);

        [, $read] = $this->webRequest(['HTTP_COOKIE' => strtok($cookie, ';')], $config, keep: true, method: 'GET');
        self::assertSame($signIn['id'], $read['id'], 'the same session');
        self::assertFalse($read['held'], 'a read let go of it before its work began');
        self::assertSame('owner', $read['signed_in'], 'and still knows who is signed in');
        self::assertFalse($read['kept'], 'a change it would lose is refused');
        self::assertSame($stored, (string) file_get_contents($file), 'the session is as the write left it');
    }

    public function testNothingIsWrittenToASessionTheRequestLetGoOf(): void
    {
        $session = $this->service(Session::class);
        $session->start(https: false);
        $session->set('signed_in', 'owner');
        $session->release();

        self::assertSame('owner', $session->get('signed_in'), 'what it held can still be read');
        foreach ([static fn() => $session->set('shop', 2), static fn() => $session->remove('signed_in'), static fn() => $session->regenerate()] as $write) {
            try {
                $write();
                self::fail('a change after the session was let go is refused');
            } catch (\LogicException) {
            }
        }
        self::assertSame('owner', $session->get('signed_in'));

        $session->end();
        $session->start(https: false);
        $session->set('shop', 2);
        self::assertSame(2, $session->get('shop'), 'the next request holds it again');
        $session->end();
    }

    public function testASessionInUseStaysOpen(): void
    {
        $this->loginAsAdmin();
        self::assertSame(200, $this->get('/api/admin/auth/me')->getStatusCode());

        $this->later($this->lifetime() - 1);
        self::assertSame(200, $this->get('/api/admin/auth/me')->getStatusCode(), 'used inside its lifetime');
        $this->later($this->lifetime() - 1);
        self::assertSame(200, $this->get('/api/admin/auth/me')->getStatusCode(), 'and its lifetime counts from the last use');
    }

    public function testAnIdleSessionStartsEmpty(): void
    {
        $this->loginAsAdmin();
        self::assertSame(200, $this->get('/api/admin/auth/me')->getStatusCode());

        $this->later($this->lifetime() + 1);
        $response = $this->get('/api/admin/auth/me');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(PanelAuthMiddleware::SIGNED_OUT, $this->decode($response)['message']);
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode(), 'what it held is gone for good');
    }

    /** SESSION_LIFETIME, in minutes. */
    private function lifetime(): int
    {
        return (int) $this->service(Config::class)->get('session.lifetime');
    }

    /** @return array<array-key, mixed> config/session.php, its files in a folder of the test's own */
    private function sessionConfig(): array
    {
        $config = (array) $this->service(Config::class)->get('session');
        $config['save_path'] = $this->scratchDir();

        return $config;
    }

    private function later(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    /**
     * One request to WEB_REQUEST under php-cgi, the CGI SAPI beside this PHP — skipped where there is none.
     *
     * @param array<string, string> $server
     * @param array<array-key, mixed> $session config/session.php
     * @param bool $keep Whether the request keeps something in the session
     * @param string $method A write (a sign-in) unless said otherwise
     * @return array{string, array{id: string, held: bool, kept: bool|null, signed_in: string|null}} The session cookie the browser was given, and what the request found
     */
    private function webRequest(array $server, array $session, bool $keep = true, string $method = 'POST'): array
    {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
        if (!is_file($cgi)) {
            self::markTestSkipped("No php-cgi beside {$cgi}: a web server's request cannot be played here.");
        }
        $script = $this->scratchDir() . '/index.php';
        file_put_contents($script, self::WEB_REQUEST);

        $environment = $server + [
            'REDIRECT_STATUS' => '200',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_METHOD' => $method,
            'CONTENT_LENGTH' => '0',
            'REQUEST_URI' => '/api/admin/auth/me',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $script,
            'SERVER_NAME' => 'shop.example',
            'SERVER_PORT' => '80',
            'AMOBOT_AUTOLOAD' => $this->app()->basePath('vendor/autoload.php'),
            'AMOBOT_SESSION' => (string) json_encode($session),
            'AMOBOT_KEEP' => $keep ? '1' : '0',
        ] + array_filter(getenv(), static fn(string $name): bool => !str_starts_with($name, 'HTTP_'), ARRAY_FILTER_USE_KEY);

        $process = proc_open([$cgi, '-d', 'session.save_handler=files', '-d', 'session.auto_start=0', '-d', 'display_errors=stderr'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertDoesNotMatchRegularExpression('/Session\.php|session_\w+\(\)/', $errors, 'no warning on the way');
        [$head, $body] = explode("\r\n\r\n", $output, 2) + ['', ''];
        preg_match('/^Set-Cookie: (.+)$/mi', $head, $cookie);
        $found = json_decode($body, true);
        self::assertIsArray($found, $output . $errors);

        return [trim($cookie[1] ?? ''), ['id' => (string) $found['id'], 'held' => (bool) $found['held'], 'kept' => $found['kept'], 'signed_in' => $found['signed_in']]];
    }
}
