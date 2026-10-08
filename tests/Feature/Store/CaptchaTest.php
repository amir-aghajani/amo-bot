<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Captcha\Verifier;
use App\Modules\Accounts\Enums\CaptchaAction;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Store\Models\Website;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;
use Tests\Support\AltchaWidget;
use Tests\Support\FakeTurnstile;

/**
 * The captcha a website asks of what takes a password — a sign-up, a sign-in, a password reset —, by the driver its
 * owner chose: the widget's token (`captcha`), solved for the form's action, judged once the form's own fields passed —
 * Cloudflare Turnstile by Cloudflare (the website's secret, the token, the visitor), ALTCHA by the shop itself, its
 * challenge the shop's own. A token that is none, solved for another form or on another site, or taken before, is a 422
 * on `captcha`; Cloudflare out of reach a 503. A refused token costs the address network that sent it what an email
 * does — once that is spent, the next is refused unjudged —, a passing one nothing; a website that asks none asks
 * nothing.
 */
final class CaptchaTest extends HttpTestCase
{
    private const SECRET = '0x4AAAAAAA-turnstile-secret';

    /** Each form a captcha guards: its address, what it answers once it passes, and the action its widget names. */
    private const FORMS = [
        '/auth/register' => [202, CaptchaAction::SignUp],
        '/auth/login' => [200, CaptchaAction::SignIn],
        '/auth/password/forgot' => [202, CaptchaAction::PasswordReset],
    ];

    private Website $website;

