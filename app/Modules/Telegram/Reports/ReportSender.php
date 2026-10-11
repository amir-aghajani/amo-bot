<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Config\Repository as Config;
use App\Core\Database\Lease;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Users\Services\CustomerPictures;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * Sends the queued reports to their topics in the report group: the lowest Topic::priority() first, each oldest first,
 * no more than Telegram's 20 a minute per group. A flood limit, an unreachable Telegram or trouble with the group holds
 * every report back for a while (ReportGroup::hold()) — nothing is lost and nothing sleeps. A deleted topic is made
 * again, a closed one reopened. A report with a picture goes as the photo with the report as caption, or as text when the
 * picture cannot go; markup Telegram cannot parse goes as plain words.
 *
 * Each row is leased while it is sent (longer than a send can take) and marked sent only while still held, so the
 * poller, a cron run and a webhook request never send one twice. Called after every update and by SendReportsTask.
 */
final class ReportSender
{
    /** Telegram's 20 a minute in a group, less room for the bot's own topic-making and replies. */
    public const PER_MINUTE = Limits::GROUP_PER_MINUTE - 2;

    /**
     * The most Telegram calls one send makes: making its topic (3), posting (3: picture, words, plain words), making or
     * reopening the topic again and posting once more (3 + 3), and taking the settled report's buttons off (1).
     */
    private const CALLS_A_SEND = 3 + 3 + 3 + 3 + 1;

    /** A row whose senders went quiet this many times (a crash mid-send) is given up on. */
    private const MAX_ATTEMPTS = 3;

    /** Rows looked at for one nobody holds. */
    private const CANDIDATES = 5;

    /** Days a sent or given-up row is kept (a later report may reply to it), and hours a row may wait to be sent. */
    private const KEEP_DAYS = 7;
    private const EXPIRE_HOURS = 24;

    /** Under a report whose picture could not be copied (the customer deleted their message, or blocked the bot). */
    private const NO_PICTURE = '🖼 تصویر کپی نشد؛ در پنل مدیریت دیده می‌شود.';

    /** Under a report whose uploaded picture Telegram would not take as a photo. */
    private const NOT_SENT = '🖼 تصویر فرستاده نشد؛ در پنل مدیریت دیده می‌شود.';

    /** Under a report whose uploaded picture is gone from the shop's files. */
    private const NO_FILE = '🖼 فایل این تصویر روی سرور پیدا نشد.';

