<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Security\RateLimiter;
use App\Modules\Accounts\Exceptions\SignInsBusyException;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\Website;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * What a website's sign-in costs the shop before anything is tried — a nonce or a redirect's state kept, an email sent —
 * is held to the address network that asks: 120 in ten minutes (one address is many customers on a mobile network), then
 * a 429 with its wait, whichever of them it asked for. Its window closes ten minutes after it opened; another address is
 * not held up. And whoever asks, from however many addresses, a shop keeps 5000 nonces at most — and apart, 5000 states —
 * in as long as one lives: past them a 503, the log told once.
 */
final class IssuanceThrottleTest extends HttpTestCase
{
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->mail();
        $this->website = $this->website(['email_signup' => true, 'telegram_client_secret' => 'tg-secret-1']);
    }

    public function testWhatCostsTheShopIsHeldToItsCountInTenMinutesAnAddress(): void
    {
        for ($i = 1; $i <= SignInThrottle::ISSUE_MAX - 3; $i++) {
            self::assertSame(200, $this->nonce()->getStatusCode(), "nonce {$i}");
        }
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/auth/telegram/authorize'), ['redirect_uri' => 'https://shop.example/signed-in', 'code_challenge' => str_repeat('c', 43)])->getStatusCode(), "a redirect's state");
        self::assertSame(202, $this->postJson($this->storeApi($this->website, '/auth/register'), ['first_name' => 'Sara', 'email' => 'sara@example.com', 'password' => self::WEB_PASSWORD])->getStatusCode(), 'a code emailed');
        self::assertSame(202, $this->postJson($this->storeApi($this->website, '/auth/password/forgot'), ['email' => 'nobody@example.com'])->getStatusCode(), "a reset asked for, emailed or not: the answer's the same");

        $waiting = $this->nonce();
        self::assertSame(429, $waiting->getStatusCode(), 'one past ISSUE_MAX');
        self::assertSame(600, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertSame(429, $this->postJson($this->storeApi($this->website, '/auth/register'), ['first_name' => 'Ali', 'email' => 'ali@example.com', 'password' => self::WEB_PASSWORD])->getStatusCode(), 'whichever it asks for');
        self::assertSame(200, $this->from('198.51.100.9')->getStatusCode(), 'another address is not held up');

        Carbon::setTestNow(now()->addMinutes(10));
        self::assertSame(200, $this->nonce()->getStatusCode(), 'ten minutes on, its window closed');
    }

    public function testARefusalThatCostsNothingIsNotCounted(): void
    {
        for ($i = 1; $i <= SignInThrottle::ISSUE_MAX; $i++) {
            self::assertSame(422, $this->postJson($this->storeApi($this->website, '/auth/register'), ['first_name' => 'Sara', 'email' => 'sara@', 'password' => self::WEB_PASSWORD])->getStatusCode());
        }

        self::assertSame(200, $this->nonce()->getStatusCode());
    }

    public function testAShopKeepsAsManySignInsBegunAsItTakesFromEveryAddressTogether(): void
    {
        $logs = $this->logs();
        // The nonces the shop issued so far, as AuthChallenges counts them: all but its last.
        $limiter = $this->service(RateLimiter::class);
        for ($i = 1; $i < AuthChallenges::LIVE_MAX; $i++) {
            $limiter->hit('challenges|1|nonce', AuthChallenges::NONCE_SECONDS);
        }

        self::assertSame(200, $this->from('198.51.100.1')->getStatusCode(), 'its last, from wherever');
        $busy = $this->from('198.51.100.2');
        self::assertSame([503, SignInsBusyException::MESSAGE], [$busy->getStatusCode(), $this->decode($busy)['message']], 'whoever asks, from however many addresses');
        self::assertSame(503, $this->from('198.51.100.3')->getStatusCode());
        self::assertSame(1, AuthChallenge::query()->count(), 'nothing kept of the refused');
        self::assertCount(1, array_filter($logs->getRecords(), static fn(LogRecord $record): bool => str_contains($record->message, 'by the thousand')), 'the log told once');
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/auth/telegram/authorize'), ['redirect_uri' => 'https://shop.example/signed-in', 'code_challenge' => str_repeat('c', 43)])->getStatusCode(), "a redirect's states have room of their own");

        $agentsSite = CurrentBot::run($this->agentBot(), fn(): Website => $this->website());
        self::assertSame(200, $this->postJson($this->storeApi($agentsSite, '/auth/nonce'))->getStatusCode(), "another shop's room is its own");

        Carbon::setTestNow(now()->addSeconds(AuthChallenges::NONCE_SECONDS));
        self::assertSame(200, $this->from('198.51.100.4')->getStatusCode(), 'as long as a nonce lives on, room again');
    }

    private function nonce(): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/nonce'));
    }

    /** A nonce asked for from another address — straight into the app: the description has nothing to say of where a request came from. */
    private function from(string $address): ResponseInterface
    {
        return $this->app()->http()->handle((new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . $this->storeApi($this->website, '/auth/nonce'), ['REMOTE_ADDR' => $address]));
    }
}
