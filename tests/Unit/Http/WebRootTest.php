<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * public/ is the web root — and on a shared host whose document root is the project folder itself, nothing else may be
 * served: every other folder refuses everything (Apache 2.4 and 2.2 alike) — app/, the PHP sources, sends what reaches
 * it on to the mini app's folder, public/app/ (the app's address is app/{store key}/) —, the root forwards into public/
 * and refuses the shop's config.php, the composer and tool files and version control even without mod_rewrite, and
 * public/ refuses a PHP error_log and answers a missing build file with a 404, never a page. (Apache reads these files;
 * the test reads what it would.)
 */
final class WebRootTest extends TestCase
{
    private const DENIED = ['bin', 'bootstrap', 'config', 'database', 'docs', 'resources', 'routes', 'scripts', 'storage', 'tests'];

    public function testEveryFolderButPublicRefusesEverything(): void
    {
        foreach (self::DENIED as $folder) {
            $rules = self::read("{$folder}/.htaccess");

            self::assertMatchesRegularExpression('/^\s*Require all denied\s*$/m', $rules, $folder);
            self::assertMatchesRegularExpression('/^\s*Deny from all\s*$/m', $rules, "{$folder}, under Apache 2.2");
        }
    }

    public function testTheSourcesFolderSendsTheMiniAppsAddressOnAndServesNothingOfItsOwn(): void
    {
        $rules = self::read('app/.htaccess');

        // With rewriting: every request goes on to public/app/ under the project's own folder, and what cannot be placed
        // is refused — nothing is left to be served from here.
        preg_match('~<IfModule mod_rewrite\.c>(.*?)</IfModule>~s', $rules, $rewriting);
        self::assertNotEmpty($rewriting, 'the forward, where rewriting is available');
        self::assertStringContainsString('RewriteCond %{REQUEST_URI}::$0 ^(.*)app/(.*)::\2$', $rewriting[1]);
        self::assertStringContainsString('RewriteRule ^.*$ %1public/app/%2 [L]', $rewriting[1]);
        self::assertMatchesRegularExpression('~\[L\]\s+RewriteRule \^ - \[F\]\s*$~', $rewriting[1], 'what the forward cannot place is refused');

        // The condition places the address as Apache would: the folder's address, then what follows app/.
        foreach (['/app/0123456789abcdef01234567/' => '/', '/shop/app/assets/x.js' => '/shop/', '/app/app/0123/' => '/app/'] as $uri => $base) {
            $relative = substr($uri, strlen($base) + strlen('app/'));
            self::assertSame(1, preg_match('~^(.*)app/(.*)::\2$~', "{$uri}::{$relative}", $placed), $uri);
            self::assertSame("{$base}public/app/{$relative}", "{$placed[1]}public/app/{$placed[2]}", $uri);
        }

        // Without rewriting: a plain refusal, Apache 2.4 and 2.2 alike.
        preg_match('~<IfModule !mod_rewrite\.c>(.*)</IfModule>~s', $rules, $plain);
        self::assertNotEmpty($plain, 'the refusal, where rewriting is unavailable');
        self::assertMatchesRegularExpression('/^\s*Require all denied\s*$/m', $plain[1]);
        self::assertMatchesRegularExpression('/^\s*Deny from all\s*$/m', $plain[1], 'under Apache 2.2');
    }

    public function testTheRootForwardsIntoPublicAndRefusesTheProjectsOwnFiles(): void
    {
        $rules = self::read('.htaccess');

        self::assertStringContainsString('RewriteRule ^(.*)$ public/$1 [L]', $rules);
        self::assertMatchesRegularExpression('~RedirectMatch 404 .*\(git\|svn\|hg\)~', $rules, 'version control, even without mod_rewrite');

        preg_match('/<FilesMatch "([^"]+)">\s*<IfModule mod_authz_core\.c>\s*Require all denied/', $rules, $refused);
        self::assertNotEmpty($refused, 'a FilesMatch that refuses');
        foreach (['config.php', 'config.php.bak', 'config.php~', 'composer.json', 'composer.lock', 'phpunit.xml', 'phpstan.neon', '.php-cs-fixer.php'] as $file) {
            self::assertMatchesRegularExpression("~{$refused[1]}~", $file, "{$file} is refused — a copy of config.php an editor left beside it too");
        }
        foreach (['index.php', 'myconfig.php'] as $file) {
            self::assertDoesNotMatchRegularExpression("~{$refused[1]}~", $file, "{$file} is no file of the project's own");
        }
    }

    public function testPublicRefusesAnErrorLogAndAnswersAMissingBuildFileWithA404(): void
    {
        $rules = self::read('public/.htaccess');

        self::assertMatchesRegularExpression('/<Files "error_log">\s*<IfModule mod_authz_core\.c>\s*Require all denied/', $rules);
        self::assertStringContainsString('RewriteRule ^(app/)?assets/ - [R=404,L]', $rules, 'a tab from before an upgrade asks for the old build\'s files — the panels\' or the mini app\'s');
    }

    private static function read(string $file): string
    {
        $path = dirname(__DIR__, 3) . '/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
