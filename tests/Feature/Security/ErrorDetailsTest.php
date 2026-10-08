<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Http\ErrorHandler;
use App\Core\Http\HttpKernel;
use App\Core\Http\RequestId;
use DI\Container;
use Illuminate\Database\QueryException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\HttpTestCase;

/**
 * What an error answer tells about the server: nothing, but to its owner at its own keyboard. An answer — an address no
 * route has, a refusal, a sign-in missing: any status under 500 — never carries APP_DEBUG's details. A failure of the
 * server's does, with APP_DEBUG on, to a request straight from this machine alone — never one a proxy or a tunnel
 * forwarded (they make every visitor look local) nor one from elsewhere —, and even then without a call's arguments or
 * the app's folder in it. And no answer names what runs it (X-Powered-By), the front controller's last resort included.
 */
final class ErrorDetailsTest extends HttpTestCase
{
    private const LOCAL = ['REMOTE_ADDR' => '127.0.0.1'];

    public function testAnAnswerNeverCarriesTheDetailsWhateverAppDebugSays(): void
    {
        $slim = $this->debugging();

        foreach ([
            'an address no route has' => ['GET', '/a%D8%B4%D8%B3%DB%8C%D8%B4', null, 404],
            'a refusal' => ['POST', '/api/admin/auth/login', [], 422],
            'nobody signed in' => ['GET', '/api/admin/auth/me', null, 401],
        ] as $what => [$method, $path, $body, $status]) {
            $response = $slim->handle($this->request($method, $path, $body, self::LOCAL));

            self::assertSame($status, $response->getStatusCode(), $what);
            $answer = $this->decode($response);
            self::assertArrayNotHasKey('debug', $answer, "{$what}: an answer, not a failure");
            self::assertSame(RequestId::current(), $answer['request_id'], $what);
            $this->assertNothingOfTheServer((string) $response->getBody(), $what);
        }
    }

    public function testAFailureShowsItsDetailsToARequestFromThisMachineAlone(): void
    {
        $slim = $this->debugging();
        $this->loginAsAdmin();
        // The server fails: a table the change feed reads is gone.
        $this->db()->getSchemaBuilder()->drop('change_versions');

        $direct = $slim->handle($this->request('GET', '/api/admin/changes', null, self::LOCAL));
        self::assertSame(500, $direct->getStatusCode());
        $debug = $this->decode($direct)['debug'] ?? null;
        self::assertIsArray($debug, 'the owner at this machine sees what failed');
        self::assertSame(QueryException::class, $debug['exception']);
        self::assertLessThanOrEqual(ErrorHandler::DEBUG_FRAMES + 1, count($debug['trace']), 'a few frames, not the whole stack');
        foreach ($debug['trace'] as $frame) {
            self::assertMatchesRegularExpression('/(^thrown at |\(\)$)/', $frame, 'no call\'s arguments');
        }
        $this->assertNothingOfTheServer(implode("\n", $debug['trace']) . $debug['detail'], 'the trace');

        foreach ([
            'another machine' => [['REMOTE_ADDR' => '203.0.113.5'], []],
            'a proxy on this machine' => [self::LOCAL, ['X-Forwarded-For' => '203.0.113.5']],
            'a standard proxy' => [self::LOCAL, ['Forwarded' => 'for=203.0.113.5;proto=https']],
            'nginx' => [self::LOCAL, ['X-Real-IP' => '203.0.113.5']],
            'a Cloudflare tunnel' => [self::LOCAL, ['CF-Connecting-IP' => '203.0.113.5']],
            'any proxy that says it passed it on' => [self::LOCAL, ['Via' => '1.1 tunnel']],
        ] as $who => [$server, $headers]) {
            $response = $slim->handle($this->request('GET', '/api/admin/changes', null, $server, $headers));

            self::assertSame(500, $response->getStatusCode(), $who);
            self::assertSame(['message' => ErrorHandler::MESSAGES[500], 'request_id' => RequestId::current()], $this->decode($response), "{$who}: the plain answer with its id");
        }
    }

    /**
     * The front controller's last resort — the app could not start — answers the error shape with the request's id and
     * every answer's headers, tells nothing of the failure, and no answer of PHP's names what runs it.
     */
    public function testTheFrontControllerSaysNothingOfTheServerEvenWhenTheAppCannotStart(): void
    {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
        if (!is_file($cgi)) {
            self::markTestSkipped("No php-cgi beside {$cgi}: a web server's request cannot be played here.");
        }
        // The front controller as it ships, before an app that cannot start: its path and a token in what it says.
        $root = $this->scratchDir();
        mkdir("{$root}/public");
        mkdir("{$root}/bootstrap");
        copy($this->app()->basePath('public/index.php'), "{$root}/public/index.php");
        file_put_contents("{$root}/bootstrap/app.php", '<?php require ' . var_export($this->app()->basePath('vendor/autoload.php'), true) . '; throw new RuntimeException("Cannot read ' . addslashes($root) . '/config.php: bot123456789:AAF' . str_repeat('x', 32) . '");');

        $process = proc_open([$cgi, '-d', 'expose_php=1', '-d', 'display_errors=1', '-d', "error_log={$root}/php-errors.log"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'REDIRECT_STATUS' => '200',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/app',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => "{$root}/public/index.php",
            'SERVER_NAME' => 'shop.example',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
        ] + array_filter(getenv(), static fn(string $name): bool => !str_starts_with($name, 'HTTP_'), ARRAY_FILTER_USE_KEY));
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        [$head, $body] = explode("\r\n\r\n", $output, 2) + ['', ''];
        self::assertDoesNotMatchRegularExpression('/^X-Powered-By:/mi', $head, 'no banner of PHP\'s');
        self::assertMatchesRegularExpression('/^Status: 500/mi', $head);
        self::assertMatchesRegularExpression("/^Content-Security-Policy: default-src 'none'/mi", $head, 'every answer\'s headers');
        preg_match('/^X-Request-Id: (\w+)/mi', $head, $id);
        self::assertSame(['message' => ErrorHandler::MESSAGES[500], 'request_id' => $id[1] ?? null], json_decode($body, true), 'the error shape and nothing else');
        $logged = (string) file_get_contents("{$root}/php-errors.log");
        self::assertStringContainsString("could not answer request {$id[1]}", $logged, 'the story is in the log, under the id');
        self::assertStringNotContainsString(str_repeat('x', 32), $logged, 'with no secret');
    }

    /**
     * A Slim app as the kernel builds it for a shop whose APP_DEBUG is on.
     *
     * @return App<Container>
     */
    private function debugging(): App
    {
        $this->config(['app.debug' => true]);

        return HttpKernel::create($this->app());
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, ?array $body, array $server, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, $server)
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $body === null ? $request : $request->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream((string) json_encode($body)));
    }

    private function assertNothingOfTheServer(string $said, string $what): void
    {
        $folder = $this->app()->basePath();
        self::assertStringNotContainsString($folder, $said, "{$what}: not the app's folder");
        self::assertStringNotContainsString(str_replace('\\', '/', $folder), $said, $what);
        self::assertStringNotContainsString(str_replace('\\', '\\\\', $folder), $said, $what);
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        return parent::decode($response);
    }
}
