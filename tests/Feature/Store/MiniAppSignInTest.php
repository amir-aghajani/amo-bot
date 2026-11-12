<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Services\MiniAppSignIn;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * Signing in on the mini app by the launch data Telegram hands it: the bot's customer or a newcomer registered as the
 * bot registers one, banned customers, data another bot signed or too old (401) counted by the address's throttle, only
 * through the mini app's key — and a session proven when the app opened, so an old launch signs in but asks a way in
 * proven again before what a recent sign-in guards.
 */
final class MiniAppSignInTest extends HttpTestCase
{
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

    private MiniApp $miniApp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->telegram();
        $this->miniApp = $this->miniApp();
    }

    public function testABotCustomerSignsInAndKeepsTheirAccount(): void
    {
        $customer = $this->wallet($this->customer(['first_name' => 'Old', 'username' => 'old_handle']), '50000');
        $madeAt = now()->subMinutes(2);

        $response = $this->send('POST', $this->storeApi($this->miniApp, '/auth/mini-app'), ['init_data' => $this->launchData(madeAt: $madeAt)], ['User-Agent' => self::ANDROID]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $answer = $this->decode($response);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $answer['token']);
        self::assertSame([$customer->id, 'Ali', 'Rezaei', ['id' => self::TELEGRAM_ID, 'username' => 'ali'], '50000.00'], [
            $answer['customer']['id'],
            $answer['customer']['first_name'],
            $answer['customer']['last_name'],
            $answer['customer']['telegram'],
            $answer['customer']['balance'],
        ], "the bot's customer, their profile as Telegram has it now");
        self::assertSame(1, User::query()->count(), 'nobody new');
        $session = CustomerSession::query()->sole();
        self::assertSame([SignInMethod::MiniApp, 'Chrome در Android'], [$session->method, $session->device]);
        self::assertSame($madeAt->getTimestamp(), $session->authenticated_at?->getTimestamp(), 'proven when the app opened, not when it reached the shop');
        self::assertNull($session->biometric_hash);

        $this->bearer($answer['token']);
        $me = $this->decode($this->get($this->storeApi($this->miniApp, '/me')));
        self::assertSame($answer['customer'], $me['customer']);
        self::assertSame(['method' => 'mini_app', 'biometric' => false, 'recent_until' => $madeAt->copy()->addSeconds(CustomerSessions::RECENT_SECONDS)->toIso8601String()], $me['session']);
        self::assertNull($me['staff'], 'the mini app has no admin API');
    }

    public function testANewcomerIsRegisteredAsTheBotRegistersOneTheirReferralCodeHonouredOnce(): void
    {
        $this->reportGroup();
        $this->referralProgram();
        $referrer = $this->customer(['telegram_id' => 7070, 'first_name' => 'Owner']);
        $code = $this->service(ReferralService::class)->codeFor($referrer);

        $response = $this->signIn($this->launchData(8080, startParam: "ref_{$code}"), ['referral_code' => strtoupper($code)]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $newcomer = User::query()->where('telegram_id', 8080)->sole();
        self::assertSame(['Ali', 'Rezaei', 'ali', $referrer->id, UserRole::Customer], [$newcomer->first_name, $newcomer->last_name, $newcomer->username, $newcomer->referred_by, $newcomer->role]);
        self::assertSame($newcomer->id, $this->decode($response)['customer']['id']);
        $reports = ReportMessage::query()->where('topic', Topic::Users)->pluck('text')->all();
        self::assertCount(1, $reports, 'reported to the admins');
        self::assertStringContainsString('<code>7070</code>', $reports[0], 'with whoever brought them');
        self::assertSame([self::text(BotText::ReferralJoined)], $this->telegram()->sentTo(7070), 'who is told');

        // Signing in again changes none of it: they are the shop's customer now.
        $other = $this->customer(['telegram_id' => 7171]);
        self::assertSame(200, $this->signIn($this->launchData(8080), ['referral_code' => $this->service(ReferralService::class)->codeFor($other)])->getStatusCode());
        self::assertSame($referrer->id, $newcomer->refresh()->referred_by);
        self::assertSame(1, ReportMessage::query()->where('topic', Topic::Users)->count());
        self::assertSame(2, CustomerSession::query()->where('user_id', $newcomer->id)->count(), 'a session each time');
    }

    public function testABannedCustomerIsRefusedAndNoSessionOpens(): void
    {
        $this->customer(['status' => UserStatus::Banned]);

        $response = $this->signIn($this->launchData());

        self::assertSame([403, SignInRefusedException::BANNED], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testLaunchDataThatDoesNotHoldSignsNobodyIn(): void
    {
        foreach ([
            'another bot\'s' => $this->launchData(token: FakeTelegram::AGENT_TOKEN),
            'made over a day ago' => $this->launchData(madeAt: now()->subSeconds(MiniAppSignIn::MAX_AGE_SECONDS + 1)),
            'tampered with' => str_replace('%22id%22%3A5151', '%22id%22%3A5152', $this->launchData()),
        ] as $case => $initData) {
            $response = $this->signIn($initData);

            self::assertSame([401, SignInRefusedException::LAUNCH_REFUSED], [$response->getStatusCode(), $this->decode($response)['message']], $case);
        }
        self::assertSame(0, CustomerSession::query()->count());
        self::assertSame(0, User::query()->count());

        self::assertSame(200, $this->signIn($this->launchData(madeAt: now()->subSeconds(MiniAppSignIn::MAX_AGE_SECONDS)))->getStatusCode(), 'a day old: it still signs in');
    }

    public function testWhatTheSignInNeedsIsSaidUnderItsField(): void
    {
        $nothing = $this->unchecked()->postJson($this->storeApi($this->miniApp, '/auth/mini-app'), ['referral_code' => 'abc']);

        self::assertSame(422, $nothing->getStatusCode());
        self::assertArrayHasKey('init_data', $this->decode($nothing)['errors']);
    }

    public function testAnAddressWhoseSignInsKeepFailingWaits(): void
    {
        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            self::assertSame(401, $this->signIn($this->launchData(token: FakeTelegram::AGENT_TOKEN))->getStatusCode(), "failure {$i}");
        }

        $waiting = $this->signIn($this->launchData());

        self::assertSame(429, $waiting->getStatusCode(), 'even good launch data waits now');
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testAWebsitesKeyHasNoSuchWayIn(): void
    {
        $website = $this->website();

        $response = $this->postJson($this->storeApi($website, '/auth/mini-app'), ['init_data' => $this->launchData()]);

        self::assertSame([404, ErrorHandler::MESSAGES[404]], [$response->getStatusCode(), $this->decode($response)['message']], "the router's own 404, as if it were not there");
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testAnOldLaunchSignsInButAsksAWayInProvenAgainBeforeWhatARecentSignInGuards(): void
    {
        $this->customer();
        $old = $this->signIn($this->launchData(madeAt: now()->subSeconds(CustomerSessions::RECENT_SECONDS + 1)));
        self::assertSame(200, $old->getStatusCode());
        $this->bearer($this->decode($old)['token']);

        self::assertNull($this->decode($this->get($this->storeApi($this->miniApp, '/me')))['session']['recent_until']);
        $refused = $this->putJson($this->storeApi($this->miniApp, '/me/biometric'));
        self::assertSame([403, RecentSignInMiddleware::SIGN_IN_AGAIN], [$refused->getStatusCode(), $this->decode($refused)['message']]);
        self::assertSame(RecentSignInMiddleware::CHALLENGE, $refused->getHeaderLine('WWW-Authenticate'));

        $fresh = $this->signIn($this->launchData(madeAt: now()->subMinute()));
        $this->bearer($this->decode($fresh)['token']);
        self::assertSame(200, $this->putJson($this->storeApi($this->miniApp, '/me/biometric'))->getStatusCode(), 'a launch of the last quarter of an hour is a recent sign-in');
    }

    public function testAnAgentsMiniAppSignsInByTheLaunchDataTheirBotSigns(): void
    {
        $bot = $this->agentBot();
        $miniApp = CurrentBot::run($bot, fn(): MiniApp => $this->miniApp());

        $mains = $this->postJson($this->storeApi($miniApp, '/auth/mini-app'), ['init_data' => $this->launchData(9001)]);
        self::assertSame([401, SignInRefusedException::LAUNCH_REFUSED], [$mains->getStatusCode(), $this->decode($mains)['message']], "the main bot's launch is no launch of the agent's bot");

        $response = $this->postJson($this->storeApi($miniApp, '/auth/mini-app'), ['init_data' => $this->launchData(9001, token: FakeTelegram::AGENT_TOKEN)]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $customer = CurrentBot::run($bot, static fn(): User => User::query()->where('telegram_id', 9001)->sole());
        self::assertSame($customer->id, $this->decode($response)['customer']['id'], "a customer of the agent's shop");
        self::assertSame(0, User::query()->where('telegram_id', 9001)->count(), "none of the main shop's");

        $agent = $this->postJson($this->storeApi($miniApp, '/auth/mini-app'), ['init_data' => $this->launchData(self::AGENT_TELEGRAM_ID, token: FakeTelegram::AGENT_TOKEN)]);
        self::assertSame(200, $agent->getStatusCode());
        self::assertSame(UserRole::Admin, CurrentBot::run($bot, static fn(): User => User::query()->where('telegram_id', self::AGENT_TELEGRAM_ID)->sole())->role, "the agent, the admin of their own bot's shop");
    }

    /**
     * POST /auth/mini-app with `$initData`, and `$more` beside it.
     *
     * @param array<string, string> $more
     * @phpstan-impure
     */
    private function signIn(string $initData, array $more = []): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->miniApp, '/auth/mini-app'), ['init_data' => $initData] + $more);
    }
}
