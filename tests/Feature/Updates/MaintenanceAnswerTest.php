<?php

declare(strict_types=1);

namespace Tests\Feature\Updates;

use App\Modules\Updates\Maintenance;
use Tests\TestCase;

/**
 * public/index.php while an update installs, played as a web server runs it (php-cgi): every request is told the shop is
 * updating — the error shape, a 503 with Retry-After and every answer's headers — before the app, whose files are being
 * replaced, is loaded at all; and once the flag is gone, or its install died long enough ago, the app answers again.
 */
final class MaintenanceAnswerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $cgi = self::cgi();
        if (!is_file($cgi)) {
            self::markTestSkipped("No php-cgi beside {$cgi}: a web server's request cannot be played here.");
        }
        // The front controller as it ships, before an app whose boot says it was reached.
        $app = $this->app();
        $this->root = $this->scratchDir();
        foreach (['public', 'bootstrap', 'vendor', 'storage'] as $folder) {
            mkdir("{$this->root}/{$folder}");
        }
        copy($app->basePath('public/index.php'), "{$this->root}/public/index.php");
        file_put_contents("{$this->root}/vendor/autoload.php", '<?php return require ' . var_export($app->basePath('vendor/autoload.php'), true) . ';');
        file_put_contents("{$this->root}/bootstrap/app.php", '<?php echo "the app answered"; exit;');
    }

    public function testEveryRequestIsToldToComeBackWhileAnUpdateInstalls(): void
    {
        file_put_contents("{$this->root}/" . Maintenance::FLAG, (string) time());

        [$head, $body] = $this->ask();

        self::assertMatchesRegularExpression('/^Status: 503/mi', $head);
        self::assertMatchesRegularExpression('/^Retry-After: ([1-9]|10)\r?$/mi', $head, 'a few seconds');
        self::assertMatchesRegularExpression("/^Content-Security-Policy: default-src 'none'/mi", $head, 'every answer\'s headers');
        self::assertMatchesRegularExpression('/^Content-Type: application\/json/mi', $head);
        preg_match('/^X-Request-Id: (\w+)/mi', $head, $id);
        self::assertSame(['message' => Maintenance::MESSAGE, 'request_id' => $id[1] ?? null], json_decode($body, true), 'the error shape and nothing else');
    }

    public function testTheAppAnswersOnceTheFlagIsGoneOrItsInstallDied(): void
    {
        self::assertSame('the app answered', $this->ask()[1], 'no update installs');

        file_put_contents("{$this->root}/" . Maintenance::FLAG, (string) (time() - Maintenance::SECONDS - 1));
        self::assertSame('the app answered', $this->ask()[1], 'its install died two minutes ago: the shop is never shut for good');
    }

    /** @return array{string, string} The answer of php-cgi to a request of the shop's API: its head and its body */
    private function ask(): array
    {
        $process = proc_open([self::cgi(), '-d', 'display_errors=0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'REDIRECT_STATUS' => '200',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/app',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => "{$this->root}/public/index.php",
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

        return [$head, $body];
    }

    private static function cgi(): string
    {
        return dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
    }
}
