<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\MirzaBot;

use App\Modules\Imports\Budget;
use App\Modules\Imports\Migrators\MirzaBot\MirzaBotMigrator;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Orders\Enums\OrderType;
use Tests\Unit\Imports\Sources\StagingTestCase;

/**
 * One MirzaBot shop in both forms its backup arrives in, through the real reading and the migrator: the backup_<date>.sql
 * MirzaBot 0.9.3's own PDO writer sends (lowercase tables, multi-row INSERTs with column lists, every value quoted, MariaDB's
 * CREATE TABLE) and a mysqldump of an old 5.x MirzaBot (Payment_report, DiscountSell, Discount, Giftcodeconsumed in their
 * old capitals, INSERTs without column lists, MySQL 8's CREATE TABLE). Each table is found under MirzaBot's name whatever
 * its case, and the shop reads alike — but for what only 0.9.3 keeps: its own order of products and «ناموجود».
 */
final class BackupFormatsTest extends StagingTestCase
{
    public function testBothFormsFindMirzaBotsTablesWhateverTheirCase(): void
    {
        [$new] = $this->read('backup-php.sql');
        [$old] = $this->read('backup-mysqldump.sql');

        $read = ['user', 'invoice', 'marzban_panel', 'product', 'admin', 'discountsell', 'discount', 'channels', 'card_number', 'botsaz', 'payment_report', 'support_message', 'service_other', 'giftcodeconsumed'];
        foreach ($read as $table) {
            self::assertTrue($new->has($table), "0.9.3: {$table}");
            self::assertTrue($old->has($table), "5.x: {$table}");
        }
        self::assertSame(['codeDiscount', 'price', 'limitDiscount'], array_slice($old->columns('discountsell'), 1, 3), 'its columns too');
        self::assertSame([], array_values(array_intersect(['Payment_report', 'DiscountSell', 'Discount', 'Giftcodeconsumed'], array_keys($old->tables()))), 'under the names MirzaBot gives them since 0.9.3');
        self::assertTrue($old->has('PaySetting') && $old->has('shopSetting') && $old->has('topicid'), 'a table it does not read keeps its own name');
        self::assertTrue($new->has('config') && !$new->has('setting'), '0.9.3 keeps its settings in `config`: a bot later than 0.1.9');
        self::assertTrue(!$old->has('config') && $old->has('setting') && !$old->hasColumns('setting', 'text_edit'), 'the old one neither `config` nor the edited texts of 0.2.x: a bot before 0.1.9');
        self::assertTrue($new->hasColumns('marzban_panel', 'version_panel', 'display_order') && !$old->hasColumns('marzban_panel', 'version_panel'), 'the 5.x line had no «نسخه پنل»');
    }

    public function testBothFormsReadAsTheSameShop(): void
    {
        [, $new] = $this->read('backup-php.sql');
        [, $old] = $this->read('backup-mysqldump.sql');

        self::assertCount(8, $new['customers'], 'the fixture is read whole');
        foreach (['customers', 'gift_codes', 'channels', 'cards'] as $kind) {
            self::assertSame($new[$kind], $old[$kind], $kind);
        }
        // The 0.9.3 shop's one service more: on its Rebecca panel, which MirzaBot could not sell on before mid-2026.
        self::assertSame(array_values(array_intersect($new['services'], $old['services'])), $old['services'], 'the services both have, alike');
        $rebecca = array_values(array_diff($new['services'], $old['services']));
        self::assertCount(1, $rebecca);
        $service = unserialize($rebecca[0]);
        self::assertInstanceOf(ImportedService::class, $service);
        self::assertSame(['Rebecca-TR', 'rz_tr', 'a1b2'], [$service->panelKey, $service->remoteName, $service->planKey]);
        // The old shop's one code more: made before codes had an audience (`agent` NULL), which MirzaBot's lookup never matched.
        $oldOnly = array_values(array_diff($old['discount_codes'], $new['discount_codes']));
        self::assertSame(array_values(array_intersect($old['discount_codes'], $new['discount_codes'])), $new['discount_codes'], 'the codes both have, alike');
        self::assertCount(1, $oldOnly);
        $code = unserialize($oldOnly[0]);
        self::assertInstanceOf(ImportedDiscountCode::class, $code);
        self::assertSame(['OLDCODE', false, 'در ربات قبلی برای هیچ خریدی پذیرفته نمی‌شد.', [OrderType::Purchase, OrderType::Renewal], 8], [$code->code, $code->enabled, $code->note, $code->orderTypes, $code->usesLeft], 'off, as MirzaBot took it for nothing; held to the kinds of order it takes codes for');
        self::assertSame(self::alike($new['plans']), self::alike($old['plans']), 'the same plans');
        self::assertSame(['a1b2', 'e5f6', 'g7h8', 'c3d4'], array_keys($new['plans']), "0.9.3's own order (display_order)");
        self::assertSame(['a1b2', 'c3d4', 'e5f6', 'g7h8'], array_keys($old['plans']), "5.x's: by id");
        self::assertSame(['a1b2' => true, 'e5f6' => true, 'g7h8' => true, 'c3d4' => false], array_map(static fn(ImportedPlan $plan): bool => $plan->selling, $new['plans']), 'sold out («ناموجود», status_product) since 0.9.3');
        self::assertSame([true, true, true, true], array_values(array_map(static fn(ImportedPlan $plan): bool => $plan->selling, $old['plans'])), 'nothing was sold out before');
    }

    /**
     * What the migrator makes of a backup: the schema it recognises, and every kind of record (plans by their keys, the
     * rest as they come serialized).
     *
     * @return array{Schema, array<string, array<array-key, mixed>>}
     */
    private function read(string $file): array
    {
        $migrator = new MirzaBotMigrator();
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload((string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/imports/mirzabot/' . $file), 'backup.sql'));
        self::assertTrue($this->stager()->stage($import, $migrator, Budget::unlimited()));
        $schema = $this->stager()->schema($import);
        self::assertTrue($migrator->recognize($schema)->ok, $file);
        $staged = $this->stager()->staged($import, $migrator);

        $read = ['plans' => []];
        foreach ($migrator->plans($staged) as $plan) {
            $read['plans'][$plan->key] = $plan;
        }
        foreach (['customers' => $migrator->customers($staged), 'services' => $migrator->services($staged), 'discount_codes' => $migrator->discountCodes($staged), 'gift_codes' => $migrator->giftCodes($staged), 'channels' => $migrator->channels($staged), 'cards' => $migrator->cards($staged)] as $kind => $yielded) {
            $read[$kind] = array_map(serialize(...), array_values(iterator_to_array($yielded)));
        }

        return [$schema, $read];
    }

    /**
     * Plans by their keys, as both MirzaBots keep them: without whether it still sold them, nor the Rebecca panel only the
     * 0.9.3 shop sells on.
     *
     * @param array<array-key, mixed> $plans
     * @return array<string, array<string, mixed>>
     */
    private static function alike(array $plans): array
    {
        $alike = [];
        foreach ($plans as $key => $plan) {
            self::assertInstanceOf(ImportedPlan::class, $plan);
            $alike[(string) $key] = array_diff_key(get_object_vars($plan), ['selling' => true]);
            $alike[(string) $key]['panelKeys'] = $plan->panelKeys === null ? null : array_values(array_diff($plan->panelKeys, ['Rebecca-TR']));
        }
        ksort($alike);

        return $alike;
    }
}