    private RecordingMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mail = $this->mail();
        $this->website = $this->website(['email_signup' => true]);
        $this->webCustomer();
    }

    public function testTurnstileGuardsWhatTakesAPasswordEachFormForItsAction(): void
    {
        $this->asks('turnstile');

        foreach (self::FORMS as $path => [$status, $action]) {
            self::assertSame([422, ['captcha' => [Verifier::REFUSED]]], $this->refused($this->post($path, null)), "{$path} without a token");
            $other = $action === CaptchaAction::SignIn ? CaptchaAction::SignUp : CaptchaAction::SignIn;
            self::assertSame([422, ['captcha' => [Verifier::REFUSED]]], $this->refused($this->post($path, FakeTurnstile::passed($other->value))), "{$path} with a token solved for another form");

            $passed = $this->post($path, FakeTurnstile::passed($action->value));
            self::assertSame($status, $passed->getStatusCode(), "{$path}: " . $passed->getBody());
        }

        self::assertCount(6, $this->turnstile()->checks, 'a form without a token never reaches Cloudflare');
        self::assertSame(['secret', 'response'], array_keys($this->turnstile()->checks[0]), "the website's secret and the widget's token");
        self::assertSame(self::SECRET, $this->turnstile()->checks[0]['secret']);
        self::assertCount(2, $this->mail->sent(), 'what a sign-up and a reset send went out once each');
    }

    public function testATokenSolvedOnAnotherSiteOrTakenBeforeDoesNotPass(): void
    {
        $this->asks('turnstile');

        self::assertSame(422, $this->post('/auth/register', FakeTurnstile::passed('sign_up', 'evil.example'))->getStatusCode(), 'solved on a page of another site');

        $token = FakeTurnstile::passed('sign_up');
        self::assertSame(202, $this->post('/auth/register', $token)->getStatusCode());
        self::assertSame(422, $this->post('/auth/register', $token)->getStatusCode(), 'Cloudflare takes a token once');
        self::assertCount(1, $this->mail->sent());
    }

    public function testCloudflareOutOfReachIsA503(): void
    {
        $this->asks('turnstile');
        $this->turnstile()->down();
        $logs = $this->logs();

        $response = $this->post('/auth/password/forgot', FakeTurnstile::passed('password_reset'));

        self::assertSame([503, CaptchaUnavailableException::MESSAGE], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertTrue($logs->hasWarningThatContains('Turnstile could not check'));
        self::assertSame([], $this->mail->sent());
    }

    public function testAltchaGuardsThemWithAChallengeOfTheShopsOwn(): void
    {
        $this->asks('altcha');

        foreach (self::FORMS as $path => [$status, $action]) {
            self::assertSame([422, ['captcha' => [Verifier::REFUSED]]], $this->refused($this->post($path, null)), "{$path} without a solution");
            $other = $action === CaptchaAction::SignIn ? CaptchaAction::SignUp : CaptchaAction::SignIn;
            self::assertSame(422, $this->post($path, $this->solved($other))->getStatusCode(), "{$path} with a challenge asked for another form");

            $solution = $this->solved($action);
            self::assertSame($status, $this->post($path, $solution)->getStatusCode(), $path);
            self::assertSame(422, $this->post($path, $solution)->getStatusCode(), "{$path}: a solution passes once");
        }
        self::assertSame([], $this->turnstile()->checks, 'no third party asked');
    }

    public function testARefusedTokenCostsTheNetworkWhatAnEmailDoesAndAPassingOneNothing(): void
    {
        $this->asks('turnstile');
        // The tests' requests come from no address: the network asked all but the last of what it may cost the shop.
        $network = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/');
        for ($asked = 1; $asked < SignInThrottle::ISSUE_MAX; $asked++) {
            $this->service(SignInThrottle::class)->issuing($network);
        }

        self::assertSame(200, $this->post('/auth/login', FakeTurnstile::passed('sign_in'))->getStatusCode(), 'a token that passes costs nothing…');
        self::assertSame(200, $this->post('/auth/login', FakeTurnstile::passed('sign_in'))->getStatusCode(), '…however many');
        self::assertSame(422, $this->post('/auth/login', 'made-up-token')->getStatusCode(), 'a refused one costs the last of it');
        $asked = count($this->turnstile()->checks);

        $waits = $this->post('/auth/login', FakeTurnstile::passed('sign_in'));

        self::assertSame(429, $waits->getStatusCode(), 'the network spent what it may cost the shop');
        self::assertCount($asked, $this->turnstile()->checks, 'refused unjudged: Cloudflare not asked');
        self::assertSame(429, $this->postJson($this->storeApi($this->website, '/auth/nonce'))->getStatusCode(), 'nor does it get a nonce');
    }

    public function testAWebsiteThatAsksNoCaptchaAsksNothing(): void
    {
        self::assertSame(202, $this->post('/auth/register', null)->getStatusCode());
        self::assertSame([], $this->turnstile()->checks, 'Cloudflare is not asked');
    }

    /** The website asks the captcha of `$driver`: Turnstile under its keys, ALTCHA under none. */
    private function asks(string $driver): void
    {
        $this->turnstile();
        $this->website->forceFill(['captcha_driver' => $driver, 'captcha_config' => $driver === 'turnstile' ? ['site_key' => '0x4AAAAAAA-site-key', 'secret_key' => self::SECRET] : null])->save();
    }

    /** What ALTCHA's widget hands a form once it solved a challenge the shop gave it for `$action`. */
    private function solved(CaptchaAction $action): string
    {
        $challenge = $this->get($this->storeApi($this->website, '/captcha/challenge?action=' . $action->value));

        return AltchaWidget::solve($this->decode($challenge));
    }

    /** A sign-up, a sign-in or a forgotten password of the fixtures' web customer — with the widget's token, or none. */
    private function post(string $path, ?string $captcha): ResponseInterface
    {
        $body = match ($path) {
            '/auth/register' => ['first_name' => 'Sara', 'email' => 'new' . bin2hex(random_bytes(3)) . '@example.com', 'password' => self::WEB_PASSWORD],
            '/auth/login' => ['email' => self::WEB_EMAIL, 'password' => self::WEB_PASSWORD],
            default => ['email' => self::WEB_EMAIL],
        };

        return $this->postJson($this->storeApi($this->website, $path), $body + ($captcha === null ? [] : ['captcha' => $captcha]));
    }

    /** @return array{int, mixed} The status, and what it said under its fields */
    private function refused(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['errors'] ?? null];
    }
}
