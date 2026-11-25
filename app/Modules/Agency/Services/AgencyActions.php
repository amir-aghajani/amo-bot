<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Database\Ledger;
use App\Core\Database\Transitions;
use App\Core\Exceptions\KeyReusedException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\DTO\AgencyTerms;
use App\Modules\Agency\DTO\SellingTerms;
use App\Modules\Agency\Enums\AgencyRequestKind;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Traffic;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Decisions about the agency («نمایندگی»), wherever they are made: a customer's request, support's verdict (the agents
 * page or the report group's buttons), an agent's terms, their traffic set right, the agency ended. Each is one
 * transaction — a verdict a compare-and-swap, the loser told which verdict stood (decided()) — and only the call that
 * decided tells the customer. Approval opens the agent's shop (their bot row and its wallet); a returning agent's shop is
 * switched on again, everything kept.
 */
final class AgencyActions
{
    /** What the customer may write about themselves with their request. */
    public const NOTE_MAX = 500;

    /** Refusals of terms for a customer who never was an agent, or whose agency ended (often a moment ago, elsewhere). */
    public const NOT_AGENT = 'این کاربر نماینده نیست.';
    public const AGENCY_ENDED = 'نمایندگی این کاربر پایان یافته است.';

    /** Refusals of a renewal's end: the program's term to give, an agency with no end to move on, an end not ahead. */
    public const RENEWAL_NO_TERM = 'در تنظیمات نمایندگی مدتی برای نمایندگی تعیین نشده است؛ پایان تازه را خودتان بدهید: در «نمایندگان» پنل یا «⚙️ مدیریت» ربات.';
    public const RENEWAL_NO_END = 'نمایندگی این نماینده دیگر تاریخ پایان ندارد؛ درخواست را رد کنید، یا پایان تازه را خودتان بدهید: در «نمایندگان» پنل یا «⚙️ مدیریت» ربات.';
    public const END_PASSED = 'تاریخ پایان تازه باید بعد از همین حالا باشد.';
    public const END_EARLIER = 'پایان تازه نمی‌تواند زودتر از پایان فعلی نمایندگی باشد؛ برای کوتاه کردن نمایندگی، شرایط فروش نماینده را عوض کنید.';

    /** Why an agent's renewal request is not filed: one waits already, or their agency has no end to move on. */
    public const RENEWAL_WAITING = 'درخواست تمدید نمایندگی شما در انتظار بررسی است؛ نتیجه به شما اعلام می‌شود.';
    public const RENEWAL_NOT_NEEDED = 'نمایندگی شما تاریخ پایان ندارد و تمدید لازم ندارد.';

    /** A renewal request still waiting when the agency ended: closed with it. */
    public const RENEWAL_CLOSED = 'با لغو نمایندگی بسته شد.';

    /** The most traffic the shop sets right at once, in GB. */
    private const ADJUST_MAX_GB = 100_000;

    public function __construct(
        private readonly AgencySettings $settings,
        private readonly AgencyStanding $standing,
        private readonly AgentBots $bots,
        private readonly PaymentMethods $methods,
        private readonly TrafficPool $pool,
        private readonly ShopReports $reports,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
    ) {}

    /** Whether the customer may ask to become an agent: in the main shop, the program on, not an agent, no request waiting. */
    public function mayRequest(User $user): bool
    {
        return CurrentBot::isMain() && $this->settings->enabled() && !$user->isAgent() && $this->pendingRequest($user) === null;
    }

    /** The customer's request to become an agent still waiting for support, if they have one. */
    public function pendingRequest(User $user): ?AgencyRequest
    {
        return self::pending($user, AgencyRequestKind::Join);
    }

    /**
     * Whether the agent may ask for their agency's end to be moved on («درخواست تمدید نمایندگی»): an agent with a shop
     * whose agency has an end, passed or not, and no such request waiting. The program's switch has no say: it closes
     * the door to new agents, not to the ones there are.
     */
    public static function mayRequestRenewal(User $agent): bool
    {
        $bot = $agent->ownBot;

        return $agent->isAgent() && $bot !== null && $bot->agency_expires_at !== null && self::pendingRenewal($agent) === null;
    }

    /** The agent's request for their agency's end to be moved on still waiting for support, if they have one. */
    public static function pendingRenewal(User $agent): ?AgencyRequest
    {
        return self::pending($agent, AgencyRequestKind::Renewal);
    }

    /**
     * Files the agent's request for their agency's end to be moved on — from the main bot, their panel or their own
     * bot's «مدیریت», whichever shop the code runs in —; null when they may not (mayRequestRenewal()). Under a lock on
     * their row, so two taps make one. Its report goes to the main bot's group, with «تایید» / «رد» (AgencyReview).
     */
    public function requestRenewal(User $agent): ?AgencyRequest
    {
        return $this->db->transaction(function () use ($agent): ?AgencyRequest {
            $row = User::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereKey($agent->id)->lockForUpdate()->first();
            if ($row === null || !self::mayRequestRenewal($row)) {
                return null;
            }

            $request = AgencyRequest::query()->create(['user_id' => $row->id, 'kind' => AgencyRequestKind::Renewal->value]);
            $this->reports->agencyRequested($request);

            return $request;
        });
    }

    /**
     * The end the program's term gives a renewal request's agent now (AgencyStanding::renewalEnd()) — what the report
     * group's «تایید» gives, and the panel's when it names none.
     *
     * @throws ValidationException 422 on `expires_at`: the program sets no term, the agent's agency has no end any more,
     *                             or the term ends past the last day the shop keeps (LocalTime::LAST_DAY)
     */
    public function termEnd(AgencyRequest $request): Carbon
    {
        $bot = $request->user->ownBot;
        if ($bot === null || $bot->agency_expires_at === null) {
            throw ValidationException::on('expires_at', self::RENEWAL_NO_END);
        }
        $end = $this->standing->renewalEnd($bot) ?? throw ValidationException::on('expires_at', self::RENEWAL_NO_TERM);
        if (LocalTime::tooLate($end)) {
            throw ValidationException::on('expires_at', LocalTime::tooLateFor('تاریخ پایان نمایندگی') . '؛ مدت نمایندگی را در تنظیمات نمایندگی کوتاه‌تر کنید یا تاریخ پایان را خودتان بنویسید.');
        }

        return $end;
    }

    /**
     * Approves an agent's renewal request: their agency's end becomes `$end` (null: none at all) — never earlier than an
     * end still ahead —, written with the request's verdict in one transaction that takes the agent's row first, as
     * revoke() does. Once committed they are told the renewal, and the end settles as a changed end does
     * (AgencyStanding::extended()): a suspension for an end passed lifts when the new one lies ahead — their bot selling
     * again told after the renewal. Nobody approves their own request.
     *
     * @throws ActorRefusedException 403 their own request
     * @throws ValidationException 422 on `status`: decided meanwhile (decided()), or the agency ended; on `expires_at`:
     *                             an end not ahead, earlier than the end still ahead, or past the last day the shop keeps
     */
    public function renew(AgencyRequest $request, Actor $actor, ?Carbon $end): AgencyRequest
    {
        if (!$request->isRenewal()) {
            throw new \LogicException("Agency request #{$request->id} asks to become an agent, not for a renewal.");
        }
        if ($request->status !== AgencyRequestStatus::Pending) {
            throw ValidationException::on('status', self::decided($request));
        }
        if ($actor->is($request->user_id)) {
            throw ActorRefusedException::ownRequest();
        }
        if ($end !== null && !$end->isFuture()) {
            throw ValidationException::on('expires_at', self::END_PASSED);
        }
        if ($end !== null && LocalTime::tooLate($end)) {
            throw ValidationException::on('expires_at', LocalTime::tooLateFor('تاریخ پایان نمایندگی') . '؛ برای بدون پایان، خالی بگذارید.');
        }

        [$agent, $bot] = $this->db->transaction(function () use ($request, $actor, $end): array {
            $agent = User::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereKey($request->user_id)->lockForUpdate()->first();
            $bot = $agent?->ownBot;
            if ($agent === null || !$agent->isAgent() || $bot === null) {
                // An agency ended closes its renewal (revoke()): one still waiting is told as decided, else as ended.
                throw ValidationException::on('status', $request->refresh()->status !== AgencyRequestStatus::Pending ? self::decided($request) : ($agent === null ? self::NOT_AGENT : self::notAgent($agent)));
            }
            $kept = $bot->agency_expires_at;
            // To the minute, as an end is kept and compared (SellingTerms::same()).
            if ($end !== null && $kept !== null && $kept->isFuture() && intdiv($end->getTimestamp(), 60) < intdiv($kept->getTimestamp(), 60)) {
                throw ValidationException::on('expires_at', self::END_EARLIER);
            }
            if (!Transitions::move($request, 'status', [AgencyRequestStatus::Pending], AgencyRequestStatus::Approved, ['extended_to' => $end, 'reviewer' => $actor->name(), 'decided_at' => now()])) {
                throw ValidationException::on('status', self::decided($request));
            }
            $this->standing->moveEnd($bot, $end);
            $this->reports->agencyDecided($request);

            return [$agent, $bot];
        });

        $this->notifier->agencyRenewed($agent, $end);
        $this->standing->extended($bot);

        return $request;
    }

    /**
     * Files the customer's request; null when they may not (mayRequest()). Under a lock on their row, so two taps make one.
     *
     * @throws ValidationException 422 on `note`: none, or longer than NOTE_MAX
     */
    public function request(User $user, string $note): ?AgencyRequest
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::on('note', $note === '' ? 'چند کلمه درباره خودتان بنویسید.' : Validation::tooLong('توضیح', self::NOTE_MAX));
        }

        return $this->db->transaction(function () use ($user, $note): ?AgencyRequest {
            $row = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($row === null || !$this->mayRequest($row)) {
                return null;
            }

            $request = AgencyRequest::query()->create(['user_id' => $row->id, 'note' => $note]);
            $this->reports->agencyRequested($request);

            return $request;
        });
    }

    /**
     * Approves a request on a level with `$credit` and `$selling` terms (the program's defaults when null). Their shop
     * opens and they are notified. Nobody approves their own request.
     *
     * @throws ActorRefusedException 403 their own request
     * @throws ValidationException 422 on `status`: decided meanwhile (decided()); on `expires_at`: the program's term,
     *                             counted from now, ends past the last day the shop keeps (LocalTime::LAST_DAY)
     */
    public function approve(AgencyRequest $request, Actor $actor, AgencyLevel $level, ?string $credit, ?SellingTerms $selling = null): AgencyRequest
    {
        if ($request->isRenewal()) {
            // Its approval would open the agent's shop afresh (begin()): their minimum's months and a suspension gone.
            throw new \LogicException("Agency request #{$request->id} asks for a renewal: renew() approves it.");
        }
        if ($actor->is($request->user_id)) {
            throw ActorRefusedException::ownRequest();
        }
        $terms = new AgencyTerms($level, $credit ?? $this->settings->defaultCredit(), $selling ?? $this->standing->defaults());
        // An end the owner typed is held to the bound as it is read (SellingTerms::fromInput()); the program's is here.
        $end = $terms->selling?->expiresAt;
        if ($end !== null && LocalTime::tooLate($end)) {
            throw ValidationException::on('expires_at', LocalTime::tooLateFor('تاریخ پایان نمایندگی') . '؛ مدت نمایندگی را در تنظیمات نمایندگی کوتاه‌تر کنید یا تاریخ پایان را خودتان بنویسید.');
        }
        $returning = $this->db->transaction(function () use ($request, $terms, $actor): ?Bot {
            if (!Transitions::move($request, 'status', [AgencyRequestStatus::Pending], AgencyRequestStatus::Approved, ['level_id' => $terms->level->id, 'reviewer' => $actor->name(), 'decided_at' => now()])) {
                throw ValidationException::on('status', self::decided($request));
            }
            $returning = $this->assign($request->user, $terms);
            $this->reports->agencyDecided($request);

            return $returning;
        });

        if ($returning !== null) {
            $this->bots->resume($returning);
        }
        $this->notifier->agencyApproved($request->user);

        return $request;
    }

    /**
     * Rejects a request — to become an agent, or a renewal — with the reason the customer reads (or none).
     *
     * @throws ValidationException 422 on `status`: decided meanwhile (decided())
     */
    public function reject(AgencyRequest $request, Actor $actor, ?string $reason): AgencyRequest
    {
        $this->db->transaction(function () use ($request, $reason, $actor): void {
            if (!Transitions::move($request, 'status', [AgencyRequestStatus::Pending], AgencyRequestStatus::Rejected, ['reason' => $reason, 'reviewer' => $actor->name(), 'decided_at' => now()])) {
                throw ValidationException::on('status', self::decided($request));
            }
            $this->reports->agencyDecided($request);
        });
        if ($request->isRenewal()) {
            $this->notifier->agencyRenewalRejected($request->user, $reason);
        } else {
            $this->notifier->agencyRejected($request->user, $reason);
        }

        return $request;
    }

    /**
     * Changes an agent's level or credit, by a conditional write that only lands while they are an agent (so an agency
     * ended at the same moment is not given back), and their terms of selling when given (AgencyStanding::change()).
     * Returns whether they were told; null when nothing changed.
     *
     * @throws ValidationException 422 on `status`: not an agent
     */
    public function change(User $agent, AgencyTerms $terms): ?Delivery
    {
        $changed = $agent->agency_level_id !== $terms->level->id || Money::compare($agent->credit_limit, $terms->credit) !== 0;
        if (!self::whileAgent($agent, ['agency_level_id' => $terms->level->id, 'credit_limit' => $terms->credit]) || $agent->refresh()->agencyLevel === null) {
            throw ValidationException::on('status', self::notAgent($agent));
        }
        $bot = $agent->ownBot;
        if ($terms->selling !== null && $bot !== null && $this->standing->change($bot, $terms->selling)) {
            $changed = true;
        }

        return $changed ? $this->notifier->agencyChanged($agent) : null;
    }

    /**
     * Ends the agency: the level and credit go (a debt stays owed), their bot stops (Bot::status()) and its webhook is
     * taken down, their panel sessions and login link end, and a renewal request of theirs still waiting is closed
     * (RENEWAL_CLOSED; the agency's end tells them). Their shop is kept for a return. Of two at once, one does it.
     *
     * @throws ValidationException 422 on `status`: not an agent
     */
    public function revoke(User $agent, ?string $note): void
    {
        $bot = $this->db->transaction(function () use ($agent): ?Bot {
            if (!self::whileAgent($agent, ['agency_level_id' => null, 'credit_limit' => '0'])) {
                throw ValidationException::on('status', self::notAgent($agent));
            }
            AgencyRequest::query()->where('user_id', $agent->id)->where('kind', AgencyRequestKind::Renewal->value)->where('status', AgencyRequestStatus::Pending->value)
                ->update(['status' => AgencyRequestStatus::Rejected->value, 'reason' => self::RENEWAL_CLOSED, 'decided_at' => now()]);
            $bot = $agent->refresh()->ownBot;
            if ($bot !== null) {
                Bot::query()->whereKey($bot->id)->increment('panel_epoch', 1, ['login_code' => null, 'login_code_expires_at' => null]);
            }

            return $bot;
        });

        if ($bot !== null) {
            $this->bots->takeDown($bot->refresh());
        }
        $this->notifier->agencyRevoked($agent, $note);
    }

    /**
     * Adds or (with a minus sign) removes GB of an agent's traffic, with the note they read on their ledger — under the
     * request's Idempotency-Key `$key` when it sent one: the same adjustment asked again is answered by the line it wrote,
     * never made twice (TrafficPool::adjust()).
     *
     * @param array<string, mixed> $input {gb, note?}
     * @throws ValidationException 422 on `status` (not an agent), `gb` (no amount, or more than the pool has) or `note`
     * @throws KeyReusedException 422 on `idempotency_key`: the key wrote another line
     */
    public function adjustTraffic(User $agent, Actor $actor, array $input, ?string $key = null): void
    {
        if (!$agent->isAgent()) {
            throw ValidationException::on('status', self::notAgent($agent));
        }
        $note = Input::note($input, 'note', Ledger::NOTE_MAX);
        $text = Input::text($input, 'gb');
        $less = in_array(mb_substr($text, 0, 1), ['-', '−'], true);
        $gb = Input::decimalOf($less ? mb_substr($text, 1) : $text);
        if ($gb === null || (float) $gb <= 0 || (float) $gb > self::ADJUST_MAX_GB) {
            throw ValidationException::on('gb', 'حجم را به گیگابایت وارد کنید؛ برای کم کردن، عدد منفی.');
        }

        $bot = $agent->ownBot ?? throw new \LogicException("Agent #{$agent->id} has no shop.");
        $this->pool->adjust($bot, $actor, ($less ? -1 : 1) * Traffic::bytesOfGb($gb), $note, $key);
    }

    /**
     * Writes the agent's terms and opens their shop (bot row and wallet the first time), its terms of selling begun afresh
     * (AgencyStanding::begin()), in the caller's transaction. Returns their existing bot when it must be resumed after the
     * commit (AgentBots::resume()).
     */
    private function assign(User $agent, AgencyTerms $terms): ?Bot
    {
        $returning = !$agent->isAgent();
        $agent->forceFill(['agency_level_id' => $terms->level->id, 'credit_limit' => $terms->credit])->save();
        $agent->setRelation('agencyLevel', $terms->level);

        $bot = $agent->ownBot;
        if ($bot !== null) {
            $this->standing->begin($bot, $terms->selling ?? $this->standing->defaults());

            return $returning ? $bot : null;
        }

        $bot = $agent->ownBot()->create();
        $agent->setRelation('ownBot', $bot);
        $this->standing->begin($bot, $terms->selling ?? $this->standing->defaults());
        CurrentBot::run($bot, fn() => $this->methods->createBuiltins());

        return null;
    }

    /** The verdict that stood, for a verdict refused because the request was decided first. */
    public static function decided(AgencyRequest $request): string
    {
        return match ($request->status) {
            AgencyRequestStatus::Approved => 'این درخواست تایید شده است.',
            AgencyRequestStatus::Rejected => 'این درخواست رد شده است.',
            AgencyRequestStatus::Pending => 'این درخواست هنوز در انتظار بررسی است.',
        };
    }

    /** The customer's request of `$kind` still waiting for support, if they have one. */
    private static function pending(User $user, AgencyRequestKind $kind): ?AgencyRequest
    {
        return AgencyRequest::query()->where('user_id', $user->id)->where('kind', $kind->value)->where('status', AgencyRequestStatus::Pending->value)->latest('id')->first();
    }

    /** Why terms are refused: never an agent, or no longer one (their shop is kept when the agency ends). */
    private static function notAgent(User $user): string
    {
        return $user->ownBot()->exists() ? self::AGENCY_ENDED : self::NOT_AGENT;
    }

    /**
     * Writes the agency columns only while the customer is an agent — one conditional UPDATE; true when it wrote them.
     *
     * @param array{agency_level_id: int|null, credit_limit: string} $terms
     */
    private static function whileAgent(User $agent, array $terms): bool
    {
        return $agent->newModelQuery()->whereKey($agent->id)->whereNotNull('agency_level_id')->update($terms) === 1;
    }
}
