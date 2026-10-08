<?php

declare(strict_types=1);

namespace App\Core\Captcha;

/**
 * What came of a token: whether it passed, the action and the host it was solved for (as far as the driver tells), and
 * why it did not pass — its codes: the provider's own (Turnstile's `timeout-or-duplicate`, `invalid-input-response` …),
 * and the shop's, HOSTNAME_MISMATCH and ACTION_MISMATCH.
 */
final readonly class CaptchaVerdict
{
    /** Solved on a page of none of the site's hosts. */
    public const HOSTNAME_MISMATCH = 'hostname-mismatch';

    /** Solved for another action than the form's. */
    public const ACTION_MISMATCH = 'action-mismatch';

    /** @param list<string> $reasons Empty when it passed */
    public function __construct(
        public bool $passed,
        public ?string $action,
        public ?string $hostname,
        public array $reasons,
    ) {}

    /**
     * A token the driver took, held to the form: solved on one of `$attempt`'s hosts (when the driver says where), for
     * its action (when it names one) — else refused with the shop's codes.
     */
    public static function held(CaptchaAttempt $attempt, ?string $action, ?string $hostname): self
    {
        $reasons = [];
        if ($hostname !== null && !in_array(strtolower($hostname), $attempt->hosts, true)) {
            $reasons[] = self::HOSTNAME_MISMATCH;
        }
        if ($attempt->action !== null && $action !== $attempt->action) {
            $reasons[] = self::ACTION_MISMATCH;
        }

        return new self($reasons === [], $action, $hostname, $reasons);
    }

    /** @param list<string> $reasons A token refused, for these reasons */
    public static function refused(array $reasons, ?string $action = null, ?string $hostname = null): self
    {
        return new self(false, $action, $hostname, $reasons);
    }
}
