<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Notifications;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\RenewalSettings;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Qr\QrCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Support\Persian;

/**
 * A service as the bot shows it — in words (the values every service text offers) and as its QR card (the subscription
 * link drawn as a QR code, the words its caption) — and what a paid order brought its customer. send() is the one place
 * a service goes out (a delivery in the bot or after a receipt's approval, a link changed, a service moved) and show()
 * the one place it takes the place of the message a customer tapped (the service screen's «لینک اشتراک»): the card when
 * the bot is set to make one and GD can draw it and the words fit under it as a caption, the words alone otherwise — a
 * missing picture never costs the customer the link.
 */
final class ServiceCard
{
    /** What the QR card is called in Telegram's upload. */
    private const FILENAME = 'service.jpg';

    public function __construct(
        private readonly BotApi $api,
        private readonly BotSettings $settings,
        private readonly RenewalSettings $renewal,
        private readonly QrCard $qr,
        private readonly BotTexts $texts,
    ) {}

    /**
     * What every text about one service is filled with: the client's name on the panel, plan, server (its «لوکیشن»),
     * the term (`duration`) or when it ends (`expires`), the quota (`traffic`) and what is left of it (`remaining`), and
     * the one subscription link as the row keeps it — never the per-inbound config links (a panel with thirty inbounds
     * would make a wall of them; the link carries them all and updates itself).
     *
     * @return array{client: string, plan: string, server: string, duration: string, expires: string, traffic: string, remaining: string, subscription: string}
     */
    public function values(Subscription $subscription): array
    {
        $remaining = $subscription->remainingBytes();

        return [
            'client' => $subscription->remote_name,
            'plan' => $subscription->plan->name ?? '',
            'server' => $subscription->server->name,
            'duration' => Messages::duration($subscription->duration_days),
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_days),
            'traffic' => Messages::traffic($subscription->traffic_limit_bytes),
            'remaining' => $remaining === null ? Messages::UNLIMITED : Messages::bytes($remaining),
            'subscription' => $subscription->subscription_url,
        ];
    }

    /**
     * A text about the service: its values and the text's own (`$extra`).
     *
     * @param array<string, scalar|Html> $extra
     */
    public function text(Subscription $subscription, BotText $text, array $extra = []): string
    {
        return $this->texts->render($text, $this->values($subscription) + $extra);
    }

    /**
     * The service sent to a chat: its QR card with the text — why it comes: delivered (PaySuccess), a fresh link
     * (LinkRotated), moved (ServiceMoved), rendered with the service's values (text()), or those words made already: a
     * notice keeps for the website the very words the card carries — as the caption, or the text alone. `$options` (a
     * reply target, buttons) apply either way.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> The message sent
     */
    public function send(int|string $chatId, Subscription $subscription, BotText|string $text, array $options = []): array
    {
        $caption = $text instanceof BotText ? $this->text($subscription, $text) : $text;
        $card = $this->card($subscription, $caption);
        if ($card !== null) {
            return $this->api->sendPhoto($chatId, $card, self::FILENAME, ['caption' => $caption] + $options);
        }

        return $this->api->sendMessage($chatId, $caption, $options + BotApi::NO_LINK_PREVIEW);
    }

    /**
     * The same in place of the message whose button asked for it: the text edited into it, or the QR card — edited in
     * where Telegram allows, the message replaced by it otherwise (Context::editPhoto()).
     *
     * @param array<string, mixed> $options
     */
    public function show(Context $ctx, Subscription $subscription, BotText $text, array $options = []): void
    {
        $caption = $this->text($subscription, $text);
        $card = $this->card($subscription, $caption);
        if ($card !== null) {
            $ctx->editPhoto($card, self::FILENAME, ['caption' => $caption] + $options);

            return;
        }

        $ctx->edit($caption, $options + BotApi::NO_LINK_PREVIEW);
    }

    /**
     * What a fulfilled order brought, sent to its customer's chat: a new service as its card (send()), a renewal (the
     * link has not changed), a wallet top-up or an agent's traffic as its text — settledText(), or `$text` when the
     * caller made it already (a notice, which keeps those words).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> The message sent
     */
    public function sendSettled(int|string $chatId, Order $order, array $options = [], ?string $text = null): array
    {
        $text ??= $this->settledText($order);
        if ($order->type === OrderType::Purchase) {
            return $this->send($chatId, self::subscriptionOf($order), $text, $options);
        }

        return $this->api->sendMessage($chatId, $text, $options);
    }

    /** What a fulfilled order brought, in words: the new service, the renewed one, the wallet's new balance, an agent's traffic. */
    public function settledText(Order $order): string
    {
        return match ($order->type) {
            OrderType::Purchase => $this->text(self::subscriptionOf($order), BotText::PaySuccess),
            OrderType::Renewal => $this->renewalText($order, BotText::Renewed),
            OrderType::WalletTopUp => $this->texts->render(BotText::WalletCharged, ['balance' => Messages::balance($order->user->balance())]),
            OrderType::Traffic => $this->texts->render(BotText::AgencyTrafficAdded, [
                'traffic' => Messages::bytes((int) $order->traffic_bytes),
                'remaining' => Messages::bytes(max(0, $order->user->ownBot?->trafficBalance() ?? 0)),
            ]),
        };
    }

    /** The order was paid but not delivered: support finishes it, nothing to pay again. */
    public function failedText(Order $order): string
    {
        return match ($order->type) {
            OrderType::Purchase => $this->texts->get(BotText::PayProvisionFailed),
            OrderType::Renewal => $this->texts->render(BotText::RenewFailed, ['client' => self::subscriptionOf($order)->remote_name]),
            OrderType::WalletTopUp => $this->texts->get(BotText::TopupFailed),
            OrderType::Traffic => $this->texts->get(BotText::AgencyTrafficFailed),
        };
    }

    /**
     * A renewal's text (Renewed, AutoRenewed): the renewed service and what became of the traffic it had left — queued
     * behind the period in use, or carried (`%leftover%`).
     *
     * @param array<string, scalar|Html> $extra The text's own values
     */
    public function renewalText(Order $renewal, BotText $text, array $extra = []): string
    {
        $subscription = self::subscriptionOf($renewal);
        $leftover = $this->periodNote($subscription);
        if ($leftover === '' && $this->carried($subscription)) {
            $leftover = $this->texts->part(BotText::RenewalLeftoverCarried);
        }

        return $this->text($subscription, $text, ['leftover' => $leftover] + $extra);
    }

    /**
     * A renewal queued behind the period in use, the shop not carrying what a period leaves unused: how much of the
     * traffic left goes when that period ends, and what the renewed one starts with — under the service screen and the
     * renewal messages; '' when nothing goes.
     */
    public function periodNote(Subscription $subscription): Html|string
    {
        $expiring = $subscription->expiringBytes();
        if ($expiring === 0 || $subscription->period_ends_at === null) {
            return '';
        }

        return $this->texts->part(BotText::RenewalLeftoverUntil, [
            'expiring' => Messages::bytes($expiring),
            'date' => Persian::date($subscription->period_ends_at),
            'next' => Messages::bytes($subscription->next_period_bytes ?? 0),
        ]);
    }

    /** Whether the renewal carried the traffic left into the renewed period: more is left than the plan gives. */
    private function carried(Subscription $subscription): bool
    {
        $planBytes = $subscription->plan?->trafficBytes() ?? 0;

        return $this->renewal->carriesTraffic() && $planBytes > 0 && ($subscription->remainingBytes() ?? 0) > $planBytes;
    }

    /**
     * The QR card of the service's link: when the bot is set to make one, GD can draw it and the text fits under it as a
     * caption (an admin's long wording goes as a plain message rather than not at all), else null.
     */
    private function card(Subscription $subscription, string $caption): ?string
    {
        if (!$this->settings->qrEnabled() || TelegramHtml::visibleLength($caption) > Limits::CAPTION) {
            return null;
        }

        return $this->qr->render($subscription->subscription_url);
    }

    private static function subscriptionOf(Order $order): Subscription
    {
        return $order->subscription ?? throw new \LogicException("Order #{$order->id} has no subscription.");
    }
}
