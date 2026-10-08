<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Database\Ledger;
use App\Core\Database\Transitions;
use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\DTO\AgencyTerms;
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
use App\Support\Money;
use App\Support\Traffic;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;

/**
 * «نمایندگی», what is decided about it — wherever: a customer's request from the main bot, support's verdict on it from
 * the agents page or the report group's buttons, an agent's terms changed, their traffic set right, their agency ended.
 * Each decision is one transaction (a request's a compare-and-swap: of two verdicts in the same moment one stands and
 * the other is refused with the one that stood, decided()), reported to the report group, and told to the customer by
 * the call that made it; a surface renders its own answer and nothing more. An approved agent's shop opens with the
 * approval — their bot's row and its wallet; an agency given back switches it on again, everything kept.
 */
final class AgencyActions
{
    /** What the customer may write about themselves with their request. */
    public const NOTE_MAX = 500;

    /** Terms asked of a customer who never was an agent; one whose agency ended — most often a moment ago, elsewhere — is AGENCY_ENDED. */
    public const NOT_AGENT = 'این کاربر نماینده نیست.';
    public const AGENCY_ENDED = 'نمایندگی این کاربر پایان یافته است.';

    /** The most traffic the shop sets right at once, in GB. */
    private const ADJUST_MAX_GB = 100_000;

    public function __construct(
        private readonly AgencySettings $settings,
        private readonly AgentBots $bots,
        private readonly PaymentMethods $methods,
        private readonly TrafficPool $pool,
        private readonly ShopReports $reports,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
    ) {}

    /** Whether the customer may ask to become an agent now: in the main bot, while the program takes requests, not one already, none of theirs waiting. */
    public function mayRequest(User $user): bool
    {
        return CurrentBot::isMain() && $this->settings->enabled() && !$user->isAgent() && $this->pendingRequest($user) === null;
    }

    /** The customer's request still waiting for support, if they have one. */
    public function pendingRequest(User $user): ?AgencyRequest
    {
        return AgencyRequest::query()->where('user_id', $user->id)->where('status', AgencyRequestStatus::Pending->value)->latest('id')->first();
    }

