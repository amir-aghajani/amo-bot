<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\ValidationException;
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
 * customer's id is treated as one that does not exist — and one that stopped being theirs while they paid is said so.
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

    /**
     * The customer's service `$id`; null after telling them it is not there, with «بازگشت» to `$back` (null: the first page
     * of «سرویس‌های من»).
     */
    public function find(Context $ctx, int $id, ?string $back = null): ?Subscription
    {
        $subscription = self::of($ctx->user)->find($id);
        if ($subscription === null) {
            $this->gone($ctx, $this->texts->get(BotText::ServiceNotFound), $back);
        }

        return $subscription;
    }

    /**
     * An order of the service refused because it is no longer the customer's since its screen was drawn — given away,
     * or gone (OrderService's 422 on `status`) —: those words in place of the screen, with «بازگشت» to `$back` (null:
     * the first page of «سرویس‌های من»); nothing was ordered. Any other refusal is thrown on.
     *
     * @throws ValidationException
     */
    public function movedOn(Context $ctx, ValidationException $refused, ?string $back = null): void
    {
        $this->gone($ctx, $refused->errors()['status'][0] ?? throw $refused, $back);
    }

    /** `$words` saying the service is not there, in place of the screen, with «بازگشت» to `$back`. */
    private function gone(Context $ctx, string $words, ?string $back): void
    {
        $ctx->edit($words, ['reply_markup' => $this->buttons->backOnly($back ?? MenuHandler::subscriptionsCallback(1))]);
    }
}
