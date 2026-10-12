<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * A service's screen — and its renewal's checkout, under the discount lines — says when the shop removes it by itself: an ended
 * service, while the shop removes ended ones, unless it is renewed first; a free test by the test accounts' own rule. A
 * running service, or a shop that keeps its ended services, says nothing of it.
 */
final class BotServiceRemovalTest extends BotTestCase
{
    private User $user;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->fakePanel();
        $this->user = $this->customer(['username' => 'ali']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testAnEndedServiceSaysWhenItGoesUnlessRenewed(): void
    {
        $this->removeAfter(12);
        $service = $this->endedService();

        $this->send($this->tap(SubscriptionHandler::serviceCallback($service->id)));

        $line = self::text(BotText::ServiceRemoval, ['date' => Persian::date(Carbon::parse('2026-10-07 22:00:00'), withTime: true)]);
        self::assertStringEndsWith("\n\n{$line}\n\n" . self::text(BotText::ServiceRotateHint), $this->params(0)['text'], 'its deadline (10:00) and 12 hours, above the hint at «تغییر لینک»');
        self::assertContains(SubscriptionHandler::serviceCallback($service->id, 'renew'), $this->callbacks(0), 'the renewal that keeps it is on the same screen');
    }

    public function testItsRenewalCheckoutSaysSoUnderTheDiscountLines(): void
    {
        $this->removeAfter(12);
        $this->walletMethod();
        $this->sale();
        $this->plan->forceFill(['checkout_note' => 'روی Happ تست شده است.'])->save();
        $service = $this->endedService();

        $this->send($this->tap(RenewalHandler::checkoutCallback($service->id)));

        $line = self::text(BotText::ServiceRemoval, ['date' => Persian::date(Carbon::parse('2026-10-07 22:00:00'), withTime: true)]);
        $before = self::text(BotText::CheckoutBeforeDiscount, ['regular' => Money::format('120000')]);
        $note = self::text(BotText::CheckoutPlanNote, ['plan_note' => 'روی Happ تست شده است.']);
        $text = $this->params(0)['text'];
        self::assertStringContainsString("{$before}\n\n{$line}\n\n{$note}", $text, 'under the discount lines, above the plan\'s note');
        self::assertSame(1, substr_count($text, $line), 'once');
    }

    public function testAShopThatKeepsItsEndedServicesSaysNothing(): void
    {
        $service = $this->endedService();

        $this->send($this->tap(SubscriptionHandler::serviceCallback($service->id)));

        self::assertStringNotContainsString(self::opening(BotText::ServiceRemoval), $this->params(0)['text']);
    }

    public function testARunningServiceSaysNothing(): void
    {
        $this->removeAfter(12);
        $service = $this->subscription($this->user, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($service);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($service->id)));

        self::assertStringNotContainsString(self::opening(BotText::ServiceRemoval), $this->params(0)['text']);
    }

    public function testAFreeTestSaysWhenItGoesByTheTestsRule(): void
    {
        $this->removeAfter(12);
        $test = $this->endedService(['is_test' => true, 'plan_id' => null]);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($test->id)));

        $text = $this->params(0)['text'];
        $line = self::text(BotText::ServiceTestRemoval, ['date' => Persian::date(Carbon::parse('2026-10-08 10:00:00'), withTime: true)]);
        self::assertStringContainsString("\n\n{$line}\n\n", $text, 'a day after it ended, as the test accounts\' rule has it by default');
        self::assertStringNotContainsString(self::opening(BotText::ServiceRemoval), $text, 'a test cannot be renewed');
    }

    /** The shop's ended services removed `$hours` after they ended. */
    private function removeAfter(int $hours): void
    {
        $this->botSettings('expired_services', ['expired_cleanup' => true, 'expired_cleanup_hours' => $hours]);
    }

    /**
     * A service of Ali's whose deadline passed at 10:00, its panel saying the same; no sync has caught up with it yet.
     *
     * @param array<string, mixed> $overrides
     */
    private function endedService(array $overrides = []): Subscription
    {
        $service = $this->subscription($this->user, $this->plan, $this->server, 'ali_1', $overrides + [
            'starts_at' => now()->subDays(30)->subHours(2),
            'expires_at' => now()->subHours(2),
        ]);
        FakeProvider::mirror($service);

        return $service;
    }

    /** The words a removal line starts with, before its date. */
    private static function opening(BotText $text): string
    {
        return explode('%date%', self::text($text))[0];
    }
}
