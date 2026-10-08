<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Tests\HttpTestCase;

/**
 * A customer's part in the referral program on the shop's website, as the bot's «👥 زیرمجموعه‌گیری» has it: the
 * program's terms, their invite code — made the first time it is asked, and theirs from then on, the program on or not —
 * with their bot's link, and what the customers it brought earned them.
 */
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
            'first_only' => true,
            'code' => $code,
            'bot_link' => "https://t.me/amo_shop_bot?start=ref_{$code}",
            'invited' => 2,
            'earned' => '18000.00',
        ], $referral, '15% of the 120,000 their friend paid');
        self::assertSame($code, $this->decode($this->get($this->storeApi($this->website, '/referral')))['code'], 'the same code on every ask');
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
