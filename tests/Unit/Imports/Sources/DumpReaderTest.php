<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Sources;

use App\Core\Exceptions\ValidationException;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Sources\DumpReader;
use App\Modules\Imports\Sources\DumpStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SQL dump reader: CREATE TABLE's columns, INSERT's and REPLACE's rows (with or without a column list), every value
 * a dump writes and every escape, comments of each kind, DELIMITER's blocks passed over, a reading gone on from an
 * offset, values longer than a piece of the file, a file that ends inside a statement refused, and telling a dump by its
 * first bytes.
 */
final class DumpReaderTest extends TestCase
{
    public function testReadsAnInsertWithoutColumnsAfterItsCreateTable(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `user` (
              `id` varchar(500) NOT NULL,
              `kind` enum('a,b','c)') DEFAULT 'a,b' COMMENT 'kind, (of it)',
              Balance int(11) NOT NULL DEFAULT '0',
              `wheelـluck` varchar(45) GENERATED ALWAYS AS (concat(`id`, ',')) VIRTUAL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `u` (`kind`, `Balance`),
              KEY `b` (`Balance`),
              CONSTRAINT `c` FOREIGN KEY (`id`) REFERENCES `other` (`id`),
              CHECK (`Balance` > -1000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='one; two';
            INSERT INTO `user` VALUES ('1','a,b',5,'x'),('2','c)',-3,NULL);
            SQL;

        self::assertSame([
            [DumpStatement::CREATE, 'user', ['id', 'kind', 'Balance', 'wheelـluck'], []],
            [DumpStatement::INSERT, 'user', null, [['1', 'a,b', '5', 'x'], ['2', 'c)', '-3', null]]],
        ], self::read($sql));
    }

    public function testUnescapesStringsAsMysqlWritesThem(): void
    {
        $sql = <<<'SQL'
            INSERT INTO t (a, b, c, d) VALUES ('\0\'\"\b\n\r\t\Z\\\%\_\q', 'it''s', "say \"hi\" ""twice""", 'سلام; (دنیا), -- /* */ #');
            SQL;

        self::assertSame([["\0'\"\x08\n\r\t\x1A\\\\%\\_q", "it's", 'say "hi" "twice"', 'سلام; (دنیا), -- /* */ #']], self::read($sql)[0][3]);
    }

    public function testReadsEveryKindOfValue(): void
    {
        $sql = <<<'SQL'
            INSERT INTO t VALUES (NULL, null, TRUE, false, -12, 3.50, -1.5e-3, 1E10, .5, +7, 0x68656C6C6F, X'6869', x'', b'101', 0b11, _binary 'ab', _utf8mb4'cd', N'ef', CURRENT_TIMESTAMP, NOW(), 0x0),();
            SQL;

        self::assertSame([
            [null, null, '1', '0', '-12', '3.50', '-1.5e-3', '1E10', '.5', '7', 'hello', 'hi', '', '5', '3', 'ab', 'cd', 'ef', 'CURRENT_TIMESTAMP', 'NOW', "\0"],
            [],
        ], self::read($sql)[0][3]);
    }

    public function testPassesOverCommentsOfEveryKind(): void
    {
        $sql = "# a hash comment; INSERT INTO t VALUES (0);\n"
            . "-- a dashed comment; INSERT INTO t VALUES (0);\n"
            . "--\n"
            . "/* a block\n comment; INSERT INTO t VALUES (0); */\n"
            . "/*!40101 SET NAMES utf8mb4 */;\n"
            . "SET @a = 1--1;\n"
            . "INSERT /* here */ INTO -- there\n t # and here\n VALUES (1 /* one */, -- after\n '2');\n";

        self::assertSame([[DumpStatement::INSERT, 't', null, [['1', '2']]]], self::read($sql));
    }

    public function testPassesOverWhatADelimiterBlockHolds(): void
    {
        $sql = <<<'SQL'
            DELIMITER ;;
            CREATE PROCEDURE p() BEGIN INSERT INTO t VALUES (9); INSERT INTO t VALUES (8); END ;;
            INSERT INTO t VALUES (7);;
            DELIMITER ;
            INSERT INTO t VALUES (1);
            DELIMITER $$
            CREATE TRIGGER x AFTER INSERT ON t FOR EACH ROW BEGIN
              INSERT INTO t VALUES (6);
            END
            $$
            DELIMITER ;
            INSERT INTO t VALUES (2);
            SQL;

        self::assertSame([[DumpStatement::INSERT, 't', null, [['1']]], [DumpStatement::INSERT, 't', null, [['2']]]], self::read($sql));
    }

    public function testReadsEachWayOfWritingAnInsert(): void
    {
        $sql = <<<'SQL'
            INSERT IGNORE INTO `bot`.`user` VALUES (1);
            REPLACE INTO user (`id`) VALUES (2);
            INSERT LOW_PRIORITY user VALUE (3);
            INSERT INTO "user" ("id", "name") VALUES (4, 'ansi');
            INSERT INTO user PARTITION (p0, p1) (id) VALUES (5) ON DUPLICATE KEY UPDATE id = VALUES(id);
            insert into user values
            (6),
            (7);
            SQL;

        self::assertSame([
            [DumpStatement::INSERT, 'user', null, [['1']]],
            [DumpStatement::INSERT, 'user', ['id'], [['2']]],
            [DumpStatement::INSERT, 'user', null, [['3']]],
            [DumpStatement::INSERT, 'user', ['id', 'name'], [['4', 'ansi']]],
            [DumpStatement::INSERT, 'user', ['id'], [['5']]],
            [DumpStatement::INSERT, 'user', null, [['6'], ['7']]],
        ], self::read($sql));
    }

    public function testPassesOverStatementsWithoutRowsOrColumns(): void
    {
        $sql = <<<'SQL'
            INSERT INTO t SELECT * FROM u;
            INSERT INTO t (SELECT * FROM u);
            INSERT INTO t SET a = 1;
            CREATE TABLE t2 LIKE t;
            CREATE TABLE t3 (LIKE t);
            CREATE TABLE t4 AS SELECT 1;
            CREATE OR REPLACE VIEW v AS SELECT 1;
            CREATE TEMPORARY TABLE IF NOT EXISTS `t5` (`a` int);
            ALTER TABLE t ADD PRIMARY KEY (a);
            LOCK TABLES t WRITE;
            SQL;

        self::assertSame([[DumpStatement::CREATE, 't5', ['a'], []]], self::read($sql));
    }

    public function testLeavesTheRowsOfAStatementUnreadWhenTheNextIsAskedFor(): void
    {
        $file = self::memory("INSERT INTO a VALUES (1,'x;y'),(2,'z');\nINSERT INTO b VALUES (3);");
        $reader = new DumpReader($file);

        self::assertSame('a', $reader->next()?->table);
        $b = $reader->next();
        self::assertSame('b', $b?->table);
        self::assertSame([['3']], iterator_to_array($reader->rows(), false));
        self::assertNull($reader->next());
    }

    public function testGoesOnFromAnOffsetWithTheDelimiterThere(): void
    {
        $sql = "CREATE TABLE t (a int);\nDELIMITER ;;\nINSERT INTO t VALUES (9);;\nSET @x = 1;;\nDELIMITER ;\nINSERT INTO t VALUES (1);\nINSERT INTO t VALUES (2);\n";
        $file = self::memory($sql);
        $reader = new DumpReader($file);
        $reader->next();
        $reader->next();
        $reader->finish();
        $offset = $reader->offset();

        self::assertStringStartsWith('INSERT INTO t VALUES (2)', ltrim(substr($sql, $offset)));
        self::assertSame([[DumpStatement::INSERT, 't', null, [['2']]]], self::read($sql, $offset));
        // In the middle of the DELIMITER block, with its delimiter.
        $inside = (int) strpos($sql, 'SET @x');
        self::assertSame([[DumpStatement::INSERT, 't', null, [['1']]], [DumpStatement::INSERT, 't', null, [['2']]]], self::read($sql, $inside, ';;'));
        // A statement read again from its own start.
        $reader = new DumpReader($file, (int) strpos($sql, 'INSERT INTO t VALUES (1)'));
        self::assertSame((int) strpos($sql, 'INSERT INTO t VALUES (1)'), $reader->next()?->start);
    }

    public function testReadsValuesLongerThanAPieceOfTheFile(): void
    {
        $long = str_repeat("ab'c\\d\"e;f)g,\n", 250_000);
        $escaped = strtr($long, ['\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\n" => '\\n']);
        $comment = '/* ' . str_repeat('*', 1_100_000) . ' */';
        $rows = implode(',', array_map(static fn(int $i): string => "({$i}, 'row {$i}')", range(1, 50_000)));
        $sql = "{$comment}\nINSERT INTO t VALUES (0, '{$escaped}'),{$rows};\nINSERT INTO t VALUES (-1, 'last');";

        $read = self::read($sql);

        self::assertCount(2, $read);
        self::assertCount(50_001, $read[0][3]);
        self::assertSame($long, $read[0][3][0][1]);
        self::assertSame(['50000', 'row 50000'], $read[0][3][50_000]);
        self::assertSame([['-1', 'last']], $read[1][3]);
    }

    public function testReadsAStatementWhereverTheFilesPiecesSplitIt(): void
    {
        $tail = "INSERT INTO `t` (`a`, `b`) VALUES ('x\\'y''z\\\\', -1.5e-3),(0x6869, NULL), (_binary 'q', X'41') /* c */ -- d\n;\n"
            . "DELIMITER ;;\nINSERT INTO t VALUES (9);;\nDELIMITER ;\n"
            . "CREATE TABLE `u` (`p` int, KEY `k` (`p`));\nREPLACE INTO u VALUES (TRUE);\n";
        $expected = self::read($tail);
        self::assertSame([['x\'y\'z\\', '-1.5e-3'], ['hi', null], ['q', 'A']], $expected[0][3]);

        // A piece is 1 MiB: a comment line before the statements puts the split at each of their bytes in turn.
        for ($at = 0; $at <= strlen($tail); $at++) {
            $sql = '-- ' . str_repeat('x', 1_048_576 - 4 - $at) . "\n" . $tail;
            self::assertSame($expected, self::read($sql), "Split at byte {$at} of the statements.");
        }
    }

    public function testReadsCrlfLinesAndAByteOrderMark(): void
    {
        $sql = "-- dump\nCREATE TABLE t (\n  `a` int,\n  `b` text\n);\nINSERT INTO t VALUES\n(1,'x\\ny'),\n(2,NULL);\n";

        self::assertSame(self::read($sql), self::read("\u{FEFF}" . str_replace("\n", "\r\n", $sql)));
    }

    /** @return array<string, array{string}> */
    public static function brokenDumps(): array
    {
        return [
            'a string' => ["INSERT INTO t VALUES (1, 'unterminated"],
            'an escape' => ["INSERT INTO t VALUES (1, 'x\\"],
            'a block comment' => ['INSERT INTO t VALUES (1); /* unterminated *'],
            'a row' => ["INSERT INTO t VALUES (1, 'a'), (2,"],
            'a row with no end' => ['INSERT INTO t VALUES (1, 2'],
            'an insert with no delimiter' => ["INSERT INTO t VALUES (1, 'a')"],
            'an insert cut before its rows' => ['INSERT INTO t VALUES'],
            'an insert cut at its table' => ['INSERT INTO `t` (`a`'],
            'a create table' => ["CREATE TABLE t (\n  `a` int,\n  `b` text"],
            'a row that is no row' => ["INSERT INTO t VALUES (1 2);\n"],
            'a quoted name' => ['INSERT INTO `t VALUES (1);'],
        ];
    }

    #[DataProvider('brokenDumps')]
    public function testRefusesAFileThatEndsInsideAStatement(string $sql): void
    {
        try {
            self::read($sql);
        } catch (ValidationException $e) {
            self::assertSame(['file' => [ImportRefusedException::BROKEN]], $e->errors());

            return;
        }

        self::fail('The broken dump was read.');
    }

    public function testReadsALargeValue(): void
    {
        $value = str_repeat('ab\\\'c', 1_000_000);

        self::assertSame(str_repeat("ab'c", 1_000_000), self::read("INSERT INTO t VALUES ('{$value}');")[0][3][0][0] ?? null);
    }

    /** @return array<string, array{\Closure(): string}> */
    public static function oversized(): array
    {
        return [
            'a value' => [static fn(): string => "INSERT INTO t VALUES (1, '" . str_repeat('x', 17_000_000) . "');"],
            'a hex value' => [static fn(): string => 'INSERT INTO t VALUES (0x' . str_repeat('ab', 17_000_000) . ');'],
            "a row's values together" => [static fn(): string => 'INSERT INTO t VALUES (' . implode(',', array_fill(0, 3, "'" . str_repeat('y', 6_000_000) . "'")) . ');'],
            'a row of too many values' => [static fn(): string => 'INSERT INTO t VALUES (' . implode(',', range(0, 4096)) . ');'],
            'a quoted name' => [static fn(): string => 'INSERT INTO `' . str_repeat('t', 70_000) . '` VALUES (1);'],
            'a bare name' => [static fn(): string => 'INSERT INTO t (' . str_repeat('c', 70_000) . ') VALUES (1);'],
            'a number' => [static fn(): string => 'INSERT INTO t VALUES (' . str_repeat('9', 70_000) . ');'],
            'a table of too many columns' => [static fn(): string => 'CREATE TABLE t (' . implode(', ', array_map(static fn(int $i): string => "`c{$i}` int", range(0, 4096))) . ');'],
            "a DELIMITER's line" => [static fn(): string => 'DELIMITER ' . str_repeat(';', 70_000) . "\nSELECT 1;"],
        ];
    }

    /** @param \Closure(): string $sql */
    #[DataProvider('oversized')]
    public function testRefusesWhatIsLargerThanAnyBotKeeps(\Closure $sql): void
    {
        try {
            self::read($sql());
        } catch (ValidationException $e) {
            self::assertSame(['file' => [ImportRefusedException::BROKEN]], $e->errors());

            return;
        }

        self::fail('It was read.');
    }

    public function testTakesALastStatementWithoutRowsUnterminated(): void
    {
        self::assertSame([[DumpStatement::INSERT, 't', null, [['1']]]], self::read("INSERT INTO t VALUES (1);\nSET FOREIGN_KEY_CHECKS=1"));
    }

    /** @return array<string, array{string, bool}> */
    public static function heads(): array
    {
        return [
            'mysqldump' => ["-- MySQL dump 10.13\n--\n/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!50503 SET NAMES utf8mb4 */;\nDROP TABLE IF EXISTS `user`;", true],
            'mysqldump --compact' => ["/*!40101 SET character_set_client = utf8 */;\nCREATE TABLE `t` (`a` int);", true],
            'phpMyAdmin' => ["-- phpMyAdmin SQL Dump\n-- version 5.2.1\n\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";", true],
            "Faoxima's PHP writer" => ["-- Database: `bot`\n-- Generated at: 2026-11-10\n\nSET FOREIGN_KEY_CHECKS=0;\n\n", true],
            'a byte order mark' => ["\u{FEFF}INSERT INTO t VALUES (1);", true],
            'lower case' => ['insert into t values (1);', true],
            'comments as far as it goes' => ["-- a long header\n/* that goes on", true],
            'a picture' => ["\x89PNG\r\n\x1A\n\0\0\0\rIHDR", false],
            'JSON' => ['{"format":"amobot-backup"}', false],
            'words' => ["Dear owner,\nhere is the backup.", false],
            'nothing' => ['', false],
        ];
    }

    #[DataProvider('heads')]
    public function testTellsADumpByItsFirstBytes(string $head, bool $dump): void
    {
        self::assertSame($dump, DumpReader::opensLikeSql($head));
    }

    /**
     * Each statement of `$sql` that carries data from `$offset`: its kind, table, columns and rows.
     *
     * @return list<array{string, string, list<string>|null, list<list<?string>>}>
     */
    private static function read(string $sql, int $offset = 0, string $delimiter = ';'): array
    {
        $file = self::memory($sql);
        $reader = new DumpReader($file, $offset, $delimiter);
        $read = [];
        while (($statement = $reader->next()) !== null) {
            $rows = $statement->kind === DumpStatement::INSERT ? iterator_to_array($reader->rows(), false) : [];
            $read[] = [$statement->kind, $statement->table, $statement->columns, $rows];
        }
        fclose($file);

        return $read;
    }

    /** @return resource */
    private static function memory(string $bytes)
    {
        $file = fopen('php://memory', 'w+b');
        self::assertNotFalse($file);
        fwrite($file, $bytes);

        return $file;
    }
}
