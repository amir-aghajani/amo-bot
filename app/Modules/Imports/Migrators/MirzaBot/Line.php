<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Staging\Staged;
use App\Support\BankCard;

/**
 * What every bot of MirzaBot's line keeps alike and its migrator reads alike: the core tables that tell a database of the
 * line (each migrator then tells its own bot from its kin by their marks), its required channels and its bank cards.
 */
final class Line
{
    /** The tables and columns every database of the line has. */
    private const CORE = [
        'user' => ['id', 'Balance', 'User_Status', 'agent'],
        'invoice' => ['id_invoice', 'id_user', 'username', 'Service_location', 'Status'],
        'marzban_panel' => ['name_panel', 'type', 'url_panel'],
    ];

    /**
     * What of the core tables and columns the database lacks: a table, or `table.column`.
     *
     * @return list<string>
     */
    public static function missing(Schema $schema): array
    {
        $missing = [];
        foreach (self::CORE as $table => $columns) {
            if (!$schema->has($table)) {
                $missing[] = $table;

                continue;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumns($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        return $missing;
    }

    /**
     * The required channels (`channels`), each by the chat the bot checked membership in (Cell::chat()): one it could not
     * tell comes as it was kept.
     *
     * @return iterable<int, ImportedChannel>
     */
    public static function channels(Staged $staged, int $after): iterable
    {
        foreach ($staged->rows('channels', $after) as $seq => $row) {
            $link = Cell::text($row['link'] ?? null);
            if ($link === null) {
                continue;
            }
            $link = Cell::chat($link) ?? $link;
            $invite = Cell::text($row['linkjoin'] ?? null);
            yield $seq => new ImportedChannel(
                key: $link,
                link: $link,
                title: Cell::text($row['remark'] ?? null),
                inviteLink: $invite !== null && preg_match('#^https?://#i', $invite) === 1 ? $invite : null,
            );
        }
    }

    /**
     * The bank cards (`card_number`), each by its digits — every one comes switched off here, whatever the bot showed
     * (ImportedCard).
     *
     * @return iterable<int, ImportedCard>
     */
    public static function cards(Staged $staged, int $after): iterable
    {
        foreach ($staged->rows('card_number', $after) as $seq => $row) {
            $number = BankCard::normalize((string) ($row['cardnumber'] ?? ''));
            if ($number !== '') {
                yield $seq => new ImportedCard(key: $number, number: $number, holder: Cell::text($row['namecard'] ?? null) ?? '');
            }
        }
    }
}
