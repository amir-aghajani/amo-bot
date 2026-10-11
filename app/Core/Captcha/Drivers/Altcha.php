<?php

declare(strict_types=1);

namespace App\Core\Captcha\Drivers;

use App\Core\Captcha\CaptchaAttempt;
use App\Core\Captcha\CaptchaDriver;
use App\Core\Captcha\CaptchaVerdict;
use App\Core\Captcha\IssuesChallenges;
use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Core\Security\Encrypter;
use App\Core\Security\RateLimiter;
use App\Support\Persian;

/**
 * ALTCHA (altcha.org), an open-source proof of work: no third party, no keys, works on any host, from Iran too. The
 * widget fetches a signed challenge from the shop; the visitor's browser finds the number whose SHA-256 after the salt
 * is the challenge; the shop checks its own signature (Encrypter::mac()), the hash and the expiry carried in the salt,
 * and takes each challenge once (a RateLimiter window on the salt's random part).
 *
 * The salt ends in `&`, a non-digit: without it the number's first digits could be moved onto the salt, making a "new"
 * challenge with the same hash and no expiry.
 */
final class Altcha implements CaptchaDriver, IssuesChallenges
{
    /** How hard a challenge is: the most numbers a browser tries — a second of a phone's work at most. */
    public const MAX_NUMBER = 100_000;

    /** How long a challenge may be solved and its solution sent. */
    public const CHALLENGE_SECONDS = 900;

    /** The one algorithm the shop issues and takes. */
    private const ALGORITHM = 'SHA-256';

    /** What its signing key is derived for (Encrypter::mac()'s purpose). */
    private const PURPOSE = 'amobot-altcha';

    /**
     * A salt the shop issues: 24 random hex characters, `?`, what it carries (its expiry, its action) as a query, and the
     * delimiter `&` that ends it — so the number after it cannot be read into it.
     */
    private const SALT = '/^([0-9a-f]{24})\?([^?]*)&$/';

    /** Refusal codes, the same as Turnstile's: not a solution of ours; expired or spent. */
    private const INVALID = 'invalid-input-response';
    private const SPENT = 'timeout-or-duplicate';

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly RateLimiter $limiter,
    ) {}

    public function key(): string
    {
        return 'altcha';
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'ALTCHA',
            description: 'تایید امنیتی متن‌باز و بدون سرویس بیرونی: مرورگر بازدیدکننده یک معمای کوچک را در کسری از ثانیه حل می‌کند و فروشگاه جواب را خودش بررسی می‌کند. کلیدی لازم ندارد، روی هر هاستی کار می‌کند و به سرویسی خارج از ایران وابسته نیست.',
            form: new Form($this->key(), []),
            notes: ['ویجت ALTCHA معمای هر فرم را از آدرس challenge_url در GET / همین API می‌گیرد؛ هر جواب یک بار و تا ' . Persian::digits((string) intdiv(self::CHALLENGE_SECONDS, 60)) . ' دقیقه پذیرفته می‌شود.'],
        );
    }

    public function siteKey(array $values): ?string
    {
        return null;
    }

    /**
     * The widget's challenge: a random salt carrying its expiry (and `$action`) and ending in `&`, the SHA-256 of the
     * salt and a number up to MAX_NUMBER, and the shop's signature of it.
     */
    public function challenge(array $values, ?string $action): array
    {
        $salt = bin2hex(random_bytes(12)) . '?' . http_build_query(['expires' => now()->getTimestamp() + self::CHALLENGE_SECONDS] + ($action === null ? [] : ['action' => $action])) . '&';
        $challenge = hash('sha256', $salt . random_int(0, self::MAX_NUMBER));

        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'maxnumber' => self::MAX_NUMBER,
            'salt' => $salt,
            'signature' => $this->encrypter->mac($challenge, self::PURPOSE),
        ];
    }

    /**
     * Judge the payload: the shop's signature and salt, the number's hash, not expired, not taken before (then taken),
     * and the form's action. ALTCHA does not say where it was solved, so no host is checked.
     */
    public function verify(array $values, CaptchaAttempt $attempt): CaptchaVerdict
    {
        $payload = json_decode((string) base64_decode($attempt->token, true), true);
        if (!is_array($payload) || ($payload['algorithm'] ?? null) !== self::ALGORITHM || !is_int($payload['number'] ?? null) || $payload['number'] < 0) {
            return CaptchaVerdict::refused([self::INVALID]);
        }
        [$challenge, $salt, $signature] = [$payload['challenge'] ?? null, $payload['salt'] ?? null, $payload['signature'] ?? null];
        if (!is_string($challenge) || !is_string($salt) || !is_string($signature) || preg_match(self::SALT, $salt, $issued) !== 1
            || !hash_equals($challenge, hash('sha256', $salt . $payload['number']))
            || !hash_equals($this->encrypter->mac($challenge, self::PURPOSE), $signature)) {
            return CaptchaVerdict::refused([self::INVALID]);
        }

        parse_str($issued[2], $params);
        $expires = is_string($params['expires'] ?? null) && ctype_digit($params['expires']) ? (int) $params['expires'] : 0;
        $action = is_string($params['action'] ?? null) ? $params['action'] : null;
        // The signature covers the salt, so the expiry was set by the shop (at most CHALLENGE_SECONDS away): a window
        // that long on the salt's random part takes each solution once.
        if ($expires <= now()->getTimestamp() || $this->limiter->attempt([['altcha|' . $issued[1], 1, self::CHALLENGE_SECONDS]]) > 0) {
            return CaptchaVerdict::refused([self::SPENT], $action);
        }

        return CaptchaVerdict::held($attempt, $action, null);
    }
}
