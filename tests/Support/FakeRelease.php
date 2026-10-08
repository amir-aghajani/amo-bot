<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A release of AmoBot as the release workflow makes one, for the updater's tests: its zip (amobot-<version>/ and the
 * files under it), its manifest (scripts/release.php's) and the manifest's signature, with a key pair made here — the
 * public half for the updater's ReleaseKey, never the repository's resources/release-key.pub. Needs PHP's zip and sodium
 * extensions.
 */
final class FakeRelease
{
    /** The release's top-level paths, as scripts/release.php ships them: all but storage/. */
    public const PATHS = ['.htaccess', 'LICENSE', 'README.md', 'app', 'bin', 'bootstrap', 'composer.json', 'composer.lock', 'config', 'database', 'public', 'resources', 'routes', 'vendor'];

    public readonly string $secretKey;

    public readonly string $publicKey;

    public function __construct()
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    /**
     * Release `$version`'s files — every path of PATHS with something in it, its version in app/Core/Application.php, an
     * empty storage/ — and `$files` (a path under the release => its content) over them.
     *
     * @param array<string, string> $files
     * @return array<string, string>
     */
    public static function files(string $version, array $files = []): array
    {
        return [
            '.htaccess' => "# {$version}\n",
            'LICENSE' => 'MIT',
            'README.md' => "AmoBot {$version}\n",
            'app/Core/Application.php' => "<?php\n\nfinal class Application\n{\n    public const VERSION = '{$version}';\n}\n",
            'app/marker.txt' => $version,
            'bin/console' => $version,
            'bootstrap/app.php' => $version,
            'composer.json' => "{\"version\": \"{$version}\"}",
            'composer.lock' => '{}',
            'config/app.php' => $version,
            'database/schema.php' => $version,
            'public/index.php' => $version,
            'public/admin/index.html' => "<p>{$version}</p>",
            'resources/assets/qr-background.jpg' => $version,
            'routes/api.php' => $version,
            'vendor/autoload.php' => $version,
            'storage/logs/.gitkeep' => '',
            ...$files,
        ];
    }

    /**
     * The zip of release `$version`: its one top folder (amobot-<version>/), `$entries` under it (a path => its content,
     * or a name of an entry of the zip's own, as given, when it starts with `!`).
     *
     * @param array<string, string> $entries
     */
    public static function zip(string $version, array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'amobot-release');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $path => $content) {
            $zip->addFromString(str_starts_with($path, '!') ? substr($path, 1) : "amobot-{$version}/{$path}", $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }

    /**
     * release.json of a release whose zip is `$zip`, as scripts/release.php writes it — `$overrides` over its fields.
     *
     * @param array<string, mixed> $overrides
     */
    public static function manifest(string $version, string $zip, array $overrides = []): string
    {
        return json_encode([
            'version' => $version,
            'file' => "amobot-{$version}.zip",
            'size' => strlen($zip),
            'sha256' => hash('sha256', $zip),
            'php' => '8.2',
            'extensions' => ['json', 'pdo'],
            'paths' => self::PATHS,
            ...$overrides,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** The manifest's detached signature with this release's key, in base64 — release.json.sig. */
    public function sign(string $manifest): string
    {
        return base64_encode(sodium_crypto_sign_detached($manifest, $this->secretKey)) . "\n";
    }

    /** The public key as resources/release-key.pub holds it. */
    public function keyFile(): string
    {
        return base64_encode($this->publicKey) . "\n";
    }

    /**
     * Release `$version` published on `$gitHub` as the release workflow publishes one: its zip of `$files` (FakeRelease::files()
     * when none), its manifest and the manifest's signature.
     *
     * @param array<string, string>|null $files
     * @return string The zip's bytes
     */
    public function publish(FakeGitHub $gitHub, string $version, ?array $files = null, string $notes = ''): string
    {
        $zip = self::zip($version, $files ?? self::files($version));
        $manifest = self::manifest($version, $zip);
        $gitHub->publish($version, ["amobot-{$version}.zip" => $zip, 'release.json' => $manifest, 'release.json.sig' => $this->sign($manifest)], $notes);

        return $zip;
    }
}
