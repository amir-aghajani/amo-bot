<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Services\Settings;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;

/**
 * The Telegram accounts that are the owner in the main bot's private chat — every section of the shop, as config.php's
 * login has in the panel. An account becomes one by a one-time link the panel makes («تنظیمات پنل › ورود به پنل»,
 * «مدیریت از ربات»): `t.me/<main bot>?start=owner-<code>`, opened from that account within LINK_MINUTES. The code is
 * kept as its sha256 alone, and one link is open at a time: a new one ends the last. The panel asks its password before
 * a link, and a new panel login ends every link (revokeAll()). Kept in the main shop's settings.
 */
final class OwnerLinks
{
    /** What the link's /start carries before its code. */
    public const PAYLOAD = 'owner-';

    /** How long a link may be opened. */
    public const LINK_MINUTES = 10;

    /** The most accounts linked at once. */
    public const MOST = 5;

    private const IDS = 'owner.telegram_ids';
    private const CODE = 'owner.link_code';
    private const EXPIRES = 'owner.link_expires_at';
    private const RAISED = 'owner.raised_ids';

    /** The code's length: 128 bits in base62 is 22; a few more for good measure. */
    private const CODE_LENGTH = 32;

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function __construct(
        private readonly Settings $settings,
        private readonly Bots $bots,
    ) {}

    /**
     * @return list<int> The linked accounts' Telegram ids, oldest first.
     */
    public function ids(): array
    {
        return $this->idsUnder(self::IDS);
    }

    public function isOwner(int $telegramId): bool
    {
        return in_array($telegramId, $this->ids(), true);
    }

    /**
     * A new one-time link — ending any open one — and until when it opens; null while the main bot's @username is not
     * known (config.php's TELEGRAM_BOT_USERNAME, filled in when its token is checked).
     *
     * @return array{url: string, expires_at: Carbon}|null
     */
    public function link(): ?array
    {
        $bot = $this->bots->username(CurrentBot::main());
        if ($bot === '') {
            return null;
        }
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $expires = Carbon::now()->addMinutes(self::LINK_MINUTES);
        $this->settings->set(self::CODE, hash('sha256', $code), Bot::MAIN);
        $this->settings->set(self::EXPIRES, $expires->getTimestamp(), Bot::MAIN);

        return ['url' => "https://t.me/{$bot}?start=" . self::PAYLOAD . $code, 'expires_at' => $expires];
    }

    /** Until when the open link opens; null with none open. */
    public function openUntil(): ?Carbon
    {
        $expires = $this->settings->get(self::EXPIRES, null, Bot::MAIN);
        if (!is_int($expires) || $this->settings->get(self::CODE, null, Bot::MAIN) === null || $expires <= Carbon::now()->getTimestamp()) {
            return null;
        }

        return Carbon::createFromTimestamp($expires);
    }

