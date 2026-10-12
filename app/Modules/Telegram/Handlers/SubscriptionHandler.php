<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Exceptions\ServiceNotReadException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\RenewalSettings;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Money;
use App\Support\Persian;
use Psr\Log\LoggerInterface;

/**
 * One of the customer's services, opened from «سرویس‌های من»:
 *   sub:{id}            → the service as its panel sees it now (the row's last numbers, flagged, when the panel is out
 *                         of reach)
 *   sub:{id}:refresh    → the same from the panel only: out of reach, a popup says so and the screen stays
 *   sub:{id}:link       → «لینک اشتراک»: the kept link (QR card or text) in place of the screen, no panel asked
 *   sub:{id}:rotate     → «تغییر لینک» asks first; `rotate:yes` claims that confirmation once, so a second tap
 *                         cannot kill the link just delivered
 *   sub:{id}:autorenew  → «تمدید خودکار» on or off; only the buttons are redrawn
 *   sub:{id}:renew      → the renewal's checkout (RenewalHandler)
 *   sub:{id}:report     → «ارسال گزارش اختلال»: a support ticket about the service (TicketHandler::report())
 * Showing the screen leaves whatever step the chat was at, so nothing typed next is taken as an old step's answer.
 */
final class SubscriptionHandler implements Handler
{
    public const PREFIX = 'sub:';

