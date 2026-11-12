<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Modules\Backups\BackupReader;
use App\Modules\Backups\BackupScope;
use App\Modules\Backups\BackupWriter;
use App\Modules\Backups\ForeignKeys;
use App\Modules\Backups\Restorer;
use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Models\User;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\DatabaseTestCase;

/**
 * A restore as a shop runs it — outside any transaction of a test's, so its foreign keys go as they do in production
 * (ForeignKeys::without(): SQLite's switched off for the work, no key's ON DELETE rule applied) — in a process of its
 * own, whose database goes with it: what it commits reaches no other test. Every key holds after it.
 */
final class RestoreCommittedTest extends DatabaseTestCase
{
    #[RunInSeparateProcess]
    public function testAShopsRestoreOutsideAnyTransactionKeepsEveryKeyAndTheOwnersLines(): void
    {
        $this->db()->rollBack();
        try {
            $berlin = $this->sellingServer('Berlin');
            $shop = $this->agentBot();
            [$kept, $gone] = CurrentBot::run($shop, function () use ($berlin): array {
                $plan = $this->plan(['name' => 'نماینده'], $berlin);
                $reza = $this->customer(['telegram_id' => 601, 'first_name' => 'رضا']);

                return [$this->purchaseOrder($reza, $plan, $berlin), $this->purchaseOrder($reza, $plan, $berlin)];
            });
            $ledger = fn(int $order): int => $this->db()->table('traffic_transactions')->insertGetId(['bot_id' => $shop->id, 'type' => 'debit', 'bytes' => 1, 'balance_after' => 0, 'description' => 'x', 'order_id' => $order, 'created_at' => now()]);
            $keptLine = $ledger($kept->id);
            $path = $this->scratchDir() . '/committed.json.gz';
            $this->service(BackupWriter::class)->write(BackupScope::shop($shop->id), $path);
            // Since the backup: an order the file does not hold, on the owner's ledger too; a customer gone.
            $later = CurrentBot::run($shop, fn() => $this->purchaseOrder(User::query()->firstOrFail(), $this->plan(['name' => 'دیگر'], $berlin), $berlin));
            $laterLine = $ledger($later->id);
            $goneLine = $ledger($gone->id);
            self::assertFalse(ForeignKeys::deleteActs($this->db()), 'no transaction: the production path');

            $contents = $this->service(BackupReader::class)->read($path);
            $restorer = $this->service(Restorer::class);
            $restorer->restore($path, $contents, $restorer->analyse($path, $contents));

            $orderOf = fn(int $line): ?string => ($id = $this->db()->table('traffic_transactions')->where('id', $line)->value('order_id')) === null ? null : (string) $id;
            self::assertSame([(string) $kept->id, (string) $gone->id, null], [$orderOf($keptLine), $orderOf($goneLine), $orderOf($laterLine)], 'a line naming an order the file brought back names it still; the one since names none');
            self::assertSame(2, $this->db()->table('orders')->where('bot_id', $shop->id)->count());
            self::assertSame([], $this->db()->select('PRAGMA foreign_key_check'), 'every key holds');
            self::assertSame('1', (string) $this->db()->selectOne('PRAGMA foreign_keys')->foreign_keys, 'the keys are checked again');
        } finally {
            $this->db()->beginTransaction();
        }
    }

    /**
     * A shop with more orders on the owner's ledger than one statement may bind values (SQLite's 32,766; MySQL's 65,535)
     * restores as a small one does: what the restore keeps of the owner's is put back in statements over sets of rows.
     */
    #[RunInSeparateProcess]
    public function testAShopWithMoreOrdersOnTheLedgerThanAStatementBindsIsRestored(): void
    {
        $this->db()->rollBack();
        try {
            $berlin = $this->sellingServer('Berlin');
            $shop = $this->agentBot();
            $order = CurrentBot::run($shop, function () use ($berlin) {
                $reza = $this->customer(['telegram_id' => 601]);

                return $this->purchaseOrder($reza, $this->plan(['name' => 'نماینده'], $berlin), $berlin);
            });
            // The order copied under the next ids, each on the ledger: one statement each, made by the database.
            $more = 33000;
            $columns = implode(', ', array_map(static fn(string $column): string => '"' . $column . '"', array_diff($this->db()->getSchemaBuilder()->getColumnListing('orders'), ['id'])));
            $this->db()->statement('insert into ' . self::sqlTable('orders') . " (\"id\", {$columns}) with recursive n(i) as (select 1 union all select i + 1 from n where i < ?) select o.\"id\" + n.i, {$columns} from " . self::sqlTable('orders') . ' o, n where o."id" = ?', [$more, $order->id]);
            $this->db()->statement('insert into ' . self::sqlTable('traffic_transactions') . ' ("bot_id", "type", "bytes", "balance_after", "description", "order_id", "created_at") select "bot_id", \'debit\', 1, 0, \'x\', "id", "created_at" from ' . self::sqlTable('orders') . ' where "bot_id" = ?', [$shop->id]);
            $path = $this->scratchDir() . '/big.json.gz';
            $this->service(BackupWriter::class)->write(BackupScope::shop($shop->id), $path);

            $contents = $this->service(BackupReader::class)->read($path);
            $restorer = $this->service(Restorer::class);
            $restored = $restorer->restore($path, $contents, $restorer->analyse($path, $contents));

            self::assertSame($more + 1, $restored['orders']);
            self::assertSame($more + 1, $this->db()->table('traffic_transactions')->where('bot_id', $shop->id)->whereNotNull('order_id')->count(), 'every line names its order still');
            self::assertSame([], $this->db()->select('PRAGMA foreign_key_check'));
        } finally {
            $this->db()->beginTransaction();
        }
    }
}
