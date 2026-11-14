<?php

declare(strict_types=1);

namespace App\Modules\Imports\Contracts;

use App\Core\Drivers\Driver;
use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Imports\Staging\Table;
use App\Modules\Imports\Survey\Recognition;
use App\Modules\Imports\Survey\Survey;

/**
 * Another bot's data, read into this shop («انتقال از ربات دیگر»): a migrator knows one bot's database and translates it
 * into the import's own records — it writes nothing. Its data arrives as the core reads it (a backup file of that bot,
 * or a connection to its database), staged once inside this shop: only the tables and columns tables() names, their
 * secrets encrypted. The core then asks recognize() whether it is that bot's at all, survey() what it holds for the
 * owner's review, and the generators for each kind of record, which it writes itself (Importer) — once, however many
 * times an import is run.
 *
 * Each generator yields its records under keys strictly increasing, and starts after `$after`: the import stops when its
 * time is up and goes on later from the last key it wrote. A key is the staged row the record comes from (Staged's
 * `seq`), or — for a small table read whole and put in an order of its own (plans by the other bot's own order) — the
 * record's place in that order, counted from 1: the staged copy never changes, so either resumes alike. A row it cannot
 * read is passed over, or yielded with what it has — never a reason to stop.
 */
interface Migrator extends Driver
{
    /** The two letters its card in the picker is drawn with (a trait of describe()). */
    public const MARK = 'mark';

    /** The bot's project page (a trait of describe()). */
    public const VENDOR = 'vendor';

    /**
     * The tables it reads, by name, each with the columns it reads and those that are secrets; nothing else is staged.
     *
     * @return array<string, Table>
     */
    public function tables(): array;

    /** Whether a database is this bot's, from its tables and columns alone — and which version, when it can tell. */
    public function recognize(Schema $schema): Recognition;

    /** What the staged copy holds, for the owner to review and decide on before anything is written. */
    public function survey(Staged $staged): Survey;

    /** @return iterable<int, ImportedCustomer> */
    public function customers(Staged $staged, int $after = 0): iterable;

    /** @return iterable<int, ImportedPlan> */
    public function plans(Staged $staged, int $after = 0): iterable;

    /** @return iterable<int, ImportedService> */
    public function services(Staged $staged, int $after = 0): iterable;

    /** @return iterable<int, ImportedDiscountCode> */
    public function discountCodes(Staged $staged, int $after = 0): iterable;

    /** @return iterable<int, ImportedGiftCode> */
    public function giftCodes(Staged $staged, int $after = 0): iterable;

    /** @return iterable<int, ImportedChannel> */
    public function channels(Staged $staged, int $after = 0): iterable;

    /** @return iterable<int, ImportedCard> */
    public function cards(Staged $staged, int $after = 0): iterable;
}
