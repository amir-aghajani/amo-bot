<?php

declare(strict_types=1);

namespace App\Core\Config;

use App\Core\Support\FileLock;
use App\Core\Support\Files;

/**
 * config.php — the shop's own configuration, as WordPress keeps its own in wp-config.php: a PHP file beside the app that
 * returns its settings. The web installer makes it, the owner's settings screen and their login change write it, and
 * the owner may edit it by hand in their host's file manager — no shell, no environment needed. The app reads it at
 * boot (Application, through ConfigValues); what shows or changes it reads it here.
 *
 * Every write renders the whole file anew, in ConfigKeys' order and with its words — a setting the file does not set is
 * shown commented out at its default, for the owner to see and set; one of no section's is kept, under «Other
 * settings» — and writes it whole (Files::writeAtomically()) under a lock: two writers change it one after the other.
 */
final class ConfigFile
{
    /** A config.php the web server may not write, in the owner's words: what to fix. */
    public const UNWRITABLE = 'فایل config.php نوشته نشد؛ به وب‌سرور اجازه نوشتن روی آن (و روی پوشه برنامه، که فایل در آن است) را بدهید.';

    private const WIDTH = 120;

    private const HEADER = <<<'TEXT'
        AmoBot's configuration: this shop's settings, as WordPress keeps its own in wp-config.php.

        The web installer wrote this file, and the panel's settings («تنظیمات پنل») change it. You may edit it by hand as
        well, in your host's File Manager: change a value between its quotes. A line that starts with // is a setting
        left at the value it shows; delete the // to set another. A line written wrongly keeps the whole shop from
        starting: keep a copy before you edit.

        It holds the database's password, the bot's token and the key that reads what the shop keeps encrypted: never
        share it, and keep a copy of it with your backups.
        TEXT;

    /** @param string $lock The container's `config.lock`: the writer that holds it writes the file */
    public function __construct(
        private readonly string $path,
        private readonly string $lock,
    ) {}

    /**
     * What the config.php at `$path` sets; nothing when there is none. One that does not parse stops whoever reads it
     * (a ParseError, its line in the message): the shop does not start on a half-written configuration.
     *
     * @return array<string, mixed>
     * @throws \UnexpectedValueException when it returns no array of settings
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $settings = (static fn(string $file): mixed => require $file)($path);
        if (!is_array($settings)) {
            throw new \UnexpectedValueException("{$path} must return the shop's settings: return ['APP_NAME' => '…', …];");
        }

        return array_filter($settings, static fn(int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** Whether setMany() can succeed: the folder takes a new file beside it (a write is a rename), and the file allows it. */
    public function isWritable(): bool
    {
        return is_writable(dirname($this->path)) && (!$this->exists() || is_writable($this->path));
    }

    /** @return array<string, mixed> Every setting the file sets, as it sets it */
    public function all(): array
    {
        return self::load($this->path);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function set(string $key, string|int|float|bool $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * Set settings in one write — the file made when there is none —, the rest kept as they are.
     *
     * @param array<string, string|int|float|bool> $values
     * @throws \RuntimeException when it cannot be written: the file is then as it was
     */
    public function setMany(array $values): void
    {
        if (!$this->isWritable()) {
            throw new \RuntimeException("{$this->path} is not writable.");
        }

        // Writers one after the other; none to hold while storage/ cannot be written yet (the installer's first step).
        $lock = FileLock::wait($this->lock);
        try {
            Files::writeAtomically($this->path, self::render(array_replace($this->all(), $values)));
        } finally {
            $lock?->release();
        }

        // The next read goes through PHP's opcode cache, which may still hold the file as it was.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }

    /**
     * The file for `$settings`: every section of ConfigKeys with its settings in order, then whatever else it sets.
     *
     * @param array<string, mixed> $settings
     */
    private static function render(array $settings): string
    {
        $lines = ['<?php', '', 'declare(strict_types=1);', '', '/*'];
        foreach (explode("\n", self::HEADER) as $line) {
            $lines[] = rtrim(' * ' . $line);
        }
        $lines = [...$lines, ' */', '', 'return ['];

        $sections = [];
        foreach ($settings as $key => $value) {
            $sections[ConfigKeys::sectionOf($key) ?? 'Other settings'][$key] = $value;
        }
        foreach ([...array_keys(ConfigKeys::SECTIONS), 'Other settings'] as $section) {
            $declared = ConfigKeys::SECTIONS[$section] ?? [];
            $set = $sections[$section] ?? [];
            if ($declared === [] && $set === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = '    // ---- ' . $section . ' ' . str_repeat('-', self::WIDTH - 13 - mb_strlen($section));
            foreach ($declared as $key => [$default, $comment]) {
                $lines[] = '';
                foreach (explode("\n", $comment) as $line) {
                    $lines[] = '    // ' . $line;
                }
                $lines[] = array_key_exists($key, $set) ? self::line($key, $set[$key]) : '    // ' . ltrim(self::line($key, $default));
                unset($set[$key]);
            }
            // A driver's own settings (DB_*, MAIL_*) and anything else of the section's, at its end.
            if ($set !== [] && $declared === []) {
                $lines[] = '';
            }
            foreach ($set as $key => $value) {
                $lines[] = self::line($key, $value);
            }
        }

        return implode("\n", [...$lines, '];']) . "\n";
    }

    private static function line(string $key, mixed $value): string
    {
        $exported = match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };

        return '    ' . var_export($key, true) . ' => ' . $exported . ',';
    }
}
