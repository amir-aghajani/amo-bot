<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Rewards\Services\GiftCodes;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The gift codes screen: making a batch (every refusal at once), the list by batch, status and search, the batches'
 * counts, a batch's unused codes, deleting untaken codes, and the `gift_codes` switch. Each shop its own.
 */
final class AdminGiftCodesApiTest extends HttpTestCase
{
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->loginAsAdmin();
        $this->ali = $this->customer(['username' => 'ali', 'first_name' => 'Ali']);
    }

    public function testABatchIsMadeAndItsCodesAnsweredToCopy(): void
    {
        $response = $this->postJson('/api/admin/gift-codes', ['count' => '۳', 'kind' => 'wallet', 'amount' => '50,000', 'batch' => 'نوروز', 'prefix' => 'NY', 'expires_on' => '2026-10-31']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $made = $this->decode($response);
        self::assertSame('نوروز', $made['batch']);
        self::assertCount(3, $made['codes']);
        self::assertSame($made['codes'], GiftCode::query()->orderBy('id')->pluck('code')->all());
        self::assertStringStartsWith('NY', $made['codes'][0]);

        $refused = $this->postJson('/api/admin/gift-codes', ['count' => 0, 'kind' => 'plan', 'batch' => '']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['count', 'plan_id', 'batch'], array_keys($this->decode($refused)['errors']), 'every refusal at once');
    }

    public function testTheListIsEveryCodeNewestFirstByBatchStateAndSearch(): void
    {
        $server = $this->sellingServer();
        $plan = $this->plan(['name' => 'یک‌ماهه'], $server);
        $old = $this->giftCode('OLDCODE2345', ['expires_at' => now()->subDay()]);
        $taken = $this->giftCode('TAKENCODE234', ['redeemed_by' => $this->ali->id, 'redeemed_at' => now()]);
        $planCode = $this->giftCode('PLANCODE2345', ['batch' => 'یلدا', 'kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $plan->id]);
        $list = fn(string $query = ''): array => $this->decode($this->get('/api/admin/gift-codes' . ($query === '' ? '' : "?{$query}")));
        $ids = static fn(array $page): array => array_column($page['codes'], 'id');

        $all = $list();
        self::assertSame([$planCode->id, $taken->id, $old->id], $ids($all));
        self::assertSame([
            'id' => $taken->id,
            'code' => 'TAKENCODE234',
            'batch' => 'نوروز',
            'kind' => 'wallet',
            'amount' => '50000.00',
            'plan' => null,
            'expires_at' => null,
            'status' => 'redeemed',
            'redeemed_by' => ['id' => $this->ali->id, 'name' => 'Ali', 'username' => 'ali', 'telegram_id' => self::TELEGRAM_ID, 'email' => null],
            'redeemed_at' => '2026-10-08T12:00:00+00:00',
            'order' => null,
            'created_at' => '2026-10-08T12:00:00+00:00',
        ], $all['codes'][1]);
        self::assertSame(['plan', ['id' => $plan->id, 'name' => 'یک‌ماهه'], 'unused'], [$all['codes'][0]['kind'], $all['codes'][0]['plan'], $all['codes'][0]['status']]);
        self::assertSame('expired', $all['codes'][2]['status']);

        self::assertSame([$planCode->id], $ids($list('status=unused')));
        self::assertSame([$taken->id], $ids($list('status=redeemed')));
        self::assertSame([$old->id], $ids($list('status=expired')));
        self::assertSame([$planCode->id], $ids($list('batch=' . rawurlencode('یلدا'))));
        self::assertSame([$taken->id], $ids($list('search=ali')), 'by who took it');
        self::assertSame([$old->id], $ids($list('search=old-code')), 'by a piece of the code, typed as a person types it');
        self::assertSame([$old->id], $ids($list('search=' . rawurlencode("#{$old->id}"))), 'the code alone');
    }

    public function testAPlanCodesOrderIsListedWithIt(): void
    {
        $this->giftCodesOn();
        $this->withoutQr();
        $server = $this->sellingServer();
        $plan = $this->plan([], $server);
        $this->giftCode('PLANCODE2345', ['kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $plan->id]);
        $redeemed = $this->service(GiftCodes::class)->redeem($this->ali, 'PLANCODE2345');

        $row = $this->decode($this->get('/api/admin/gift-codes'))['codes'][0];

        self::assertSame(['id' => $redeemed->given->order?->id, 'status' => OrderStatus::Fulfilled->value], $row['order']);
    }

    public function testTheBatchesAreCountedAndAnUnusedBatchCopied(): void
    {
        $this->giftCode('AAAAAAAA22', ['expires_at' => now()->subDay()]);
        $this->giftCode('BBBBBBBB22', ['redeemed_by' => $this->ali->id, 'redeemed_at' => now()]);
        $this->giftCode('CCCCCCCC22');
        $this->giftCode('DDDDDDDD22');
        $this->giftCode('EEEEEEEE22', ['batch' => 'یلدا']);

        self::assertSame([
            ['batch' => 'یلدا', 'total' => 1, 'redeemed' => 0, 'expired' => 0, 'unused' => 1, 'created_at' => '2026-10-08T12:00:00+00:00'],
            ['batch' => 'نوروز', 'total' => 4, 'redeemed' => 1, 'expired' => 1, 'unused' => 2, 'created_at' => '2026-10-08T12:00:00+00:00'],
        ], $this->decode($this->get('/api/admin/gift-codes/batches'))['batches']);

        self::assertSame(['codes' => ['CCCCCCCC22', 'DDDDDDDD22']], $this->decode($this->get('/api/admin/gift-codes/unused?batch=' . rawurlencode('نوروز'))));
    }

    public function testOnlyCodesNobodyTookAreDeleted(): void
    {
        $taken = $this->giftCode('TAKENCODE234', ['redeemed_by' => $this->ali->id, 'redeemed_at' => now()]);
        $free = $this->giftCode('FREECODE2345');
        $this->giftCode('MORECODE2345');

        $refused = $this->deleteJson("/api/admin/gift-codes/{$taken->id}");
        self::assertSame(422, $refused->getStatusCode());
        self::assertArrayHasKey('status', $this->decode($refused)['errors']);

        self::assertSame(204, $this->deleteJson("/api/admin/gift-codes/{$free->id}")->getStatusCode());
        self::assertSame(['deleted' => 1], $this->decode($this->postJson('/api/admin/gift-codes/discard', ['batch' => 'نوروز'])));
        self::assertSame(['TAKENCODE234'], GiftCode::query()->pluck('code')->all());

        $noBatch = $this->postJson('/api/admin/gift-codes/discard', ['batch' => ' ']);
        self::assertSame(422, $noBatch->getStatusCode());
        self::assertArrayHasKey('batch', $this->decode($noBatch)['errors']);
    }

    public function testEachShopHasItsOwnCodes(): void
    {
        $this->giftCode('MAINCODE2345');
        $bot = $this->agentBot();
        CurrentBot::run($bot, fn() => $this->giftCode('AGENTCODE234'));
        $this->loginAsAgent($bot);

        self::assertSame(['AGENTCODE234'], array_column($this->decode($this->get('/api/agent/gift-codes'))['codes'], 'code'));
        $made = $this->decode($this->postJson('/api/agent/gift-codes', ['count' => 2, 'kind' => 'wallet', 'amount' => 10000, 'batch' => 'ویژه']));
        self::assertSame(2, CurrentBot::run($bot, static fn(): int => GiftCode::query()->where('batch', 'ویژه')->count()));
        self::assertCount(2, $made['codes']);
        self::assertSame(1, GiftCode::query()->count(), 'the main shop has its own');
    }

    public function testTheSwitchIsAGroupOfTheBotSettings(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/gift_codes', ['gift_codes_enabled' => true]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($this->decode($response)['settings']['gift_codes_enabled']);
    }
}
