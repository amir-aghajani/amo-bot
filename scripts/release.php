<?php

declare(strict_types=1);

use App\Core\Support\Files;

/*
 * Builds a release: the one zip a shop owner uploads to their host — the app, its PHP packages (vendor/, without the
 * development ones), the built panels (public/admin, public/agent and the files they share in public/assets), the key
 * releases are signed with (resources/release-key.pub) and an empty storage/ — and nothing a running shop does not read
 * (no tests, no panel sources, no node_modules, no config.php, no logs). Beside it, build/release.json — the manifest the
 * shops' updater reads: the version, the zip's size and sha256, the PHP and extensions it needs, the app's top-level paths
 * it replaces (Modules\Updates\Manifest) — and, signed with the key in RELEASE_SIGNING_KEY (base64, as
 * scripts/release-key.php writes it; the release workflow's secret), its signature build/release.json.sig. Without the
 * key the release is unsigned, and no shop updates itself to it.
 *
 *   php scripts/release.php              # pnpm build first, then build/amobot-<version>.zip
 *   php scripts/release.php --no-build   # use the panels already built in public/
 *   php scripts/release.php --composer="php /path/to/composer.phar"
 *   php scripts/release.php --trusted-key=<file>   # the public key the shops trust now — the last release's — when
 *                                                  # this one brings a new one (default: resources/release-key.pub)
 *
 * Needs composer (a composer.phar on the PATH is run with this PHP) and pnpm, either PHP's zip extension or a `tar` that
 * writes zips (Windows 10+, macOS) or `zip` (Linux), and, to sign, PHP's sodium extension.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$options = getopt('', ['no-build', 'composer:', 'trusted-key:']);

$version = version($root);
$name = "amobot-{$version}";
$buildDir = $root . '/build';
$stage = $buildDir . '/' . $name;
$zip = $buildDir . '/' . $name . '.zip';

step("AmoBot {$version}");

if (!isset($options['no-build'])) {
    step('Building the panels (pnpm build)');
    run('pnpm build', $root);
}
foreach (['admin', 'agent'] as $panel) {
    if (!is_file("{$root}/public/{$panel}/index.html")) {
        fail("public/{$panel} is empty: run `pnpm build` (or drop --no-build).");
    }
}

step('Copying the app');
remove($stage);
@unlink($zip);
mkdir($stage, 0o775, true);

// What a running shop reads. The panels' sources, the API's description, the tests and the tooling stay out.
foreach (['app', 'bin', 'bootstrap', 'config', 'database', 'public', 'routes', 'resources/assets'] as $dir) {
    copyTree($root . '/' . $dir, $stage . '/' . $dir);
}
foreach (['.htaccess', 'composer.json', 'composer.lock', 'LICENSE', 'README.md', 'resources/release-key.pub'] as $file) {
    copy($root . '/' . $file, $stage . '/' . $file);
}
// The key the next update is checked with is the one this release carries.
if (trim((string) file_get_contents($root . '/resources/release-key.pub')) === '') {
    step('resources/release-key.pub is empty: shops on this release cannot update themselves (scripts/release-key.php makes the key).');
}

// An empty storage/ with the folders the app writes in, each kept by a .gitkeep, and the rule that serves none of it
// over the web — the folders made as the app makes its own (Files: its owner's alone, as this process owns the build).
Files::ownerOnly(Files::processOwns($root));
foreach (['cache', 'logs', 'sessions', 'updates', 'uploads'] as $dir) {
    Files::writableDirectory($stage . '/storage/' . $dir) || fail("Could not make storage/{$dir}.");
    touch($stage . '/storage/' . $dir . '/.gitkeep');
}
copy($root . '/storage/.htaccess', $stage . '/storage/.htaccess');

step('Installing the PHP packages (composer install --no-dev)');
run(composer($options) . ' install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress', $stage);

// Nothing of the project but public/ is served (the folders the copy brought carry their own rule; these two are made
// here): the PHP packages and the shipped assets refuse the web too, for a host where the project sits in the web root.
foreach (['vendor', 'resources'] as $dir) {
    copy($root . '/app/.htaccess', $stage . '/' . $dir . '/.htaccess');
}

step('Writing ' . basename($zip));
archive($stage, $zip, $name);

$size = number_format(filesize($zip) / 1048576, 1);
step("Done: build/{$name}.zip ({$size} MB)");
fwrite(STDOUT, '  sha256 ' . hash_file('sha256', $zip) . PHP_EOL);

step('Writing release.json, the manifest the updater reads');
$manifest = manifest($root, $stage, $zip, $version);
file_put_contents($buildDir . '/release.json', $manifest);

$secret = getenv('RELEASE_SIGNING_KEY');
@unlink($buildDir . '/release.json.sig');
if (is_string($secret) && trim($secret) !== '') {
    file_put_contents($buildDir . '/release.json.sig', sign($manifest, $secret, trustedKey($root, $options)) . PHP_EOL);
    step('Signed: build/release.json.sig');
} else {
    step('Unsigned: RELEASE_SIGNING_KEY is not set, and no shop updates itself to an unsigned release (the release workflow signs it).');
}

// --- helpers ---------------------------------------------------------------------------------------------------------

/**
 * The manifest the shops' updater reads (Modules\Updates\Manifest): the version, the zip's name, size and sha256, the
 * oldest PHP and the extensions composer.json asks for, and the app's top-level paths the release replaces — all but
 * storage/, the shop's own.
 */
