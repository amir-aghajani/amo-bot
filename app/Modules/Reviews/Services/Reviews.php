<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

use App\Core\Captcha\CaptchaSite;
use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Captcha\Verifier;
use App\Core\Database\Transitions;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\RequestOrigin;
use App\Core\Security\RateLimiter;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\ReviewsFullException;
use App\Modules\Reviews\Exceptions\SignInToReviewException;
use App\Modules\Reviews\Models\Review;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Customers' reviews of the shop («نظرات»): written on the website by a guest or a signed-in customer, kept waiting on
 * support (the report group hears of it), then approved (the website shows it), rejected (it does not) — either way again
 * later — or deleted. One service for the panels and the website's admin API. Every status change is one conditional
 * update (Transitions), so of two decisions at once one stands and the other is refused by the state it found. A shop
 * admin never decides about a review they wrote themselves.
 *
 * What a review may cost the shop is limited:
 * - a guest's review is taken only while the website asks a captcha (without one nothing would stop a bot);
 * - at most PENDING_MAX wait on support (a 503 until support decides some);
 * - per window: NETWORK_REVIEWS per address network (an IPv6 one by its /48), CUSTOMER_REVIEWS per signed-in customer,
 *   GUEST_REVIEWS for all guests together (a 429).
 * The windows are counted before the captcha is judged. A refused token keeps its network's count (a bot's guesses cost
 * it reviews, and its captcha provider is asked no more) but gives the guests' count back (else a bot would spend it for
 * every guest); a token that could not be judged gives every count back. These are the reviews' own windows, never the
 * sign-ins' (SignInThrottle), so a review bot behind a carrier's shared address locks nobody out of signing in.
 */
final class Reviews
{
    public const NAME_MIN = 2;
    public const NAME_MAX = 64;
    public const BODY_MIN = 10;
    public const BODY_MAX = 600;
    public const CONTEXT_MAX = 100;

    /** The action a review's captcha names (Turnstile's `data-action`, ALTCHA's `?action=`): a token solved for another form fails. */
    public const CAPTCHA_ACTION = 'review';

    /** The most reviews waiting on support; then no new one is taken until support decides some. */
    public const PENDING_MAX = 200;

    /** Reviews one address network writes per NETWORK_WINDOW seconds, refused captchas included (generous: mobile customers share addresses). */
    public const NETWORK_REVIEWS = 20;
    public const NETWORK_WINDOW = 3600;

    /** The prefix an IPv6 address network is counted by: a /48, since one host is often handed that much (a /64 per try otherwise). */
    public const NETWORK_IPV6_PREFIX = 48;

    /** Reviews one signed-in customer writes in a window of CUSTOMER_WINDOW seconds. */
    public const CUSTOMER_REVIEWS = 3;
    public const CUSTOMER_WINDOW = 86400;

    /** Reviews the shop takes from all guests together per GUEST_WINDOW seconds. */
    public const GUEST_REVIEWS = 20;
    public const GUEST_WINDOW = 3600;

    /** Refusals by the state the review is in. */
    public const APPROVED = 'این نظر تایید شده است.';
    public const REJECTED = 'این نظر رد شده است.';

    /** Where a review may be approved from, and rejected from: support moves it either way at any time. */
    private const APPROVABLE = [ReviewStatus::Pending, ReviewStatus::Rejected];
    private const REJECTABLE = [ReviewStatus::Pending, ReviewStatus::Approved];

    /** An avatar: a key of the website's own set — never an address the website would load a picture from. */
    private const AVATAR = '/^[a-z0-9_-]{1,32}$/';