    /**
     * Link the account that opened `$payload` (a /start's), when it is the open link's and in time: the code ends there,
     * used once — spent by a compare-and-swap of the kept code, so of two accounts opening it at the same moment (each
     * update its own request) one links. Returns whether it linked — false for a payload of another kind too ($payload
     * not starting PAYLOAD).
     */
    public function redeem(?string $payload, int $telegramId): bool
    {
        if (!$this->opens($payload)) {
            return false;
        }
        $kept = $this->settings->get(self::CODE, null, Bot::MAIN);
        $spent = Setting::query()->where('bot_id', Bot::MAIN)->where('key', self::CODE)->where('value', json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->update(['value' => json_encode(null)]) === 1;
        if (!$spent) {
            // Spent meanwhile — or a new link made —: what this process read of the settings is stale.
            $this->settings->refresh();

            return false;
        }
        $this->settings->set(self::CODE, null, Bot::MAIN);
        $this->settings->set(self::EXPIRES, null, Bot::MAIN);
        $ids = array_values(array_diff($this->ids(), [$telegramId]));
        $ids[] = $telegramId;
        $this->settings->set(self::IDS, array_slice($ids, -self::MOST), Bot::MAIN);
        // The oldest past MOST is the owner no more.
        $this->lower(array_slice($ids, 0, -self::MOST));

        return true;
    }

    /** Whether `$payload` (a /start's) is the open link's, in time — what redeem() would link; nothing is spent. */
    public function opens(?string $payload): bool
    {
        if ($payload === null || !str_starts_with($payload, self::PAYLOAD) || $this->openUntil() === null) {
            return false;
        }
        $kept = $this->settings->get(self::CODE, null, Bot::MAIN);

        return is_string($kept) && hash_equals($kept, hash('sha256', substr($payload, strlen(self::PAYLOAD))));
    }

    /** Whether `$payload` (a /start's) is an owner link's, whatever became of it. */
    public static function isLink(?string $payload): bool
    {
        return $payload !== null && str_starts_with($payload, self::PAYLOAD);
    }

    /**
     * The account's admin role in the main bot is the link's (StartHandler made it one): it goes with the link — at
     * unlink(), or when a sixth account drops it —, as an account that was an admin before keeps its role.
     */
    public function raised(int $telegramId): void
    {
        $raised = $this->raisedIds();
        if (!in_array($telegramId, $raised, true)) {
            $this->settings->set(self::RAISED, [...$raised, $telegramId], Bot::MAIN);
        }
    }

    /**
     * The account's role in the main bot was set by hand since (UserActions::setRole()): it is the owner's choice now,
     * not the link's, and no unlink takes it back.
     */
    public function chosen(int $telegramId): void
    {
        $raised = $this->raisedIds();
        if (in_array($telegramId, $raised, true)) {
            $this->settings->set(self::RAISED, array_values(array_diff($raised, [$telegramId])), Bot::MAIN);
        }
    }

    /** The account is the owner no more: the admin role the link gave it goes too, and with it the bot's management. */
    public function unlink(int $telegramId): void
    {
        $this->settings->set(self::IDS, array_values(array_diff($this->ids(), [$telegramId])), Bot::MAIN);
        $this->lower([$telegramId]);
    }

    /**
     * The panel's login changed (Auth\Services\OwnerAuth::changeCredentials()): no account is the owner any more, and an
     * open link ends unopened — whoever held the old login, a session of it or a link made with it, keeps nothing in the
     * bot. Every admin role a link gave goes, as unlink() takes one; a role the owner set by hand (chosen()) stays. The
     * accounts are linked again from the panel, under the new login. Returns the Telegram ids let go, oldest first.
     *
     * @return list<int>
     */
    public function revokeAll(): array
    {
        // What the bot linked a moment ago is in the database, not in what this process read. The code first, so a link
        // being opened meanwhile is spent for nothing (redeem()'s compare-and-swap finds it gone).
        $this->settings->refresh();
        $this->settings->set(self::CODE, null, Bot::MAIN);
        $this->settings->set(self::EXPIRES, null, Bot::MAIN);
        $ids = $this->ids();
        $this->settings->set(self::IDS, [], Bot::MAIN);
        // Every role a link raised, whichever account it was on: none is linked now.
        $this->lower([...$ids, ...$this->raisedIds()]);

        return $ids;
    }

    /**
     * The accounts of `$telegramIds` the link made admins, customers again in the main bot.
     *
     * @param list<int> $telegramIds
     */
    private function lower(array $telegramIds): void
    {
        $raised = $this->raisedIds();
        $back = array_values(array_intersect($raised, $telegramIds));
        if ($back === []) {
            return;
        }
        CurrentBot::run(Bot::MAIN, static function () use ($back): void {
            foreach (User::query()->whereIn('telegram_id', $back)->where('role', UserRole::Admin->value)->get() as $user) {
                $user->role = UserRole::Customer;
                $user->save();
            }
        });
        $this->settings->set(self::RAISED, array_values(array_diff($raised, $back)), Bot::MAIN);
    }

    /**
     * @return list<int>
     */
    private function raisedIds(): array
    {
        return $this->idsUnder(self::RAISED);
    }

    /**
     * @return list<int> The Telegram ids kept under `$key` in the main shop's settings.
     */
    private function idsUnder(string $key): array
    {
        $ids = $this->settings->get($key, [], Bot::MAIN);

        return is_array($ids) ? array_values(array_map(intval(...), array_filter($ids, is_numeric(...)))) : [];
    }
}
