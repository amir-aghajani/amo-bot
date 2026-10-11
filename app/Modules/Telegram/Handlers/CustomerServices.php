<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which service a customer's button may open: one of their own, not gone from its panel. Another
 * customer's id is treated as one that does not exist.
 */
final class CustomerServices
{
    public function __construct(
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** @return Builder<Subscription> The customer's services, without those gone from their panel. */
    public static function of(User $customer): Builder
    {
        return Subscription::query()->where('user_id', $customer->id)->where('status', '!=', SubscriptionStatus::Deleted->value);
    }

    /** @return Builder<Subscription> The customer's running services, newest first: what «سرویس‌های من» lists. */
    public static function running(int $customerId): Builder
    {
        return Subscription::active()->where('user_id', $customerId)->latest('id');
    }

    /** The customer's service `$id`; null after telling them it is not there, with «بازگشت» to `$back`. */
    public function find(Context $ctx, int $id, string $back): ?Subscription
    {
        $subscription = self::of($ctx->user)->find($id);
        if ($subscription === null) {
            $ctx->edit($this->texts->get(BotText::ServiceNotFound), ['reply_markup' => $this->buttons->backOnly($back)]);
        }

        return $subscription;
    }
}
