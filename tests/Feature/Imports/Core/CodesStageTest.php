<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Discounts\Enums\DiscountType;
use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Writers\GiftCodeWriter;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Models\GiftCode;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeMigrator;

/**
 * The codes' stages: a discount code made through the codes' form with the uses it has left, the plans and servers it was
 * for — switched off when all of them stayed behind —, and what does not come (used up, ended, taken); a gift code with
 * one redemption left made one of the shop's wallet codes, and what does not (used up, past, off, more than one use left,
 * a shape or amount the shop's codes cannot be, taken).
 */
final class CodesStageTest extends ImportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 12:00:00');
    }

    public function testADiscountCodeComesWithTheUsesItHasLeftAndWhatItWasFor(): void
    {
        $this->fakePanel();
        $de = $this->fakeServer('آلمان');
        $this->migrator->panels = [self::fakePanelOf('p-de')];
        $this->migrator->plans = [new ImportedPlan('pl', 'یک‌ماهه', null, '100000', null, '30', 30)];
        $this->migrator->discountCodes = [new ImportedDiscountCode('d1', 'yalda-1404', 'percent', '20', usesLeft: 7, perUser: 1, firstPurchaseOnly: true, endsAt: new \DateTimeImmutable('2026-12-21 20:30:00'), orderTypes: [OrderType::Purchase, OrderType::WalletTopUp], planKeys: ['pl'], panelKeys: ['p-de'], note: 'یلدا')];

        $import = $this->importAll(['panels' => ['p-de' => ['action' => Choices::SERVER, 'server_id' => $de->id]]]);

        $code = DiscountCode::query()->sole();
        self::assertSame(['YALDA-1404', DiscountType::Percent, '20.00', 7, 1, true, true, 'یلدا'], [$code->code, $code->type, $code->value, $code->max_uses, $code->per_user, $code->first_purchase_only, $code->enabled, $code->note]);
        self::assertSame('2026-12-21 20:30:00', $code->ends_at?->format('Y-m-d H:i:s'));
        self::assertSame([OrderType::Purchase->value], $code->order_types, 'a top-up is nothing a code takes off here');
        self::assertSame([$de->id], $code->server_ids);
        self::assertCount(1, (array) $code->plan_ids);
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($import)['discount_codes']);
    }

    public function testADiscountCodeWhoseScopeStayedBehindComesSwitchedOff(): void
    {
        $this->migrator->panels = [self::fakePanelOf('p-de')];
        $this->migrator->discountCodes = [
            new ImportedDiscountCode('d1', 'PANEL', 'fixed', '15000', panelKeys: ['p-de']),
            new ImportedDiscountCode('d2', 'PLANS', 'percent', '10', planKeys: ['nothing']),
            new ImportedDiscountCode('d3', 'TOPUP', 'percent', '10', orderTypes: [OrderType::WalletTopUp]),
        ];

        $import = $this->importAll();

        foreach (DiscountCode::query()->orderBy('id')->get() as $code) {
            self::assertFalse($code->enabled, "{$code->code} never holds for everything instead");
            self::assertSame([null, null, null], [$code->plan_ids, $code->server_ids, $code->order_types]);
        }
        self::assertSame(['scope_lost', 'scope_lost', 'scope_lost'], array_column(self::problemRows($import), 'reason'));
        self::assertSame('15000.00', DiscountCode::query()->where('code', 'PANEL')->value('value'));
    }

    public function testDiscountCodesUsedUpEndedOrTakenStayBehind(): void
    {
        $this->discountCode('MINE');
        $this->migrator->discountCodes = [
            new ImportedDiscountCode('d1', 'USED', 'percent', '10', usesLeft: 0),
            new ImportedDiscountCode('d2', 'OLD', 'percent', '10', endsAt: new \DateTimeImmutable('2026-01-01')),
            new ImportedDiscountCode('d3', 'mine', 'percent', '10'),
            new ImportedDiscountCode('d4', 'X', 'percent', '10'),
            new ImportedDiscountCode('d5', 'FREE', 'free', '0', enabled: false),
        ];

        $import = $this->importAll();

        self::assertSame(['MINE', 'FREE'], DiscountCode::query()->orderBy('id')->pluck('code')->all());
        self::assertFalse(DiscountCode::query()->where('code', 'FREE')->value('enabled'), 'off as it was');
        self::assertSame([['d3', 'code_taken'], ['d4', 'refused']], array_map(static fn(array $row): array => [$row['key'], $row['reason']], self::problemRows($import)));
        self::assertSame(['used_up' => 1, 'expired' => 1, 'code_taken' => 1, 'refused' => 1], array_column($this->imports()->present($import)['problems']['by_reason'], 'count', 'reason'));
    }

    public function testAGiftCodeWithOneUseLeftBecomesOneOfTheShopsWalletCodes(): void
    {
        $this->migrator->giftCodes = [new ImportedGiftCode('g1', 'gift-2025', '50000', usesLeft: 1, expiresAt: new \DateTimeImmutable('2027-01-01'))];

        $import = $this->importAll();

        $gift = GiftCode::query()->sole();
        self::assertSame(['GIFT2025', GiftCodeKind::Wallet, '50000.00', sprintf(GiftCodeWriter::BATCH, FakeMigrator::LABEL), '2027-01-01'], [$gift->code, $gift->kind, $gift->amount, $gift->batch, $gift->expires_at?->format('Y-m-d')]);
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($import)['gift_codes']);

        $again = $this->importAll();
        self::assertSame(1, GiftCode::query()->count());
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($again)['gift_codes']);
    }

    public function testGiftCodesTheShopCannotKeepStayBehindAndAreNamed(): void
    {
        $this->giftCode('TAKEN1');
        $this->migrator->giftCodes = [
            new ImportedGiftCode('g1', 'USED', '50000', usesLeft: 0),
            new ImportedGiftCode('g2', 'PAST', '50000', expiresAt: new \DateTimeImmutable('2026-01-01')),
            new ImportedGiftCode('g3', 'OFF', '50000', enabled: false),
            new ImportedGiftCode('g4', 'MANY', '50000', usesLeft: 12),
            new ImportedGiftCode('g5', 'ENDLESS', '50000', usesLeft: null),
            new ImportedGiftCode('g6', 'کد', '50000'),
            new ImportedGiftCode('g7', 'SMALL', '500'),
            new ImportedGiftCode('g8', 'taken1', '50000'),
        ];

        $import = $this->importAll();

        self::assertSame(['TAKEN1'], GiftCode::query()->pluck('code')->all());
        self::assertSame([
            ['g4', 'multi_use_gift', "\u{2068}MANY\u{2069} — ۱۲ بار مانده"],
            ['g5', 'multi_use_gift', "\u{2068}ENDLESS\u{2069} — بدون محدودیت"],
            ['g6', 'refused'],
            ['g7', 'refused'],
            ['g8', 'code_taken', "\u{2068}TAKEN1\u{2069}"],
        ], array_map(static fn(array $row): array => in_array($row['reason'], ['refused'], true) ? [$row['key'], $row['reason']] : [$row['key'], $row['reason'], $row['detail']], self::problemRows($import)));
        self::assertSame(['used_up' => 1, 'expired' => 1, 'ended' => 1], array_intersect_key(array_column($this->imports()->present($import)['problems']['by_reason'], 'count', 'reason'), ['used_up' => 1, 'expired' => 1, 'ended' => 1]));
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 8], self::counts($import)['gift_codes']);
    }

    public function testAGiftCodeKeptForOneCustomerStaysBehindNamingWhoseItWas(): void
    {
        $this->migrator->customers = [new ImportedCustomer('c1', 1001)];
        $this->migrator->giftCodes = [
            new ImportedGiftCode('g1', 'FORALI', '50000', forCustomer: 'c1'),
            new ImportedGiftCode('g2', 'FORGONE', '20000', usesLeft: 3, forCustomer: 'nobody'),
            new ImportedGiftCode('g3', 'OLDONE', '20000', usesLeft: 0, forCustomer: 'c1'),
        ];

        $import = $this->importAll();

        self::assertSame(0, GiftCode::query()->count());
        self::assertSame([
            ['g1', 'one_customer', "\u{2068}FORALI\u{2069} — ۵۰٬۰۰۰ تومان — برای مشتری با شناسه تلگرام \u{2068}1001\u{2069}"],
            ['g2', 'one_customer', "\u{2068}FORGONE\u{2069} — ۲۰٬۰۰۰ تومان — برای مشتری \u{2068}nobody\u{2069} ربات قبلی"],
        ], array_map(static fn(array $row): array => [$row['key'], $row['reason'], $row['detail']], self::problemRows($import)), 'before more than one use is said; after a code used up');
        self::assertSame(['used_up' => 1, 'one_customer' => 2], array_column($this->imports()->present($import)['problems']['by_reason'], 'count', 'reason'));
    }

    public function testCodesTheOwnerDeletedSinceAreNeverBroughtBack(): void
    {
        $this->migrator->discountCodes = [new ImportedDiscountCode('YALDA', 'YALDA', 'percent', '20')];
        $this->migrator->giftCodes = [new ImportedGiftCode('GIFT-1', 'GIFT-1', '50000')];
        $this->importAll();
        DiscountCode::query()->delete();
        GiftCode::query()->delete();

        $again = $this->importAll();

        self::assertSame([0, 0], [DiscountCode::query()->count(), GiftCode::query()->count()], 'a gift code deleted is never redeemable again');
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 1], self::counts($again)['discount_codes']);
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 1], self::counts($again)['gift_codes']);
    }

    public function testWithoutCodesNoneComes(): void
    {
        $this->migrator->discountCodes = [new ImportedDiscountCode('d1', 'CODE', 'percent', '10')];
        $this->migrator->giftCodes = [new ImportedGiftCode('g1', 'GIFT', '50000')];

        $this->importAll(['discount_codes' => false, 'gift_codes' => false]);

        self::assertSame([0, 0], [DiscountCode::query()->count(), GiftCode::query()->count()]);
    }
}