    private const NAME_MISSING = 'نام خود را بنویسید.';
    private const NAME_SHORT = 'نام حداقل ' . self::NAME_MIN . ' کاراکتر است.';
    private const RATING_RANGE = 'امتیاز باید عددی بین 1 تا 5 باشد.';
    private const BODY_MISSING = 'متن نظر را بنویسید.';
    private const BODY_SHORT = 'متن نظر حداقل ' . self::BODY_MIN . ' کاراکتر است.';
    private const AVATAR_INVALID = 'آواتار باید کلید یکی از آواتارهای خود وب‌سایت باشد: 1 تا 32 حرف کوچک لاتین، عدد، - یا _.';
    private const TOO_MANY = 'نظر زیادی ثبت شده است';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Verifier $captcha,
        private readonly RateLimiter $limiter,
        private readonly RequestOrigin $origin,
        private readonly ShopReports $reports,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A review written on the website — `name`, `rating`, `body`, a line of `context`, one of the site's `avatar`s — by
     * `$writer` (null for a guest, taken only while the site asks a captcha). In order: every field refusal at once; the
     * shop's room for one more; the windows counted; the captcha judged (while the site asks one); then kept, waiting on
     * support, and the report group told.
     *
     * @param array<string, mixed> $input
     * @throws SignInToReviewException 403: a guest's, while the site asks no captcha
     * @throws ValidationException 422 on a field — every one at once —, then on `captcha`
     * @throws ReviewsFullException 503: the shop keeps PENDING_MAX waiting on support
     * @throws TooManyAttemptsException 429: the network's, the customer's or the guests' window is full
     * @throws CaptchaUnavailableException 503: the captcha could not be judged
     */
    public function submit(CaptchaSite $site, ServerRequestInterface $request, ?User $writer, array $input): Review
    {
        if ($writer === null && !$this->captcha->asks($site)) {
            throw new SignInToReviewException();
        }
        $errors = [];
        $fields = [
            'name' => self::name($input, $errors),
            'rating' => self::rating($input, $errors),
            'body' => self::body($input, $errors),
            'context' => self::context($input, $errors),
            'avatar' => self::avatar($input, $errors),
        ];
        ValidationException::ifAny($errors);
        if (Review::query()->where('status', ReviewStatus::Pending->value)->count() >= self::PENDING_MAX) {
            throw new ReviewsFullException();
        }

        $windows = [
            ['reviews|network|' . CurrentBot::id() . '|' . $this->origin->clientNetwork($request, self::NETWORK_IPV6_PREFIX), self::NETWORK_REVIEWS, self::NETWORK_WINDOW],
            $writer !== null
                ? ['reviews|customer|' . $writer->id, self::CUSTOMER_REVIEWS, self::CUSTOMER_WINDOW]
                : ['reviews|guests|' . CurrentBot::id(), self::GUEST_REVIEWS, self::GUEST_WINDOW],
        ];
        $this->limiter->enforce($windows, self::TOO_MANY);
        try {
            $this->captcha->check($site, $request, $input, self::CAPTCHA_ACTION);
        } catch (CaptchaUnavailableException $e) {
            foreach ($windows as [$key]) {
                $this->limiter->release($key);
            }

            throw $e;
        } catch (ValidationException $e) {
            // A refused token costs its network the review but not the guests' count, which a bot would spend for everyone.
            if ($writer === null) {
                $this->limiter->release($windows[1][0]);
            }

            throw $e;
        }

        return $this->db->transaction(function () use ($writer, $fields): Review {
            $review = Review::query()->create(['user_id' => $writer?->id] + $fields);
            $review->setRelation('user', $writer);
            $this->reports->reviewWritten($review);

            return $review;
        });
    }

    /**
     * Approve the review (the website shows it from now on), whether it waited or was rejected before.
     *
     * @throws ActorRefusedException 403 their own review
     * @throws ValidationException 422 on `status`: approved already
     */
    public function approve(Review $review, Actor $actor): Review
    {
        return $this->decide($review, $actor, ReviewStatus::Approved, self::APPROVABLE, self::APPROVED);
    }

    /**
     * Reject the review (kept, never shown), whether it waited or was shown before.
     *
     * @throws ActorRefusedException 403 their own review
     * @throws ValidationException 422 on `status`: rejected already
     */
    public function reject(Review $review, Actor $actor): Review
    {
        return $this->decide($review, $actor, ReviewStatus::Rejected, self::REJECTABLE, self::REJECTED);
    }

    /**
     * Delete the review, whatever its status.
     *
     * @throws ActorRefusedException 403 their own review
     */
    public function delete(Review $review, Actor $actor): void
    {
        self::notTheirOwn($review, $actor);
        $review->delete();
        $this->logger->info('Review {id} deleted by {reviewer}', ['id' => $review->id, 'reviewer' => $actor->reviewer]);
    }

