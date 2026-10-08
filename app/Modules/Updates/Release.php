<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Application;

/**
 * A release of AmoBot as GitHub lists it (Releases): its version — its tag, v1.2.3 —, when it was published, its notes
 * and the files attached to it. Nothing of it is trusted for the install: what the updater installs is what the release
 * key signed (Manifest). Its page is on GitHub under the repository, whatever the answer says it is.
 */
final class Release
{
    /** The files the updater needs of a release: the zip, its manifest and the manifest's signature. */
    public const MANIFEST = 'release.json';
    public const SIGNATURE = 'release.json.sig';

    /** The most of its notes a screen shows: a release's notes are a few paragraphs. */
    private const NOTES_MAX = 20000;

    /** @param list<string> $files The files attached to it, by name */
    public function __construct(
        public readonly string $version,
        public readonly ?string $publishedAt,
        public readonly string $notes,
        public readonly array $files,
    ) {}

    /**
     * The release GitHub's API describes (`GET /repos/{repository}/releases/latest`); null for one the shop does not take:
     * a draft, a pre-release, or a tag that names no version.
     *
     * @param array<string, mixed> $answer
     */
    public static function fromApi(array $answer): ?self
    {
        $tag = is_string($answer['tag_name'] ?? null) ? $answer['tag_name'] : '';
        if (($answer['draft'] ?? false) !== false || ($answer['prerelease'] ?? false) !== false || preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $version) !== 1) {
            return null;
        }

        $files = [];
        foreach (is_array($answer['assets'] ?? null) ? $answer['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null)) {
                $files[] = $asset['name'];
            }
        }
        $notes = is_string($answer['body'] ?? null) ? str_replace("\r\n", "\n", $answer['body']) : '';

        return new self($version[1], self::moment($answer['published_at'] ?? null), mb_substr(trim($notes), 0, self::NOTES_MAX), $files);
    }

    /**
     * The release as the shop keeps it between two reads (fromKept()).
     *
     * @return array{version: string, published_at: string|null, notes: string, files: list<string>}
     */
    public function kept(): array
    {
        return ['version' => $this->version, 'published_at' => $this->publishedAt, 'notes' => $this->notes, 'files' => $this->files];
    }

    /** The release kept() made; null for anything else. */
    public static function fromKept(mixed $kept): ?self
    {
        if (!is_array($kept) || !is_string($kept['version'] ?? null) || !is_string($kept['notes'] ?? null) || !is_array($kept['files'] ?? null)) {
            return null;
        }

        return new self($kept['version'], self::moment($kept['published_at'] ?? null), $kept['notes'], array_values(array_filter($kept['files'], is_string(...))));
    }

    /** Whether it is newer than the version the shop runs. */
    public function isNewer(): bool
    {
        return version_compare($this->version, Application::VERSION, '>');
    }

    /** Whether it carries what the updater installs from: its zip, its manifest and the manifest's signature. */
    public function isInstallable(): bool
    {
        return array_diff([$this->zip(), self::MANIFEST, self::SIGNATURE], $this->files) === [];
    }

    /** Its zip's name. */
    public function zip(): string
    {
        return "amobot-{$this->version}.zip";
    }

    /** Its page on GitHub. */
    public function url(): string
    {
        return 'https://github.com/' . Releases::REPOSITORY . "/releases/tag/v{$this->version}";
    }

    /** @return array{version: string, published_at: string|null, notes: string, url: string} As the update screen shows it */
    public function present(): array
    {
        return ['version' => $this->version, 'published_at' => $this->publishedAt, 'notes' => $this->notes, 'url' => $this->url()];
    }

    /** A moment as the API says one (ISO 8601, UTC); null for anything that is none. */
    private static function moment(mixed $value): ?string
    {
        try {
            return is_string($value) ? (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
