<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\ReadableCode;
use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Enums\GiftCodeRefusal;
use App\Modules\Rewards\Exceptions\GiftCodeRefusedException;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Rewards\Services\GiftCodes;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Gift codes: batches of readable unique codes; redeeming once however typed, Toman credited with the take, a plan's service
 * given (the code given back when no server delivers it, or an agent's shop whose selling is suspended gives none), two
 * takers at once, wrong codes rate-limited per customer and network, and shops kept apart.
 */
final class GiftCodesTest extends DatabaseTestCase
{
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->withoutQr();
        $this->telegram();
        $this->giftCodesOn();
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testABatchIsReadableUniqueAndPrefixed(): void
    {
        $made = $this->codes()->make(['count' => 40, 'kind' => 'wallet', 'amount' => '۵۰٬۰۰۰', 'batch' => 'نوروز ۱۴۰۵', 'prefix' => 'now-ruz', 'expires_on' => '2026-10-20']);

        self::assertSame('نوروز ۱۴۰۵', $made['batch']);
        self::assertCount(40, $made['codes']);
        self::assertCount(40, array_unique($made['codes']));
        foreach ($made['codes'] as $code) {
            self::assertMatchesRegularExpression('/^NOWRUZ[' . ReadableCode::ALPHABET . ']{' . GiftCodes::RANDOM_LENGTH . '}$/', $code);
        }

        $kept = GiftCode::query()->where('batch', 'نوروز ۱۴۰۵')->get();
        self::assertCount(40, $kept);
        self::assertSame(['50000.00'], $kept->pluck('amount')->unique()->values()->all());
        self::assertSame(GiftCodeKind::Wallet, $kept->firstOrFail()->kind);
        self::assertSame('2026-10-21T00:00:00+00:00', $kept->firstOrFail()->expires_at?->toIso8601String(), "the end of that day in the shop's zone");
    }

    public function testABatchIsRefusedWholeWithEveryReason(): void
    {
        try {
            $this->codes()->make(['count' => 501, 'kind' => 'plan', 'plan_id' => 999, 'batch' => '', 'prefix' => 'سلام', 'expires_on' => '2026-10-01']);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['count', 'plan_id', 'batch', 'prefix', 'expires_on'], array_keys($e->errors()));
        }

        try {
            $this->codes()->make(['count' => 1, 'kind' => 'wallet', 'amount' => '500', 'batch' => 'x']);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['amount'], array_keys($e->errors()));
        }
        self::assertSame(0, GiftCode::query()->count());
    }

    public function testTomanLandInTheWalletOnceTypedAsAPersonTypesIt(): void
    {
        $this->giftCode('NOWRUZ7K3M9QXAPT');

        $redeemed = $this->codes()->redeem($this->ali, ' nowruz-7k3m ۹qxapt ');

        self::assertSame('50000.00', $this->ali->balance());
        self::assertSame('کد هدیه NOWRUZ7K3M9QXAPT', WalletTransaction::query()->where('user_id', $this->ali->id)->sole()->description);
        $code = GiftCode::query()->sole();
        self::assertSame($this->ali->id, $code->redeemed_by);
        self::assertNotNull($code->redeemed_at);
        self::assertSame($code->id, $redeemed->code->id);

        $this->assertRefused(GiftCodeRefusal::Yours, fn() => $this->codes()->redeem($this->ali, 'NOWRUZ7K3M9QXAPT'));
        $this->assertRefused(GiftCodeRefusal::Taken, fn() => $this->codes()->redeem($this->customer(['telegram_id' => 7002]), 'NOWRUZ7K3M9QXAPT'));
        self::assertSame('50000.00', $this->ali->balance(), 'given once');
    }

    public function testOfTwoAtOnceOneTakesIt(): void
    {
        $this->giftCode('TWINCODE2345');
        $sara = $this->customer(['telegram_id' => 7002]);

        $raced = false;
        $refused = $this->whileListening('eloquent.retrieved: ' . GiftCode::class, function () use (&$raced, $sara): void {
            if (!$raced) {
                $raced = true;
                $this->codes()->redeem($sara, 'TWINCODE2345');
            }
        }, function (): ?GiftCodeRefusal {
            try {
                $this->codes()->redeem($this->ali, 'TWINCODE2345');
            } catch (GiftCodeRefusedException $e) {
                return $e->reason;
            }

            return null;
        });

        self::assertSame(GiftCodeRefusal::Taken, $refused, 'the code read free, taken by the other meanwhile');
        self::assertSame(['0.00', '50000.00'], [$this->ali->balance(), $sara->balance()]);
        self::assertSame(1, WalletTransaction::query()->count());
    }

    public function testAPlansServiceIsGivenFreeItsOrderOnTheCode(): void
    {
        $server = $this->sellingServer();
        $plan = $this->plan(['name' => 'یک‌ماهه', 'is_active' => false], $server);
        $this->giftCode('PLANGIFT2345', ['kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $plan->id]);

        $redeemed = $this->codes()->redeem($this->ali, 'plangift2345');

        $order = $redeemed->given->order ?? self::fail('no order');
        self::assertSame([OrderStatus::Fulfilled, '0.00', OrderPricing::Gift], [$order->status, $order->amount, $order->pricing]);
        self::assertSame($order->id, GiftCode::query()->sole()->order_id);
        self::assertCount(1, FakeProvider::$created, 'a plan kept for gifts, off sale, still given');
        self::assertNotSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'its service sent');
    }

    public function testAPlanNoServerDeliversNowLeavesTheCodeAsItWas(): void
    {
        $plan = $this->plan();
        $this->giftCode('PLANGIFT2345', ['kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $plan->id]);

        $this->assertRefused(GiftCodeRefusal::Unavailable, fn() => $this->codes()->redeem($this->ali, 'PLANGIFT2345'));

        self::assertNull(GiftCode::query()->sole()->redeemed_at, 'still the customer\'s to try later');
    }

    public function testAPlanThatStopsDeliveringOnceTakenGivesTheCodeBack(): void
    {
        $server = $this->sellingServer();
        $plan = $this->plan([], $server);
        $this->giftCode('PLANGIFT2345', ['kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $plan->id]);

        // The server switched off the moment the code is taken: the delivery finds no server.
        $refused = $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use ($server): void {
            if (str_starts_with(strtolower($query->sql), 'update "gift_codes"') && str_contains($query->sql, 'redeemed_by')) {
                Server::query()->whereKey($server->id)->update(['is_active' => false]);
            }
        }, function (): ?GiftCodeRefusedException {
            try {
                $this->codes()->redeem($this->ali, 'PLANGIFT2345');
            } catch (GiftCodeRefusedException $e) {
                return $e;
            }

            return null;
        });

        self::assertSame(GiftCodeRefusal::Unavailable, $refused?->reason);
        self::assertSame(RewardUnavailableException::NO_SERVER, $refused->getMessage());
        $code = GiftCode::query()->sole();
        self::assertNull($code->redeemed_at, 'given back');
        self::assertNull($code->redeemed_by);
    }

    public function testAPlanCodeInAnAgentsShopWhoseSellingIsSuspendedSaysSoAndStaysTheCustomers(): void
    {
        $server = $this->sellingServer();
        $bot = $this->agentBot();
        CurrentBot::run($bot, function () use ($server): void {
            $this->giftCodesOn();
            $this->giftCode('PLANGIFT2345', ['kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $this->plan([], $server)->id]);
            $this->giftCode('TOMANGIFT234');
        });
        $this->service(AgencyStanding::class)->suspendByHand($bot, null);

        CurrentBot::run($bot->refresh(), function (): void {
            $reza = $this->customer(['telegram_id' => 7003]);
            try {
                $this->codes()->redeem($reza, 'PLANGIFT2345');
                self::fail('a service was given');
            } catch (GiftCodeRefusedException $e) {
                // What the bot's text and the website's 422 on `code` say alike.
                self::assertSame([GiftCodeRefusal::Unavailable, RewardUnavailableException::SUSPENDED], [$e->reason, $e->getMessage()], 'the suspension — no server that cannot deliver it');
            }
            self::assertNull(GiftCode::query()->where('code', 'PLANGIFT2345')->sole()->redeemed_at, "still the customer's for when it sells again");

            $this->codes()->redeem($reza, 'TOMANGIFT234');
            self::assertSame('50000.00', $reza->balance(), 'Toman are still given');
        });
    }

    public function testExpiredUnknownAndOffAreRefused(): void
    {
        $this->giftCode('OLDCODE2345', ['expires_at' => now()->subMinute()]);

        $this->assertRefused(GiftCodeRefusal::Expired, fn() => $this->codes()->redeem($this->ali, 'OLDCODE2345'));
        $this->assertRefused(GiftCodeRefusal::Unknown, fn() => $this->codes()->redeem($this->ali, 'NOSUCHCODE22'));
        $this->assertRefused(GiftCodeRefusal::Unknown, fn() => $this->codes()->redeem($this->ali, 'کد فارسی'));
        $this->assertRefused(GiftCodeRefusal::Empty, fn() => $this->codes()->redeem($this->ali, ' - '));

        $this->giftCodesOn(false);
        $this->assertRefused(GiftCodeRefusal::Off, fn() => $this->codes()->redeem($this->ali, 'OLDCODE2345'));
    }

    public function testTenWrongCodesAnHourAndTheCustomerWaits(): void
    {
        $this->giftCode('RIGHTCODE234');
        for ($i = 0; $i < GiftCodes::WRONG_CODES; $i++) {
            $this->assertRefused(GiftCodeRefusal::Unknown, fn() => $this->codes()->redeem($this->ali, "WRONG{$i}", 'net-1'));
        }

        try {
            $this->codes()->redeem($this->ali, 'RIGHTCODE234', 'net-1');
            self::fail('held');
        } catch (TooManyAttemptsException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
        }

        $sara = $this->customer(['telegram_id' => 7002]);
        $this->codes()->redeem($sara, 'RIGHTCODE234', 'net-1');
        self::assertSame('50000.00', $sara->balance(), 'another customer behind the same network: its count is not spent yet');

        Carbon::setTestNow(now()->addHour()->addMinute());
        $this->assertRefused(GiftCodeRefusal::Taken, fn() => $this->codes()->redeem($this->ali, 'RIGHTCODE234', 'net-1'));
    }

    public function testCodesOfTheShopFoundTheRightOnesCostNothing(): void
    {
        foreach (range(1, GiftCodes::WRONG_CODES + 2) as $i) {
            $this->giftCode("SHOPCODE{$i}AB");
            $this->codes()->redeem($this->ali, "SHOPCODE{$i}AB");
        }

        self::assertSame('600000.00', $this->ali->balance(), 'twelve right codes in a row, none held');
    }

    public function testAnotherShopsCodeIsNoneOfThisShops(): void
    {
        $bot = $this->agentBot();
        CurrentBot::run($bot, function (): void {
            $this->giftCodesOn();
            $this->giftCode('AGENTCODE234');
        });

        $this->assertRefused(GiftCodeRefusal::Unknown, fn() => $this->codes()->redeem($this->ali, 'AGENTCODE234'));

        CurrentBot::run($bot, function (): void {
            $reza = $this->customer(['telegram_id' => 7003]);
            $this->codes()->redeem($reza, 'AGENTCODE234');
            self::assertSame('50000.00', $reza->balance());
        });
    }

    public function testOnlyCodesNobodyTookAreDeleted(): void
    {
        $taken = $this->giftCode('TAKENCODE234', ['redeemed_by' => $this->ali->id, 'redeemed_at' => now()]);
        $free = $this->giftCode('FREECODE2345');
        $this->giftCode('OLDCODE2345', ['expires_at' => now()->subDay()]);
        $this->giftCode('OTHERBATCH23', ['batch' => 'یلدا']);

        try {
            $this->codes()->delete($taken);
            self::fail('kept');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors());
        }
        $this->codes()->delete($free);
        self::assertSame(1, $this->codes()->deleteUnused('نوروز'), 'the one that ran out; the taken one stays');

        self::assertSame(['OTHERBATCH23', 'TAKENCODE234'], GiftCode::query()->orderBy('code')->pluck('code')->all());
    }

    /** @param \Closure(): mixed $redeem */
    private function assertRefused(GiftCodeRefusal $reason, \Closure $redeem, string $message = ''): void
    {
        try {
            $redeem();
            self::fail("refused: {$reason->value}");
        } catch (GiftCodeRefusedException $e) {
            self::assertSame($reason, $e->reason, $message);
        }
    }

    private function codes(): GiftCodes
    {
        return $this->service(GiftCodes::class);
    }
}
