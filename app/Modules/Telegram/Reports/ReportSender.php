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
 * Delivers the queued reports (ShopReports) to their topics in the report group: what support must act on first (a
 * receipt, a delivery that failed — Topic::priority()), the rest after it, each oldest first. Telegram takes 20
 * messages a minute in a group, so a round sends no more than the minute has room for and the rest waits; a flood limit,
 * an unreachable Telegram or trouble with the group itself holds every report back for a while (ReportGroup::hold() —
 * the group's trouble is the screen's problem): nothing is lost, and the bot never sleeps on a report. A topic an admin
 * deleted is made again and one they closed opened again, and the report goes in after all. A receipt — or a ticket's
 * message with a picture — is the picture with the report as its caption and its buttons under it (ReceiptReview,
 * TicketClose) — a copy of the customer's message in the bot, or a picture uploaded from a website or a panel sent by its
 * bytes (CustomerPictures) —; one that cannot be copied or sent goes as text, buttons and all; what settles it, once
 * sent, takes the buttons off. Markup Telegram cannot parse goes as plain words. Each row is leased while it is sent —
 * for longer than its send can take (leaseSeconds()), and marked sent only while still held —, so two senders (the
 * poller, a cron run, a webhook request) never send it twice. Called after every update the bot handles and by
 * SendReportsTask.
 */
final class ReportSender
{
    /** Telegram's 20 a minute in a group, less room for the bot's own topic-making and replies. */
    public const PER_MINUTE = Limits::GROUP_PER_MINUTE - 2;

    /**
     * The most Telegram calls one send makes: its topic made (Telegram's icons asked, the topic made, made again without
     * the icon Telegram refused), the report posted (its picture, its words, its words as plain text), the topic made
     * again — or opened again — and the report posted once more, and the buttons taken off the report it settles.
     */
    private const CALLS_A_SEND = 3 + 3 + 3 + 3 + 1;

    /** A row whose senders went quiet this many times (a crash mid-send) is given up on. */
    private const MAX_ATTEMPTS = 3;

    /** Rows looked at for one nobody holds. */
    private const CANDIDATES = 5;

    /** Days a sent or abandoned row is kept (a receipt's verdict replies to its report), and hours one may wait to be sent. */
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
     * Send what waits, oldest first, for up to `$seconds` and as many as this minute has room for. Returns how many went
     * out. What Telegram refuses is held back or given up on, and logged. It runs after every batch the bot serves, so
     * with nothing waiting it costs two reads: the group, and the queue.
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
     * Forget what was sent or given up on long enough ago — but a ticket's reports, which pruneTickets() lets go of —, and
     * give up on what waited too long. A row is sent or given up on, never both: the two halves are two ranges of the
     * bot's pruning index under a null ticket, so a run that finds nothing old reads nothing — not the whole queue's
     * history, nor the reports kept for the tickets still open, every minute.
     */
    public function prune(): void
    {
        self::old(ReportMessage::query()->whereNull('ticket_id'))->delete();
        ReportMessage::waiting()->where('created_at', '<', now()->subHours(self::EXPIRE_HOURS))->update(['failed_at' => now(), 'error' => 'Waited too long to be sent.'] + Lease::FREE);
    }

    /**
     * Forget a ticket's reports sent or given up on long enough ago once the ticket is closed — or gone. Those of a ticket
     * not closed stay, however old: a bot admin's reply to any of them is support's answer (TicketReplies). Hourly, with
     * the tickets' housekeeping: each run reads the tickets' reports, the kept ones among them.
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
     * The first waiting row nobody holds — by priority, then the oldest —, now held by this sender and read as it is held
     * (a ticket's next message folded into it until then goes with it: ShopReports) — counted as an attempt when its last
     * sender went quiet holding it — or null when there is none to take, or this minute has no room left (asked only once
     * one waits). One whose senders kept going quiet is given up on.
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

    /**
     * How long a sender holds a row it is sending: longer than the send can take — CALLS_A_SEND calls, each as long as
     * one can be (BotApi::longestCall()) —, so no other sender takes it over while it is still going out.
     */
    private function leaseSeconds(): int
    {
        return self::CALLS_A_SEND * BotApi::longestCall((float) $this->config->get('app.http_timeout', 30));
    }

    /**
     * Post the report in its topic — making the topic first when the group lacks it, again when an admin deleted it,
     * opening it when they closed it —, mark it sent while this sender still holds it, and take the buttons off the
     * report it settles. Returns the message's id, or null when the topic is being made by someone else.
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
            // Taken over meanwhile: the sender that took it marks it, and takes the buttons off what it settles.
            $this->logger->warning('Report {id} went out as message {message}, but another sender holds it now: it may show twice in the group', ['id' => $message->id, 'message' => $sentId]);

            return $sentId;
        }
        if ($message->clears_buttons && $target !== null) {
            $this->groupButtons->set($chatId, $target, null);
        }

        return $sentId;
    }

    /**
     * The message itself, with its buttons: the picture with the report as its caption — a copy of the customer's message,
     * or a picture uploaded to the shop sent by its bytes; the report as text when the picture is refused for a reason of
     * its own, or gone — or the text.
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
     * A picture Telegram refused — a copy, an upload —: the group's, the topic's or Telegram's own trouble is everyone's,
     * and goes up; the picture's is its own, and the report goes as text.
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
     * The report as a message — as plain words when Telegram cannot parse its markup: a report is never lost to it.
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

    /** The message a report about an earlier one (a receipt's verdict) replies to: that one's, when it went to this group. */
    private function replyTarget(ReportMessage $message, int $chatId): ?int
    {
        if ($message->reply_ref === null) {
            return null;
        }

        return ReportMessage::query()->where('ref', $message->reply_ref)->where('chat_id', $chatId)->whereNotNull('message_id')->latest('id')->first()?->message_id;
    }
}
