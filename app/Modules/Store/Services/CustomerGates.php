<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Store\Contracts\Door;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Channels\ChannelMembership;
use App\Modules\Telegram\Models\BotChannel;
use App\Modules\Users\Models\User;

/**
 * What the bot asks of a customer before it gives anything for free (Telegram\Gates: their own number while the shop asks
 * it, the shop's channels joined), asked the same on the website and in the mini app before a free test or a spin of the
 * wheel — and a Telegram account, which the bot's quotas count people by (refusal()). Through the mini app — the bot's
 * own door, where the bot answers nothing at all before its gates — the number and the channels are asked before an
 * order, a gift code or a service claimed by its link too (holdBack()). Bot admins pass, as in the bot.
 */
final class CustomerGates
{
    public const NO_TELEGRAM = 'این بخش برای حساب‌هایی است که به تلگرام وصل‌اند؛ اول حساب تلگرام خود را به حسابتان وصل کنید.';
    public const NO_PHONE = 'اول در ربات فروشگاه شماره موبایل خود را تایید کنید و بعد دوباره امتحان کنید.';
    public const CHANNELS = 'اول در %s عضو شوید و بعد دوباره امتحان کنید.';

    public function __construct(
        private readonly BotSettings $settings,
        private readonly ChannelMembership $membership,
    ) {}

    /** Why the bot would hold the customer back now, in their words; null when it would not. */
    public function refusal(User $customer): ?string
    {
        if ($customer->isAdmin()) {
            return null;
        }
        if ($customer->telegram_id === null) {
            return self::NO_TELEGRAM;
        }

        return $this->gates($customer);
    }

    /**
     * Refuse what the customer asks through the mini app — an order (a purchase, a renewal, an extra, a top-up, a service
     * built), a gift code redeemed, a service claimed by its link — while the bot's gates would hold them back: in the
     * bot, a customer without their number (while the shop asks it) or outside the shop's channels gets nothing but the
     * ask, whatever they send, and the mini app must not be the way around it. The website asks neither: it is no door of
     * the bot's, and its customers need no Telegram account.
     *
     * @throws ValidationException 422 on `status`
     */
    public function holdBack(Door $door, User $customer): void
    {
        $held = $door instanceof MiniApp && !$customer->isAdmin() ? $this->gates($customer) : null;
        if ($held !== null) {
            throw ValidationException::on('status', $held);
        }
    }

    /** Why the bot's gates would hold the customer back — their number, then the shop's channels; null when they would not. */
    private function gates(User $customer): ?string
    {
        if ($this->settings->phoneRequired() && !$customer->hasVerifiedPhone()) {
            return self::NO_PHONE;
        }
        $missing = $this->membership->missingFor($customer);

        return $missing === [] ? null : sprintf(self::CHANNELS, implode('، ', array_map(static fn(BotChannel $channel): string => "«{$channel->title}»", $missing)));
    }
}
