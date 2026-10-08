<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Broadcasts;

use App\Core\Database\Lease;
use App\Core\Database\Transitions;
use App\Core\Exceptions\ValidationException;
use App\Modules\Notifications\Services\CustomerChats;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Pacer;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Handlers\BroadcastHandler;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\Broadcast;
use App\Modules\Telegram\Models\BroadcastPin;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * «ارسال همگانی»: a bot admin's message copied — or forwarded — to an Audience of customers, with the admin's link
 * buttons under a copy and each message pinned when they asked; and «لغو پین», the same pins taken off again. Sending is
 * paced (Api\Pacer: Telegram takes about 30 messages a second) and done in batches by the one worker holding the run's lease: the
 * bot for the first few right after the admin pressed «ارسال», then Tasks\SendBroadcastsTask every minute — past a
 * cursor, each customer claimed before their message goes, so none gets it twice. The admin pauses, resumes or cancels
 * it (control(): a compare-and-swap on its status, so the worker stops at the next customer) from the bot's live
 * progress message — which the worker redraws every few seconds — or from the panel. A customer who turned the bot away
 * is counted, not sent to, and learned when Telegram says so (CustomerChats); a flood limit Telegram imposes stops the
 * batch where it was, for the next one to carry on.
 */
final class BroadcastService
{
    /** The admin's link buttons: rows, and buttons a row. */
    public const BUTTON_ROWS = 8;
    public const BUTTONS_PER_ROW = 4;

    private const CHUNK = 100;

    /** How long a worker holds a run without moving on — longer than one customer can take. */
    private const LEASE_SECONDS = 60;

    /** How often a worker redraws the admin's progress message. */
    private const PROGRESS_SECONDS = 4;

    /** What the panel's list keeps of a message's text. */
    private const EXCERPT_MAX = 200;

    /** What became of one customer: the run's counter it goes under. A flood limit is none of them (null). */
    private const SENT = 'sent';
    private const BLOCKED = 'blocked';
    private const FAILED = 'failed';

