<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Discounts\Enums\DiscountSource;
use App\Modules\Discounts\Exceptions\DiscountInUseException;
use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The discounts screens: codes (paged with their uses, saved whole with every refusal at once, switched, deleted while
 * unused), sales, a customer's own percent, the codes' hold setting, and an order's discount lines. Each shop its own.
 */
final class AdminDiscountsApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        // The shop's clock: a window is typed as its day and time.
        LocalTime::use('Asia/Tehran');
        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testACodeIsMadeWithEveryFieldAndListedWithItsUses(): void
    {
        $server = $this->fakeServer('آلمان');
        $plan = $this->plan(['name' => 'یک‌ماهه'], $server);

        $made = $this->postJson('/api/admin/discount-codes', [
            'code' => ' nowruz۱۴۰۵ ',
            'type' => 'percent',
            'value' => '۲۰',
            'max_discount' => '50,000',
            'min_amount' => '100000',
            'order_types' => ['purchase', 'renewal'],
            'plan_ids' => [$plan->id],
            'server_ids' => [$server->id],
            'first_purchase_only' => true,
            'starts_at' => '2026-10-08T12:00',
            'ends_at' => '2026-10-10T18:00',
            'max_uses' => '100',
            'per_user' => 1,
            'note' => 'اینستاگرام',
            'enabled' => true,
        ]);

        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        $code = $this->decode($made)['code'];
        self::assertSame([
            'code' => 'NOWRUZ1405',
            'note' => 'اینستاگرام',
            'type' => 'percent',
            'value' => '20.00',
            'max_discount' => '50000.00',
            'min_amount' => '100000.00',
            'order_types' => ['purchase', 'renewal'],
            'plans' => [['id' => $plan->id, 'name' => 'یک‌ماهه']],
            'servers' => [['id' => $server->id, 'name' => 'آلمان']],
            'first_purchase_only' => true,
            'starts_at' => '2026-10-08T08:30:00+00:00',
            'ends_at' => '2026-10-10T14:30:00+00:00',
            'max_uses' => 100,
            'per_user' => 1,
            'enabled' => true,
            'uses' => 0,
            'deletable' => true,
            'state' => 'active',
        ], array_diff_key($code, ['id' => 0, 'created_at' => 0]), 'kept in upper case, the window read in the shop\'s zone');

        $order = $this->purchaseOrder($this->customer(), $plan, $server, ['discount_code_id' => $code['id']]);
        $this->purchaseOrder($this->customer(['telegram_id' => 7001]), $plan, $server, ['discount_code_id' => $code['id'], 'status' => OrderStatus::Cancelled]);
        $list = $this->decode($this->get('/api/admin/discount-codes'));
        self::assertSame([1, 1, false], [$list['meta']['total'], $list['codes'][0]['uses'], $list['codes'][0]['deletable']], 'an order cancelled is no use; the other keeps it, which the list says up front');

        self::assertSame(1, $this->decode($this->get('/api/admin/discount-codes?search=' . rawurlencode('اینستا')))['meta']['total'], 'searched by its note');
        self::assertSame(1, $this->decode($this->get('/api/admin/discount-codes?search=nowruz'))['meta']['total'], 'and its code, in any case');
        self::assertSame(0, $this->decode($this->get('/api/admin/discount-codes?search=yalda'))['meta']['total']);

        self::assertSame(409, $this->deleteJson("/api/admin/discount-codes/{$code['id']}")->getStatusCode(), 'a code with a use stays');
        $off = $this->decode($this->patchJson("/api/admin/discount-codes/{$code['id']}", ['enabled' => false]))['code'];
        self::assertSame([false, 'off', false], [$off['enabled'], $off['state'], $off['deletable']]);

        $order->forceFill(['status' => OrderStatus::Cancelled])->save();
        self::assertTrue($this->decode($this->get('/api/admin/discount-codes'))['codes'][0]['deletable'], 'only cancelled orders name it');
        self::assertSame(204, $this->deleteJson("/api/admin/discount-codes/{$code['id']}")->getStatusCode(), 'nothing uses it now');
        self::assertNull($order->refresh()->discount_code_id);
    }

    public function testACodeOnlyRefundedOrdersNamedCountsNoUseButIsKeptAndTheListSaysSo(): void
    {
        $server = $this->sellingServer();
        $plan = $this->plan([], $server);
        $refunded = $this->discountCode('REFUNDED', 10);
        $this->purchaseOrder($this->customer(), $plan, $server, ['discount_code_id' => $refunded->id, 'status' => OrderStatus::Refunded]);
        $unused = $this->discountCode('UNUSED', 10);

        $rows = array_column($this->decode($this->get('/api/admin/discount-codes'))['codes'], null, 'code');

        self::assertSame([0, false], [$rows['REFUNDED']['uses'], $rows['REFUNDED']['deletable']], 'no use, but its refunded order keeps it');
        self::assertSame([0, true], [$rows['UNUSED']['uses'], $rows['UNUSED']['deletable']]);
        $refused = $this->deleteJson("/api/admin/discount-codes/{$refunded->id}");
        self::assertSame([409, DiscountInUseException::REFUNDED], [$refused->getStatusCode(), $this->decode($refused)['message']], 'as the delete answers');
        self::assertSame(204, $this->deleteJson("/api/admin/discount-codes/{$unused->id}")->getStatusCode());
    }

    public function testEveryRefusalOfTheFormComesAtOnce(): void
    {
        $this->discountCode('TAKEN');

        $refused = $this->unchecked()->postJson('/api/admin/discount-codes', [
            'code' => 'taken',
            'type' => 'percent',
            'value' => 120,
            'min_amount' => '1.5',
            'order_types' => ['wallet_topup'],
            'plan_ids' => [999],
            'first_purchase_only' => 'maybe',
            'starts_at' => '2026-10-10T12:00',
            'ends_at' => '2026-10-09T12:00',
            'max_uses' => 0,
            'enabled' => true,
        ]);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['code', 'value', 'min_amount', 'order_types', 'plan_ids', 'first_purchase_only', 'ends_at', 'max_uses'], array_keys($this->decode($refused)['errors']));
        self::assertSame(1, DiscountCode::query()->count());

        $fixed = $this->postJson('/api/admin/discount-codes', ['code' => 'FIX', 'type' => 'fixed', 'value' => null, 'first_purchase_only' => false, 'enabled' => true]);
        self::assertSame(['value'], array_keys($this->decode($fixed)['errors']), "a fixed code's Toman");
        $free = $this->decode($this->postJson('/api/admin/discount-codes', ['code' => 'FREE', 'type' => 'free', 'first_purchase_only' => false, 'enabled' => true, 'order_types' => ['purchase', 'renewal', 'extra_traffic', 'extra_time', 'extra_devices']]))['code'];
        self::assertSame([null, null], [$free['value'], $free['order_types']], 'every kind named is every kind');
    }

    public function testACodesAndASalesStateFollowTheSwitchTheWindowAndTheUses(): void
    {
        $plan = $this->plan();
        $ali = $this->customer();
        $codes = [
            'active' => $this->discountCode('ACTIVE'),
            'off' => $this->discountCode('OFF', overrides: ['enabled' => false]),
            'scheduled' => $this->discountCode('SOON', overrides: ['starts_at' => '2026-10-09 00:00:00']),
            'ended' => $this->discountCode('PAST', overrides: ['ends_at' => '2026-10-08 11:00:00']),
            'used_up' => $this->discountCode('ONCE', overrides: ['max_uses' => 1]),
        ];
        $this->purchaseOrder($ali, $plan, $this->fakeServer(), ['discount_code_id' => $codes['used_up']->id]);
        $this->purchaseOrder($ali, $plan, $this->fakeServer('دوم'), ['discount_code_id' => $codes['active']->id, 'status' => OrderStatus::Cancelled]);

        $states = array_column($this->decode($this->get('/api/admin/discount-codes'))['codes'], 'state', 'code');
        self::assertSame(['ONCE' => 'used_up', 'PAST' => 'ended', 'SOON' => 'scheduled', 'OFF' => 'off', 'ACTIVE' => 'active'], $states, 'newest first; a cancelled order is no use');

        $this->sale('آینده', overrides: ['starts_at' => '2026-10-09 00:00:00']);
        $this->sale('گذشته', overrides: ['ends_at' => '2026-10-08 11:00:00']);
        $this->sale('خاموش', overrides: ['enabled' => false]);
        self::assertSame(['خاموش' => 'off', 'گذشته' => 'ended', 'آینده' => 'scheduled'], array_column($this->decode($this->get('/api/admin/sales'))['sales'], 'state', 'name'));
    }

    public function testACodeIsSavedWhole(): void
    {
        $code = $this->discountCode('OLD', 10, ['max_uses' => 5]);

        $saved = $this->decode($this->putJson("/api/admin/discount-codes/{$code->id}", ['code' => 'NEW', 'type' => 'fixed', 'value' => '۱۰٬۰۰۰', 'first_purchase_only' => false, 'enabled' => true]))['code'];

        self::assertSame(['NEW', 'fixed', '10000.00', null], [$saved['code'], $saved['type'], $saved['value'], $saved['max_uses']]);
    }

    public function testSalesAreMadeSwitchedAndDeleted(): void
    {
        $plan = $this->plan(['name' => 'یک‌ماهه']);

        $made = $this->postJson('/api/admin/sales', ['name' => 'فروش ویژه نوروز', 'percent' => '۲۰', 'plan_ids' => [$plan->id], 'order_types' => null, 'starts_at' => '', 'ends_at' => '2026-10-20T00:00', 'enabled' => true]);
        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        $sale = $this->decode($made)['sale'];
        self::assertSame(['فروش ویژه نوروز', 20, [['id' => $plan->id, 'name' => 'یک‌ماهه']], null, '2026-10-19T20:30:00+00:00', 'active'], [$sale['name'], $sale['percent'], $sale['plans'], $sale['order_types'], $sale['ends_at'], $sale['state']]);

        $refused = $this->postJson('/api/admin/sales', ['name' => '', 'percent' => 0, 'enabled' => true]);
        self::assertSame(['name', 'percent'], array_keys($this->decode($refused)['errors']));

        // Past the last day the database keeps (MySQL's TIMESTAMP ends in 2038): refused, never a 500.
        $tooLate = $this->postJson('/api/admin/sales', ['name' => 'حراج', 'percent' => 10, 'starts_at' => '2040-01-01T00:00', 'ends_at' => '2099-01-01T00:00', 'enabled' => true]);
        self::assertSame(422, $tooLate->getStatusCode());
        self::assertSame(
            ['starts_at' => [LocalTime::tooLateFor('زمان شروع') . '؛ برای بدون محدودیت، خالی بگذارید.'], 'ends_at' => [LocalTime::tooLateFor('زمان پایان') . '؛ برای بدون محدودیت، خالی بگذارید.']],
            $this->decode($tooLate)['errors'],
        );
        $code = $this->postJson('/api/admin/discount-codes', ['code' => 'LATE', 'type' => 'percent', 'value' => 10, 'first_purchase_only' => false, 'ends_at' => '2038-01-18T00:01', 'enabled' => true]);
        self::assertSame(['ends_at'], array_keys($this->decode($code)['errors']), 'a discount code\'s window too');
        self::assertSame(201, $this->postJson('/api/admin/discount-codes', ['code' => 'LAST', 'type' => 'percent', 'value' => 10, 'first_purchase_only' => false, 'ends_at' => LocalTime::LAST_DAY . 'T23:59', 'enabled' => true])->getStatusCode(), 'its last day is fine');

        self::assertSame('off', $this->decode($this->patchJson("/api/admin/sales/{$sale['id']}", ['enabled' => false]))['sale']['state']);
        self::assertSame(30, $this->decode($this->putJson("/api/admin/sales/{$sale['id']}", ['name' => 'حراج', 'percent' => 30, 'enabled' => true]))['sale']['percent']);
        self::assertSame([['name' => 'حراج']], array_map(static fn(array $row): array => ['name' => $row['name']], $this->decode($this->get('/api/admin/sales'))['sales']));
        self::assertSame(204, $this->deleteJson("/api/admin/sales/{$sale['id']}")->getStatusCode());
        self::assertSame(0, Sale::query()->count());
    }

    public function testACustomersOwnDiscountIsSetFromTheirPage(): void
    {
        $ali = $this->customer();

        $user = $this->decode($this->putJson("/api/admin/users/{$ali->id}/discount", ['percent' => '۱۵']))['user'];
        self::assertSame(15, $user['discount_percent']);
        self::assertSame(15, $this->decode($this->get("/api/admin/users/{$ali->id}"))['user']['discount_percent']);

        self::assertSame(['percent'], array_keys($this->decode($this->putJson("/api/admin/users/{$ali->id}/discount", ['percent' => 101]))['errors']));
        self::assertSame(0, $this->decode($this->putJson("/api/admin/users/{$ali->id}/discount", ['percent' => 0]))['user']['discount_percent'], '0 takes it away');
    }

    public function testTheHoldIsABotSetting(): void
    {
        $saved = $this->decode($this->putJson('/api/admin/bot/settings/discounts', ['hold_minutes' => '۳۰']))['settings'];
        self::assertSame(30, $saved['hold_minutes']);

        $refused = $this->putJson('/api/admin/bot/settings/discounts', ['hold_minutes' => 2000]);
        self::assertSame(['hold_minutes'], array_keys($this->decode($refused)['errors']));
    }

    public function testAnOrderSaysWhatWasTakenOffOnBothScreens(): void
    {
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $code = $this->discountCode('NOWRUZ');
        $order = $this->purchaseOrder($this->customer(), $plan, $server, [
            'amount' => '96000.00',
            'discount_amount' => '24000.00',
            'discounts' => [(new DiscountLine(DiscountSource::Code, 'کد NOWRUZ', '24000', 20, $code->id, code: 'NOWRUZ'))->toArray()],
            'discount_code_id' => $code->id,
        ]);
        $payment = $this->cardPayment($order, $this->cardMethod());

        $row = $this->decode($this->get("/api/admin/orders/{$order->id}"))['order'];
        self::assertSame(['24000.00', [['source' => 'code', 'label' => 'کد NOWRUZ', 'amount' => '24000.00', 'percent' => 20]], ['id' => $code->id, 'code' => 'NOWRUZ']], [$row['discount_amount'], $row['discounts'], $row['discount_code']]);

        $paid = $this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment']['order'];
        self::assertSame(['24000.00', ['id' => $code->id, 'code' => 'NOWRUZ']], [$paid['discount_amount'], $paid['discount_code']]);
        self::assertSame(1, Order::query()->count());
    }

    public function testAnAgentsPanelKeepsItsBotsCodesAndSales(): void
    {
        $bot = $this->agentBot($this->agent());
        $this->discountCode('MAIN');
        $this->loginAsAgent($bot);

        self::assertSame(0, $this->decode($this->get('/api/agent/discount-codes'))['meta']['total']);
        $made = $this->decode($this->postJson('/api/agent/discount-codes', ['code' => 'MAIN', 'type' => 'percent', 'value' => 10, 'first_purchase_only' => false, 'enabled' => true]))['code'];
        self::assertSame('MAIN', $made['code'], "another shop's code of the same text is no matter");
        self::assertSame($bot->id, CurrentBot::run($bot, static fn() => DiscountCode::query()->whereKey($made['id'])->value('bot_id')));
        $main = DiscountCode::query()->where('code', 'MAIN')->value('id');
        self::assertSame(404, $this->patchJson("/api/agent/discount-codes/{$main}", ['enabled' => false])->getStatusCode());
        self::assertSame(201, $this->postJson('/api/agent/sales', ['name' => 'حراج', 'percent' => 10, 'enabled' => true])->getStatusCode());
    }
}
