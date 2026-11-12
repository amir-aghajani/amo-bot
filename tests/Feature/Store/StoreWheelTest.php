<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Enums\WheelRefusal;
use App\Modules\Rewards\Models\WheelSpin;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * The lucky wheel on the website and in the mini app, as the bot's «بچرخان» spins it: the wheel as the customer finds it,
 * a spin's landing and what came of it in the bot's words, each kind of prize, a prize not given (the cost back, no
 * cooldown), and every refusal on `status` with nothing spent; nothing sent in Telegram.
 */
final class StoreWheelTest extends HttpTestCase
{
    private Website $website;

    private User $ali;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->withoutQr();
        $this->telegram();
        $this->website = $this->website();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer();
        $this->wheelOn();
        $this->bearer($this->customerSession($this->ali));
    }

    public function testTheWheelAsTheCustomerFindsIt(): void
    {
        $this->wheelOn(['wheel_cost' => 5000, 'wheel_cooldown_hours' => 12]);
        $this->wheelPrize('خاموش', ['enabled' => false]);
        $this->wheelPrize('۱۰ هزار تومان');
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing, 'amount' => null]);

        self::assertSame([
            'slices' => [['label' => '۱۰ هزار تومان', 'kind' => 'wallet'], ['label' => 'پوچ', 'kind' => 'nothing']],
            'cost' => '5000.00',
            'cooldown_hours' => 12,
            'next_spin_at' => null,
        ], $this->decode($this->get($this->storeApi($this->website, '/wheel'))));

        $this->wheelSpin($this->ali);
        self::assertSame('2026-10-08T00:00:00+00:00', $this->decode($this->get($this->storeApi($this->website, '/wheel')))['next_spin_at']);
    }

    public function testASpinLandsOnItsSliceAndGivesItsPrize(): void
    {
        $this->wheelPrize('۷ روز', ['kind' => RewardKind::Days, 'amount' => '7']);
        $this->wheelPrize('۱۰ هزار تومان', ['weight' => 10_000]);

        $spun = $this->decode($this->spin());

        self::assertSame([
            'slices' => [['label' => '۷ روز', 'kind' => 'days'], ['label' => '۱۰ هزار تومان', 'kind' => 'wallet']],
            'landed' => 1,
            'given' => true,
            'words' => 'بردی: ۱۰ هزار تومان. ۱۰٬۰۰۰ تومان به کیف پول شما اضافه شد؛ موجودی: ۱۰٬۰۰۰ تومان.',
            'failure' => null,
            'refunded' => false,
            'next_spin_at' => '2026-10-08T12:00:00+00:00',
            'balance' => '10000.00',
        ], $spun, 'no running service: the days cannot reach them');
        self::assertSame([], $this->telegram()->calls(), 'the screen shows it: nothing sent in Telegram');

        $tooSoon = $this->spin();
        $this->assertRefusedUnder('status', 'هنوز نوبت چرخش بعدی شما نرسیده است؛ از ۱۶ مهر ۱۴۰۵، ۱۲:۰۰ می‌توانید دوباره بچرخانید.', $tooSoon);
        self::assertSame(1, WheelSpin::query()->count(), 'nothing spun');
    }

    public function testNothingWonSaysSo(): void
    {
        $this->wheelOn(['wheel_cost' => 5000]);
        $this->wallet($this->ali, '8000');
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing, 'amount' => null]);

        $spun = $this->decode($this->spin());

        self::assertSame([0, false, 'پوچ! این بار جایزه‌ای نبود؛ دفعه بعد شانس با شماست.', null, false, '3000.00'], [$spun['landed'], $spun['given'], $spun['words'], $spun['failure'], $spun['refunded'], $spun['balance']]);
        self::assertNotNull($spun['next_spin_at'], 'it counts for the cooldown');
    }

    public function testTrafficGoesToTheirServiceAndAPlanMakesOne(): void
    {
        $service = $this->subscription($this->ali, $this->plan([], $this->server), $this->server, 'ali_1', ['expires_at' => now()->addDays(20), 'label' => 'گوشی']);
        FakeProvider::mirror($service);
        $traffic = $this->wheelPrize('۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5']);

        self::assertSame("بردی: ۵ گیگ. ۵ گیگابایت حجم به سرویس {$service->shownAs()} اضافه شد.", $this->decode($this->spin())['words'], 'the service by the name its customer gave it');
        self::assertSame(Traffic::bytesOfGb(35), $service->refresh()->traffic_limit_bytes);

        $traffic->forceFill(['enabled' => false])->save();
        $this->wheelPrize('سرویس هدیه', ['kind' => RewardKind::Plan, 'amount' => null, 'plan_id' => $this->plan(['name' => 'هدیه'], $this->server)->id]);
        Carbon::setTestNow(now()->addDay()->addMinute());

        $spun = $this->decode($this->spin());

        self::assertSame([true, 'بردی: سرویس هدیه. سرویس «هدیه» برای شما ساخته شد و در سرویس‌های شماست.'], [$spun['given'], $spun['words']]);
        $order = WheelSpin::query()->with('order')->latest('id')->first()->order ?? self::fail('no order');
        self::assertSame(OrderStatus::Fulfilled, $order->status);
        self::assertSame(2, Subscription::query()->where('user_id', $this->ali->id)->count());
        self::assertSame([], $this->telegram()->calls(), 'the service is among theirs: not sent in Telegram');
    }

    public function testAPrizeNotGivenGivesTheCostBackAndStartsNoCooldown(): void
    {
        $this->wheelOn(['wheel_cost' => 5000]);
        $this->wallet($this->ali, '5000');
        $service = $this->subscription($this->ali, $this->plan([], $this->server), $this->server, 'ali_1', ['expires_at' => now()->addDays(20)]);
        FakeProvider::mirror($service);
        $this->wheelPrize('۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5']);
        FakeProvider::$unreachable = true;

        $spun = $this->decode($this->spin());
        FakeProvider::$unreachable = false;

        self::assertSame([
            'slices' => [['label' => '۵ گیگ', 'kind' => 'traffic']],
            'landed' => 0,
            'given' => false,
            'words' => 'گردونه روی «۵ گیگ» ایستاد، ولی جایزه الان داده نشد: سرور در دسترس نبود. هزینه چرخش به کیف پول شما برگشت. این چرخش حساب نشد؛ می‌توانید دوباره بچرخانید.',
            'failure' => 'سرور در دسترس نبود.',
            'refunded' => true,
            'next_spin_at' => null,
            'balance' => '5000.00',
        ], $spun);
    }

    public function testEveryRefusalIsOnStatusWithNothingSpent(): void
    {
        $this->wheelOn(['wheel_cost' => 5000]);
        $this->wallet($this->ali, '3000');
        $toman = $this->wheelPrize();

        $this->assertRefusedUnder('status', 'موجودی کیف پول برای چرخش کافی نیست: هزینه هر چرخش ۵٬۰۰۰ تومان است و ۲٬۰۰۰ تومان کم دارید.', $this->spin());

        $toman->forceFill(['enabled' => false])->save();
        $this->wheelPrize('۷ روز', ['kind' => RewardKind::Days, 'amount' => '7']);
        $this->assertRefusedUnder('status', WheelRefusal::Empty->message(), $this->spin(), message: 'no slice can reach them');

        $this->wheelOn(['wheel_buyers_only' => true]);
        $this->assertRefusedUnder('status', WheelRefusal::BuyersOnly->message(), $this->get($this->storeApi($this->website, '/wheel')));
        $this->assertRefusedUnder('status', WheelRefusal::BuyersOnly->message(), $this->spin());

        $this->wheelOn(['wheel_enabled' => false]);
        $this->assertRefusedUnder('status', WheelRefusal::Off->message(), $this->get($this->storeApi($this->website, '/wheel')));
        $this->assertRefusedUnder('status', WheelRefusal::Off->message(), $this->spin());

        self::assertSame([0, '3000.00'], [WheelSpin::query()->count(), $this->ali->balance()]);
    }

    public function testSignedInOnly(): void
    {
        $this->bearer(null);

        self::assertSame(401, $this->unchecked()->get($this->storeApi($this->website, '/wheel'))->getStatusCode());
    }

    private function spin(): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/wheel/spin'));
    }
}
