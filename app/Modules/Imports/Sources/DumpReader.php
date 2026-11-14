<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

use App\Core\Exceptions\ValidationException;
use App\Modules\Imports\Exceptions\ImportRefusedException;

/**
 * Reads a SQL dump — mysqldump's, phpMyAdmin's, a bot's own PHP writer's — a statement at a time from any byte offset,
 * holding no more of the file than a piece of it and the value being read: each table's columns (CREATE TABLE) and its
 * rows (INSERT and REPLACE, one row or many, with or without a column list). Every other statement is passed over, and
 * so is everything while the delimiter is not `;` (DELIMITER's blocks: triggers and procedures). A file that ends inside
 * a quote, a comment or a statement carrying data is refused as broken, and so is one with a name, a value or a row
 * larger than any a bot keeps — which would take all of PHP's memory, the same way at every step.
 */
final class DumpReader
{
    /** How much of the file one read takes. */
    private const PIECE = 1_048_576;

    /** The longest name (a table's, a column's), bare word or number, bytes: MySQL's names have 64 characters. */
    private const NAME_MOST = 65_536;

    /** The most of a value, and of a row's values together, bytes — and an eighth of PHP's memory at most. */
    private const VALUE_MOST = 16_777_216;

    /** The most columns a table has, and values a row: MySQL's own limit. */
    private const COLUMNS_MOST = 4096;

    private const SPACE = " \t\n\r\f\v";

    private const DIGITS = '0123456789';

    private const HEX = '0123456789abcdefABCDEF';

    /** The words a dump's first statement opens with (opensLikeSql()). */
    private const OPENINGS = ['SET', 'CREATE', 'DROP', 'INSERT', 'REPLACE', 'LOCK', 'UNLOCK', 'USE', 'START', 'BEGIN', 'COMMIT', 'DELIMITER', 'ALTER', 'TRUNCATE'];

    /** The words between INSERT (or REPLACE) and its table. */
    private const INSERT_WORDS = ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE', 'INTO'];

