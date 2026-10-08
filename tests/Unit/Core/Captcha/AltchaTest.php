<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Captcha;

use App\Core\Captcha\CaptchaAttempt;
use App\Core\Captcha\CaptchaVerdict;
use App\Core\Captcha\Drivers\Altcha;
use App\Core\Security\Encrypter;
use Illuminate\Support\Carbon;
use Tests\Support\AltchaWidget;
use Tests\TestCase;

/**
 * ALTCHA, judged by the shop alone: a challenge it issued — signed with a key of its own derived from APP_KEY, its salt
 * carrying when it expires and the action it was asked for, and ending in its delimiter — that the visitor's browser
 * solved passes once, before it expires, for its action; one tampered with, forged, split another way, expired or taken
 * before passes nothing — and no third party is asked, whatever happens.
 */
final class AltchaTest extends TestCase
{
    private Altcha $altcha;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->altcha = $this->service(Altcha::class);
    }

    public function testAChallengeIsTheWidgetsShapeSignedByTheShop(): void
    {
        $challenge = $this->altcha->challenge([], 'sign_up');

        self::assertSame(['algorithm', 'challenge', 'maxnumber', 'salt', 'signature'], array_keys($challenge));
        self::assertSame(['SHA-256', Altcha::MAX_NUMBER], [$challenge['algorithm'], $challenge['maxnumber']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}\?expires=\d+&action=sign_up&$/', $challenge['salt'], 'ending in its delimiter');
        self::assertSame(now()->getTimestamp() + Altcha::CHALLENGE_SECONDS, (int) substr($challenge['salt'], 33, 10), 'it expires in a quarter of an hour');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['challenge']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['signature']);
        self::assertNotSame($challenge['challenge'], $this->altcha->challenge([], 'sign_up')['challenge'], 'a new one every time');
    }

    public function testASolvedChallengePassesOnce(): void
    {
        $payload = AltchaWidget::solve($this->altcha->challenge([], null));

        $verdict = $this->verify($payload, null);
        self::assertTrue($verdict->passed);
        self::assertSame([null, null, []], [$verdict->action, $verdict->hostname, $verdict->reasons], 'ALTCHA says nothing of where it was solved');

        $again = $this->verify($payload, null);
        self::assertSame([false, ['timeout-or-duplicate']], [$again->passed, $again->reasons], 'taken once');
    }

    public function testAChallengeAskedForAnActionPassesNoFormOfAnother(): void
    {
        $forSignUp = $this->altcha->challenge([], 'sign_up');

        $other = $this->verify(AltchaWidget::solve($forSignUp), 'sign_in');
        self::assertSame([false, 'sign_up', [CaptchaVerdict::ACTION_MISMATCH]], [$other->passed, $other->action, $other->reasons]);
        self::assertFalse($this->verify(AltchaWidget::solve($this->altcha->challenge([], null)), 'sign_up')->passed, 'nor one asked for none, where an action is expected');
        self::assertTrue($this->verify(AltchaWidget::solve($this->altcha->challenge([], 'sign_up')), 'sign_up')->passed);
        self::assertTrue($this->verify(AltchaWidget::solve($this->altcha->challenge([], 'review')), null)->passed, 'no action expected: any');
    }

    public function testAnExpiredChallengePassesNothing(): void
    {
        $payload = AltchaWidget::solve($this->altcha->challenge([], null));
        Carbon::setTestNow(now()->addSeconds(Altcha::CHALLENGE_SECONDS));

        self::assertSame(['timeout-or-duplicate'], $this->verify($payload, null)->reasons);
    }

    public function testASolutionTakenStaysTakenUntilItsChallengeExpiresAndPassesNothingAfter(): void
    {
        $payload = AltchaWidget::solve($this->altcha->challenge([], null));
        self::assertTrue($this->verify($payload, null)->passed);

        Carbon::setTestNow(now()->addSeconds(Altcha::CHALLENGE_SECONDS - 1));
        self::assertSame(['timeout-or-duplicate'], $this->verify($payload, null)->reasons, 'its last second: taken');
        Carbon::setTestNow(now()->addDays(30));
        self::assertSame(['timeout-or-duplicate'], $this->verify($payload, null)->reasons, 'a month on: expired');
    }

    /**
     * The hash is of the salt and the number one after the other: the number's first digits moved onto the salt's end
     * keep the hash and the signature — a "new" salt, an expiry far ahead. The salt's delimiter refuses every such split.
     */
    public function testASolutionSplitAnotherWayPassesNothing(): void
    {
        do {
            $challenge = $this->altcha->challenge([], null);
            $payload = json_decode((string) base64_decode(AltchaWidget::solve($challenge), true), true);
            self::assertIsArray($payload);
            $digits = (string) $payload['number'];
        } while (strlen($digits) < 3);

        $splits = 0;
        foreach ([0, 30] as $days) {
            Carbon::setTestNow(now()->addDays($days));
            for ($moved = 1; $moved < strlen($digits); $moved++) {
                $rest = substr($digits, $moved);
                if ($rest[0] === '0') {
                    continue;
                }
                $variant = AltchaWidget::payload(['salt' => $payload['salt'] . substr($digits, 0, $moved), 'number' => (int) $rest] + $payload);
                // Its hash and its signature still hold: only the salt's delimiter tells the split apart.
                self::assertSame($payload['challenge'], hash('sha256', $payload['salt'] . substr($digits, 0, $moved) . $rest));
                self::assertSame([false, ['invalid-input-response']], [$this->verify($variant, null)->passed, $this->verify($variant, null)->reasons], "the number's first {$moved} digits on the salt, {$days} days on");
                $splits++;
            }
        }
        self::assertGreaterThan(0, $splits);

        Carbon::setTestNow(now()->subDays(30));
        self::assertTrue($this->verify(AltchaWidget::solve($challenge), null)->passed, 'none of them took the challenge: its own solution still passes, once');
    }

    public function testASaltWithoutItsDelimiterIsNoneOfTheShopsThoughSignedByIt(): void
    {
        // As challenges were issued before the delimiter: signed with the shop's own key, its salt ending in its expiry.
        $salt = bin2hex(random_bytes(12)) . '?expires=' . (now()->getTimestamp() + Altcha::CHALLENGE_SECONDS);
        $challenge = hash('sha256', $salt . '4217');
        $signed = ['algorithm' => 'SHA-256', 'challenge' => $challenge, 'maxnumber' => Altcha::MAX_NUMBER, 'salt' => $salt, 'signature' => $this->service(Encrypter::class)->mac($challenge, 'amobot-altcha')];

        self::assertSame(['invalid-input-response'], $this->verify(AltchaWidget::solve($signed), null)->reasons);
    }

    public function testATamperedOrForgedSolutionPassesNothing(): void
    {
        $challenge = $this->altcha->challenge([], null);
        $later = (string) preg_replace('/expires=\d+/', 'expires=' . (now()->getTimestamp() + 86400), $challenge['salt']);
        $mine = $this->altcha->challenge([], null);

        foreach ([
            'another number' => AltchaWidget::solve($challenge, ['number' => Altcha::MAX_NUMBER + 1]),
            'a salt that expires later' => AltchaWidget::solve($challenge, ['salt' => $later]),
            'a signature made up' => AltchaWidget::solve($challenge, ['signature' => hash_hmac('sha256', $challenge['challenge'], 'not the shop\'s key')]),
            "another challenge's signature" => AltchaWidget::solve($challenge, ['signature' => $mine['signature']]),
            'a challenge of its own making' => AltchaWidget::payload(['algorithm' => 'SHA-256', 'challenge' => hash('sha256', 'salt?expires=9999999999' . '7'), 'number' => 7, 'salt' => 'salt?expires=9999999999', 'signature' => str_repeat('0', 64)]),
            'another algorithm' => AltchaWidget::solve($challenge, ['algorithm' => 'SHA-1']),
            'no payload at all' => 'not-base64-json',
            'a number as text' => AltchaWidget::solve($challenge, ['number' => '7']),
        ] as $what => $payload) {
            self::assertSame([false, ['invalid-input-response']], [$this->verify($payload, null)->passed, $this->verify($payload, null)->reasons], $what);
        }

        self::assertTrue($this->verify(AltchaWidget::solve($challenge), null)->passed, 'none of them took the challenge: its own solution still passes');
    }

    private function verify(string $payload, ?string $action): CaptchaVerdict
    {
        return $this->altcha->verify([], new CaptchaAttempt($payload, $action, ['shop.example'], null));
    }
}
