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
 * Customers' reviews of the shop («نظرات»), one service whichever door a decision comes through — the panels, or the
 * website's admin side —: written on the shop's website by a guest, or by a customer signed in there (theirs, then),
 * every refusal of its fields at once; kept waiting on support, the report group told; approved — the website shows it
 * —, rejected — it does not —, either way again later, or deleted — never by the admin who wrote it, signed in, whose
 * own review is another admin's to decide. Every change of where a review stands is one conditional update
 * (Core\Database\Transitions), so of two decisions at once one stands, and the other is refused in the words of the
 * state it found.
 *
 * What a review costs the shop is held: a guest's is taken only while the website asks a captcha — without one, nothing
 * would stand between a bot and the queue, so it takes its signed-in customers' alone (a 403) —; the shop keeps
 * PENDING_MAX waiting on support at most (a 503 until support decides some); an address network — an IPv6 one by its
 * /48 (NETWORK_IPV6_PREFIX), as much as one host is often handed — writes NETWORK_REVIEWS in NETWORK_WINDOW, a signed-in
 * customer CUSTOMER_REVIEWS in CUSTOMER_WINDOW, and every guest together GUEST_REVIEWS in GUEST_WINDOW (a 429) — counted
 * before the website's captcha judges the review's token, so a review refused for its wait spends none, and a token
 * refused keeps its network's count: a bot's guesses cost its network its reviews, and its provider is asked no more
 * than that — never the guests' count, which a bot's guesses would spend for every guest —; a token that could not be
 * judged gives the counts back. The reviews' own windows hold the captcha's tries, never the sign-ins'
 * (Auth\Services\SignInThrottle::issuing()): a review bot behind a carrier's shared address keeps nobody behind it from
 * signing in.
 */
final class Reviews
{
    public const NAME_MIN = 2;
    public const NAME_MAX = 64;
    public const BODY_MIN = 10;
    public const BODY_MAX = 600;
    public const CONTEXT_MAX = 100;

    /** The action a review's captcha widget names (Turnstile's `data-action`, ALTCHA's challenge asked with `?action=`): a token solved for another form passes none. */
    public const CAPTCHA_ACTION = 'review';

    /** The reviews the shop keeps waiting on support at most: then it takes no new one until support decides some. */
    public const PENDING_MAX = 200;

    /** Reviews one address network writes in a window of NETWORK_WINDOW seconds — a refused captcha among them —: many customers share one address on a mobile network. */
    public const NETWORK_REVIEWS = 20;
    public const NETWORK_WINDOW = 3600;

    /** The bits an IPv6 address network is counted by: a /48 — what a host is often handed, a /64 for every try from it otherwise. */
    public const NETWORK_IPV6_PREFIX = 48;

    /** Reviews one signed-in customer writes in a window of CUSTOMER_WINDOW seconds. */
    public const CUSTOMER_REVIEWS = 3;
    public const CUSTOMER_WINDOW = 86400;

    /** Reviews the shop takes from guests — every one together, from wherever — in a window of GUEST_WINDOW seconds. */
    public const GUEST_REVIEWS = 20;
    public const GUEST_WINDOW = 3600;

    /** Refused, as the state the review is in says. */
    public const APPROVED = 'این نظر تایید شده است.';
    public const REJECTED = 'این نظر رد شده است.';

    /** Where a review may be approved from, and rejected from: support moves it either way, at any time. */
    private const APPROVABLE = [ReviewStatus::Pending, ReviewStatus::Rejected];
    private const REJECTABLE = [ReviewStatus::Pending, ReviewStatus::Approved];

    /** An avatar: a key of the website's own set — never an address, which the website would load a picture from. */
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
     * A review written on the shop's website — the `name` it is signed with, a `rating`, its `body`, a line of
     * `context`, one of the site's own avatars —, `$writer` the customer signed in there (null for a guest, taken only
     * while the site asks a captcha): every refusal of its fields at once; then the shop's room for one; then the tries
     * counted; then `captcha` judged for the review's action, while the site asks one; then kept, waiting on support, and
     * the report group told.
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
        $wait = $this->limiter->attempt($windows);
        if ($wait > 0) {
            throw TooManyAttemptsException::wait(self::TOO_MANY, $wait);
        }
        try {
            $this->captcha->check($site, $request, $input, self::CAPTCHA_ACTION);
        } catch (CaptchaUnavailableException $e) {
            foreach ($windows as [$key]) {
                $this->limiter->release($key);
            }

            throw $e;
        } catch (ValidationException $e) {
            // A refused token costs its network the review, never the guests' count: a bot's guesses would spend it for everyone.
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
     * Approved — the website shows it from now on —, whether it waited or was rejected before.
     *
     * @throws ActorRefusedException 403 their own review
     * @throws ValidationException 422 on `status`: approved already
     */
    public function approve(Review $review, Actor $actor): Review
    {
        return $this->decide($review, $actor, ReviewStatus::Approved, self::APPROVABLE, self::APPROVED);
    }

    /**
     * Rejected — kept, never shown —, whether it waited or was shown before (hidden again).
     *
     * @throws ActorRefusedException 403 their own review
     * @throws ValidationException 422 on `status`: rejected already
     */
    public function reject(Review $review, Actor $actor): Review
    {
        return $this->decide($review, $actor, ReviewStatus::Rejected, self::REJECTABLE, self::REJECTED);
    }

    /**
     * Gone, whatever it stood at — shown on the website no more.
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
     * The review moved to `$to` from where it stood, who decided it and when on it — one conditional update, refused with
     * `$refusal` when it stood there already (its state as it is now read onto it).
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
     * One of the shop's admins — its customer too — deciding about the review they wrote themselves, signed in: another
     * admin's (or the panels') to decide.
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
     * The name it is signed with — one line, NAME_MIN to NAME_MAX characters.
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
     * A line of where they use the service from — CONTEXT_MAX characters at most —; blank for none.
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
     * A field that is one line — a name, the context —: trimmed, every run of spaces and line breaks one space.
     *
     * @param array<string, mixed> $input
     */
    private static function line(array $input, string $field): string
    {
        return (string) preg_replace('/\s+/u', ' ', Input::text($input, $field));
    }
}
