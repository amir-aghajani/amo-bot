<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Captcha\CaptchaVerdict;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Http\RequestOrigin;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Exceptions\NoCaptchaException;
use App\Modules\Store\Models\Website;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;
use Tests\Support\AltchaWidget;
use Tests\Support\FakeTurnstile;

/**
 * The website's captcha for forms of its own — a review, a contact form —, the shop holding its secret: the site's
 * backend posts the token a form carried (POST /captcha/verify) and is answered whether it passed — for the action it
 * names, solved where —, 200 either way, the reasons' codes when not; a token is taken once. ALTCHA's widget fetches
 * its challenge from the shop (GET /captcha/challenge, fresh every time, never cached). The website asks 3000 in ten
 * minutes, whoever its visitors are, and 120 refused tokens of a visitor's network — the one the backend names, else
 * its own — in them; never the sign-ins' budget. A website that asks no captcha has nothing to judge.
 */
final class WebsiteCaptchaTest extends HttpTestCase
{
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->turnstile();
        $this->website = $this->website(['captcha_driver' => 'turnstile', 'captcha_config' => ['site_key' => '0x4AAAAAAA-site', 'secret_key' => '0x4AAAAAAA-secret']]);
    }

    public function testTheBackendAsksTheShopToJudgeATokenOfItsOwnForm(): void
    {
        $token = FakeTurnstile::passed('review');

        self::assertSame(['passed' => true, 'action' => 'review', 'hostname' => 'shop.example', 'verified_at' => '2026-10-07T12:00:00+00:00', 'reasons' => []], $this->verify($token, 'review'));
        self::assertSame(['secret' => '0x4AAAAAAA-secret', 'response' => $token], $this->turnstile()->checks[0], "the website's secret and the token — no visitor: its backend asks");

        self::assertSame([false, ['timeout-or-duplicate']], self::outcome($this->verify($token, 'review')), 'a token is taken once');
        self::assertSame([false, [CaptchaVerdict::ACTION_MISMATCH]], self::outcome($this->verify(FakeTurnstile::passed('contact'), 'review')), 'solved for another form');
        self::assertSame([false, [CaptchaVerdict::HOSTNAME_MISMATCH]], self::outcome($this->verify(FakeTurnstile::passed('review', 'evil.example'), 'review')), 'solved on another site');
        self::assertSame([true, []], self::outcome($this->verify(FakeTurnstile::passed('review', 'localhost'), 'review')), 'on an origin it lists');
        self::assertSame([true, []], self::outcome($this->verify(FakeTurnstile::passed('contact'), null)), 'no action named: any');
        self::assertSame([false, ['invalid-input-response']], self::outcome($this->verify('made-up', null)), "Cloudflare's own codes");
    }

    public function testAltchasChallengeIsTheShopsOwnAndItsSolutionPassesOnce(): void
    {
        $this->website->forceFill(['captcha_driver' => 'altcha', 'captcha_config' => null])->save();

        $response = $this->get($this->storeApi($this->website, '/captcha/challenge?action=review'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), 'never kept by a cache');
        $challenge = $this->decode($response);
        self::assertStringEndsWith('&action=review&', $challenge['salt']);

        $solution = AltchaWidget::solve($challenge);
        self::assertSame(['passed' => true, 'action' => 'review', 'hostname' => null, 'verified_at' => '2026-10-07T12:00:00+00:00', 'reasons' => []], $this->verify($solution, 'review'));
        self::assertSame([false, ['timeout-or-duplicate']], self::outcome($this->verify($solution, 'review')), 'taken once');
        self::assertSame([false, [CaptchaVerdict::ACTION_MISMATCH]], self::outcome($this->verify($this->solved(), 'review')), 'a challenge asked for no action is no review form\'s');

        $late = $this->solved();
        Carbon::setTestNow(now()->addMinutes(15));
        self::assertSame([false, ['timeout-or-duplicate']], self::outcome($this->verify($late, null)), 'a quarter of an hour on, expired');
        self::assertSame([], $this->turnstile()->checks, 'no third party asked');
    }

    public function testOnlyAWidgetThatAsksTheShopGetsAChallenge(): void
    {
        $turnstile = $this->get($this->storeApi($this->website, '/captcha/challenge'));
        self::assertSame([404, NoCaptchaException::NO_CHALLENGES], [$turnstile->getStatusCode(), $this->decode($turnstile)['message']], "Turnstile's widget draws its own");

        $this->website->forceFill(['captcha_driver' => null, 'captcha_config' => null])->save();
        self::assertSame(404, $this->get($this->storeApi($this->website, '/captcha/challenge'))->getStatusCode(), 'none asked');
        $off = $this->postJson($this->storeApi($this->website, '/captcha/verify'), ['token' => FakeTurnstile::passed(null)]);
        self::assertSame([409, NoCaptchaException::OFF], [$off->getStatusCode(), $this->decode($off)['message']], 'nothing to judge it by');
    }

    public function testTheTokenTheActionAndTheVisitorAreSaidUnderTheirFields(): void
    {
        $missing = $this->unchecked()->postJson($this->storeApi($this->website, '/captcha/verify'), ['action' => 'a review!', 'remoteip' => 'the visitor']);
        self::assertSame(['token', 'action', 'remoteip'], array_keys($this->decode($missing)['errors']));
        $challenge = $this->unchecked()->get($this->storeApi($this->website, '/captcha/challenge?action=' . str_repeat('a', 33)));
        self::assertSame(['action'], array_keys($this->decode($challenge)['errors']));
        self::assertSame([], $this->turnstile()->checks);
    }

    public function testCloudflareOutOfReachIsA503(): void
    {
        $this->turnstile()->down();

        $response = $this->postJson($this->storeApi($this->website, '/captcha/verify'), ['token' => FakeTurnstile::passed(null)]);

        self::assertSame([503, CaptchaUnavailableException::MESSAGE], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    /**
     * The backend names its visitor: Cloudflare is told who solved it, and the visitor's network — an IPv6 one by its
     * /64 — spends its own refusals: once they are spent its next token is not judged, while another visitor's is.
     */
    public function testTheVisitorTheBackendNamesSpendsTheirOwnNetworksRefusals(): void
    {
        self::assertTrue($this->verify(FakeTurnstile::passed('review'), 'review', '2001:db8:a::1')['passed']);
        self::assertSame('2001:db8:a::1', $this->turnstile()->checks[0]['remoteip'] ?? null, 'the visitor told to Cloudflare');

        $this->refusals('2001:db8:a::/64', SignInThrottle::CAPTCHA_REFUSALS - 1);
        self::assertFalse($this->verify('made-up', null, '2001:db8:a::1')['passed'], 'the last refusal its network has room for');
        $checks = count($this->turnstile()->checks);

        self::assertSame(429, $this->ask(FakeTurnstile::passed(null), '2001:db8:a::99')->getStatusCode(), 'another address of that /64: spent, the next not judged');
        self::assertCount($checks, $this->turnstile()->checks, 'Cloudflare not asked');
        self::assertTrue($this->verify(FakeTurnstile::passed(null), null, '2001:db8:b::1')['passed'], "another visitor's network is judged");

        Carbon::setTestNow(now()->addSeconds(SignInThrottle::CAPTCHA_SECONDS));
        self::assertTrue($this->verify(FakeTurnstile::passed(null), null, '2001:db8:a::1')['passed'], 'ten minutes on, room again');
    }

    public function testABackendThatNamesNoVisitorSpendsItsOwnNetworksRefusalsAndATokenThatPassedCostsNone(): void
    {
        // The tests' requests come from no address: the backend's network.
        $backend = $this->service(RequestOrigin::class)->clientNetwork((new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/'));
        $this->refusals($backend, SignInThrottle::CAPTCHA_REFUSALS - 1);

        self::assertTrue($this->verify(FakeTurnstile::passed(null), null)['passed'], 'one that passes costs no refusal');
        self::assertFalse($this->verify('made-up', null)['passed'], 'a refused one costs the last of them');
        self::assertSame(429, $this->ask(FakeTurnstile::passed(null))->getStatusCode(), 'its refusals spent: the next is not judged');
        self::assertTrue($this->verify(FakeTurnstile::passed(null), null, '198.51.100.7')['passed'], 'a visitor it names is counted apart');
    }

    /**
     * The website's own budget, whoever its visitors are: its checks in ten minutes — the backend is one address for all
     * of them —, the last one judged and the next not.
     */
    public function testTheWebsiteAsksItsChecksInTenMinutesWhoeverItsVisitors(): void
    {
        $throttle = $this->service(SignInThrottle::class);
        for ($asked = 1; $asked < SignInThrottle::CAPTCHA_CHECKS; $asked++) {
            $throttle->verifyingCaptcha($this->website->key, 'a visitor');
            $throttle->captchaNotRefused($this->website->key, 'a visitor');
        }

        self::assertTrue($this->verify(FakeTurnstile::passed(null), null, '198.51.100.7')['passed'], 'its last check');
        self::assertSame(429, $this->ask(FakeTurnstile::passed(null), '198.51.100.8')->getStatusCode(), 'whoever asks next');

        $other = CurrentBot::run($this->agentBot(), fn(): Website => $this->website(['captcha_driver' => 'altcha']));
        self::assertSame(200, $this->postJson($this->storeApi($other, '/captcha/verify'), ['token' => 'made-up'])->getStatusCode(), "an agent's website's budget is its own");
    }

    /**
     * A refused token — Turnstile's, or ALTCHA's, which asks no third party — costs only the verify budget: whatever a bot
     * sends through a site's forms, nobody behind its network is kept from signing in.
     */
    public function testRefusedTokensNeverSpendTheSignInsBudget(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/');
        $throttle = $this->service(SignInThrottle::class);
        for ($asked = 1; $asked < SignInThrottle::ISSUE_MAX; $asked++) {
            $throttle->issuing($request);
        }

        self::assertFalse($this->verify('made-up', null)['passed']);
        $this->website->forceFill(['captcha_driver' => 'altcha', 'captcha_config' => null])->save();
        self::assertFalse($this->verify('made-up', null)['passed']);

        $throttle->issuing($request);
        $this->expectException(TooManyAttemptsException::class);
        $throttle->issuing($request);
    }

    /**
     * @return array<string, mixed> What POST /captcha/verify answered, the shop's backend asking for `$token` and `$action`
     *                              — from the visitor at `$visitor`, when it names one
     */
    private function verify(string $token, ?string $action, ?string $visitor = null): array
    {
        $response = $this->ask($token, $visitor, $action);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response);
    }

    private function ask(string $token, ?string $visitor = null, ?string $action = null): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/captcha/verify'), ['token' => $token] + ($action === null ? [] : ['action' => $action]) + ($visitor === null ? [] : ['remoteip' => $visitor]));
    }

    /** `$count` tokens refused from `$network` (as RequestOrigin counts it), as the website's backend asked for them. */
    private function refusals(string $network, int $count): void
    {
        $throttle = $this->service(SignInThrottle::class);
        for ($refused = 0; $refused < $count; $refused++) {
            $throttle->verifyingCaptcha($this->website->key, $network);
        }
    }

    /** What ALTCHA's widget hands a form once it solved a challenge the shop gave it, asked for no action. */
    private function solved(): string
    {
        return AltchaWidget::solve($this->decode($this->get($this->storeApi($this->website, '/captcha/challenge'))));
    }

    /**
     * @param array<string, mixed> $verdict
     * @return array{mixed, mixed} Whether it passed, and why not
     */
    private static function outcome(array $verdict): array
    {
        return [$verdict['passed'], $verdict['reasons']];
    }
}
