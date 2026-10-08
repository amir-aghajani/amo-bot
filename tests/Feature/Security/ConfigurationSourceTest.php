<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Config\ConfigValues;
use App\Core\Config\Repository as Config;
use Tests\TestCase;

/**
 * The shop's configuration comes from config.php alone. Under a web server PHP hands a request's headers to the script
 * as HTTP_* variables beside the environment's ($_SERVER, and getenv() under FastCGI) — its Content-Type and
 * Content-Length as CONTENT_TYPE and CONTENT_LENGTH: while the configuration was read from the environment, a setting
 * spelled that way was any visitor's to choose for their request (a `Timeout: 0` header lifted every time limit of the
 * calls to Telegram while the key was HTTP_TIMEOUT). Nothing the environment holds — put there by a request or by the
 * host — reaches it now.
 */
final class ConfigurationSourceTest extends TestCase
{
    public function testWhatTheEnvironmentHoldsNeverReachesTheConfiguration(): void
    {
        [$server, $env] = [$_SERVER, $_ENV];
        $_SERVER = ['HTTP_APP_DEBUG' => 'true', 'APP_DEBUG' => 'true', 'HTTP_OUTGOING_HTTP_TIMEOUT' => '0', 'DB_PASSWORD' => 'from a header'] + $_SERVER;
        $_ENV = ['APP_DEBUG' => 'true', 'DB_HOST' => 'elsewhere.example', 'APP_KEY' => 'base64:theirs'] + $_ENV;
        putenv('APP_DEBUG=true');
        putenv('OUTGOING_HTTP_TIMEOUT=0');
        try {
            $config = Config::fromDirectory($this->app()->configPath(), new ConfigValues([]));
        } finally {
            [$_SERVER, $_ENV] = [$server, $env];
            putenv('APP_DEBUG');
            putenv('OUTGOING_HTTP_TIMEOUT');
        }

        self::assertFalse($config->get('app.debug'));
        self::assertSame(30.0, $config->get('app.http_timeout'), 'the time limit is the shop\'s');
        self::assertSame('', $config->get('app.key'));
        self::assertSame([], $config->get('database.connection'), 'no DB_* setting but config.php\'s');
    }

    public function testNoCodeReadsTheEnvironmentForWhatItRunsWith(): void
    {
        $root = dirname(__DIR__, 3);
        $files = glob($root . '/config/*.php') ?: [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/\bgetenv\s*\(|\$_ENV\b|(?<![\w>$:])env\s*\(/', (string) file_get_contents($file), "{$file} reads the environment");
        }
    }
}
