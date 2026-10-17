<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Channels;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Models\BotChannel;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The admin's list of required channels: added by link (refused unless the bot is an admin there — it needs that to see
 * members), checked again on demand, reordered and removed. Telegram out of reach changes nothing (a 502).
 */
final class RequiredChannels
{
    private const TYPES = ['channel', 'supergroup'];
    private const ADMIN = ['creator', 'administrator'];
    private const ADDED_ALREADY = 'این کانال قبلا اضافه شده است.';

    public function __construct(private readonly BotApi $api) {}

    /** @return list<array<string, mixed>> In the order the customer sees them. */
    public function list(): array
    {
        return BotChannel::ordered()->get()->map($this->present(...))->all();
    }

    /**
     * Add a channel by a public link or handle, or a private one's numeric id (the bot exports its invite link). The bot
     * must already be an admin there.
     *
     * @throws ValidationException with the reason under "link"
     * @throws TelegramUnreachableException
     */
    public function add(string $input): BotChannel
    {
        $reference = ChannelReference::parse($input);
        if ($reference === null) {
            throw self::invalid("لینک یا نام کاربری کانال معتبر نیست. مثلا https://t.me/mychannel یا \u{200E}@mychannel، و برای کانال خصوصی شناسه عددی آن.");
        }
        if ($reference->isInviteLink()) {
            throw self::invalid('از روی لینک دعوت خصوصی نمی‌شود کانال را پیدا کرد. ربات را ادمین کانال کنید و شناسه عددی کانال را وارد کنید (مثل -1001234567890)؛ لینک عضویت را ربات خودش می‌سازد.');
        }

        try {
            $chat = $this->api->getChat($reference->chat());
        } catch (TelegramApiException $e) {
            throw $e->refusal()->isTransient() ? new TelegramUnreachableException($e) : self::invalid('کانال پیدا نشد. اگر خصوصی است، ربات باید اول ادمین آن شده باشد.');
        }

        $type = (string) ($chat['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw self::invalid('این لینک به کانال یا گروه اشاره نمی‌کند.');
        }
        $chatId = (int) ($chat['id'] ?? 0);
        if (BotChannel::query()->where('chat_id', $chatId)->exists()) {
            throw self::invalid(self::ADDED_ALREADY);
        }
        if (!$this->botIsAdmin($chatId)) {
            throw self::invalid('ربات در این کانال ادمین نیست. ربات را به ادمین‌های کانال اضافه کنید و دوباره امتحان کنید.');
        }

        $username = self::username($chat);
        $invite = $username === null ? $this->inviteLinkFor($chat) : null;
        if ($username === null && $invite === null) {
            throw self::invalid('ربات نتوانست لینک عضویت این کانال خصوصی را بگیرد. به ربات دسترسی «افزودن اعضا» (invite users via link) بدهید.');
        }

        try {
            return BotChannel::query()->create([
                'chat_id' => $chatId,
                'type' => $type,
                'title' => self::title($chat),
                'username' => $username,
                'invite_link' => $invite,
                'bot_is_admin' => true,
                'checked_at' => now(),
                'sort' => Sorting::next(BotChannel::class),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request added it while this one asked Telegram.
            throw self::invalid(self::ADDED_ALREADY);
        }
    }

    /**
     * Look the channel up again: the bot's rights, the title and the way in (a private one's last invite link stays when
     * none can be made now). A chat Telegram no longer shows is flagged like one where the bot lost its rights.
     *
     * @throws TelegramUnreachableException
     */
    public function check(BotChannel $channel): BotChannel
    {
        $attributes = ['checked_at' => now()];

        try {
            $chat = $this->api->getChat($channel->chat_id);
            $admin = $this->botIsAdmin($channel->chat_id);
            $username = self::username($chat);
            $invite = $username === null ? ($admin ? $this->inviteLinkFor($chat) : null) ?? $channel->invite_link : null;
            $attributes += [
                'title' => self::title($chat),
                'username' => $username ?? ($invite === null ? $channel->username : null),
                'invite_link' => $invite,
                'bot_is_admin' => $admin,
            ];
        } catch (TelegramApiException $e) {
            if ($e->refusal()->isTransient()) {
                throw new TelegramUnreachableException($e);
            }
            $attributes['bot_is_admin'] = false;
        }

        $channel->forceFill($attributes)->save();

        return $channel;
    }

    /** Take the channel off the list (the bot stays in it). */
    public function delete(BotChannel $channel): void
    {
        $channel->delete();
    }

    /** @param list<int> $ids */
    public function reorder(array $ids): void
    {
        Sorting::reorder(BotChannel::class, $ids);
    }

    /** @return array<string, mixed> */
    public function present(BotChannel $channel): array
    {
        return [
            'id' => $channel->id,
            'chat_id' => $channel->chat_id,
            'type' => $channel->type,
            'title' => $channel->title,
            'username' => $channel->username,
            'link' => $channel->link(),
            'bot_is_admin' => $channel->bot_is_admin,
            'checked_at' => $channel->checked_at?->toIso8601String(),
            'sort' => $channel->sort,
        ];
    }

    /** @throws TelegramUnreachableException */
    private function botIsAdmin(int $chatId): bool
    {
        $botId = $this->api->botId();
        if ($botId === null) {
            return false;
        }

        try {
            $status = (string) ($this->api->getChatMember($chatId, $botId)['status'] ?? '');
        } catch (TelegramApiException $e) {
            return $e->refusal()->isTransient() ? throw new TelegramUnreachableException($e) : false;
        }

        return in_array($status, self::ADMIN, true);
    }

    /**
     * A private chat's invite link: the one Telegram shows its admins, else one the bot exports; null when it may not.
     *
     * @param array<string, mixed> $chat
     * @throws TelegramUnreachableException
     */
    private function inviteLinkFor(array $chat): ?string
    {
        if (is_string($chat['invite_link'] ?? null) && $chat['invite_link'] !== '') {
            return $chat['invite_link'];
        }

        try {
            return $this->api->exportChatInviteLink((int) ($chat['id'] ?? 0));
        } catch (TelegramApiException $e) {
            return $e->refusal()->isTransient() ? throw new TelegramUnreachableException($e) : null;
        }
    }

    /** @param array<string, mixed> $chat */
    private static function title(array $chat): string
    {
        $title = trim((string) ($chat['title'] ?? ''));

        return mb_substr($title !== '' ? $title : (string) ($chat['username'] ?? $chat['id'] ?? ''), 0, Limits::TITLE);
    }

    /** @param array<string, mixed> $chat */
    private static function username(array $chat): ?string
    {
        $username = trim((string) ($chat['username'] ?? ''));

        return $username === '' ? null : $username;
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::on('link', $message);
    }
}