    /** The conversation's state while «تغییر لینک» waits for its confirmation, the service's id after it. */
    private const ROTATING = 'sub.rotate.';

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly RenewalHandler $renewal,
        private readonly TicketHandler $tickets,
        private readonly AutoRenewal $autoRenewal,
        private readonly RenewalSettings $renewalSettings,
        private readonly ExtrasHandler $extras,
        private readonly Pricing $pricing,
        private readonly ServiceCard $card,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
        private readonly LoggerInterface $logger,
    ) {}

    /** The service's screen; `$action` one of its buttons (refresh, link, rotate…). */
    public static function serviceCallback(int $subscriptionId, string ...$action): string
    {
        return CallbackData::build(self::PREFIX, $subscriptionId, ...$action);
    }

    public function handle(Context $ctx): void
    {
        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $action = $args[1] ?? 'show';

        $subscription = CustomerServices::of($ctx->user)->find((int) ($args[0] ?? 0));
        if ($subscription === null) {
            $ctx->edit($this->texts->get(BotText::ServiceNotFound), ['reply_markup' => InlineKeyboard::make()->row($this->backToList(MenuHandler::subscriptionsCallback(1)))->build()]);

            return;
        }

        match (true) {
            $action === 'refresh' => $this->show($ctx, $subscription, refresh: true),
            $action === 'rotate' && ($args[2] ?? null) === 'yes' => $this->rotate($ctx, $subscription),
            $action === 'rotate' => $this->confirmRotate($ctx, $subscription),
            $action === 'link' => $this->link($ctx, $subscription),
            $action === 'autorenew' => $this->toggleAutoRenew($ctx, $subscription),
            $action === 'renew' => $this->renewal->checkout($ctx, $subscription),
            $action === 'report' => $this->tickets->report($ctx, $subscription),
            default => $this->show($ctx, $subscription),
        };
    }

    /**
     * The screen, read from the panel with presence (ProvisioningService::look(), which skips a panel in backoff so the
     * customer never waits out its timeout). Unread, it shows the row's last numbers, flagged — but a `$refresh` promises
     * the panel's numbers, so then a popup says the panel was not reached and the screen stays as it was.
     */
    private function show(Context $ctx, Subscription $subscription, bool $refresh = false): void
    {
        $ctx->session->clear();
        $client = null;
        $live = false;
        try {
            $client = $this->provisioning->look($subscription);
            $live = true;
        } catch (ServiceNotReadException) {
            // The row's last known numbers, flagged.
        }
        if (!$live && $refresh) {
            $ctx->answer($this->texts->get(BotText::ServiceRefreshFailed), alert: true);

            return;
        }

        $rotates = $this->provisioning->rotates($subscription);
        if ($refresh) {
            $ctx->answer($this->texts->get(BotText::ServiceRefreshed));
        }
        $ctx->edit($this->details($subscription, $client, $live, $rotates), ['reply_markup' => $this->keyboard($subscription, $rotates)]);
    }

    /** «لینک اشتراک»: the kept link in place of the screen, with «بازگشت» to it — no panel asked, so it works while one is down. */
    private function link(Context $ctx, Subscription $subscription): void
    {
        $this->card->show($ctx, $subscription, BotText::LinkRequested, ['reply_markup' => $this->buttons->backOnly(self::serviceCallback($subscription->id))]);
    }

    /** «تمدید خودکار» flipped: only the buttons are redrawn. Turning it on says when it renews and what the wallet pays. */
    private function toggleAutoRenew(Context $ctx, Subscription $subscription): void
    {
        $on = !$subscription->auto_renew;
        try {
            $this->autoRenewal->set($subscription, $on);
        } catch (AutoRenewUnavailableException) {
            $ctx->answer($this->texts->get(BotText::AutoRenewUnavailable), alert: true);
            $ctx->editKeyboard($this->keyboard($subscription, $this->provisioning->rotates($subscription)));

            return;
        }

        $ctx->answer($on ? $this->texts->render(BotText::AutoRenewEnabled, [
            'days' => Persian::digits($this->renewalSettings->autoRenewDays()),
            // The customer's renewal price: an agent's at the agent price, less the automatic discount.
            'amount' => Money::format($subscription->plan === null ? 0 : $this->pricing->renewal($ctx->user, $subscription, $subscription->plan)->amount()),
        ]) : $this->texts->get(BotText::AutoRenewDisabled), alert: $on);
        $ctx->editKeyboard($this->keyboard($subscription, $this->provisioning->rotates($subscription)));
    }

    /** «تغییر لینک» asks first; the chat waits on that one confirmation, which rotate() claims. */
    private function confirmRotate(Context $ctx, Subscription $subscription): void
    {
        if (!$subscription->isActive()) {
            $ctx->edit($this->texts->message(BotText::ServiceInactive), ['reply_markup' => $this->buttons->backOnly(self::serviceCallback($subscription->id))]);

            return;
        }

        // Reading order: "yes" first (right), "no" after it (left) — Telegram lays a row out left to right.
        $keyboard = InlineKeyboard::make()->row(
            InlineKeyboard::callback($this->texts->get(BotText::Cancel), self::serviceCallback($subscription->id)),
            InlineKeyboard::callback($this->texts->get(BotText::ServiceRotateYes), self::serviceCallback($subscription->id, 'rotate', 'yes'), 'danger'),
        );
        $ctx->session->enter(self::ROTATING . $subscription->id);
        $ctx->edit($this->texts->render(BotText::ServiceRotateConfirm, ['client' => $subscription->remote_name]), ['reply_markup' => $keyboard->build()]);
    }

    /**
     * The confirmed change: new credentials on the panel, then the new link sent like a delivery in place of the
     * confirmation. The confirmation is claimed first, so a second tap (or one after the chat moved on) changes nothing.
     * If the panel changed the link but Telegram refused the delivery, the customer gets a word and a button to resend it.
     */
    private function rotate(Context $ctx, Subscription $subscription): void
    {
        if (!$ctx->session->claim(self::ROTATING . $subscription->id)) {
            $ctx->answer($this->texts->get(BotText::ServiceRotateExpired), alert: true);

            return;
        }
        if (!$subscription->isActive()) {
            $ctx->answer($this->texts->get(BotText::ServiceInactive), alert: true);

            return;
        }

        try {
            $this->provisioning->rotateLink($subscription);
        } catch (ProviderException | DomainRuleException $e) {
            $this->logger->warning('Could not rotate the link of subscription {id} on {server}: {message}', ['id' => $subscription->id, 'server' => $subscription->server->name, 'message' => $e->getMessage()]);
            $ctx->edit($this->texts->get(BotText::ServiceRotateFailed), ['reply_markup' => $this->buttons->backOnly(self::serviceCallback($subscription->id))]);

            return;
        }

        $ctx->answer($this->texts->get(BotText::ServiceRotated));
        $ctx->delete();
        try {
            $this->card->send($ctx->chatId(), $subscription, BotText::LinkRotated);
        } catch (TelegramApiException $e) {
            $this->logger->warning('Rotated the link of subscription {id} but could not send it: {message}', ['id' => $subscription->id, 'message' => $e->getMessage()]);
            $ctx->reply($this->texts->get(BotText::ServiceRotatedUnsent), ['reply_markup' => InlineKeyboard::make()->row($this->linkButton($subscription))->build()]);
        }
    }

    private function details(Subscription $subscription, ?ClientInfo $client, bool $live, bool $rotates): string
    {
        $limit = $subscription->traffic_limit_bytes;
        $used = $subscription->usedBytes();
        $remaining = $subscription->remainingBytes();

        $text = $this->texts->render(BotText::ServiceDetails, array_intersect_key($this->card->values($subscription), array_flip(['client', 'plan', 'server', 'traffic', 'expires'])) + [
            'status' => $this->status($subscription, $client),
            'used' => $used > 0 ? Messages::bytes($used) : $this->texts->part(BotText::ServiceUnused),
            'remaining' => $remaining === null
                ? Messages::UNLIMITED
                : Messages::bytes($remaining) . ' (' . Messages::percent($remaining, $limit) . ')',
            'last_online' => match (true) {
                $client === null => $this->texts->part(BotText::ServiceUnknown),
                $client->lastOnlineAt === null => $this->texts->part(BotText::ServiceNeverConnected),
                default => Persian::date($client->lastOnlineAt, withTime: true),
            },
            'connection' => $this->texts->part(match ($client?->online) {
                null => BotText::ServiceUnknown,
                true => BotText::ServiceOnline,
                false => BotText::ServiceOffline,
            }),
        ]);

        // The traffic that expires with the current period, when the shop removes the service, the link-change hint,
        // and the stale flag.
        return BotTexts::paragraphs(
            $text,
            $this->card->periodNote($subscription),
            $this->card->removal($subscription),
            $rotates ? $this->texts->part(BotText::ServiceRotateHint) : '',
            $live ? '' : $this->texts->part(BotText::ServiceStale),
        );
    }

    /** The service's status word: the row's state first, then the panel's own switch. */
    private function status(Subscription $subscription, ?ClientInfo $client): Html
    {
        return $this->texts->part(match (true) {
            $subscription->status === SubscriptionStatus::Deleted => BotText::ServiceStatusDeleted,
            $subscription->status === SubscriptionStatus::Disabled => BotText::ServiceStatusDisabled,
            $subscription->isExpiredByTraffic() => BotText::ServiceStatusDepleted,
            $subscription->isExpiredByTime(), $subscription->status === SubscriptionStatus::Expired => BotText::ServiceStatusExpired,
            $client !== null && !$client->enabled => BotText::ServiceStatusDisabled,
            default => BotText::ServiceStatusActive,
        });
    }

    /**
     * The screen's buttons (Telegram lays a row out left to right, so a row's first button sits on the left): refresh;
     * renew (none without a plan: a free test, or a service whose plan was deleted) | link; the extras; the «تمدید خودکار»
     * switch while offered; report | change link (when the panel can); back to the list page the service is on.
     *
     * @return array<string, mixed>
     */
    private function keyboard(Subscription $subscription, bool $rotates): array
    {
        $id = $subscription->id;
        $report = InlineKeyboard::callback($this->texts->get(BotText::ServiceReport), self::serviceCallback($id, 'report'));
        $autoRenew = $subscription->auto_renew
            ? InlineKeyboard::callback($this->texts->get(BotText::ServiceAutoRenewOn), self::serviceCallback($id, 'autorenew'), 'success')
            : InlineKeyboard::callback($this->texts->get(BotText::ServiceAutoRenewOff), self::serviceCallback($id, 'autorenew'));
        $renew = $subscription->plan_id === null ? [] : [InlineKeyboard::callback($this->texts->get(BotText::ServiceRenew), self::serviceCallback($id, 'renew'), 'success')];

        return InlineKeyboard::make()
            ->row(InlineKeyboard::callback($this->texts->get(BotText::ServiceRefresh), self::serviceCallback($id, 'refresh'), 'primary'))
            ->row($this->linkButton($subscription), ...$renew)
            ->row(...$this->extras->buttons($subscription))
            ->row(...$this->autoRenewal->offeredFor($subscription) ? [$autoRenew] : [])
            ->row(...$rotates ? [InlineKeyboard::callback($this->texts->get(BotText::ServiceRotate), self::serviceCallback($id, 'rotate')), $report] : [$report])
            ->row($this->backToList(MenuHandler::subscriptionsCallbackFor($subscription)))
            ->build();
    }

    /** @return array<string, mixed> «لینک اشتراک»: on the screen, and under a new link Telegram would not take. */
    private function linkButton(Subscription $subscription): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::ServiceLink), self::serviceCallback($subscription->id, 'link'), 'primary');
    }

    /** @return array<string, mixed> The red «بازگشت به لیست سرویس‌ها» button. */
    private function backToList(string $screen): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::ServiceBack), $screen, 'danger');
    }
}