    /** The words a CREATE TABLE definition that is no column opens with. */
    private const NOT_COLUMNS = ['PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'FULLTEXT', 'SPATIAL', 'CHECK', 'PERIOD', 'LIKE'];

    /** What a backslash and the character after it stand for in a quoted string; any other stands for itself. */
    private const ESCAPES = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A", '%' => '\\%', '_' => '\\_'];

    private string $buffer = '';

    /** Where the reader stands in the buffer. */
    private int $at = 0;

    /** The file offset of the buffer's first byte. */
    private int $base;

    private bool $ended = false;

    /** Whether the INSERT next() returned is still to be read to its end. */
    private bool $open = false;

    /** The most of a value here (VALUE_MOST, or less where PHP has less memory). */
    private readonly int $valueMost;

    /** @param resource $file */
    public function __construct(
        private $file,
        int $offset = 0,
        private string $delimiter = ';',
    ) {
        fseek($file, $offset);
        $this->base = $offset;
        $memory = self::memoryLimit();
        $this->valueMost = $memory === null ? self::VALUE_MOST : max(self::PIECE, min(self::VALUE_MOST, intdiv($memory, 8)));
        // A byte order mark some editors put first.
        if ($offset === 0 && $this->fill(3) && str_starts_with($this->buffer, "\u{FEFF}")) {
            $this->at = 3;
        }
    }

    /**
     * Whether a file's first bytes open like a SQL dump: past whitespace, comments and empty statements, a statement's
     * first word — or comments alone, as far as the bytes go.
     */
    public static function opensLikeSql(string $head): bool
    {
        $memory = $head === '' || str_contains($head, "\0") ? false : fopen('php://memory', 'w+b');
        if ($memory === false) {
            return false;
        }
        fwrite($memory, $head);
        $reader = new self($memory);

        try {
            $reader->blank();
            while ($reader->atDelimiter()) {
                $reader->at++;
                $reader->blank();
            }

            return $reader->char() === '' || in_array(strtoupper($reader->word()), self::OPENINGS, true);
        } catch (ValidationException) {
            // A comment that goes on past the head.
            return true;
        } finally {
            fclose($memory);
        }
    }

    /** Where the reader stands in the file: once a statement is read through, where the next one may start. */
    public function offset(): int
    {
        return $this->base + $this->at;
    }

    /** The delimiter in force where the reader stands. */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * The next statement that carries data, the rest of the one before passed over first; null at the end of the file.
     *
     * @throws ValidationException BROKEN under `file`
     * @phpstan-impure
     */
    public function next(): ?DumpStatement
    {
        $this->finish();
        while (true) {
            $this->blank();
            if ($this->char() === '') {
                return null;
            }
            $start = $this->offset();
            if ($this->atDelimiter()) {
                $this->at += strlen($this->delimiter);

                continue;
            }

            $word = strtoupper($this->word());
            if ($word === 'DELIMITER' && in_array($this->char(), [' ', "\t"], true)) {
                $this->changeDelimiter();

                continue;
            }
            $data = in_array($word, ['INSERT', 'REPLACE', 'CREATE'], true);
            $statement = null;
            if ($data && $this->delimiter === ';') {
                $statement = $word === 'CREATE' ? $this->create($start) : $this->insert($start);
            }
            // A CREATE TABLE is read up to its columns, an INSERT up to its rows; any other is passed over whole.
            if ($statement === null || $statement->kind === DumpStatement::CREATE) {
                $this->skip($data);
            }
            if ($statement !== null) {
                return $statement;
            }
        }
    }

    /**
     * The rows of the INSERT next() returned, each its values in order — text as it stands unescaped, a number as
     * written, a hex or bit literal as its bytes or its number, NULL as null —, the statement read to its end once they
     * are through.
     *
     * @return \Generator<int, list<?string>>
     * @throws ValidationException BROKEN under `file`: a row the file ends in, or one that is no row
     */
    public function rows(): \Generator
    {
        while ($this->open && $this->symbol('(')) {
            $values = [];
            $bytes = 0;
            if (!$this->symbol(')')) {
                do {
                    $value = $this->value();
                    $bytes += strlen((string) $value);
                    if (count($values) === self::COLUMNS_MOST || $bytes > $this->valueMost) {
                        throw self::broken();
                    }
                    $values[] = $value;
                } while ($this->symbol(','));
                if (!$this->symbol(')')) {
                    throw self::broken();
                }
            }

            yield $values;

            if (!$this->symbol(',')) {
                break;
            }
        }
        $this->finish();
    }

    /** The rest of the INSERT next() returned passed over — the rows not read too —, so the reader stands after it. */
    public function finish(): void
    {
        if ($this->open) {
            $this->open = false;
            $this->skip(true);
        }
    }

    /** An INSERT's (or REPLACE's) table and columns, up to its first row; null for one with no rows of values (… SELECT, … SET). */
    private function insert(int $start): ?DumpStatement
    {
        do {
            $name = $this->name();
            if ($name === null) {
                return null;
            }
        } while (!$name[1] && in_array(strtoupper($name[0]), self::INSERT_WORDS, true));
        $table = $this->qualified($name[0]);

        $word = $this->bareWord();
        if ($word === 'PARTITION') {
            if (!$this->symbol('(') || $this->scan(')') !== ')') {
                return null;
            }
            $this->at++;
            $word = $this->bareWord();
        }
        $columns = null;
        if ($word === '' && $this->symbol('(')) {
            $columns = [];
            do {
                $column = $this->name();
                if ($column === null || (!$column[1] && strtoupper($column[0]) === 'SELECT')) {
                    return null;
                }
                if (count($columns) === self::COLUMNS_MOST) {
                    throw self::broken();
                }
                $columns[] = $column[0];
            } while ($this->symbol(','));
            if (!$this->symbol(')')) {
                return null;
            }
            $word = $this->bareWord();
        }
        if ($table === null || ($word !== 'VALUES' && $word !== 'VALUE')) {
            return null;
        }
        $this->open = true;

        return new DumpStatement(DumpStatement::INSERT, $start, $table, $columns);
    }

    /**
     * A CREATE TABLE's table and columns, up to the end of its definitions; null for any other CREATE, and for a table
     * made like another or from a SELECT.
     */
    private function create(int $start): ?DumpStatement
    {
        do {
            $word = $this->bareWord();
        } while (in_array($word, ['OR', 'REPLACE', 'TEMPORARY'], true));
        if ($word !== 'TABLE') {
            return null;
        }
        $name = $this->name();
        if ($name !== null && !$name[1] && strtoupper($name[0]) === 'IF') {
            if ($this->bareWord() !== 'NOT' || $this->bareWord() !== 'EXISTS') {
                return null;
            }
            $name = $this->name();
        }
        $table = $name === null ? null : $this->qualified($name[0]);
        if ($table === null || !$this->symbol('(')) {
            return null;
        }

        $columns = [];
        while (true) {
            $name = $this->name();
            if ($name !== null && ($name[1] || !in_array(strtoupper($name[0]), self::NOT_COLUMNS, true))) {
                if (count($columns) === self::COLUMNS_MOST) {
                    throw self::broken();
                }
                $columns[] = $name[0];
            }
            $stop = $this->scan(',)');
            if ($stop !== ',' && $stop !== ')') {
                return null;
            }
            $this->at++;
            if ($stop === ')') {
                break;
            }
        }

        return $columns === [] ? null : new DumpStatement(DumpStatement::CREATE, $start, $table, $columns);
    }

    /**
     * One value of a row.
     *
     * @throws ValidationException BROKEN under `file`: the file ends before it, or it is too large
     */
    private function value(): ?string
    {
        $this->blank();
        $c = $this->char();
        if ($c === '') {
            throw self::broken();
        }
        if ($c === "'" || $c === '"') {
            $this->at++;

            return $this->quoted($c, $this->valueMost);
        }
        if (str_contains('+-.' . self::DIGITS, $c)) {
            $next = $c === '0' ? strtolower($this->char(1)) : '';
            if ($next === 'x' || $next === 'b') {
                $this->at += 2;

                return $next === 'x' ? self::bytes($this->span(self::HEX, 2 * $this->valueMost)) : self::bits($this->span('01', self::NAME_MOST));
            }

            return $this->number();
        }

        $word = $this->word();
        $upper = strtoupper($word);
        // X'…', B'…', N'…', and a character set's introducer before a string: _binary '…', _utf8mb4'…'.
        if ($this->char() === "'" && in_array($upper, ['X', 'B', 'N'], true)) {
            $this->at++;
            $text = $this->quoted("'", $upper === 'X' ? 2 * $this->valueMost : $this->valueMost);

            return match ($upper) {
                'X' => self::bytes($text),
                'B' => self::bits($text),
                default => $text,
            };
        }
        if (str_starts_with($word, '_')) {
            $this->blank();
            $c = $this->char();
            if ($c === "'" || $c === '"') {
                $this->at++;

                return $this->quoted($c, $this->valueMost);
            }
        }
        // Any other word stands for itself, an expression after it passed over (a dump writes none).
        $this->scan(',)');

        return match ($upper) {
            'NULL' => null,
            'TRUE' => '1',
            'FALSE' => '0',
            default => $word,
        };
    }

    /** A number as written: a sign, digits, a decimal point, an exponent. */
    private function number(): string
    {
        $sign = $this->char();
        $number = '';
        if ($sign === '-' || $sign === '+') {
            $this->at++;
            $number = $sign === '-' ? '-' : '';
        }
        $number .= $this->span(self::DIGITS . '.', self::NAME_MOST);
        $e = $this->char();
        if ($e === 'e' || $e === 'E') {
            $after = $this->char(1);
            $length = ctype_digit($after) ? 1 : (($after === '-' || $after === '+') && ctype_digit($this->char(2)) ? 2 : 0);
            if ($length > 0) {
                $number .= substr($this->buffer, $this->at, $length + 1);
                $this->at += $length + 1;
                $number .= $this->span(self::DIGITS, self::NAME_MOST);
            }
        }

        return $number;
    }

    /** The bytes a hex literal stands for; the digits as they are when they are not hex. */
    private static function bytes(string $hex): string
    {
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        $bytes = $hex === '' || ctype_xdigit($hex) ? hex2bin($hex) : false;

        return $bytes === false ? $hex : $bytes;
    }

    /** The number a bit literal stands for. */
    private static function bits(string $bits): string
    {
        return (string) bindec($bits === '' ? '0' : $bits);
    }

    /**
     * The next name — quoted (`…`, or "…" as ANSI_QUOTES writes it) or bare — and whether it was quoted; null when none
     * is there.
     *
     * @return array{string, bool}|null
     * @phpstan-impure
     */
    private function name(): ?array
    {
        $this->blank();
        $c = $this->char();
        if ($c === '`' || $c === '"') {
            $this->at++;

            return [$this->quoted($c, self::NAME_MOST), true];
        }
        $word = $this->word();

        return $word === '' ? null : [$word, false];
    }

    /** A table's name after its first part: a database's name before it (`db`.`table`) is dropped. */
    private function qualified(string $name): ?string
    {
        while ($this->symbol('.')) {
            $part = $this->name();
            if ($part === null) {
                return null;
            }
            $name = $part[0];
        }

        return $name;
    }

    /**
     * The next bare word, in capitals; '' when a quote, a bracket or the like comes next.
     *
     * @phpstan-impure
     */
    private function bareWord(): string
    {
        $this->blank();

        return strtoupper($this->word());
    }

    /** @phpstan-impure */
    private function word(): string
    {
        static $bytes = null;
        // ASCII letters, digits, `_` and `$`, and every byte of a character beyond ASCII (a name may be Persian).
        $bytes ??= 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$' . implode('', array_map(chr(...), range(0x80, 0xFF)));

        return $this->span($bytes, self::NAME_MOST);
    }

    /**
     * The bytes from here that are all of `$mask`, at most `$most` of them.
     *
     * @throws ValidationException BROKEN under `file`: there are more
     */
    private function span(string $mask, int $most): string
    {
        $span = '';
        while (true) {
            $length = strspn($this->buffer, $mask, $this->at);
            $span .= substr($this->buffer, $this->at, $length);
            $this->at += $length;
            if (strlen($span) > $most) {
                throw self::broken();
            }
            if ($this->at < strlen($this->buffer) || !$this->fill(1)) {
                return $span;
            }
        }
    }

    /**
     * The rest of a quoted string or name after its opening quote — its text, of at most `$most` bytes; '' when `$most`
     * is null, passed over: a backslash escapes in a string (not in a `…` name), and the quote doubled stands for itself.
     *
     * @throws ValidationException BROKEN under `file`: the file ends inside it, or it is longer
     */
    private function quoted(string $quote, ?int $most): string
    {
        $keep = $most !== null;
        $stops = $quote === '`' ? '`' : $quote . '\\';
        $text = '';
        while (true) {
            $length = strcspn($this->buffer, $stops, $this->at);
            if ($keep) {
                $text .= substr($this->buffer, $this->at, $length);
                if (strlen($text) > $most) {
                    throw self::broken();
                }
            }
            $this->at += $length;
            // The piece ran out first: the string goes on in the next.
            if ($this->at === strlen($this->buffer)) {
                if (!$this->fill(1)) {
                    throw self::broken();
                }

                continue;
            }
            $c = $this->buffer[$this->at];
            if ($c === '\\') {
                $escaped = $this->char(1);
                if ($escaped === '') {
                    throw self::broken();
                }
                if ($keep) {
                    $text .= self::ESCAPES[$escaped] ?? $escaped;
                }
                $this->at += 2;
            } elseif ($this->char(1) === $quote) {
                if ($keep) {
                    $text .= $quote;
                }
                $this->at += 2;
            } else {
                $this->at++;

                return $text;
            }
        }
    }

    /**
     * Whitespace and comments passed over: `-- ` and `#` to the end of the line, `/* … *\/` — MySQL's `/*! … *\/` too,
     * which in a dump only ever sets the session up.
     *
     * @throws ValidationException BROKEN under `file`: the file ends inside a comment
     */
    private function blank(): void
    {
        while (true) {
            $this->at += strspn($this->buffer, self::SPACE, $this->at);
            $c = $this->char();
            if ($c !== '' && str_contains(self::SPACE, $c)) {
                continue;
            }
            if ($c === '#' || ($c === '-' && $this->char(1) === '-' && self::endsDashes($this->char(2)))) {
                $this->line();
            } elseif ($c === '/' && $this->char(1) === '*') {
                $this->comment();
            } else {
                return;
            }
        }
    }

    /** `--` opens a comment only before whitespace, a control character or the end of the file (MySQL's rule). */
    private static function endsDashes(string $c): bool
    {
        return $c === '' || ord($c) <= 32;
    }

    /** The rest of the line passed over, its line break too. */
    private function line(): void
    {
        while (true) {
            $break = strpos($this->buffer, "\n", $this->at);
            if ($break !== false) {
                $this->at = $break + 1;

                return;
            }
            $this->at = strlen($this->buffer);
            if (!$this->fill(1)) {
                return;
            }
        }
    }

    /** A `/* … *\/` comment passed over. */
    private function comment(): void
    {
        $this->at += 2;
        while (true) {
            $end = strpos($this->buffer, '*/', $this->at);
            if ($end !== false) {
                $this->at = $end + 2;

                return;
            }
            // The last byte stays: a `*` whose `/` the next piece brings.
            $this->at = max($this->at, strlen($this->buffer) - 1);
            if (!$this->fill(2)) {
                throw self::broken();
            }
        }
    }

    /**
     * DELIMITER's line: from here on statements end at the word after it.
     *
     * @throws ValidationException BROKEN under `file`: the line is longer than any name
     */
    private function changeDelimiter(): void
    {
        $line = '';
        while (true) {
            $length = strcspn($this->buffer, "\n", $this->at);
            $line .= substr($this->buffer, $this->at, $length);
            $this->at += $length;
            if (strlen($line) > self::NAME_MOST) {
                throw self::broken();
            }
            if ($this->at < strlen($this->buffer)) {
                $this->at++;

                break;
            }
            if (!$this->fill(1)) {
                break;
            }
        }
        $word = strtok(trim($line), self::SPACE);
        if ($word !== false) {
            $this->delimiter = $word;
        }
    }

    /**
     * The rest of the statement passed over, its delimiter with it. One that carries data must end before the file
     * does; any other may (a last `SET …` with no `;`).
     *
     * @throws ValidationException BROKEN under `file`
     */
    private function skip(bool $data): void
    {
        if ($this->scan() !== '') {
            $this->at += strlen($this->delimiter);
        } elseif ($data) {
            throw self::broken();
        }
    }

    /**
     * Passes over everything up to the delimiter, or up to the first of `$stops` outside quotes, comments and brackets,
     * and returns which it found, not passed: the delimiter, the stop, or '' at the end of the file.
     *
     * @throws ValidationException BROKEN under `file`: the file ends inside a quote or a comment
     */
    private function scan(string $stops = ''): string
    {
        $depth = 0;
        $first = $this->delimiter[0];
        $specials = "'\"`#-/()" . $first . $stops;
        while (true) {
            $this->at += strcspn($this->buffer, $specials, $this->at);
            $c = $this->char();
            if ($c === '') {
                return '';
            }
            if ($c === $first && $this->atDelimiter()) {
                return $this->delimiter;
            }
            if ($depth === 0 && $stops !== '' && str_contains($stops, $c)) {
                return $c;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $this->at++;
                $this->quoted($c, null);
            } elseif ($c === '#' || ($c === '-' && $this->char(1) === '-' && self::endsDashes($this->char(2)))) {
                $this->line();
            } elseif ($c === '/' && $this->char(1) === '*') {
                $this->comment();
            } else {
                $depth = max(0, $depth + match ($c) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                });
                $this->at++;
            }
        }
    }

