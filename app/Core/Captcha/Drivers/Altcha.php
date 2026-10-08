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
 * ALTCHA (altcha.org), the open-source proof-of-work captcha: no third party to reach, nothing to block, no keys to type —
 * it works on any host, from Iran too. Its widget fetches a challenge from the shop (challenge()) and has the visitor's
 * browser find the number whose SHA-256, after the challenge's salt, is the challenge — a fraction of a second's work,
 * which a bot pays on every form; the form carries what it found (the payload: the challenge, the number, the salt and
 * the signature, as base64 JSON). The shop judges it (verify()) by hash alone: its own signature on the challenge
 * (HMAC-SHA256 under a key derived from APP_KEY for it, Encrypter::mac()), the number's hash, and the salt's expiry —
 * which, like the action a challenge was asked for, travels in the salt the signature covers —, each challenge taken
 * once until it expires (Core\Security\RateLimiter: a window of CHALLENGE_SECONDS on the salt's random part).
 *
 * The hash covers the salt and the number written one after the other, so the salt ends in a delimiter that is no digit
 * (SALT's `&`): without it, the number's first digits moved onto the salt's end would make another salt with the same
 * hash — a solution passing again under a "new" challenge, with an expiry that never comes. A salt without it is none
 * of the shop's.
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

    /** A payload's codes of refusal — as Turnstile's for the same: not a solution of ours, or one expired or spent. */
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
     * The widget's challenge (`algorithm`, `challenge`, `maxnumber`, `salt`, `signature`): a random salt carrying when it
     * expires — and `$action`, when named — and ending in its delimiter (SALT), the SHA-256 of the salt and a number up
     * to MAX_NUMBER, signed.
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
     * The payload judged: a challenge of the shop's (its signature, and a salt as the shop issues them — ending in its
     * delimiter), solved (the number's hash), not expired, and not taken before — then taken, whatever the form makes of
     * it —, for the form's action when the challenge was asked for one. ALTCHA says nothing of where it was solved: no
     * host is held to.
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
        // The signature covers the salt, so its expiry is the shop's own — at most CHALLENGE_SECONDS away: a solution is
        // taken once, by its salt's random part, for as long as a challenge lives.
        if ($expires <= now()->getTimestamp() || $this->limiter->attempt([['altcha|' . $issued[1], 1, self::CHALLENGE_SECONDS]]) > 0) {
            return CaptchaVerdict::refused([self::SPENT], $action);
        }

        return CaptchaVerdict::held($attempt, $action, null);
    }
}
