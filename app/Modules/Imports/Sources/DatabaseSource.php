<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

use App\Core\Exceptions\ValidationException;
use App\Core\Security\DecryptionException;
use App\Core\Security\Encrypter;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Models\Import;
use App\Support\Input;
use Illuminate\Database\Connection;

/**
 * The other bot's database as the source, read and never written: tried at once when the owner gives it, kept
 * encrypted on the import (`connection`) with its tables (`schema`), then staged a table at a time — the columns the
 * migrator reads that the table has, a page at a time after the last row staged (by its primary key when that is one
 * column, by its place otherwise), where it got to saved with each batch (RowWriter).
 */
final class DatabaseSource
{
    /** MySQL's own port, when the owner gives none. */
    private const PORT = 3306;

    /** MySQL's numbers for the refusals the owner can act on. */
    private const DENIED = [1045, 1698];
    private const NO_ACCESS = 1044;
    private const UNKNOWN_DATABASE = 1049;

    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
        private readonly Dialect $dialect,
    ) {}

    /**
     * Try the connection the owner gave ({host, port, database, username, password}) and keep it on `$import`, encrypted,
     * with the database's tables.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException under the field at fault
     */
    public function keep(Import $import, array $input): void
    {
        $connection = self::connection($input);
        $pdo = $this->connect($connection, null);
        try {
            $tables = $this->dialect->tables($pdo, $connection['database']);
        } catch (\PDOException $e) {
            throw self::refused($e, null);
        }
        if ($tables === []) {
            throw ImportRefusedException::on('database', ImportRefusedException::EMPTY_DATABASE);
        }

        $import->connection = $this->encrypter->encrypt(json_encode($connection, JSON_THROW_ON_ERROR));
        $import->schema = array_map(static fn(array $table): array => $table['columns'], $tables);
        $import->reading = ['done' => 0, 'total' => null];
        $import->save();
    }

    /**
     * Read on from where the import's reading stopped — the first step plans it: which tables, which columns, their rows
     * counted — until every table the migrator reads is staged (true) or the budget is spent.
     *
     * @throws ValidationException under `connection`: the database cannot be read on
     */
    public function stage(Import $import, Migrator $migrator, Budget $budget): bool
    {
        $reading = $import->reading ?? [];
        if (($reading['finished'] ?? false) === true) {
            return true;
        }

        $connection = $this->kept($import);
        $pdo = $this->connect($connection, 'connection');
        try {
            if (!is_array($reading['tables'] ?? null)) {
                $reading = $this->plan($import, $pdo, $connection['database'], $migrator);
            }

            return $this->read($import, $pdo, $reading, $migrator, $budget);
        } catch (\PDOException $e) {
            throw ImportRefusedException::on('connection', sprintf(ImportRefusedException::CONNECTION_LOST, trim($e->getMessage())));
        }
    }

    /**
     * Rows staged, of all the tables to stage have (null until the first step has counted them).
     *
     * @return array{done: int, total: int|null}
     */
    public static function progress(Import $import): array
    {
        $total = $import->reading['total'] ?? null;

        return ['done' => (int) ($import->reading['done'] ?? 0), 'total' => is_int($total) ? $total : null];
    }

    /**
     * What to read for `$migrator`: each table of its that the database has — under either name (Names) —, the columns
     * of it there are, the primary key its pages follow, and the rows of all of them counted. The schema is saved under
     * the migrator's names.
     *
     * @return array<string, mixed> The reading
     */
    private function plan(Import $import, \PDO $pdo, string $database, Migrator $migrator): array
    {
        $tables = $this->dialect->tables($pdo, $database);
        $names = new Names($migrator->tables());
        $sources = [];
        foreach (array_keys($tables) as $source) {
            $source = (string) $source;
            $name = $names->table($source);
            if ($name !== null && ($name === $source || !isset($sources[$name]))) {
                $sources[$name] = $source;
            }
        }

        $plan = [];
        $total = 0;
        foreach ($migrator->tables() as $name => $read) {
            $source = $sources[$name] ?? null;
            if ($source === null) {
                continue;
            }
            $select = [];
            foreach ($tables[$source]['columns'] as $column) {
                $named = $names->columns($name, [$column])[0];
                if ($read->columns === null || in_array($named, $read->columns, true)) {
                    $select[$named] = $column;
                }
            }
            $key = $tables[$source]['key'];
            $plan[] = ['table' => $name, 'source' => $source, 'select' => $select, 'key' => count($key) === 1 ? $key[0] : null, 'after' => null, 'offset' => 0];
            $total += (int) self::query($pdo, 'SELECT COUNT(*) FROM ' . $this->dialect->quote($source))->fetchColumn();
        }

        $reading = ['tables' => $plan, 'current' => 0, 'done' => 0, 'total' => $total, 'finished' => false];
        $import->schema = $names->schema(array_map(static fn(array $table): array => $table['columns'], $tables));
        $import->reading = $reading;
        $import->save();

        return $reading;
    }

    /**
     * The staging itself, a page of a table at a time. The budget is asked after each row staged; a step stops there,
     * saving that row's key (or place) as where its table goes on from.
     *
     * @param array<string, mixed> $reading
     */
    private function read(Import $import, \PDO $pdo, array $reading, Migrator $migrator, Budget $budget): bool
    {
        $rows = new RowWriter($this->db, $this->encrypter, $import, $migrator->tables());
        /** @var list<array{table: string, source: string, select: array<string, string>, key: string|null, after: string|null, offset: int}> $tables */
        $tables = $reading['tables'];
        $current = (int) $reading['current'];
        $done = (int) $reading['done'];
        $save = static function (bool $finished) use ($rows, $reading, &$tables, &$current, &$done): void {
            $rows->flush(['tables' => $tables, 'current' => $current, 'done' => $done, 'finished' => $finished] + $reading);
        };

        while ($current < count($tables)) {
            $page = $this->page($pdo, $tables[$current]);
            foreach ($page as [$key, $row]) {
                $rows->add($tables[$current]['table'], $row);
                $tables[$current]['after'] = $key;
                $tables[$current]['offset']++;
                $done++;
                if ($budget->spent()) {
                    $save(false);

                    return false;
                }
                if ($rows->full()) {
                    $save(false);
                }
            }
            // A page short of a batch is the table's last.
            if (count($page) < RowWriter::BATCH) {
                $current++;
            }
            $save(false);
        }
        $save(true);

        return true;
    }

    /**
     * Up to a batch of the table's rows after the last staged, each with its primary key (null without one) and its
     * values by the migrator's names.
     *
     * @param array{table: string, source: string, select: array<string, string>, key: string|null, after: string|null, offset: int} $table
     * @return list<array{string|null, array<string, ?string>}>
     */
    private function page(\PDO $pdo, array $table): array
    {
        $quote = $this->dialect->quote(...);
        $key = $table['key'];
        $columns = array_map($quote, array_values($table['select']));
        $sql = 'SELECT ' . implode(', ', [...($key === null ? [] : [$quote($key)]), ...($columns === [] ? ['1'] : $columns)]) . ' FROM ' . $quote($table['source']);
        if ($key === null) {
            $statement = self::query($pdo, $sql . ' LIMIT ' . RowWriter::BATCH . ' OFFSET ' . $table['offset']);
        } else {
            $after = $table['after'];
            $statement = self::query($pdo, $sql . ($after === null ? '' : ' WHERE ' . $quote($key) . ' > ?') . ' ORDER BY ' . $quote($key) . ' LIMIT ' . RowWriter::BATCH, $after === null ? [] : [$after]);
        }

        $page = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $values) {
            $values = array_map(static fn(mixed $value): ?string => $value === null ? null : (string) $value, $values);
            $at = $key === null ? null : array_shift($values);
            $row = [];
            foreach (array_keys($table['select']) as $i => $named) {
                $row[$named] = $values[$i] ?? null;
            }
            $page[] = [$at, $row];
        }

        return $page;
    }

    /**
     * The connection as the owner gave it, each field checked.
     *
     * @param array<string, mixed> $input
     * @return array{host: string, port: int, database: string, username: string, password: string}
     * @throws ValidationException under each field refused
     */
    private static function connection(array $input): array
    {
        $host = Input::text($input, 'host');
        $port = Input::text($input, 'port') === '' ? self::PORT : Input::integer($input, 'port');
        $database = Input::text($input, 'database');
        $username = Input::text($input, 'username');

        $errors = [];
        if ($host === '') {
            $errors['host'] = [ImportRefusedException::HOST_MISSING];
        } elseif (preg_match('/^[A-Za-z0-9._:\-]{1,255}$/D', $host) !== 1) {
            // A name or an address alone: anything else would be read as more of the connection's settings.
            $errors['host'] = [ImportRefusedException::HOST_INVALID];
        }
        if ($port === null || $port < 1 || $port > 65535) {
            $errors['port'] = [ImportRefusedException::PORT_INVALID];
        }
        if ($database === '') {
            $errors['database'] = [ImportRefusedException::DATABASE_MISSING];
        } elseif (str_contains($database, ';')) {
            $errors['database'] = [ImportRefusedException::UNKNOWN_DATABASE];
        }
        if ($username === '') {
            $errors['username'] = [ImportRefusedException::USERNAME_MISSING];
        }
        ValidationException::ifAny($errors);

        return ['host' => $host, 'port' => (int) $port, 'database' => $database, 'username' => $username, 'password' => Input::password($input, 'password')];
    }

    /**
     * The connection kept on the import.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string}
     * @throws ValidationException under `connection`: it is gone
     */
    private function kept(Import $import): array
    {
        try {
            $kept = $import->connection === null ? null : json_decode($this->encrypter->decrypt($import->connection), true);
        } catch (DecryptionException) {
            $kept = null;
        }
        if (!is_array($kept)) {
            throw ImportRefusedException::on('connection', ImportRefusedException::GONE);
        }

        return [
            'host' => (string) ($kept['host'] ?? ''),
            'port' => (int) ($kept['port'] ?? self::PORT),
            'database' => (string) ($kept['database'] ?? ''),
            'username' => (string) ($kept['username'] ?? ''),
            'password' => (string) ($kept['password'] ?? ''),
        ];
    }

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string} $connection
     * @throws ValidationException under the field at fault, or all under `$field`
     */
    private function connect(array $connection, ?string $field): \PDO
    {
        try {
            return $this->dialect->connect($connection);
        } catch (\PDOException $e) {
            throw self::refused($e, $field);
        }
    }

    /**
     * What the owner should fix, from MySQL's number for the refusal, with the server's own words after it (they name
     * the account and the host it was refused from — never its password) — under the field at fault, or under `$field`.
     */
    private static function refused(\PDOException $e, ?string $field): ValidationException
    {
        $number = (int) ($e->errorInfo[1] ?? 0);
        if ($number === 0) {
            $number = is_int($e->getCode()) ? $e->getCode() : 0;
        }
        if ($number === 0 && preg_match('/\[(\d{4})]/', $e->getMessage(), $match) === 1) {
            $number = (int) $match[1];
        }

        [$fields, $message] = match (true) {
            str_contains($e->getMessage(), 'could not find driver') => [['host'], ImportRefusedException::NO_MYSQL],
            in_array($number, self::DENIED, true) => [['username', 'password'], ImportRefusedException::DENIED],
            $number === self::NO_ACCESS => [['database'], ImportRefusedException::NO_ACCESS],
            $number === self::UNKNOWN_DATABASE => [['database'], ImportRefusedException::UNKNOWN_DATABASE],
            default => [['host'], ImportRefusedException::UNREACHABLE],
        };
        if ($message !== ImportRefusedException::NO_MYSQL) {
            $message .= ' (' . trim($e->getMessage()) . ')';
        }

        return new ValidationException(array_fill_keys($field === null ? $fields : [$field], [$message]), $message);
    }

    /**
     * A statement run with its values (the connection throws on a failure; a driver that only says false is answered
     * alike).
     *
     * @param list<string> $values
     */
    private static function query(\PDO $pdo, string $sql, array $values = []): \PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false || !$statement->execute($values)) {
            throw new \PDOException("The statement failed: {$sql}");
        }

        return $statement;
    }
}
