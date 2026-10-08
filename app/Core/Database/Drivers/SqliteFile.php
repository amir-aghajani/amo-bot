<?php

declare(strict_types=1);

namespace App\Core\Database\Drivers;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;

/**
 * SQLite's database file (DB_DATABASE): relative to the application or absolute — never in memory, which every request
 * would start empty, and never under public/, where the web server hands out whatever is there.
 *
 * @extends Field<string>
 */
final class SqliteFile extends Field
{
    /** @param string $basePath The application's folder: a relative path is the file's place in it */
    public function __construct(string $name, string $key, string $default, private readonly string $basePath, ?FieldSpec $spec = null)
    {
        parent::__construct($name, $key, $default, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Path;
    }

    public function required(): bool
    {
        return true;
    }

    public function read(array $input, mixed $kept): string
    {
        $path = Input::text($input, $this->name);
        $refusal = match (true) {
            $path === '' => 'مسیر فایل دیتابیس را وارد کنید.',
            $path === SqliteDriver::MEMORY => 'دیتابیس در حافظه با پایان هر درخواست پاک می‌شود؛ مسیر یک فایل را بدهید.',
            $this->isPublic($this->absolute($path)) => 'فایل دیتابیس نباید در پوشه public باشد: از وب دانلود می‌شود.',
            default => null,
        };
        if ($refusal !== null) {
            throw new FieldRefused($refusal);
        }

        return $path;
    }

    public function cast(mixed $kept): string
    {
        return is_scalar($kept) && trim((string) $kept) !== '' ? trim((string) $kept) : $this->default;
    }

    /** The file's whole path: a relative one is its place in the application's folder. */
    public function absolute(string $path): string
    {
        return preg_match('~^([A-Za-z]:)?[/\\\\]~', $path) === 1 ? $path : $this->basePath . '/' . $path;
    }

    /** Whether the file would sit under public/, where the web server hands out whatever is there. */
    private function isPublic(string $path): bool
    {
        $public = realpath($this->basePath . '/public');
        $directory = realpath(dirname($path));

        return $public !== false && $directory !== false && str_starts_with($directory . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR);
    }
}
