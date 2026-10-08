<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Channels;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Models\BotChannel;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The channels a customer must join before the bot serves them ("کانال‌های اجباری"), as the admin keeps them: one is
 * added by its link, the bot looks the chat up and refuses it unless it is an admin there (it needs that to see who is
 * a member, ChannelMembership), and a check looks it up again. Telegram out of reach for a moment answers neither: the
 * admin is told so (TelegramUnreachableException) and nothing changes.
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
     * Add a channel by what the admin pasted: a public link or handle ("https://t.me/name", "@name") or,
     * for a private channel, its numeric id — the bot exports the invite link itself. The bot must
     * already be an admin of the chat.
     *
     * @throws ValidationException with the reason under "link"
     * @throws TelegramUnreachableException
     */
    public function add(string $input): BotChannel
    {
        $reference = ChannelReference::parse($input);
        if ($reference === null) {
            throw self::invalid('لینک یا نام کاربری کانال معتبر نیست. مثلا https://t.me/mychannel یا @mychannel، و برای کانال خصوصی شناسه عددی آن.');
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
     * Look the channel up again: is the bot still an admin, and did the title or the way in change — its handle, or a
     * private one's invite link (the bot needs to be an admin to get one; when it cannot, the last way known stays).
     * A chat Telegram no longer shows the bot is flagged like one where it lost its rights.
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
     * A private chat's way in: the invite link Telegram already shows its admins, else one the bot exports; null when
     * it may not. (A public one's is its handle's — BotChannel::link().)
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

        return mb_substr($title !== '' ? $title : (string) ($chat['username'] ?? $chat['id'] ?? ''), 0, 128);
    }

    /** @param array<string, mixed> $chat */
    private static function username(array $chat): ?string
    {
        $username = trim((string) ($chat['username'] ?? ''));

        return $username === '' ? null : $username;
    }

    private static function invalid(string $message): ValidationException
    {
        return new ValidationException(['link' => [$message]], $message);
    }
}
