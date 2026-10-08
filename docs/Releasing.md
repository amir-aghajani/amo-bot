# Releasing

A release is one zip a shop owner uploads to their host — the app, its PHP packages without the development ones, the
built panels, the release key and an empty `storage/`, and nothing a running shop does not read (no tests, no panel
sources, no `node_modules`, no `config.php`, no logs, no `docs/`) — and, beside it, `release.json`, its manifest, signed:
the shops' updater installs a release only when the key built into them signed its manifest
([Upgrading](Upgrading.md#from-the-panel)).

## The version

`App\Core\Application::VERSION` is the version. The installer records it in `storage/installed.lock`, and every update
moves it: the database's upgrades start from there. A release that changes `database/schema.php` ships the same change
as `database/upgrades/<its version>.php` (the rule is in that folder's README).

## The signing key

Releases are signed with an Ed25519 key. Its public half is `resources/release-key.pub`, which every release carries and
every shop checks the next release with; its secret half is the release workflow's secret `RELEASE_SIGNING_KEY`, and
yours, offline. Make the pair once:

```bash
php -d extension=sodium scripts/release-key.php ~/amobot-release.key
```

It writes the public key into `resources/release-key.pub` (commit it) and the secret one, in base64, to the file named
— outside the repository, never printed. Then:

1. make the file's content the repository's secret `RELEASE_SIGNING_KEY` (GitHub › Settings › Secrets and variables ›
   Actions, or `gh secret set RELEASE_SIGNING_KEY < ~/amobot-release.key`);
2. keep a copy offline (a password manager, an encrypted drive) and delete the file here.

A shop trusts the key of the release it runs. A new key (`--rotate`, after a key is lost or on purpose) reaches the shops
only through a release signed with the key they trust and carrying the new one: sign that release with the old key —
the workflow checks the signature against the last release's key — and switch the secret to the new key for the
releases after it. A key lost is a new one every shop is handed by an update by hand.

## Building the zip

```bash
php scripts/release.php              # pnpm build first, then build/amobot-<version>.zip and build/release.json
php scripts/release.php --no-build   # use the panels already built in public/
php scripts/release.php --composer="php /path/to/composer.phar"
```

It builds the panels, copies what runs — `app`, `bin`, `bootstrap`, `config`, `database`, `public` with the built panels,
`routes`, `resources/assets` and `resources/release-key.pub`, the root `.htaccess`, `composer.json` and `composer.lock`,
`LICENSE` and `README.md` —, makes an empty `storage/` (its `cache`, `logs`, `sessions`, `updates` and `uploads`
folders and its deny-all `.htaccess`), runs `composer install --no-dev --optimize-autoloader --classmap-authoritative`,
gives `vendor/` and `resources/` a deny-all `.htaccess` of their own (for a host where the project sits in the web
root), and writes `build/amobot-<version>.zip`, printing its sha256. Then `build/release.json`: the version, the zip's
name, size and sha256, the oldest PHP and the extensions `composer.json` requires, and the app's top-level paths the
release replaces. With `RELEASE_SIGNING_KEY` in its environment it signs it — once the signature verifies with the key
the shops trust (`resources/release-key.pub`, or `--trusted-key=<file>`) — into `build/release.json.sig`; without the key
the release is unsigned, and no shop updates itself to it.

It needs Composer (a `composer.phar` on the PATH is run with this PHP) and pnpm, either PHP's zip extension or a `tar`
that writes zips (Windows 10+, macOS) or `zip` (Linux), and, to sign, PHP's sodium extension.

## Publishing it

1. Write the version's notes in `CHANGELOG.md`, under a heading of its own (`## 1.2.3`, or `## [1.2.3] - <date>`).
2. Set `Application::VERSION`, commit, and push a tag `v<version>` — `v1.2.3` for `1.2.3` — on a commit CI passed on
   every PHP version.

`.github/workflows/release.yml` checks that the tag names `Application::VERSION`, that `CHANGELOG.md` has the version's
section and that the signing secret is set — no unsigned release is ever published —, runs `composer check` (on PHP
8.2) and `pnpm check` again, builds and signs the release with `scripts/release.php` (the key the shops trust now taken
from the last release's tag), and publishes the GitHub release: the zip, `release.json` and `release.json.sig`, its
notes the changelog's section. The shops' daily check sees it within a day; «بررسی دوباره» at once.

Every push to `main` and every pull request runs the checks on PHP 8.2–8.4 and builds the panels
(`.github/workflows/ci.yml`, [Testing](Testing.md#ci)).
