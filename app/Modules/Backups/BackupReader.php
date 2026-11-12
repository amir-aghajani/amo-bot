<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Core\Application;
use App\Core\Database\Schema;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\Encrypter;
use App\Modules\Backups\Exceptions\BackupRefusedException;

/**
 * Reads a backup file BackupWriter wrote. read() checks it whole before anything is restored: a backup of AmoBot, signed
 * by this installation's key over every line, by this version or an older one no older than the oldest it restores
 * (BackupTables::OLDEST_RESTORABLE), holding only the tables its scope holds
 * — and, a shop's, only that shop's rows: each own row with its `bot_id`, each child row under a parent row of the file.
 * rows() then gives its rows, a line at a time.
 */
final class BackupReader
{
    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly Schema $schema,
    ) {}

    /** @throws ValidationException (BackupRefusedException's words) when the file is none the shop restores */
    public function read(string $path): BackupContents
    {
        $file = @gzopen($path, 'rb');
        if ($file === false) {
            throw BackupRefusedException::because(BackupRefusedException::NOT_A_BACKUP);
        }

        try {
            return $this->check($file);
        } finally {
            gzclose($file);
        }
    }

    /**
     * Each row of the file, with its table and columns, in the file's order — of a file read() took.
     *
     * @return \Generator<int, array{string, list<string>, list<mixed>}>
     */
    public function rows(string $path): \Generator
    {
        $file = gzopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException("The backup {$path} cannot be read.");
        }

        try {
            gzgets($file);
            $table = null;
            $columns = [];
            while (($line = gzgets($file)) !== false) {
                $document = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (array_is_list($document)) {
                    yield [(string) $table, $columns, $document];
                } elseif (isset($document['table'])) {
                    [$table, $columns] = [$document['table'], $document['columns']];
                } else {
                    return;
                }
            }
        } finally {
            gzclose($file);
        }
    }

    /** @param resource $file */
    private function check($file): BackupContents
    {
        $signature = hash_init('sha256', HASH_HMAC, $this->encrypter->macKey(BackupWriter::PURPOSE));
        $first = gzgets($file);
        $header = is_string($first) ? json_decode($first, true) : null;
        if (!is_array($header) || ($header['format'] ?? null) !== BackupWriter::FORMAT || !is_int($header['format_version'] ?? null)) {
            throw BackupRefusedException::because(BackupRefusedException::NOT_A_BACKUP);
        }
        hash_update($signature, (string) $first);

        $scope = ($header['scope'] ?? null) === 'shop' && is_int($header['shop']['id'] ?? null) ? BackupScope::shop($header['shop']['id']) : BackupScope::installation();
        $held = BackupTables::of($scope, $this->schema->tables());
        $columns = [];
        $rows = [];
        $declared = null;
        $signed = false;
        $guard = new ShopRows($scope);
        $table = null;

        while (($line = gzgets($file)) !== false) {
            $document = json_decode($line, true);
            if (!is_array($document) || $signed) {
                throw BackupRefusedException::because(BackupRefusedException::BROKEN);
            }
            if (array_key_exists('signature', $document)) {
                $signed = is_string($document['signature']) && hash_equals(hash_final($signature), $document['signature']);
                if (!$signed) {
                    throw BackupRefusedException::because(BackupRefusedException::BROKEN);
                }

                continue;
            }
            hash_update($signature, $line);

            if (array_is_list($document)) {
                if ($table === null) {
                    throw BackupRefusedException::because(BackupRefusedException::BROKEN);
                }
                $rows[$table]++;
                $guard->row($table, $columns[$table], $document);
            } elseif (isset($document['table'], $document['columns']) && is_string($document['table']) && is_array($document['columns'])) {
                $table = $document['table'];
                if (isset($columns[$table])) {
                    throw BackupRefusedException::because(BackupRefusedException::BROKEN);
                }
                $columns[$table] = array_values(array_map('strval', $document['columns']));
                $rows[$table] = 0;
            } elseif (isset($document['end']['rows']) && is_array($document['end']['rows'])) {
                $declared = $document['end']['rows'];
            } else {
                throw BackupRefusedException::because(BackupRefusedException::BROKEN);
            }
        }

        if (!$signed || $declared != $rows) {
            throw BackupRefusedException::because(BackupRefusedException::BROKEN);
        }
        $version = (string) ($header['app_version'] ?? '');
        if ($version === '' || version_compare($version, Application::VERSION, '>')) {
            throw BackupRefusedException::because(sprintf(BackupRefusedException::NEWER, $version, Application::VERSION));
        }
        if (version_compare($version, BackupTables::OLDEST_RESTORABLE, '<')) {
            throw BackupRefusedException::because(sprintf(BackupRefusedException::OLDER, $version, Application::VERSION, BackupTables::OLDEST_RESTORABLE));
        }
        // Only what its scope holds — signed by this installation's own writer, so never otherwise but for a bug.
        if (array_diff(array_keys($columns), $held) !== [] || $guard->strays()) {
            throw BackupRefusedException::because(BackupRefusedException::STRAY_ROWS);
        }

        $shop = $scope->shop === null ? null : ['id' => $scope->shop, 'username' => self::text($header['shop']['username'] ?? null), 'title' => self::text($header['shop']['title'] ?? null)];

        return new BackupContents($scope, $shop, $version, (string) ($header['made_at'] ?? ''), $rows);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
