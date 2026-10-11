<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Notifications;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Enums\ExtraKind;
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
 * A service as the bot shows it: the values every service text is filled with, and the QR card of its link with the
 * text as caption. send() is the one place a service goes out (a delivery, a new link, a move) and show() puts it in
 * place of a tapped message. The card is used when the setting is on, GD can draw it and the text fits a caption;
 * otherwise the text goes alone — a missing picture never costs the customer their link.
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
     * What every text about one service is filled with. The link is the one subscription link the row keeps, never the
     * per-inbound config links (a panel with thirty inbounds would make a wall of them; the link carries them all).
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
            'duration' => Messages::duration($subscription->duration_hours),
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_hours),
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
     * Send the service to a chat as its QR card with `$text` as caption, or the text alone. `$text` is a BotText to
     * render with the service's values, or words made already (a notice keeps the very words it sent).
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
     * The same in place of the tapped message: the text edited in, or the QR card (Context::editPhoto()).
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
     * Send what a fulfilled order brought: a new service as its card, anything else (whose link did not change) as text.
     * `$text` replaces settledText() when the caller made it already (a notice).
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

    /**
     * What a fulfilled order brought, in words: the new service, the renewed one, the extra traffic or time on it, the
     * wallet's new balance, an agent's traffic.
     */
    public function settledText(Order $order): string
    {
        return match ($order->type) {
            OrderType::Purchase => $this->text(self::subscriptionOf($order), BotText::PaySuccess),
            OrderType::Renewal => $this->renewalText($order, BotText::Renewed),
            OrderType::ExtraTraffic => $this->text(self::subscriptionOf($order), BotText::ExtraTrafficAdded, ['extra_traffic' => self::extraOf($order)]),
            OrderType::ExtraTime => $this->text(self::subscriptionOf($order), BotText::ExtraTimeAdded, ['extra_time' => self::extraOf($order)]),
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
            OrderType::ExtraTraffic, OrderType::ExtraTime => $this->texts->render(BotText::ExtraFailed, ['client' => self::subscriptionOf($order)->remote_name, 'extra' => self::extraOf($order)]),
            OrderType::WalletTopUp => $this->texts->get(BotText::TopupFailed),
            OrderType::Traffic => $this->texts->get(BotText::AgencyTrafficFailed),
        };
    }

    /**
     * A renewal's text (Renewed, AutoRenewed) with `%leftover%`: what becomes of the traffic left — expiring with the
     * current period, or carried over.
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
     * With a renewal queued behind the current period: how much traffic expires when that period ends and what the next
     * one starts with; '' when nothing expires.
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

    /** The QR card's JPEG, or null when it is off, GD cannot draw it, or the text is too long for a caption. */
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

    /** What an order of extra traffic or time brings, in words: «۱۰ گیگابایت», «۷ روز». */
    private static function extraOf(Order $order): string
    {
        $kind = ExtraKind::of($order->type) ?? throw new \LogicException("Order #{$order->id} buys no extra.");

        return $kind->words($kind->quantityOf($order));
    }
}
