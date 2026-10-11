<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Models\WheelPrize;
use App\Modules\Rewards\Services\LuckyWheel;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The lucky wheel's page: slices kept in order (every refusal at once), switched, reordered and deleted — a spin keeps the
 * slice as it was —, the spins list, and the `wheel` settings group. Each shop its own.
 */
final class AdminWheelApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->loginAsAdmin();
    }

    public function testSlicesAreAddedChangedSwitchedReorderedAndDeleted(): void
    {
        $plan = $this->plan(['name' => 'یک‌ماهه']);

        $wallet = $this->postJson('/api/admin/wheel/prizes', ['label' => '۱۰ هزار تومان', 'kind' => 'wallet', 'amount' => '۱۰٬۰۰۰', 'weight' => '۵']);
        self::assertSame(201, $wallet->getStatusCode(), (string) $wallet->getBody());
        $walletRow = $this->decode($wallet)['prize'];
        self::assertSame(['label' => '۱۰ هزار تومان', 'kind' => 'wallet', 'amount' => '10000.00', 'plan' => null, 'weight' => 5, 'enabled' => true], array_diff_key($walletRow, ['id' => 0, 'sort' => 0]));

        $traffic = $this->decode($this->postJson('/api/admin/wheel/prizes', ['label' => '۲.۵ گیگ', 'kind' => 'traffic', 'amount' => '2.5', 'weight' => 3, 'enabled' => false]))['prize'];
        self::assertSame(['2.50', false], [$traffic['amount'], $traffic['enabled']]);
        $planRow = $this->decode($this->postJson('/api/admin/wheel/prizes', ['label' => 'سرویس رایگان', 'kind' => 'plan', 'plan_id' => $plan->id, 'weight' => 1]))['prize'];
        self::assertSame([null, ['id' => $plan->id, 'name' => 'یک‌ماهه']], [$planRow['amount'], $planRow['plan']]);
        $nothing = $this->decode($this->postJson('/api/admin/wheel/prizes', ['label' => 'پوچ', 'kind' => 'nothing', 'weight' => 10]))['prize'];

        $refused = $this->postJson('/api/admin/wheel/prizes', ['label' => '', 'kind' => 'days', 'amount' => '0', 'weight' => 0]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['label', 'amount', 'weight'], array_keys($this->decode($refused)['errors']), 'every refusal at once');

        $changed = $this->decode($this->putJson("/api/admin/wheel/prizes/{$walletRow['id']}", ['label' => '۷ روز', 'kind' => 'days', 'amount' => '7', 'weight' => 2]))['prize'];
        self::assertSame(['days', '7.00', true], [$changed['kind'], $changed['amount'], $changed['enabled']], 'the switch left out keeps its own');

        self::assertTrue($this->decode($this->patchJson("/api/admin/wheel/prizes/{$traffic['id']}", ['enabled' => true]))['prize']['enabled']);

        $order = [$nothing['id'], $planRow['id'], $walletRow['id'], $traffic['id']];
        self::assertSame($order, array_column($this->decode($this->postJson('/api/admin/wheel/prizes/reorder', ['ids' => $order]))['prizes'], 'id'));
        self::assertSame($order, array_column($this->decode($this->get('/api/admin/wheel/prizes'))['prizes'], 'id'));

        self::assertSame(204, $this->deleteJson("/api/admin/wheel/prizes/{$nothing['id']}")->getStatusCode());
        self::assertSame(3, WheelPrize::query()->count());
    }

    public function testTheSpinsAreListedNewestFirstWithWhatCameOfThem(): void
    {
        $this->wheelOn();
        $ali = $this->customer(['username' => 'ali', 'first_name' => 'Ali']);
        $prize = $this->wheelPrize('۱۰ هزار تومان');
        $this->service(LuckyWheel::class)->spin($ali);
        $failed = $this->wheelSpin($ali, ['kind' => RewardKind::Traffic, 'label' => '۵ گیگ', 'amount' => '5', 'outcome' => 'سرور در دسترس نبود.']);
        $prize->delete();

        $page = $this->decode($this->get('/api/admin/wheel/spins'));

        self::assertSame([$failed->id, $failed->id - 1], array_column($page['spins'], 'id'));
        self::assertSame([
            'id' => $failed->id - 1,
            'customer' => ['id' => $ali->id, 'name' => 'Ali', 'username' => 'ali', 'telegram_id' => self::TELEGRAM_ID, 'email' => null],
            'label' => '۱۰ هزار تومان',
            'kind' => 'wallet',
            'amount' => '10000.00',
            'given' => true,
            'outcome' => null,
            'order' => null,
            'subscription' => null,
            'created_at' => '2026-10-08T12:00:00+00:00',
        ], $page['spins'][1], 'the slice as it was, deleted since');
        self::assertSame([false, 'سرور در دسترس نبود.'], [$page['spins'][0]['given'], $page['spins'][0]['outcome']]);

        self::assertSame(2, $this->decode($this->get("/api/admin/wheel/spins?user={$ali->id}"))['meta']['total']);
        self::assertSame(0, $this->decode($this->get('/api/admin/wheel/spins?search=sara'))['meta']['total']);
        self::assertSame(2, $this->decode($this->get('/api/admin/wheel/spins?search=ali'))['meta']['total']);
    }

    public function testTheRulesAreAGroupOfTheBotSettings(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/wheel', ['wheel_enabled' => true, 'wheel_cooldown_hours' => '۱۲', 'wheel_buyers_only' => true, 'wheel_cost' => '5,000']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->decode($response)['settings'];
        self::assertSame([true, 12, true, 5000], [$settings['wheel_enabled'], $settings['wheel_cooldown_hours'], $settings['wheel_buyers_only'], $settings['wheel_cost']]);

        $refused = $this->putJson('/api/admin/bot/settings/wheel', ['wheel_enabled' => true, 'wheel_cooldown_hours' => 0, 'wheel_buyers_only' => false, 'wheel_cost' => 0]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['wheel_cooldown_hours'], array_keys($this->decode($refused)['errors']));
    }

    public function testEachShopHasItsOwnWheel(): void
    {
        $this->wheelPrize('اصلی');
        $bot = $this->agentBot();
        CurrentBot::run($bot, fn() => $this->wheelPrize('نماینده'));
        $this->loginAsAgent($bot);

        self::assertSame(['نماینده'], array_column($this->decode($this->get('/api/agent/wheel/prizes'))['prizes'], 'label'));
        $main = WheelPrize::query()->where('label', 'اصلی')->sole();
        self::assertSame(404, $this->putJson("/api/agent/wheel/prizes/{$main->id}", ['label' => 'x', 'kind' => 'nothing', 'weight' => 1])->getStatusCode());
    }
}