function manifest(string $root, string $stage, string $zip, string $version): string
{
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];
    if (preg_match('/\d+\.\d+(\.\d+)?/', is_string($require['php'] ?? null) ? $require['php'] : '', $php) !== 1) {
        fail('composer.json names no PHP version.');
    }
    $extensions = array_values(array_map(static fn(string $package): string => substr($package, 4), array_filter(array_keys($require), static fn(string $package): bool => str_starts_with($package, 'ext-'))));

    return json_encode([
        'version' => $version,
        'file' => basename($zip),
        'size' => filesize($zip),
        'sha256' => hash_file('sha256', $zip),
        'php' => $php[0],
        'extensions' => $extensions,
        'paths' => array_values(array_diff(scandir($stage) ?: [], ['.', '..', 'storage'])),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * The manifest's signature (base64) with `$secret`, the signing key in base64 — once it verifies with `$trusted`, the key
 * the shops trust now: a release they would refuse is never signed.
 */
function sign(string $manifest, string $secret, string $trusted): string
{
    if (!function_exists('sodium_crypto_sign_detached')) {
        fail('Signing needs PHP\'s sodium extension.');
    }
    $key = base64_decode(trim($secret), true);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail('RELEASE_SIGNING_KEY is no signing key: it is the base64 scripts/release-key.php wrote.');
    }
    $signature = sodium_crypto_sign_detached($manifest, $key);
    sodium_memzero($key);

    $public = base64_decode(trim($trusted), true);
    if (!is_string($public) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !sodium_crypto_sign_verify_detached($signature, $manifest, $public)) {
        fail('The signature does not verify with the key the shops trust: RELEASE_SIGNING_KEY is another key. Sign with the one they trust — after a new key, the last release\'s (--trusted-key).');
    }

    return base64_encode($signature);
}

/**
 * The public key the shops trust now: --trusted-key's file (the last release's, when this one brings a new key), else
 * the one this release carries.
 *
 * @param array<string, mixed> $options
 */
function trustedKey(string $root, array $options): string
{
    $file = is_string($options['trusted-key'] ?? null) ? $options['trusted-key'] : $root . '/resources/release-key.pub';
    $key = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if ($key === '') {
        fail("No key in {$file}: the shops trust none to check this release with (scripts/release-key.php makes one).");
    }

    return $key;
}

function version(string $root): string
{
    $source = (string) file_get_contents($root . '/app/Core/Application.php');
    if (preg_match("/const VERSION = '([^']+)'/", $source, $match) !== 1) {
        fail('Could not read Application::VERSION.');
    }

    return $match[1];
}

function step(string $message): void
{
    fwrite(STDOUT, "\n> {$message}\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "\nrelease: {$message}\n");
    exit(1);
}

/**
 * How to run composer here: --composer when given; else `composer` when the PATH has a command by that name the shell
 * can start (a .bat on Windows); else a composer.phar on the PATH, run with this PHP.
 *
 * @param array<string, mixed> $options
 */
function composer(array $options): string
{
    if (is_string($options['composer'] ?? null) && $options['composer'] !== '') {
        return $options['composer'];
    }

    $names = PHP_OS_FAMILY === 'Windows' ? ['composer.bat', 'composer.cmd', 'composer.exe'] : ['composer'];
    $dirs = explode(PATH_SEPARATOR, (string) getenv('PATH'));
    foreach ($dirs as $dir) {
        foreach ($names as $file) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                return 'composer';
            }
        }
    }
    foreach ($dirs as $dir) {
        if (is_file($dir . DIRECTORY_SEPARATOR . 'composer.phar')) {
            return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . DIRECTORY_SEPARATOR . 'composer.phar');
        }
    }

    return 'composer';
}

function run(string $command, string $cwd): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);
    if (!is_resource($process) || proc_close($process) !== 0) {
        fail("`{$command}` failed.");
    }
}

/** @param list<string> $exclude File names (at the top of the tree) to leave out. */
function copyTree(string $from, string $to, array $exclude = []): void
{
    if (!is_dir($from)) {
        fail("Missing {$from}.");
    }
    @mkdir($to, 0o775, true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $relative = substr($item->getPathname(), strlen($from) + 1);
        if (in_array(str_replace('\\', '/', $relative), $exclude, true)) {
            continue;
        }
        $target = $to . '/' . $relative;
        if ($item->isDir()) {
            @mkdir($target, 0o775, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function remove(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

/** The staged folder as a zip whose top level is that folder: PHP's zip extension, else `tar` (writes zips), else `zip`. */
function archive(string $stage, string $zip, string $name): void
{
    $parent = dirname($stage);

    if (class_exists(ZipArchive::class)) {
        $archive = new ZipArchive();
        if ($archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fail("Could not write {$zip}.");
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $archive->addFile($item->getPathname(), $name . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($stage) + 1)));
        }
        $archive->close();

        return;
    }

    // Names relative to the build folder (a GNU tar would read "C:" as a host). Windows' own tar is bsdtar, which
    // writes zips; a GNU tar earlier on the PATH (Git's) does not, so Windows names its own.
    $file = basename($zip);
    $tar = PHP_OS_FAMILY === 'Windows' && is_file((string) getenv('SystemRoot') . '\System32\tar.exe')
        ? escapeshellarg((string) getenv('SystemRoot') . '\System32\tar.exe')
        : 'tar';
    $commands = PHP_OS_FAMILY === 'Linux'
        ? ["zip -qr {$file} {$name}", "{$tar} -a -cf {$file} {$name}"]
        : ["{$tar} -a -cf {$file} {$name}", "zip -qr {$file} {$name}"];
    foreach ($commands as $command) {
        @unlink($zip);
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $parent);
        if (is_resource($process) && proc_close($process) === 0 && is_file($zip) && file_get_contents($zip, false, null, 0, 4) === "PK\x03\x04") {
            return;
        }
    }
    @unlink($zip);

    fail('No way to write a zip here: enable PHP\'s zip extension, or install zip.');
}