    /**
     * The customer asks to become an agent, with a few words about themselves. Null when they may not (mayRequest()) —
     * decided under a lock on their row, so two taps make one request.
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
     * Approve a request on a level, the agent starting with `$credit` — the program's default when none is given (the
     * report group's way). Their shop opens; they are told their terms. A bot admin is the shop's customer too: their own
     * request is another admin's to approve.
     *
     * @throws ActorRefusedException 403 the request of the admin who pressed (the report group's buttons)
     * @throws ValidationException 422 on `status`: decided meanwhile — said as it was (decided())
     */
    public function approve(AgencyRequest $request, Actor $actor, AgencyLevel $level, ?string $credit): AgencyRequest
    {
        if ($actor->is($request->user_id)) {
            throw ActorRefusedException::ownRequest();
        }
        $terms = new AgencyTerms($level, $credit ?? $this->settings->defaultCredit());
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
     * Reject a request, with the reason the customer reads (or none).
     *
     * @throws ValidationException 422 on `status`: decided meanwhile — said as it was (decided())
     */
    public function reject(AgencyRequest $request, Actor $actor, ?string $reason): AgencyRequest
    {
        $this->db->transaction(function () use ($request, $reason, $actor): void {
            if (!Transitions::move($request, 'status', [AgencyRequestStatus::Pending], AgencyRequestStatus::Rejected, ['reason' => $reason, 'reviewer' => $actor->name(), 'decided_at' => now()])) {
                throw ValidationException::on('status', self::decided($request));
            }
            $this->reports->agencyDecided($request);
        });
        $this->notifier->agencyRejected($request->user, $reason);

        return $request;
    }

    /**
     * Move an agent to another level, or change their credit — only while they are one (a conditional write: an agency
     * ended in the same moment is not given back by it); they are told their new terms, and the answer is whether they
     * were — null when the terms are the ones they had: nothing changed, nobody told.
     *
     * @throws ValidationException 422 on `status`: not an agent — one whose agency ended in the same moment too
     */
    public function change(User $agent, AgencyTerms $terms): ?Delivery
    {
        $changed = $agent->agency_level_id !== $terms->level->id || Money::compare($agent->credit_limit, $terms->credit) !== 0;
        if (!self::whileAgent($agent, ['agency_level_id' => $terms->level->id, 'credit_limit' => $terms->credit]) || $agent->refresh()->agencyLevel === null) {
            throw ValidationException::on('status', self::notAgent($agent));
        }

        return $changed ? $this->notifier->agencyChanged($agent) : null;
    }

    /**
     * End the agency: the customer pays and spends as any other from now on (a balance below zero stays owed), their bot
     * goes off with it (Bot::status()) — its webhook taken down —, their panel sessions end and an unused login link
     * with them. Their shop is kept for when the agency is given back. Of two in the same moment, one ends it and tells
     * them, with support's note.
     *
     * @throws ValidationException 422 on `status`: not an agent
     */
    public function revoke(User $agent, ?string $note): void
    {
        $bot = $this->db->transaction(function () use ($agent): ?Bot {
            if (!self::whileAgent($agent, ['agency_level_id' => null, 'credit_limit' => '0'])) {
                throw ValidationException::on('status', self::notAgent($agent));
            }
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
     * Set an agent's traffic right — more GB, or less (a minus sign, either kind, Persian digits as typed) —, with the
     * note they read on their ledger.
     *
     * @param array<string, mixed> $input {gb, note?}
     * @throws ValidationException 422 on `status` (not an agent), `gb` (no amount, or more than the pool has) or `note`
     */
    public function adjustTraffic(User $agent, Actor $actor, array $input): void
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
        $this->pool->adjust($bot, $actor, ($less ? -1 : 1) * Traffic::bytesOfGb($gb), $note);
    }

    /**
     * The agent's terms written and their shop open — in the caller's transaction: their bot's row and its wallet made the
     * first time. Answers their bot when it was off and must run again — after the commit, AgentBots::resume().
     */
    private function assign(User $agent, AgencyTerms $terms): ?Bot
    {
        $returning = !$agent->isAgent();
        $agent->forceFill(['agency_level_id' => $terms->level->id, 'credit_limit' => $terms->credit])->save();
        $agent->setRelation('agencyLevel', $terms->level);

        $bot = $agent->ownBot;
        if ($bot !== null) {
            return $returning ? $bot : null;
        }

        $bot = $agent->ownBot()->create();
        $agent->setRelation('ownBot', $bot);
        CurrentBot::run($bot, fn() => $this->methods->createBuiltins());

        return null;
    }

    /**
     * A verdict refused because the request was decided first — on the page, in the report group, a moment ago —: what
     * that decision was (the request as read after it).
     */
    public static function decided(AgencyRequest $request): string
    {
        return match ($request->status) {
            AgencyRequestStatus::Approved => 'این درخواست تایید شده است.',
            AgencyRequestStatus::Rejected => 'این درخواست رد شده است.',
            AgencyRequestStatus::Pending => 'این درخواست هنوز در انتظار بررسی است.',
        };
    }

    /** Terms refused because the customer is not an agent: never was, or no longer — their shop is kept when it ends. */
    private static function notAgent(User $user): string
    {
        return $user->ownBot()->exists() ? self::AGENCY_ENDED : self::NOT_AGENT;
    }

    /**
     * Write the customer's agency columns only while they are an agent — one conditional UPDATE: true when this call
     * wrote them.
     *
     * @param array{agency_level_id: int|null, credit_limit: string} $terms
     */
    private static function whileAgent(User $agent, array $terms): bool
    {
        return $agent->newModelQuery()->whereKey($agent->id)->whereNotNull('agency_level_id')->update($terms) === 1;
    }
}
