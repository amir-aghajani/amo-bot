<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\User;
use App\Support\Input;

/**
 * A customer of a shop named by their numeric Telegram id, as another customer — or support — types it, to hand them
 * something: a service («انتقال سرویس», Subscriptions\Services\ServiceTransfers) or money («انتقال موجودی»,
 * WalletTransfers). The id as typed (Persian digits and spaces around it are fine, one split by a space is no id:
 * Input::telegramIdOf()), a customer of that shop. A customer's lookups are counted, every kind together: LOOKUPS per
 * LOOKUP_WINDOW — a typo or two, never a scan of the shop's customers. Whether the one found may receive what is the
 * caller's to judge. Refusals are 422s under `telegram_id`.
 */
final class CustomerLookup
{
    /** Ids a customer may look up per LOOKUP_WINDOW seconds, services and money together. */
    public const LOOKUPS = 10;
    public const LOOKUP_WINDOW = 600;

    public const NOT_AN_ID = 'شناسه عددی تلگرام را وارد کنید: فقط رقم، بدون @ یا نام کاربری.';
    public const UNKNOWN = 'مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست؛ صاحب این شناسه باید یک بار ربات را استارت کند.';

    /** The refusal past the limit; TooManyAttemptsException::wait() adds the wait. */
    private const TOO_MANY_LOOKUPS = 'شناسه‌های زیادی را بررسی کرده‌اید';

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * The customer of `$shop` with Telegram id `$telegramId` (as typed). What is no id is refused before it is counted.
     *
     * @param int|null $by The customer looking someone up, whose lookups are counted; null for support, never counted
     * @throws ValidationException 422 on `telegram_id`: no id (NOT_AN_ID), or nobody of the shop (UNKNOWN)
     * @throws TooManyAttemptsException 429 the customer looked up LOOKUPS in the window
     */
    public function find(Bot $shop, mixed $telegramId, ?int $by): User
    {
        $id = Input::telegramIdOf($telegramId) ?? throw ValidationException::on('telegram_id', self::NOT_AN_ID);
        if ($by !== null) {
            $this->limiter->enforce([['transfer|lookup|' . $by, self::LOOKUPS, self::LOOKUP_WINDOW]], self::TOO_MANY_LOOKUPS);
        }

        return CurrentBot::run($shop, static fn(): ?User => User::query()->where('telegram_id', $id)->first())
            ?? throw ValidationException::on('telegram_id', self::UNKNOWN);
    }
}
