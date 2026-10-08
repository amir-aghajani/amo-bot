<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Captcha;

use App\Core\Captcha\CaptchaAttempt;
use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Captcha\CaptchaVerdict;
use App\Core\Captcha\Drivers\Turnstile;
use Tests\Support\FakeTurnstile;
use Tests\TestCase;

/**
 * Cloudflare Turnstile, judged by Cloudflare's siteverify — the site's secret, the token, the visitor's address when it
 * came with them —, then held to the form: a token Cloudflare passed on none of the site's hosts, or for another action
 * than the form's, does not pass; Cloudflare's own refusals say its codes; a secret Cloudflare does not know is the log's
 * error, for the owner; Cloudflare out of reach is no verdict at all.
 */
final class TurnstileTest extends TestCase
{
    private const KEYS = ['site_key' => '0x4AAAAAAA-site', 'secret_key' => '0x4AAAAAAA-secret'];

    private const HOSTS = ['shop.example', 'localhost'];

    private Turnstile $turnstile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->turnstile = $this->service(Turnstile::class);
        $this->turnstile();
    }

    public function testATokenPassedOnTheSitesHostForTheFormsActionPasses(): void
    {
        $verdict = $this->verify(FakeTurnstile::passed('sign_up'), 'sign_up', '203.0.113.7');

        self::assertSame([true, 'sign_up', 'shop.example', []], [$verdict->passed, $verdict->action, $verdict->hostname, $verdict->reasons]);
        self::assertSame(['secret' => '0x4AAAAAAA-secret', 'response' => $this->turnstile()->checks[0]['response'], 'remoteip' => '203.0.113.7'], $this->turnstile()->checks[0], "the site's secret, the token and the visitor");
        self::assertTrue($this->verify(FakeTurnstile::passed('review', 'localhost'), null)->passed, 'on an origin the site lists, no action expected');
        self::assertArrayNotHasKey('remoteip', $this->turnstile()->checks[1], "a site's backend sends no visitor");
    }

    public function testOneSolvedElsewhereOrForAnotherActionDoesNotPass(): void
    {
        $elsewhere = $this->verify(FakeTurnstile::passed('sign_up', 'evil.example'), 'sign_up');
        self::assertSame([false, 'evil.example', [CaptchaVerdict::HOSTNAME_MISMATCH]], [$elsewhere->passed, $elsewhere->hostname, $elsewhere->reasons]);

        $another = $this->verify(FakeTurnstile::passed('sign_in'), 'sign_up');
        self::assertSame([false, 'sign_in', [CaptchaVerdict::ACTION_MISMATCH]], [$another->passed, $another->action, $another->reasons]);

        self::assertSame([CaptchaVerdict::ACTION_MISMATCH], $this->verify(FakeTurnstile::passed(null), 'sign_up')->reasons, 'a widget that named no action, where one is expected');
    }

    public function testCloudflaresRefusalsSayItsCodes(): void
    {
        self::assertSame([false, ['invalid-input-response']], [$this->verify('made-up-token', null)->passed, $this->verify('made-up-token', null)->reasons]);

        $token = FakeTurnstile::passed(null);
        self::assertTrue($this->verify($token, null)->passed);
        self::assertSame(['timeout-or-duplicate'], $this->verify($token, null)->reasons, 'Cloudflare takes a token once');
    }

    public function testASecretCloudflareDoesNotKnowIsTheOwnersToSetRight(): void
    {
        $logs = $this->logs();

        $verdict = $this->turnstile->verify(['site_key' => '0x4AAAAAAA-site', 'secret_key' => FakeTurnstile::UNKNOWN_SECRET], new CaptchaAttempt(FakeTurnstile::passed(null), null, self::HOSTS, null));

        self::assertSame([false, ['invalid-input-secret']], [$verdict->passed, $verdict->reasons]);
        self::assertTrue($logs->hasErrorThatContains('does not know the website\'s captcha secret'));
    }

    public function testCloudflareOutOfReachIsNoVerdict(): void
    {
        $this->turnstile()->down();
        $logs = $this->logs();

        try {
            $this->verify(FakeTurnstile::passed(null), null);
            self::fail('Cloudflare out of reach judged nothing');
        } catch (CaptchaUnavailableException $e) {
            self::assertSame([503, CaptchaUnavailableException::MESSAGE], [$e->status(), $e->getMessage()]);
        }
        self::assertTrue($logs->hasWarningThatContains('Turnstile could not check'));
    }

    public function testItsFormIsTheWidgetsKeysTheSecretBelongingWithItsSiteKey(): void
    {
        $described = $this->turnstile->describe()->toArray();

        self::assertSame(['turnstile', 'Cloudflare Turnstile'], [$described['key'], $described['label']]);
        self::assertSame([['site_key', 'text', true, false], ['secret_key', 'secret', true, true]], array_map(static fn(array $field): array => [$field['name'], $field['type'], $field['required'], $field['secret']], $described['fields']));
        self::assertSame(['site_key'], $described['fields'][1]['bound_to']);
        self::assertSame('0x4AAAAAAA-site', $this->turnstile->siteKey(self::KEYS), 'its public key, what a page draws the widget with');
    }

    private function verify(string $token, ?string $action, ?string $visitor = null): CaptchaVerdict
    {
        return $this->turnstile->verify(self::KEYS, new CaptchaAttempt($token, $action, self::HOSTS, $visitor));
    }
}