    /** @return array{approve: bool, reject: bool} What support may decide about the review now (a delete, always). */
    public static function allowed(Review $review): array
    {
        return [
            'approve' => in_array($review->status, self::APPROVABLE, true),
            'reject' => in_array($review->status, self::REJECTABLE, true),
        ];
    }

    /**
     * Move the review to `$to` from `$from`, stamping who decided and when, in one conditional update; refused with
     * `$refusal` when it was not in `$from` (its current state is read onto it).
     *
     * @param list<ReviewStatus> $from
     * @throws ActorRefusedException 403 their own review
     * @throws ValidationException 422 on `status`
     */
    private function decide(Review $review, Actor $actor, ReviewStatus $to, array $from, string $refusal): Review
    {
        self::notTheirOwn($review, $actor);
        if (!Transitions::move($review, 'status', $from, $to, ['reviewer' => $actor->name(), 'decided_at' => now()])) {
            throw ValidationException::on('status', $refusal);
        }
        $this->logger->info('Review {id} {status} by {reviewer}', ['id' => $review->id, 'status' => $to->value, 'reviewer' => $actor->reviewer]);

        return $review;
    }

    /**
     * Refuse a shop admin (also a customer) deciding about a review they wrote themselves: another admin or a panel
     * decides it.
     *
     * @throws ActorRefusedException
     */
    private static function notTheirOwn(Review $review, Actor $actor): void
    {
        if ($review->user_id !== null && $actor->is($review->user_id)) {
            throw ActorRefusedException::ownReview();
        }
    }

    /**
     * The name it is signed with: one line, NAME_MIN to NAME_MAX characters.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function name(array $input, array &$errors): string
    {
        $name = self::line($input, 'name');
        $length = mb_strlen($name);
        $problem = match (true) {
            $length === 0 => self::NAME_MISSING,
            $length < self::NAME_MIN => self::NAME_SHORT,
            $length > self::NAME_MAX => Validation::tooLong('نام', self::NAME_MAX),
            default => null,
        };
        if ($problem !== null) {
            $errors['name'] = [$problem];
        }

        return $name;
    }

    /**
     * Its stars, 1 to 5 (Persian digits too).
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function rating(array $input, array &$errors): int
    {
        $rating = Input::integerOf($input['rating'] ?? null);
        if ($rating === null || $rating < 1 || $rating > 5) {
            $errors['rating'] = [self::RATING_RANGE];
        }

        return (int) $rating;
    }

    /**
     * The writer's words: trimmed, their line breaks kept, BODY_MIN to BODY_MAX characters.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function body(array $input, array &$errors): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", Input::text($input, 'body'));
        $length = mb_strlen($body);
        $problem = match (true) {
            $length === 0 => self::BODY_MISSING,
            $length < self::BODY_MIN => self::BODY_SHORT,
            $length > self::BODY_MAX => Validation::tooLong('متن نظر', self::BODY_MAX),
            default => null,
        };
        if ($problem !== null) {
            $errors['body'] = [$problem];
        }

        return $body;
    }

    /**
     * A line of where they use the service from (CONTEXT_MAX characters at most); blank for none.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function context(array $input, array &$errors): ?string
    {
        $context = self::line($input, 'context');
        if (mb_strlen($context) > self::CONTEXT_MAX) {
            $errors['context'] = [Validation::tooLong('مشخصات', self::CONTEXT_MAX)];
        }

        return $context === '' ? null : $context;
    }

    /**
     * A key of the website's own avatars; blank for none.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function avatar(array $input, array &$errors): ?string
    {
        $avatar = Input::text($input, 'avatar');
        if ($avatar !== '' && preg_match(self::AVATAR, $avatar) !== 1) {
            $errors['avatar'] = [self::AVATAR_INVALID];
        }

        return $avatar === '' ? null : $avatar;
    }

    /**
     * A one-line field (a name, the context): trimmed, every run of whitespace one space.
     *
     * @param array<string, mixed> $input
     */
    private static function line(array $input, string $field): string
    {
        return (string) preg_replace('/\s+/u', ' ', Input::text($input, $field));
    }
}