    /** @phpstan-impure */
    private function atDelimiter(): bool
    {
        $length = strlen($this->delimiter);

        return $this->fill($length) && substr_compare($this->buffer, $this->delimiter, $this->at, $length) === 0;
    }

    /**
     * Whether `$c` comes next, past blanks — passed when it does.
     *
     * @phpstan-impure
     */
    private function symbol(string $c): bool
    {
        $this->blank();
        if ($this->char() !== $c) {
            return false;
        }
        $this->at++;

        return true;
    }

    /**
     * The byte `$ahead` after where the reader stands; '' past the end of the file.
     *
     * @phpstan-impure
     */
    private function char(int $ahead = 0): string
    {
        return $this->fill($ahead + 1) ? $this->buffer[$this->at + $ahead] : '';
    }

    /**
     * At least `$bytes` bytes in the buffer from where the reader stands, read on as needed — what it has passed dropped
     * once it is a piece's worth; false when the file ends before.
     */
    private function fill(int $bytes): bool
    {
        while (strlen($this->buffer) - $this->at < $bytes) {
            if ($this->ended) {
                return false;
            }
            if ($this->at >= self::PIECE) {
                $this->buffer = substr($this->buffer, $this->at);
                $this->base += $this->at;
                $this->at = 0;
            }
            $piece = fread($this->file, self::PIECE);
            if ($piece === false || $piece === '') {
                $this->ended = true;
            } else {
                $this->buffer .= $piece;
            }
        }

        return true;
    }

    /** PHP's memory limit, bytes; null for none. */
    private static function memoryLimit(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));
        $bytes = (int) $limit;
        if ($bytes <= 0) {
            return null;
        }

        return match (strtolower(substr($limit, -1))) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    private static function broken(): ValidationException
    {
        return ImportRefusedException::on('file', ImportRefusedException::BROKEN);
    }
}
