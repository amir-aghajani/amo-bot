<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Tests\HttpTestCase;

/** A customer's referral page: the program's terms, their invite code and bot link, and what their referrals earned. */
final class StoreReferralTest extends HttpTestCase
{
    private Website $website;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->withoutQr();
        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->website = $this->website();
        $this->customer = $this->customer(['username' => 'ali']);
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheirCodeIsMadeOnFirstAskAndWhatItBroughtThemIsTheirs(): void
    {
        $this->referralProgram(firstOnly: true, rate: 15);
        $server = $this->sellingServer();
        $plan = $this->plan([], $server);
        $friend = $this->customer(['telegram_id' => 7272, 'referred_by' => $this->customer->id]);
        $this->customer(['telegram_id' => 7373, 'referred_by' => $this->customer->id]);
        $this->customer(['telegram_id' => 7474]);
        $this->paidByCard($this->purchaseOrder($friend, $plan, $server));

        $referral = $this->decode($this->get($this->storeApi($this->website, '/referral')));

        $code = $this->customer->refresh()->referral_code ?? self::fail('No code was made.');
        self::assertSame([
            'enabled' => true,
            'rate' => 15,
            'renewal_rate' => 15,
            'first_only' => true,
            'start_gift' => null,
            'reward' => null,
            'code' => $code,
            'bot_link' => "https://t.me/amo_shop_bot?start=ref_{$code}",
            'invited' => 2,
            'earned' => '18000.00',
            'rewards' => 0,
        ], $referral, '15% of the 120,000 their friend paid');
        self::assertSame($code, $this->decode($this->get($this->storeApi($this->website, '/referral')))['code'], 'the same code on every ask');
    }

    public function testTheTermsSayTheRenewalRateTheStartGiftAndThePlanRewardAndWhatTheirsBroughtThem(): void
    {
        $server = $this->sellingServer();
        $prize = $this->plan(['name' => 'جایزه'], $server);
        $this->referralProgram(overrides: [
            'referral_renewal_rate' => 4,
            'referral_start_invitee' => 10_000,
            'referral_start_when' => 'join',
            'referral_reward_every' => 1,
            'referral_reward_plan_id' => $prize->id,
        ]);
        $friend = $this->customer(['telegram_id' => 7272, 'referred_by' => $this->customer->id]);
        $this->buy($friend, $this->plan([], $server), $server);

        $referral = $this->decode($this->get($this->storeApi($this->website, '/referral')));

        self::assertSame([4, ['referrer' => '0.00', 'invitee' => '10000.00', 'trigger' => 'join'], ['every' => 1, 'plan' => ['id' => $prize->id, 'name' => 'جایزه']], 1], [$referral['renewal_rate'], $referral['start_gift'], $referral['reward'], $referral['rewards']], 'a service given them for their friend\'s first purchase');
    }

    public function testWithTheProgramOffTheirCodeStillStands(): void
    {
        $this->referralProgram(enabled: false);
        $this->config(['telegram.username' => '']);

        $referral = $this->decode($this->get($this->storeApi($this->website, '/referral')));

        self::assertSame([false, null, 0, '0.00'], [$referral['enabled'], $referral['bot_link'], $referral['invited'], $referral['earned']], "no link while the bot's @username is not known");
        self::assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $referral['code']);
        self::assertSame($this->customer->refresh()->referral_code, $referral['code']);
    }

    public function testAnAgentsShopsLinkIsTheirBots(): void
    {
        $bot = $this->agentBot();
        [$website, $token] = CurrentBot::run($bot, function (): array {
            $this->referralProgram();

            return [$this->website(), $this->customerSession($this->customer(['telegram_id' => 8181]))];
        });
        $this->bearer($token);

        $referral = $this->decode($this->get($this->storeApi($website, '/referral')));

        self::assertSame("https://t.me/agent_shop_bot?start=ref_{$referral['code']}", $referral['bot_link']);
        self::assertTrue($referral['enabled'], "the agent's shop's own program");
    }
}
