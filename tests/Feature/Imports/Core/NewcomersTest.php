<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Discounts\DTO\CodeTarget;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Referrals\Enums\StartGiftTrigger;
use App\Modules\Referrals\Models\ReferralBonus;
use App\Modules\Referrals\Models\ReferralReward;
use App\Modules\Subscriptions\Enums\TestRefusal;
use App\Modules\Subscriptions\Exceptions\TestAccountRefusedException;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;

/**
 * What an import brings of a customer's past in the other bot: a referral's start gift there is decided here too (none
 * on their first purchase, to either side), and a customer who bought there (users.bought_elsewhere_at) is no newcomer
 * — no first-purchase price or code, no newcomers' test, no first purchase here, no referrer's milestone.
 */
final class NewcomersTest extends ImportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 12:00:00');
        $this->withoutQr();
    }

    public function testAnImportedReferralsFirstPurchaseHerePaysNoStartGiftToEitherSide(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000, 'referral_start_invitee' => 10_000]);
        $this->migrator->customers = [new ImportedCustomer('r', 3001), new ImportedCustomer('f', 3002, referrerKey: 'r')];

        $this->importAll();

        $referrer = User::query()->where('telegram_id', 3001)->sole();
        $friend = User::query()->where('telegram_id', 3002)->sole();
        $bonus = ReferralBonus::query()->sole();
        self::assertSame([$friend->id, $referrer->id, '0.00', '0.00', StartGiftTrigger::Purchase], [$bonus->invitee_id, $bonus->referrer_id, $bonus->referrer_amount, $bonus->invitee_amount, $bonus->trigger], 'decided, as a withheld gift is');

        $server = $this->sellingServer();
        $this->buy($friend, $this->plan([], $server), $server);
        self::assertSame('0.00', $referrer->balance(), 'no start gift (paid from the wallet, no commission either)');
        self::assertSame('0.00', $friend->balance());
        self::assertSame(1, ReferralBonus::query()->count());
    }

    public function testAReferrerTheShopKnewAlreadyLeavesTheStartGiftAsTheShopHasIt(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000]);
        $other = $this->customer(['telegram_id' => 4001]);
        $this->customer(['telegram_id' => 3002, 'referred_by' => $other->id]);
        $this->migrator->customers = [new ImportedCustomer('r', 3001), new ImportedCustomer('f', 3002, referrerKey: 'r')];

        $this->importAll();

        self::assertSame(0, ReferralBonus::query()->count(), 'no referrer set: nothing decided');
    }

    public function testACustomerWhoBoughtThereIsNoNewcomerHere(): void
    {
        $known = $this->customer(['telegram_id' => 5002]);
        $this->migrator->customers = [new ImportedCustomer('a', 5001, boughtBefore: true), new ImportedCustomer('b', 5002, boughtBefore: true), new ImportedCustomer('c', 5003)];

        $this->importAll();

        $bought = User::query()->where('telegram_id', 5001)->sole();
        $fresh = User::query()->where('telegram_id', 5003)->sole();
        self::assertSame(['2026-11-13 12:00:00', '2026-11-13 12:00:00', null], [$bought->bought_elsewhere_at?->format('Y-m-d H:i:s'), $known->refresh()->bought_elsewhere_at?->format('Y-m-d H:i:s'), $fresh->bought_elsewhere_at], 'a newcomer row or one the shop had');
        self::assertTrue($bought->boughtElsewhere());

        $server = $this->sellingServer();
        $plan = $this->plan(['first_purchase_price' => '60000.00'], $server);
        $pricing = $this->service(Pricing::class);
        self::assertFalse($pricing->buyer($bought, $plan)->firstPurchase, 'no first-purchase price');
        self::assertTrue($pricing->buyer($fresh, $plan)->firstPurchase);

        $this->discountCode('FIRST', 20, ['first_purchase_only' => true]);
        $codes = $this->service(DiscountCodes::class);
        $target = new CodeTarget(OrderType::Purchase, $plan->id, $plan->category_id, $server->id);
        try {
            $codes->apply($bought, 'FIRST', $target, '120000');
            self::fail('A first-purchase code held for one who bought there.');
        } catch (DiscountCodeRefusedException $e) {
            self::assertSame(DiscountCodes::FIRST_ONLY, $e->getMessage());
        }
        self::assertSame('24000.00', $codes->apply($fresh, 'FIRST', $target, '120000')->amount);

        self::assertTrue(OrderService::boughtBefore($this->purchaseOrder($bought, $plan, $server)), 'their first order here is no first purchase (its first-purchase price or code refused at delivery)');
    }

    public function testACustomerWhoBoughtThereTakesNoNewcomersTest(): void
    {
        $this->botSettings('test_accounts', ['test_enabled' => true, 'test_per_user' => 1, 'test_period_days' => 0, 'test_daily_limit' => 0, 'test_newcomers_only' => true, 'test_cleanup' => true, 'test_cleanup_hours' => 24, 'test_unused_days' => 7]);
        $this->migrator->customers = [new ImportedCustomer('a', 5001, boughtBefore: true)];
        $this->importAll();

        try {
            $this->service(TestAccounts::class)->offer(User::query()->where('telegram_id', 5001)->sole());
            self::fail('A newcomers\' test offered to one who bought there.');
        } catch (TestAccountRefusedException $e) {
            self::assertSame(TestRefusal::NewcomersOnly, $e->reason);
        }
    }

    public function testAReferralWhoBoughtThereTakesNoMilestoneOfTheirReferrerHere(): void
    {
        $server = $this->sellingServer();
        $plan = $this->plan([], $server);
        $this->referralProgram(overrides: ['referral_reward_every' => 2, 'referral_reward_plan_id' => $plan->id]);
        $this->migrator->customers = [new ImportedCustomer('r', 3001), new ImportedCustomer('f', 3002, referrerKey: 'r', boughtBefore: true), new ImportedCustomer('g', 3003, referrerKey: 'r')];
        $this->importAll();

        $this->buy(User::query()->where('telegram_id', 3002)->sole(), $plan, $server);
        self::assertSame(0, ReferralReward::query()->count(), 'their purchase here is no first one');
        $this->buy(User::query()->where('telegram_id', 3003)->sole(), $plan, $server);

        self::assertSame(0, ReferralReward::query()->count(), 'one referral\'s first purchase counted, not two: the one who bought there is not');
    }
}