    public function __construct(
        private readonly BotApi $api,
        private readonly ReportGroupState $state,
        private readonly ReportGroup $group,
        private readonly ReportTopics $topics,
        private readonly GroupButtons $groupButtons,
        private readonly CustomerPictures $pictures,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Send what waits for up to `$seconds`, as many as this minute has room for; returns how many went out. With nothing
     * waiting it costs two reads (the group's row and the queue), since it runs after every update.
     */
    public function flush(float $seconds = 3.0): int
    {
        $chatId = $this->state->sendingTo();
        if ($chatId === null) {
            return 0;
        }

        $deadline = microtime(true) + $seconds;
        $sent = 0;
        while (microtime(true) < $deadline) {
            $claimed = $this->claimNext();
            if ($claimed === null) {
                break;
            }

            [$message, $lease] = $claimed;
            try {
                $sentId = $this->deliver($message, $lease, $chatId);
            } catch (TelegramApiException $e) {
                if ($this->group->hold($e)) {
                    break;
                }
                $lease->finish(['failed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 255)]);
                $this->logger->warning('Report {id} was refused and dropped: {message}', ['id' => $message->id, 'message' => $e->getMessage()]);

                continue;
            } finally {
                $lease->release();
            }

            if ($sentId === null) {
                // Someone else is making the topic right now: everything waits for it, in order.
                break;
            }
            $sent++;
        }

        return $sent;
    }

    /**
     * Forget rows sent or given up on KEEP_DAYS ago (but tickets' reports: pruneTickets()) and give up on rows waiting
     * too long. Both halves are ranges of the pruning index under a null ticket, so a run with nothing old reads nothing.
     */
    public function prune(): void
    {
        self::old(ReportMessage::query()->whereNull('ticket_id'))->delete();
        ReportMessage::waiting()->where('created_at', '<', now()->subHours(self::EXPIRE_HOURS))->update(['failed_at' => now(), 'error' => 'Waited too long to be sent.'] + Lease::FREE);
    }

    /**
     * Forget old reports of closed (or deleted) tickets. Those of an open ticket stay however old: a reply to any of them
     * still answers the ticket (TicketReplies). Runs hourly with the tickets' housekeeping.
     */
    public function pruneTickets(): void
    {
        self::old(ReportMessage::query()->whereNotNull('ticket_id'))
            ->whereNotIn('ticket_id', Ticket::query()->select('id')->where('status', '!=', TicketStatus::Closed->value))
            ->delete();
    }

    /**
     * Of `$query`, what was sent, or given up on without being sent, KEEP_DAYS ago.
     *
     * @param Builder<ReportMessage> $query
     * @return Builder<ReportMessage>
     */
    private static function old(Builder $query): Builder
    {
        $old = now()->subDays(self::KEEP_DAYS);

        return $query->where(static fn(Builder $rows) => $rows
            ->where('sent_at', '<', $old)
            ->orWhere(static fn(Builder $failed) => $failed->whereNull('sent_at')->where('failed_at', '<', $old)));
    }

    /** How many more this minute takes. */
    private function room(): int
    {
        return self::PER_MINUTE - ReportMessage::query()->where('sent_at', '>=', now()->subMinute())->count();
    }

    /**
     * Take the first waiting row nobody holds (by priority, then oldest), read again once held (a folded ticket message
     * goes with it). A row whose sender went quiet holding it counts an attempt, and is given up on after MAX_ATTEMPTS.
     * Null when nothing waits or this minute has no room left.
     *
     * @return array{ReportMessage, Lease}|null
     */
    private function claimNext(): ?array
    {
        $waiting = static fn(Builder $query) => $query->whereNull('sent_at')->whereNull('failed_at');
        $candidates = ReportMessage::waiting()->orderBy('priority')->oldest('id')->limit(self::CANDIDATES)->get();
        if ($candidates->isEmpty() || $this->room() <= 0) {
            return null;
        }

        foreach ($candidates as $message) {
            // A row taken while a token is still on it was left by a sender whose time ran out mid-send.
            $abandoned = $message->lease_token !== null;
            $lease = Lease::take($message, $this->leaseSeconds(), $waiting);
            if ($lease === null) {
                continue;
            }
            $message->refresh();
            if ($abandoned) {
                $lease->increment('attempts');
            }
            if ($message->attempts >= self::MAX_ATTEMPTS) {
                $lease->finish(['failed_at' => now(), 'error' => 'Its sends kept ending without an answer.']);
                continue;
            }

            return [$message, $lease];
        }

        return null;
    }

    /** How long a sender holds a row: CALLS_A_SEND calls at their longest, so nobody takes it over mid-send. */
    private function leaseSeconds(): int
    {
        return self::CALLS_A_SEND * BotApi::longestCall((float) $this->config->get('app.http_timeout', 30));
    }

    /**
     * Post the report in its topic (made or reopened as needed), mark it sent while still held, and take the buttons off
     * the report it settles. Returns the message's id, or null while another process is making the topic.
     *
     * @throws TelegramApiException
     */
    private function deliver(ReportMessage $message, Lease $lease, int $chatId): ?int
    {
        $thread = $this->topics->threadFor($message->topic);
        if ($thread === null) {
            return null;
        }

        $target = $this->replyTarget($message, $chatId);
        try {
            $sentId = $this->post($message, $chatId, $thread, $target);
        } catch (TelegramApiException $e) {
            if ($e->is(Refusal::ThreadGone)) {
                $this->logger->info('Report topic {topic} (thread {thread}) is gone; making it again', ['topic' => $message->topic->value, 'thread' => $thread]);
                $thread = $this->topics->replaceThread($message->topic, $thread);
                if ($thread === null) {
                    return null;
                }
            } elseif ($e->is(Refusal::TopicClosed)) {
                $this->logger->info('Report topic {topic} (thread {thread}) is closed; opening it again', ['topic' => $message->topic->value, 'thread' => $thread]);
                $this->topics->reopen($thread);
            } else {
                throw $e;
            }
            $sentId = $this->post($message, $chatId, $thread, $target);
        }

        if (!$lease->finish(['sent_at' => now(), 'chat_id' => $chatId, 'message_id' => $sentId])) {
            // Taken over meanwhile: the new holder marks it and settles the buttons.
            $this->logger->warning('Report {id} went out as message {message}, but another sender holds it now: it may show twice in the group', ['id' => $message->id, 'message' => $sentId]);

            return $sentId;
        }
        if ($message->clears_buttons && $target !== null) {
            $this->groupButtons->set($chatId, $target, null);
        }

        return $sentId;
    }

    /**
     * Post the message with its buttons: the picture captioned with the report (a copy of the customer's message, or an
     * upload sent by its bytes), or the text when there is no picture or it cannot go.
     *
     * @throws TelegramApiException
     */
    private function post(ReportMessage $message, int $chatId, int $thread, ?int $target): int
    {
        $options = ['message_thread_id' => $thread];
        if ($target !== null) {
            $options['reply_parameters'] = ['message_id' => $target, 'allow_sending_without_reply' => true];
        }
        if ($message->keyboard !== null && $message->keyboard !== []) {
            $options['reply_markup'] = ['inline_keyboard' => $message->keyboard];
        }
        $captioned = ['caption' => $message->text, 'parse_mode' => 'HTML'] + $options;

        if ($message->copy_chat_id !== null && $message->copy_message_id !== null) {
            try {
                return $this->api->copyMessage($chatId, $message->copy_chat_id, $message->copy_message_id, $captioned);
            } catch (TelegramApiException $e) {
                $this->throwUnlessThePicturesOwn($e);
                $this->logger->info('Report {id}: the customer\'s message was not copied ({message}); sending the text', ['id' => $message->id, 'message' => $e->getMessage()]);

                return $this->text($message, $chatId, $message->text . "\n\n" . self::NO_PICTURE, $options);
            }
        }

        if ($message->photo_path !== null) {
            $bytes = $this->pictures->readReference($message->photo_path);
            if ($bytes === null) {
                $this->logger->warning('Report {id}: the uploaded picture {name} is gone; sending the text', ['id' => $message->id, 'name' => $message->photo_path]);

                return $this->text($message, $chatId, $message->text . "\n\n" . self::NO_FILE, $options);
            }
            try {
                return (int) ($this->api->sendPhoto($chatId, $bytes, basename($message->photo_path), $captioned)['message_id'] ?? 0);
            } catch (TelegramApiException $e) {
                $this->throwUnlessThePicturesOwn($e);
                $this->logger->info('Report {id}: the uploaded picture was not taken as a photo ({message}); sending the text', ['id' => $message->id, 'message' => $e->getMessage()]);

                return $this->text($message, $chatId, $message->text . "\n\n" . self::NOT_SENT, $options);
            }
        }

        return $this->text($message, $chatId, $message->text, $options);
    }

    /**
     * A refused picture: trouble with Telegram, the group or the topic goes up (it would refuse the text too); a refusal
     * of the picture itself lets the report go as text.
     *
     * @throws TelegramApiException
     */
    private function throwUnlessThePicturesOwn(TelegramApiException $e): void
    {
        if ($e->refusal()->isTransient() || $e->is(Refusal::ThreadGone, Refusal::TopicClosed) || GroupProblem::of($e) !== null) {
            throw $e;
        }
    }

    /**
     * The report as a message — as plain words when Telegram cannot parse its markup.
     *
     * @param array<string, mixed> $options
     * @throws TelegramApiException
     */
    private function text(ReportMessage $message, int $chatId, string $text, array $options): int
    {
        $options += BotApi::NO_LINK_PREVIEW;

        try {
            $sent = $this->api->sendMessage($chatId, $text, $options);
        } catch (TelegramApiException $e) {
            if (!$e->is(Refusal::Unparsable)) {
                throw $e;
            }
            $this->logger->warning('Report {id}: Telegram could not parse its markup ({message}); sent as plain words', ['id' => $message->id, 'message' => $e->getMessage()]);
            $sent = $this->api->sendText($chatId, html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5), $options);
        }

        return (int) ($sent['message_id'] ?? 0);
    }

    /** The message a follow-up report (a receipt's verdict) replies to, when that one went to this group. */
    private function replyTarget(ReportMessage $message, int $chatId): ?int
    {
        if ($message->reply_ref === null) {
            return null;
        }

        return ReportMessage::query()->where('ref', $message->reply_ref)->where('chat_id', $chatId)->whereNotNull('message_id')->latest('id')->first()?->message_id;
    }
}
