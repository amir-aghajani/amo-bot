<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Http\RequestOrigin;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Rewards\Exceptions\GiftCodeRefusedException;
use App\Modules\Rewards\Services\GiftCodes;
use App\Modules\Store\Presenters\OrderPresenter;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A customer redeems a gift code on the website, as with the bot's «🎁 کد هدیه» (Rewards\Services\GiftCodes; wrong tries
 * counted per customer and per address network). The site shows the outcome, so nothing is sent in Telegram.
 */
final class CustomerGiftCodes
{
    public function __construct(
        private readonly GiftCodes $codes,
        private readonly CustomerOrders $orders,
        private readonly OrderPresenter $presenter,
        private readonly CustomerSubscriptions $subscriptions,
        private readonly RequestOrigin $origin,
    ) {}

    /**
     * Redeem `code`: what it gave — the wallet's new balance, or a plan's order and, once delivered, its service.
     *
     * @param array<string, mixed> $input {code}
     * @return array{kind: string, words: string, balance: string|null, order: array<string, mixed>|null, subscription: array<string, mixed>|null}
     * @throws GiftCodeRefusedException 422 on `code`
     * @throws TooManyAttemptsException 429
     */
    public function redeem(User $customer, array $input, ServerRequestInterface $request): array
    {
        $redeemed = $this->codes->redeem($customer, Input::text($input, 'code'), $this->origin->clientNetwork($request), tell: false);
        $orderId = $redeemed->given->order?->id;
        $order = $orderId === null ? null : $this->orders->find($customer, $orderId);
        $delivered = $order !== null && $order->status === OrderStatus::Fulfilled && $order->subscription_id !== null;

        return [
            'kind' => $redeemed->code->kind->value,
            'words' => $redeemed->given->reward->words(),
            'balance' => $order === null ? $customer->balance() : null,
            'order' => $order === null ? null : $this->presenter->present($order),
            'subscription' => $delivered ? $this->subscriptions->present($this->subscriptions->find($customer, (int) $order->subscription_id)) : null,
        ];
    }
}
