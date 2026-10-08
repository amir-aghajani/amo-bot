<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Http\BasePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where the app is mounted: the configured prefix, else what the front controller's path says. (What is routed under
 * it is RouteGuardsTest's.)
 */
final class BasePathTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> configured, SCRIPT_NAME, SAPI, expected */
    public static function cases(): iterable
    {
        yield 'root install' => ['', '/index.php', 'apache2handler', ''];
        yield 'public/ of a sub-folder' => ['', '/shop/public/index.php', 'fpm-fcgi', '/shop'];
        yield 'Windows path separators' => ['', '\\shop\\public\\index.php', 'apache2handler', '/shop'];
        yield 'the root .htaccess fallback (no public/)' => ['', '/shop/index.php', 'apache2handler', '/shop'];
        yield 'the built-in dev server reports the request path, not trusted' => ['', '/shop/index.php', 'cli-server', ''];
        yield 'the CLI has no front controller' => ['', '/usr/bin/console', 'cli', ''];
        yield 'a path that is not the front controller' => ['', '/shop/public/', 'apache2handler', ''];
        yield 'configured, bare' => ['shop', '/index.php', 'apache2handler', '/shop'];
        yield 'configured, with slashes' => ['/shop/', '/index.php', 'apache2handler', '/shop'];
        yield 'configured wins over detection' => ['panel', '/shop/public/index.php', 'apache2handler', '/panel'];
    }

    #[DataProvider('cases')]
    public function testTheMountPointIsDetected(string $configured, string $scriptName, string $sapi, string $expected): void
    {
        self::assertSame($expected, BasePath::detect($configured, $scriptName, $sapi));
    }
}
