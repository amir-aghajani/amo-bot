<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\TelegramSignIn;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegramLogin;

/**
 * Signing in on the shop's website with Telegram's redirect (authorization code + PKCE), bound to the browser that began
 * it: the site makes the PKCE verifier, keeps it, and sends the shop only its challenge; the shop hands the site
 * Telegram's authorization address — the site's Client ID, its return address on one of its origins, the challenge, a
 * one-time state keeping the challenge, the address and a nonce —, Telegram sends the browser back with a code, which the
 * site posts with the state and its verifier; the shop holds the verifier to the challenge — a code and state brought
 * back to another browser come without it and open nothing —, exchanges the code at Telegram's token endpoint with the
 * site's client secret (HTTP Basic) and that verifier, and checks the id_token it gets as the popup's is checked, its
 * nonce the one kept. The state is spent once; a code Telegram refuses signs nobody in; Telegram out of reach is a 502.
 * Only a website that keeps a client secret has the flow.
 */
final class TelegramRedirectSignInTest extends HttpTestCase
{
    private const SECRET = 'tg-client-secret-1';

    private const RETURN = 'https://shop.example/auth/telegram?next=%2Fdashboard';

    /** The PKCE verifier the site made for the sign-in and keeps in the browser. */
    private const VERIFIER = 'the-sites-own-pkce-verifier.kept~in_this-browser-0123456789';

