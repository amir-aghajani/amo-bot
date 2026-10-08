<?php

declare(strict_types=1);

/*
 * Makes the key pair AmoBot's releases are signed with (Modules\Updates\ReleaseKey): the public half into
 * resources/release-key.pub — every release carries it, and a shop updates itself only to a release it signed —, the
 * secret half, in base64, into the file you name: outside the repository, never printed.
 *
 *   php scripts/release-key.php ~/amobot-release.key              # the first key
 *   php scripts/release-key.php ~/amobot-release-2.key --rotate   # a new key in place of the one there
 *
 * Needs PHP's sodium extension (`php -d extension=sodium scripts/release-key.php …` where it is not on).
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$root = dirname(__DIR__);
$public = $root . '/resources/release-key.pub';
$arguments = array_values(array_filter(array_slice($argv, 1), static fn(string $argument): bool => $argument !== '--rotate'));
$rotate = in_array('--rotate', $argv, true);

if (count($arguments) !== 1) {
    fail('Name the file the secret key goes to — outside this repository: php scripts/release-key.php ~/amobot-release.key');
}
if (!function_exists('sodium_crypto_sign_keypair')) {
    fail('This PHP has no sodium extension: run it with `php -d extension=sodium scripts/release-key.php …`.');
}

$secretFile = $arguments[0];
$folder = realpath(dirname($secretFile));
if ($folder === false || !is_dir($folder)) {
    fail('The folder of ' . $secretFile . ' is not there.');
}
$inside = static fn(string $path): string => rtrim(PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $path)) : $path, '/') . '/';
if (str_starts_with($inside($folder), $inside((string) realpath($root)))) {
    fail('The secret key goes outside the repository, where nothing commits or ships it.');
}
$secretFile = $folder . DIRECTORY_SEPARATOR . basename($secretFile);
if (file_exists($secretFile)) {
    fail("{$secretFile} is there already: a secret key is never written over.");
}

$current = is_file($public) ? trim((string) file_get_contents($public)) : '';
if ($current !== '' && !$rotate) {
    fail(<<<'TEXT'
        resources/release-key.pub holds a key already, and every shop trusts it: a new one in its place (--rotate) reaches
        them only with a release signed with this one. Keep using it — or, losing it or rotating it on purpose, run this
        again with --rotate.
        TEXT);
}

$pair = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$handle = fopen($secretFile, 'x');
if ($handle === false || fwrite($handle, $secret . PHP_EOL) !== strlen($secret . PHP_EOL)) {
    fail("Could not write {$secretFile}.");
}
fclose($handle);
chmod($secretFile, 0o600);
file_put_contents($public, base64_encode(sodium_crypto_sign_publickey($pair)) . PHP_EOL);
sodium_memzero($pair);
sodium_memzero($secret);

// A new key reaches the shops only through a release signed with the old one, which carries it.
$when = $current === '' ? 'now' : 'once the next release — signed with the OLD key, the secret kept as it is until then — is out';
fwrite(STDOUT, <<<TEXT

    The public key is in resources/release-key.pub: commit it — the next release carries it.
    The secret key is in {$secretFile} (it is never printed). Now:

      1. Make it the release workflow's secret RELEASE_SIGNING_KEY {$when} — GitHub › the repository › Settings ›
         Secrets and variables › Actions, or: gh secret set RELEASE_SIGNING_KEY < "{$secretFile}"
      2. Keep a copy offline (a password manager, an encrypted drive): a shop updates itself only to a release this key
         signed, and a key lost is a new one every shop must be handed by an update by hand.
      3. Delete the file here once it is kept there.

    TEXT);

function fail(string $message): never
{
    fwrite(STDERR, "\nrelease-key: {$message}\n");
    exit(1);
}
