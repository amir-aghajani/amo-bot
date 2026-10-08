<?php

declare(strict_types=1);

namespace App\Core\Database\Drivers;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Core\Forms\Form;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * MySQL, and MariaDB through the same connection — the database every host offers, and the one the installer
 * proposes. The schema needs MySQL 5.7.8 (JSON columns) or MariaDB 10.3 (Laravel 12's oldest).
 */
final class MySqlDriver implements DatabaseDriver
{
    /** The oldest server a probe accepts, by the name the connection gives it. */
    private const MINIMUM = ['MySQL' => '5.7.8', 'MariaDB' => '10.3'];

    /** Seconds a probe waits for a server that does not answer (Illuminate's connector tries twice). */
    private const PROBE_TIMEOUT = 3;

    /**
     * The longest table prefix taken: it begins every key name the schema makes, which MySQL holds to 64 characters
     * (tests/Unit/Database/SchemaTest).
     */
    public const PREFIX_MAX = 16;

    /** The password left blank while the address it was kept for moved: it never goes to another server, a test of the new one included. */
    public const ADDRESS_MOVED = 'آدرس دیتابیس عوض شده است؛ رمز عبور را دوباره وارد کنید.';

    public function key(): string
    {
        return 'mysql';
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'MySQL / MariaDB',
            description: 'یک دیتابیس خالی بسازید (در cPanel از بخش MySQL Databases) و کاربرش را با همه دسترسی‌ها به آن اضافه کنید؛ بعد مشخصاتش را این‌جا بنویسید.',
            form: $this->form(),
            notes: ['MySQL 5.7.8 یا بالاتر، یا MariaDB 10.3 یا بالاتر'],
            traits: [self::INSTALLABLE => true],
        );
    }

    public function extensions(): array
    {
        return ['pdo_mysql'];
    }

    public function connection(array $config): array
    {
        $values = $this->form()->values(static fn(Field $field): mixed => $config[$field->key] ?? null);

        return [
            'driver' => 'mysql',
            'host' => $values['host'],
            'port' => $values['port'],
            'database' => $values['database'],
            'username' => $values['username'],
            'password' => $values['password'],
            'unix_socket' => $values['socket'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $values['prefix'],
            'prefix_indexes' => true,
            'strict' => true,
            // The session speaks UTC whatever the server's own zone (and its daylight saving): the TIMESTAMP columns
            // keep the moments PHP writes, PHP's clock is UTC too, and the shop's zone is applied only when shown.
            'timezone' => '+00:00',
            'engine' => 'InnoDB',
            'options' => self::foundRows(),
        ];
    }

    public function probe(array $config): ProbeResult
    {
        $connection = $this->connection($config);
        $connection['options'][\PDO::ATTR_TIMEOUT] = self::PROBE_TIMEOUT;

        try {
            return ProbeResult::probe($connection, self::MINIMUM);
        } catch (\PDOException $e) {
            throw new ProbeFailedException(self::explain($e), previous: $e);
        }
    }

    public function localDate(string $column, int $offset): string
    {
        // Seconds rather than CONVERT_TZ: any offset works (CONVERT_TZ stops at +13:00 before MySQL 8.0.19), no zone tables.
        return sprintf('DATE(DATE_ADD(%s, INTERVAL %d SECOND))', $column, $offset);
    }

    public function releaseNames(Builder $schema, string $table): void
    {
        // A foreign key's name is unique across the whole database on MySQL; an index's only within its table.
        $names = array_column($schema->getForeignKeys($table), 'name');
        if ($names !== []) {
            $schema->table($table, static function (Blueprint $blueprint) use ($names): void {
                foreach ($names as $name) {
                    $blueprint->dropForeign($name);
                }
            });
        }
    }

    /** What the admin should fix, from the server's error number, with its own message as the detail. */
    public static function explain(\PDOException $e): string
    {
        $lead = match ((int) ($e->errorInfo[1] ?? $e->getCode())) {
            1045, 1698 => 'نام کاربری یا رمز عبور دیتابیس پذیرفته نشد.',
            1044 => 'این کاربر به دیتابیس دسترسی ندارد.',
            1049 => 'دیتابیسی با این نام وجود ندارد؛ اول آن را در MySQL بسازید.',
            2002, 2003, 2005 => 'اتصال به سرور دیتابیس برقرار نشد؛ هاست و پورت را بررسی کنید.',
            default => 'اتصال به دیتابیس ناموفق بود.',
        };

        return $lead . ' (' . trim($e->getMessage()) . ')';
    }

    /**
     * Where the database is — a host and a port, or a socket on this machine —, the account on it, and its tables'
     * prefix: each a config.php setting, the password kept with the address it was given for.
     */
    private function form(): Form
    {
        return new Form($this->key(), [
            new Text(
                'host',
                'DB_HOST',
                'localhost',
                label: 'هاست دیتابیس',
                max: 255,
                required: true,
                pattern: '/^[A-Za-z0-9.:_-]+$/',
                mismatch: 'هاست دیتابیس را مثل localhost یا 127.0.0.1 بنویسید.',
                spec: new FieldSpec('هاست', hint: 'روی هاست اشتراکی معمولا localhost', placeholder: 'localhost', ltr: true),
            ),
            new Number('port', 'DB_PORT', 3306, label: 'پورت', min: 1, max: 65535, spec: new FieldSpec('پورت', placeholder: '3306')),
            new Text(
                'database',
                'DB_DATABASE',
                'amobot',
                label: 'نام دیتابیس',
                max: 64,
                required: true,
                pattern: '/^[A-Za-z0-9_$-]+$/',
                mismatch: 'نام دیتابیس فقط می‌تواند حروف انگلیسی، عدد و _ داشته باشد.',
                spec: new FieldSpec('نام دیتابیس', ltr: true),
            ),
            new Text('username', 'DB_USERNAME', 'root', label: 'نام کاربری دیتابیس', max: 64, required: true, spec: new FieldSpec('نام کاربری', ltr: true)),
            // A password is no token: its hint gives none of it away.
            new Secret(
                'password',
                'DB_PASSWORD',
                '',
                pattern: '/^[^\x00-\x1f\x7f]{1,255}$/u',
                mismatch: 'رمز عبور دیتابیس حداکثر 255 کاراکتر است، بدون کاراکترهای کنترلی.',
                hint: static fn(string $password): string => '••••••••',
                boundTo: ['host' => null, 'port' => null, 'socket' => null],
                moved: self::ADDRESS_MOVED,
                spec: new FieldSpec('رمز عبور'),
            ),
            new Text(
                'socket',
                'DB_SOCKET',
                '',
                label: 'سوکت',
                max: 255,
                pattern: '~^/\S+$~',
                mismatch: 'مسیر سوکت باید کامل باشد، مثل /var/run/mysqld/mysqld.sock',
                type: FieldType::Path,
                spec: new FieldSpec('سوکت', hint: 'به جای هاست و پورت از راه این فایل وصل می‌شود؛ روی هاست اشتراکی خالی بماند.', placeholder: '/var/run/mysqld/mysqld.sock', advanced: true),
            ),
            new Text(
                'prefix',
                'DB_PREFIX',
                '',
                label: 'پیشوند جدول‌ها',
                max: self::PREFIX_MAX,
                pattern: '/^[A-Za-z0-9_]+$/',
                mismatch: 'پیشوند جدول‌ها فقط می‌تواند حروف انگلیسی، عدد و _ داشته باشد.',
                spec: new FieldSpec('پیشوند جدول‌ها', hint: 'فقط اگر دیتابیس با برنامه‌های دیگر مشترک است؛ بعد از نصب تغییر ندهید.', placeholder: 'amo_', advanced: true, ltr: true),
            ),
        ]);
    }

    /**
     * An UPDATE reports the rows it matched, as SQLite (and PostgreSQL) do — not only those whose values it changed:
     * a compare-and-swap or a lease renewal that writes what is already there won (Core\Database\Transitions, Lease).
     * PHP 8.4 names the option on the driver's own class; neither exists without pdo_mysql, which then never connects.
     *
     * @return array<int, bool>
     */
    private static function foundRows(): array
    {
        foreach (['Pdo\Mysql::ATTR_FOUND_ROWS', 'PDO::MYSQL_ATTR_FOUND_ROWS'] as $option) {
            if (\defined($option)) {
                return [(int) \constant($option) => true];
            }
        }

        return [];
    }
}