    public function __construct(
        private readonly BotApi $api,
        private readonly CustomerChats $chats,
        private readonly Pacer $pacer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Start a run of the message a bot admin sent in their chat with the bot, as their draft says (mode, audience, pin,
     * buttons); its progress is drawn in `$progressMessageId`, a message of the bot's in that chat.
     *
     * @param array{message_id: int, content?: string|null, excerpt?: string|null, mode: BroadcastMode, audience: string, audience_id?: int|null, pin: bool, buttons?: list<list<array{text: string, url: string}>>} $draft
     * @throws ValidationException on `audience` when it names a group or a server that is gone: it reaches nobody
     */
    public function start(User $admin, array $draft, ?int $progressMessageId): Broadcast
    {
        $audience = Audience::of($draft['audience'], $draft['audience_id'] ?? null) ?? throw ValidationException::on('audience', Messages::BROADCAST_AUDIENCE_GONE);
        $forward = $draft['mode'] === BroadcastMode::Forward;

        return Broadcast::query()->create([
            'kind' => BroadcastKind::Message,
            'user_id' => $admin->id,
            'message_id' => $draft['message_id'],
            'mode' => $draft['mode'],
            'audience' => $audience->key,
            'audience_id' => $audience->id,
            'pin' => $draft['pin'],
            'buttons' => !$forward && ($draft['buttons'] ?? []) !== [] ? $draft['buttons'] : null,
            'content' => $draft['content'] ?? null,
            'excerpt' => isset($draft['excerpt']) ? mb_substr($draft['excerpt'], 0, self::EXCERPT_MAX) : null,
            'status' => BroadcastStatus::Sending,
            'total' => $audience->users()->count(),
            'progress_message_id' => $progressMessageId,
        ]);
    }

    /**
     * Start taking a pinned broadcast's pins off again — from the bot (`$admin`, who then sends the run its progress
     * message) or the panel (`$reviewer`, no progress message).
     *
     * @throws ValidationException on `status` when there is nothing to take off, or it is being done already
     */
    public function startUnpin(Broadcast $source, ?User $admin, ?string $reviewer): Broadcast
    {
        if (!$source->canUnpin()) {
            throw ValidationException::on('status', match (true) {
                $source->isUnpin() || !$source->pin => Messages::BROADCAST_UNPIN_NOT_PINNED,
                $source->isOpen() => Messages::BROADCAST_UNPIN_OPEN,
                default => Messages::BROADCAST_UNPIN_NOTHING,
            });
        }

        return Broadcast::query()->create([
            'kind' => BroadcastKind::Unpin,
            'source_id' => $source->id,
            'user_id' => $admin?->id,
            'reviewer' => $reviewer,
            'pin' => false,
            'status' => BroadcastStatus::Sending,
            'total' => $source->pins()->count(),
        ]);
    }

    /**
     * Work on the run until `$limit` customers are handled or `$deadline` (a unix time with fraction) passes, while no
     * one else does; true when it is over (done now, or done or cancelled before), false while there is more — or it is
     * paused, or another worker has it.
     */
    public function process(Broadcast $broadcast, int $limit, ?float $deadline = null): bool
    {
        if (!$broadcast->isOpen()) {
            return true;
        }
        $lease = Lease::take($broadcast, self::LEASE_SECONDS, static fn(Builder $query) => $query->where('status', BroadcastStatus::Sending->value));
        if ($lease === null) {
            return false;
        }

        $late = static fn(): bool => $deadline !== null && microtime(true) >= $deadline;
        $shown = microtime(true);
        try {
            $handled = 0;
            while ($handled < $limit && !$late()) {
                $batch = $this->next($broadcast, min(self::CHUNK, $limit - $handled));
                if ($batch->isEmpty()) {
                    return $this->finish($broadcast, $lease);
                }

                foreach ($batch as $recipient) {
                    if ($late()) {
                        break;
                    }
                    $userId = $recipient instanceof BroadcastPin ? $recipient->user_id : $recipient->id;
                    $previous = $broadcast->last_user_id;
                    // Claimed before the message goes: paused, cancelled or taken over meanwhile, the run stops here.
                    if (!$lease->write(['last_user_id' => $userId])) {
                        return false;
                    }

                    $outcome = $recipient instanceof BroadcastPin ? $this->unpin($recipient) : $this->deliver($broadcast, $recipient);
                    if ($outcome === null) {
                        // Telegram wants a longer pause than a worker sleeps: this customer waits for the next batch.
                        $lease->move('last_user_id', $userId, $previous);

                        return false;
                    }
                    // Counted whatever became of the run meanwhile: what was done is done.
                    Broadcast::query()->whereKey($broadcast->id)->increment($outcome);
                    $broadcast->setAttribute($outcome, $broadcast->getAttribute($outcome) + 1)->syncOriginalAttribute($outcome);
                    $handled++;

                    if (microtime(true) - $shown >= self::PROGRESS_SECONDS) {
                        $this->showProgress($broadcast);
                        $shown = microtime(true);
                    }
                }
            }

            return false;
        } finally {
            $lease->release();
            if ($broadcast->isOpen()) {
                $this->showProgress($broadcast);
            }
        }
    }

    /**
     * Pause, resume or cancel a run — a compare-and-swap on its status; on success its worker stops at the next customer
     * and the admin's progress message shows the new state. True when this call moved it.
     */
    public function control(Broadcast $broadcast, BroadcastControl $control): bool
    {
        $moved = Transitions::move($broadcast, 'status', $control->movesFrom(), $control->movesTo(), $control === BroadcastControl::Cancel ? ['finished_at' => now()] : []);
        if ($moved) {
            $this->logger->info('Broadcast {id} is {status} now', ['id' => $broadcast->id, 'status' => $broadcast->status->value]);
            $this->showProgress($broadcast);
        }

        return $moved;
    }

    /** Redraw the admin's progress message, when the run has one: its state, its numbers, the buttons it allows. */
    public function showProgress(Broadcast $broadcast): void
    {
        $chat = $broadcast->adminChat();
        if ($broadcast->progress_message_id === null || $chat === null) {
            return;
        }

        $broadcast->refresh();
        try {
            $this->api->editMessageText($chat, $broadcast->progress_message_id, $this->progressText($broadcast), ['reply_markup' => $this->progressKeyboard($broadcast)]);
        } catch (TelegramApiException $e) {
            $this->logger->info('Broadcast {id} progress not shown: {message}', ['id' => $broadcast->id, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Tell the admin a message run they sent from the bot went to everyone (the scheduler's way; inline, they are
     * watching it) — not one they cancelled, nor an unpin run.
     */
    public function report(Broadcast $broadcast): void
    {
        $chat = $broadcast->adminChat();
        if ($broadcast->isUnpin() || $broadcast->status !== BroadcastStatus::Done || $broadcast->progress_message_id === null || $chat === null) {
            return;
        }

        try {
            $this->api->sendMessage($chat, Messages::fill(Messages::BROADCAST_DONE, [
                'id' => (string) $broadcast->id,
                'sent' => Persian::number($broadcast->sent),
                'blocked' => Persian::number($broadcast->blocked),
                'failed' => Persian::number($broadcast->failed),
            ]));
        } catch (TelegramApiException $e) {
            $this->logger->warning('Could not report broadcast {id} to its admin: {message}', ['id' => $broadcast->id, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The admin's link buttons, typed in the bot — a row a line, buttons on a line apart by «|», each «text - link» (a
     * link: http(s) or tg://), a label as long as a keyboard's.
     *
     * @return list<list<array{text: string, url: string}>>
     * @throws ValidationException on `buttons`, with the line that is not right, or too many
     */
    public static function parseButtons(string $typed): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', trim($typed)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = [];
            foreach (explode('|', $line) as $part) {
                $part = trim($part);
                if (preg_match('~^(.+?)\s+[-–—]\s+((?:https?|tg)://\S+)$~iu', $part, $m) !== 1 || mb_strlen(trim($m[1])) > KeyboardLayouts::LABEL_MAX
                    || (str_starts_with(strtolower($m[2]), 'http') && filter_var($m[2], FILTER_VALIDATE_URL) === false)) {
                    throw ValidationException::on('buttons', Messages::fill(Messages::BROADCAST_BUTTONS_INVALID, ['line' => htmlspecialchars(mb_substr($part, 0, 60)), 'max' => (string) KeyboardLayouts::LABEL_MAX]));
                }
                $row[] = ['text' => trim($m[1]), 'url' => $m[2]];
            }
            $rows[] = $row;
        }

        if ($rows === [] || count($rows) > self::BUTTON_ROWS || max(array_map('count', $rows)) > self::BUTTONS_PER_ROW) {
            throw ValidationException::on('buttons', Messages::fill(Messages::BROADCAST_BUTTONS_TOO_MANY, ['rows' => (string) self::BUTTON_ROWS, 'per_row' => (string) self::BUTTONS_PER_ROW]));
        }

        return $rows;
    }

    /** The progress message's words. */
    private function progressText(Broadcast $broadcast): string
    {
        $values = [
            'status' => $broadcast->status->label(),
            'sent' => Persian::number($broadcast->sent),
            'failed' => Persian::number($broadcast->failed),
            'done' => Persian::number(min($broadcast->total, $broadcast->sent + $broadcast->blocked + $broadcast->failed)),
            'total' => Persian::number($broadcast->total),
        ];

        if ($broadcast->isUnpin()) {
            return Messages::fill(Messages::UNPIN_PROGRESS, $values + ['source' => (string) $broadcast->source_id]);
        }
        ['key' => $audience, 'id' => $audienceId] = $broadcast->audienceRef();

        return Messages::fill(Messages::BROADCAST_PROGRESS, $values + [
            'id' => (string) $broadcast->id,
            'audience' => htmlspecialchars(Audience::labelOf($audience, $audienceId)),
            'pin' => $broadcast->pin ? Messages::BROADCAST_PROGRESS_PINNED : '',
            'blocked' => Persian::number($broadcast->blocked),
        ]);
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>} The buttons the run's state allows. */
    private function progressKeyboard(Broadcast $broadcast): array
    {
        $cancel = InlineKeyboard::callback(Messages::BROADCAST_STOP, BroadcastHandler::controlCallback(BroadcastControl::Cancel->value, $broadcast->id), 'danger');
        $keyboard = InlineKeyboard::make();
        if ($broadcast->status === BroadcastStatus::Sending) {
            $keyboard->row($cancel, InlineKeyboard::callback(Messages::BROADCAST_PAUSE, BroadcastHandler::controlCallback(BroadcastControl::Pause->value, $broadcast->id)));
        } elseif ($broadcast->status === BroadcastStatus::Paused) {
            $keyboard->row($cancel, InlineKeyboard::callback(Messages::BROADCAST_RESUME, BroadcastHandler::controlCallback(BroadcastControl::Resume->value, $broadcast->id), 'success'));
        }
        if ($broadcast->canUnpin()) {
            $keyboard->row(InlineKeyboard::callback(Messages::BROADCAST_UNPIN, BroadcastHandler::controlCallback(BroadcastHandler::UNPIN, $broadcast->id)));
        }

        return $keyboard->build();
    }

    /**
     * The next recipients past the cursor: a message run's audience, an unpin run's pins (by customer). An audience whose
     * group or server is gone has nobody left to reach.
     *
     * @return Collection<int, User>|Collection<int, BroadcastPin>
     */
    private function next(Broadcast $broadcast, int $limit): Collection
    {
        if ($broadcast->isUnpin()) {
            return BroadcastPin::query()->where('broadcast_id', $broadcast->source_id)->where('user_id', '>', $broadcast->last_user_id)->orderBy('user_id')->with('user')->limit($limit)->get();
        }

        ['key' => $key, 'id' => $id] = $broadcast->audienceRef();

        return Audience::of($key, $id)?->users()->where('id', '>', $broadcast->last_user_id)->oldest('id')->limit($limit)->get() ?? new Collection();
    }

    /**
     * One customer's message: skipped when they turned the bot away, copied (with the admin's buttons) or forwarded, and
     * pinned when the run says so — a pin that fails does not undo the message.
     *
     * @return self::SENT|self::BLOCKED|self::FAILED|null Null: a flood limit, for the next batch to try again
     */
    private function deliver(Broadcast $broadcast, User $user): ?string
    {
        if ($user->bot_blocked) {
            return self::BLOCKED;
        }

        [$chat, $original] = $broadcast->original();
        try {
            $messageId = $broadcast->message()->mode === BroadcastMode::Forward
                ? $this->api->forwardMessage($user->telegram_id, $chat, $original)
                : $this->api->copyMessage($user->telegram_id, $chat, $original, self::markup($broadcast));
        } catch (TelegramApiException $e) {
            $this->pacer->pace();

            return match (true) {
                $e->is(Refusal::FloodWait) => null,
                $this->chats->turnedAway($user, $e) => self::BLOCKED,
                default => $this->failed($broadcast, $user, $e),
            };
        }
        $this->pacer->pace();

        if ($broadcast->pin && $messageId > 0) {
            try {
                $this->api->pinChatMessage($user->telegram_id, $messageId);
                BroadcastPin::query()->insert(['broadcast_id' => $broadcast->id, 'user_id' => $user->id, 'message_id' => $messageId]);
            } catch (TelegramApiException $e) {
                $this->logger->info('Broadcast {id} message to user {user} not pinned: {message}', ['id' => $broadcast->id, 'user' => $user->id, 'message' => $e->getMessage()]);
            }
            $this->pacer->pace();
        }

        return self::SENT;
    }

    /**
     * One pin taken off, in its customer's chat; a pin Telegram no longer has (the customer deleted it, or the chat) —
     * or one in the chat of a customer who has no Telegram account any more (taken off it on the website: there is no
     * chat to ask) — counts as off.
     *
     * @return self::SENT|self::FAILED|null Null: a flood limit, for the next batch to try again
     */
    private function unpin(BroadcastPin $pin): ?string
    {
        $chat = $pin->user->telegram_id;
        if ($chat !== null) {
            try {
                $this->api->unpinChatMessage($chat, $pin->message_id);
            } catch (TelegramApiException $e) {
                $this->pacer->pace();
                if ($e->is(Refusal::FloodWait)) {
                    return null;
                }
                if (!$e->is(Refusal::Forbidden, Refusal::ChatGone, Refusal::BadRequest)) {
                    $this->logger->warning('Pin of broadcast {id} not taken off for user {user}: {message}', ['id' => $pin->broadcast_id, 'user' => $pin->user_id, 'message' => $e->getMessage()]);

                    return self::FAILED;
                }
            }
            $this->pacer->pace();
        }
        BroadcastPin::query()->where('broadcast_id', $pin->broadcast_id)->where('user_id', $pin->user_id)->delete();

        return self::SENT;
    }

    /** @return self::FAILED */
    private function failed(Broadcast $broadcast, User $user, TelegramApiException $e): string
    {
        $this->logger->warning('Broadcast {id} could not reach user {user}: {message}', ['id' => $broadcast->id, 'user' => $user->id, 'message' => $e->getMessage()]);

        return self::FAILED;
    }

    /** Everyone has been through: done. True when it is over — done by this call, or cancelled meanwhile. */
    private function finish(Broadcast $broadcast, Lease $lease): bool
    {
        $done = $lease->finish(['status' => BroadcastStatus::Done, 'finished_at' => now()]);
        $this->showProgress($broadcast);
        if ($done) {
            $this->logger->info('Broadcast {id} finished: {sent} sent, {blocked} blocked, {failed} failed', ['id' => $broadcast->id, 'sent' => $broadcast->sent, 'blocked' => $broadcast->blocked, 'failed' => $broadcast->failed]);
        }

        return $done || !$broadcast->refresh()->isOpen();
    }

    /** @return array<string, mixed> A copy's link buttons, as reply_markup — none for a run without them. */
    private static function markup(Broadcast $broadcast): array
    {
        $rows = $broadcast->buttons ?? [];
        if ($rows === []) {
            return [];
        }

        $keyboard = InlineKeyboard::make();
        foreach ($rows as $row) {
            // Rows are typed in reading order; Telegram lays them out left to right.
            $keyboard->row(...array_map(static fn(array $button): array => InlineKeyboard::url($button['text'], $button['url']), array_reverse($row)));
        }

        return ['reply_markup' => $keyboard->build()];
    }
}
