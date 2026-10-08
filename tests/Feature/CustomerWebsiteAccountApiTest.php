<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Password;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * A customer's account on the shop's website, as support reads it on their page in either panel (GET /users/{id}'s
 * `account`): its ways in, whether its password sign-in asks a second step, the devices signed in, and the accounts
 * merged into it — and what support does to it: two-factor sign-in turned off for a customer who lost their phone (told
 * in Telegram, logged with who did it), every device signed out (logged).
 */
final class CustomerWebsiteAccountApiTest extends HttpTestCase
{
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->website = $this->website();
    }

    public function testTheirPageSaysTheirWaysInTheirDevicesAndTheAccountsMergedIntoIt(): void
    {
        $this->loginAsAdmin();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $sara = $this->webCustomer(['google_sub' => 'sara-google']);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $ali = $this->customer(['username' => 'ali', 'first_name' => 'Ali', 'last_name' => 'Rezaei']);
        $merger = $this->service(AccountMerger::class);
        $merger->merge($ali, $sara, AccountMerger::BY_CUSTOMER);
        Carbon::setTestNow('2026-10-02 10:00:00');
        $old = $this->customer(['telegram_id' => null, 'first_name' => 'Old']);
        $merger->merge($sara, $old, AccountMerger::BY_CUSTOMER);
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->twoFactorOn($this->website, $sara->refresh());
        $this->customerSession($sara);
        $this->customerSession($sara);
        $this->customerSession($sara, ['expires_at' => now()->subMinute()]);

        $account = $this->decode($this->get("/api/admin/users/{$sara->id}"))['account'];

        self::assertSame(['email' => self::WEB_EMAIL, 'google' => true, 'has_password' => true, 'two_factor' => true, 'sessions' => 2], array_diff_key($account, ['merges' => true]), 'the sessions that have not ended');
        self::assertSame([
            ['merged_user_id' => $old->id, 'merged' => ['telegram_id' => null, 'username' => null, 'email' => null, 'google' => false, 'name' => 'Old'], 'created_at' => '2026-10-02T10:00:00+00:00'],
            ['merged_user_id' => $ali->id, 'merged' => ['telegram_id' => self::TELEGRAM_ID, 'username' => 'ali', 'email' => null, 'google' => false, 'name' => 'Ali Rezaei'], 'created_at' => '2026-10-01T10:00:00+00:00'],
        ], $account['merges'], 'newest first');

        $other = $this->decode($this->get("/api/admin/users/{$this->customer(['telegram_id' => 4040])->id}"))['account'];
        self::assertSame(['email' => null, 'google' => false, 'has_password' => false, 'two_factor' => false, 'sessions' => 0, 'merges' => []], $other);
    }

    public function testAnAgentReadsTheMergesOfTheirOwnCustomer(): void
    {
        $bot = $this->agentBot();
        [$theirs, $merged] = CurrentBot::run($bot, function (): array {
            $sara = $this->webCustomer();
            $old = $this->webCustomer(['email' => null, 'password_hash' => null, 'google_sub' => 'old-google']);
            $this->service(AccountMerger::class)->merge($sara, $old, AccountMerger::BY_CUSTOMER);

            return [$sara, $old];
        });

        $this->loginAsAgent($bot);

        $merges = $this->decode($this->get("/api/agent/users/{$theirs->id}"))['account']['merges'];
        self::assertSame([[$merged->id, true]], array_map(static fn(array $merge): array => [$merge['merged_user_id'], $merge['merged']['google']], $merges));
    }

    public function testSupportTurnsTwoFactorOffForACustomerWhoLostTheirPhone(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();
        $ali = $this->customer(['email' => 'ali@example.com', 'password_hash' => Password::hash('ali-secret-1')]);
        $this->twoFactorOn($this->website, $ali);

        $response = $this->postJson("/api/admin/users/{$ali->id}/two-factor/disable");

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFalse($this->decode($response)['account']['two_factor']);
        self::assertFalse($ali->refresh()->hasTwoFactor());
        self::assertSame([self::text(BotText::TwoFactorTurnedOn), self::text(BotText::TwoFactorDisabled)], $this->telegram()->sentTo(self::TELEGRAM_ID), 'told it went on, then that support turned it off — in Telegram');
        self::assertTrue($logs->hasInfoThatContains('turned two-factor sign-in off'));
        self::assertSame([422, AccountRefusedException::TWO_FACTOR_OFF], [($again = $this->postJson("/api/admin/users/{$ali->id}/two-factor/disable"))->getStatusCode(), $this->decode($again)['message']]);
    }

    public function testAnAgentTurnsItOffForTheirOwnCustomerTheirBotTellingThem(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, function (): User {
            $sara = $this->customer(['telegram_id' => 8080, 'email' => 'sara@example.com', 'password_hash' => Password::hash('sara-secret-1')]);
            $this->twoFactorOn($this->website(), $sara);

            return $sara;
        });
        $main = $this->customer(['telegram_id' => 8181]);

        $this->loginAsAgent($bot);
        $this->telegram()->reset();

        self::assertSame(200, $this->postJson("/api/agent/users/{$theirs->id}/two-factor/disable")->getStatusCode());
        self::assertSame([['sendMessage'], FakeTelegram::AGENT_TOKEN], [$this->telegram()->calls(), $this->telegram()->tokenOf(0)], "told by the agent's bot");
        self::assertSame(404, $this->postJson("/api/agent/users/{$main->id}/two-factor/disable")->getStatusCode(), "another shop's customer");
    }

    public function testSupportSignsTheCustomerOutOfTheWebsiteEverywhere(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();
        $sara = $this->webCustomer();
        $first = $this->customerSession($sara);
        $this->customerSession($sara);
        $leila = $this->customerSession($this->webCustomer(['email' => 'leila@example.com']));

        $response = $this->postJson("/api/admin/users/{$sara->id}/sessions/end");

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, CustomerSession::query()->where('user_id', $sara->id)->count());
        self::assertSame(0, $this->decode($this->get("/api/admin/users/{$sara->id}"))['account']['sessions']);
        $this->bearer($first);
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
        $this->bearer($leila);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), "another customer's stay");
        self::assertTrue($logs->hasInfoThatContains('out of the website on every device'));
    }
}