    /** Another browser's verifier: the one a crafted link's victim holds, if any. */
    private const ANOTHER_VERIFIER = 'another-browsers-pkce-verifier.made~for_its-own-sign-in-9876';

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->telegramLogin();
        $this->website = $this->website(['telegram_client_secret' => self::SECRET]);
    }

    public function testTheAuthorizationAddressCarriesTheSitesIdItsReturnAndItsChallenge(): void
    {
        $address = $this->authorize();

        self::assertSame('https://oauth.telegram.org/auth', strtok($address['url'], '?'));
        self::assertSame(self::WEBSITE_CLIENT_ID, $address['client_id']);
        self::assertSame(self::RETURN, $address['redirect_uri']);
        self::assertSame('code', $address['response_type']);
        self::assertSame(TelegramSignIn::SCOPE, $address['scope']);
        self::assertSame('openid profile telegram:bot_access', $address['scope']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $address['state']);
        self::assertSame(self::challenge(self::VERIFIER), $address['code_challenge'], "the site's own challenge");
        self::assertSame('S256', $address['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $address['nonce']);

        $kept = AuthChallenge::query()->sole();
        self::assertSame(ChallengePurpose::OidcState, $kept->purpose);
        self::assertSame(hash('sha256', $address['state']), $kept->secret_hash, 'the state itself is kept nowhere');
        self::assertStringNotContainsString('shop.example', (string) $this->db()->table('auth_challenges')->value('payload'), 'what it keeps, encrypted');
        self::assertEquals(now()->addMinutes(10), $kept->expires_at);
    }

    public function testTheChallengeIsTheSitesToMake(): void
    {
        foreach ([null, '', 'plain', str_repeat('a', 42), str_repeat('a', 44), str_repeat('+', 43)] as $challenge) {
            $body = ['redirect_uri' => self::RETURN] + ($challenge === null ? [] : ['code_challenge' => $challenge]);
            $response = $this->unchecked()->postJson($this->storeApi($this->website, '/auth/telegram/authorize'), $body);

            self::assertSame(422, $response->getStatusCode(), (string) $challenge);
            self::assertSame(['code_challenge'], array_keys($this->decode($response)['errors']), (string) $challenge);
        }
        self::assertSame(0, AuthChallenge::query()->count(), 'no state kept');
    }

    public function testTheReturnAddressIsOnTheWebsitesOrigins(): void
    {
        foreach (['https://evil.example/auth', 'https://shop.example.evil.example/auth', 'javascript:alert(1)', '/auth/telegram', 'https://shop.example/auth#done', ''] as $address) {
            $response = $this->postJson($this->storeApi($this->website, '/auth/telegram/authorize'), ['redirect_uri' => $address, 'code_challenge' => self::challenge(self::VERIFIER)]);

            self::assertSame(422, $response->getStatusCode(), $address);
            self::assertArrayHasKey('redirect_uri', $this->decode($response)['errors'], $address);
        }

        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/auth/telegram/authorize'), ['redirect_uri' => 'http://localhost:3000/auth/telegram', 'code_challenge' => self::challenge(self::VERIFIER)])->getStatusCode(), 'an origin it lists');
    }

    public function testWithoutAClientSecretOrWithTelegramSignInOffThereIsNoRedirect(): void
    {
        $address = $this->authorize();
        $this->website->forceFill(['telegram_client_secret' => null])->save();

        $authorize = $this->postJson($this->storeApi($this->website, '/auth/telegram/authorize'), ['redirect_uri' => self::RETURN, 'code_challenge' => self::challenge(self::VERIFIER)]);
        self::assertSame([422, SignInRefusedException::REDIRECT_OFF], [$authorize->getStatusCode(), $this->decode($authorize)['message']]);
        $code = $this->signIn('a-code', $address['state']);
        self::assertSame([422, SignInRefusedException::REDIRECT_OFF], [$code->getStatusCode(), $this->decode($code)['message']]);

        $this->website->forceFill(['telegram_client_secret' => self::SECRET, 'telegram_login' => false])->save();
        $off = $this->postJson($this->storeApi($this->website, '/auth/telegram/authorize'), ['redirect_uri' => self::RETURN, 'code_challenge' => self::challenge(self::VERIFIER)]);
        self::assertSame([422, SignInRefusedException::TELEGRAM_OFF], [$off->getStatusCode(), $this->decode($off)['message']]);
        self::assertSame([], $this->telegramLogin()->calls());
    }

    public function testTheCodeIsExchangedWithTheSecretAndTheSitesVerifierAndTheStateIsSpentOnce(): void
    {
        $address = $this->authorize();
        $this->telegramLogin()->answerCode(self::token($address['nonce']));

        $response = $this->signIn('the-code', $address['state']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(self::TELEGRAM_ID, $this->decode($response)['customer']['telegram']['id']);
        self::assertSame(1, CustomerSession::query()->count());

        $exchange = $this->telegramLogin()->history[0]['request'];
        self::assertSame('POST https://oauth.telegram.org/token', $this->telegramLogin()->calls()[0]);
        self::assertSame('Basic ' . base64_encode(self::WEBSITE_CLIENT_ID . ':' . self::SECRET), $exchange->getHeaderLine('Authorization'));
        self::assertStringStartsWith('application/x-www-form-urlencoded', $exchange->getHeaderLine('Content-Type'));
        parse_str((string) $exchange->getBody(), $form);
        self::assertSame(['grant_type', 'code', 'redirect_uri', 'client_id', 'code_verifier'], array_keys($form));
        self::assertSame(['authorization_code', 'the-code', self::RETURN, self::WEBSITE_CLIENT_ID, self::VERIFIER], [$form['grant_type'], $form['code'], $form['redirect_uri'], $form['client_id'], $form['code_verifier']], "the site's verifier, which the challenge was made of");

        $again = $this->signIn('the-code', $address['state']);
        self::assertSame([401, SignInRefusedException::SIGN_IN_SPENT], [$again->getStatusCode(), $this->decode($again)['message']]);
        self::assertCount(2, $this->telegramLogin()->calls(), 'the spent state sends nothing to Telegram: the code and its keys, once');
    }

    public function testACodeAndStateBroughtBackToAnotherBrowserOpenNothing(): void
    {
        // Someone begins the sign-in in their browser, approves it with their own Telegram account and hands a victim the
        // site's return address with their code and state: the victim's browser has no verifier of that sign-in.
        $address = $this->authorize();
        $this->telegramLogin()->answerCode(self::token($address['nonce']));

        self::assertRefused($this->signIn('the-code', $address['state'], self::ANOTHER_VERIFIER), SignInRefusedException::SIGN_IN_SPENT);
        self::assertSame([], $this->telegramLogin()->calls(), 'the code is not even exchanged');
        self::assertSame(0, CustomerSession::query()->count());
        self::assertRefused($this->signIn('the-code', $address['state']), SignInRefusedException::SIGN_IN_SPENT, 'the state is spent: no second guess at the verifier');
    }

    public function testWithoutTheVerifierItIsSaidUnderItsField(): void
    {
        $address = $this->authorize();

        foreach ([['code' => 'the-code', 'state' => $address['state']], ['code' => 'the-code', 'state' => $address['state'], 'code_verifier' => 'too-short']] as $body) {
            $response = $this->unchecked()->postJson($this->storeApi($this->website, '/auth/telegram'), $body);

            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['code_verifier'], array_keys($this->decode($response)['errors']));
        }
        self::assertSame(200, $this->signIn('the-code', $address['state'], answer: $address['nonce'])->getStatusCode(), 'the state was not spent');
    }

    public function testAStateExpiresAfterTenMinutes(): void
    {
        $address = $this->authorize();
        Carbon::setTestNow(now()->addMinutes(11));

        self::assertRefused($this->signIn('the-code', $address['state']), SignInRefusedException::SIGN_IN_SPENT);
        self::assertSame([], $this->telegramLogin()->calls());
    }

    public function testACodeTelegramRefusesSignsNobodyIn(): void
    {
        $address = $this->authorize();
        $this->telegramLogin()->refuseCode();
        $logs = $this->logs();

        self::assertRefused($this->signIn('spent-code', $address['state']), SignInRefusedException::CODE_REFUSED);
        self::assertTrue($logs->hasWarningThatContains('refused a sign-in\'s code'));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode(array_map(static fn(LogRecord $record): array => $record->toArray(), $logs->getRecords())), 'never the secret');
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testATokenOfAnotherSignInIsRefused(): void
    {
        $address = $this->authorize();
        $this->telegramLogin()->answerCode(self::token(bin2hex(random_bytes(16))));

        self::assertRefused($this->signIn('the-code', $address['state']), SignInRefusedException::SIGN_IN_SPENT);
    }

    public function testTelegramOutOfReachIsA502(): void
    {
        $address = $this->authorize();
        $this->telegramLogin()->down();

        $response = $this->signIn('the-code', $address['state']);

        self::assertSame([502, TelegramUnreachableException::MESSAGE], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testAMissingStateIsSaidUnderItsField(): void
    {
        $response = $this->unchecked()->postJson($this->storeApi($this->website, '/auth/telegram'), ['code' => 'the-code', 'code_verifier' => self::VERIFIER]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['state'], array_keys($this->decode($response)['errors']));
    }

    /**
     * The authorization address for RETURN — begun with the site's challenge of VERIFIER —, and what it carries.
     *
     * @return array<string, string> Its query's fields, and the address itself under `url`
     */
    private function authorize(): array
    {
        $response = $this->postJson($this->storeApi($this->website, '/auth/telegram/authorize'), ['redirect_uri' => self::RETURN, 'code_challenge' => self::challenge(self::VERIFIER)]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $url = (string) $this->decode($response)['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $fields);

        return array_map(strval(...), $fields) + ['url' => $url];
    }

    /** The redirect's code and state posted as the site posts them, with `$verifier` — the browser's —; `$answer` a nonce Telegram's exchange signs a token for first. */
    private function signIn(string $code, string $state, string $verifier = self::VERIFIER, ?string $answer = null): ResponseInterface
    {
        if ($answer !== null) {
            $this->telegramLogin()->answerCode(self::token($answer));
        }

        return $this->postJson($this->storeApi($this->website, '/auth/telegram'), ['code' => $code, 'state' => $state, 'code_verifier' => $verifier]);
    }

    /** What S256 makes of a verifier: the base64url of its SHA-256, unpadded. */
    private static function challenge(string $verifier): string
    {
        return JWT::urlsafeB64Encode(hash('sha256', $verifier, true));
    }

    private static function token(string $nonce): string
    {
        return FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce));
    }

    private function assertRefused(ResponseInterface $response, string $why, string $message = ''): void
    {
        self::assertSame([401, $why], [$response->getStatusCode(), $this->decode($response)['message']], $message);
    }
}
