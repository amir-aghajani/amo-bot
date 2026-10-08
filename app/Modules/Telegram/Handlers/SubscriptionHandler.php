<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\DomainRuleException;
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
 * One of the customer's services, opened from "سرویس‌های من":
 *   sub:{id}              → the service as the panel sees it right now: status, traffic, expiry, last connection
 *   sub:{id}:refresh      → the same, asked again — from the panel only: out of reach, a popup says so and the
 *                           screen stays as it was
 *   sub:{id}:rotate       → «تغییر لینک»: confirm first — every device on the old link drops
 *   sub:{id}:rotate:yes   → a fresh credential and subscription id on the panel, once: the confirmation is spent
 *                           (ChatSession::claim()), so a second tap changes nothing; the new link comes like a
 *                           delivery (QR card or text) in place of the confirm message
 *   sub:{id}:link         → «لینک اشتراک»: the link the shop keeps, in place of the screen (QR card or text) with a
 *                           back button to it — no panel round-trip, so it shows while the panel is down
 *   sub:{id}:autorenew    → «تمدید خودکار» on or off (AutoRenewal); only the buttons are redrawn, the switch's label
 *                           says where it stands — offered while the service runs, ends some day and the wallet is on
 *   sub:{id}:renew        → «تمدید سرویس»: the renewal's checkout (RenewalHandler), «بازگشت» to this screen
 *   sub:{id}:report       → «ارسال گزارش اختلال»: a support ticket about the service, its first message awaited
 *                           (TicketHandler::report()), «بازگشت» to this screen
 * Only the customer's own services answer. When the panel cannot be asked as the screen opens, it shows the
 * row's last known numbers and says so; the back button returns to the list page the service is on. A panel that
 * cannot rotate credentials gets neither the «تغییر لینک» button nor the hint that points at it. The screen is where the
 * chat comes back to from the steps it starts — a report's message, a link's change, a renewal's checkout —, so showing
 * it leaves whatever step the chat was at: what the customer types next is no outage report, and no old confirmation
 * changes a link.
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

        $subscription = Subscription::query()
            ->where('user_id', $ctx->user->id)
            ->where('status', '!=', SubscriptionStatus::Deleted->value)
            ->find((int) ($args[0] ?? 0));
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
     * The screen, from the panel (the row synced on the way), with whether the service is connected now
     * (ProvisioningService::look()). Opened while the panel cannot be read — out of reach, or one the shop leaves alone a
     * while after it failed, so the bot does not wait out its timeout for every customer — it shows the row's last known
     * numbers, flagged. «به‌روزرسانی اطلاعات» (`$refresh`) promises the panel's numbers, so then the customer is told the
     * panel could not be reached and the screen stays as it was — the row's copy is never passed off as fresh. The chat is
     * back on the screen: whatever step it was at is left.
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

    /**
     * «لینک اشتراک»: the link the shop keeps for the service — refreshed from the panel whenever the screen
     * is drawn — in place of the screen: the text edited into it, or the QR card (edited in where Telegram
     * allows, the screen replaced otherwise), with "back" to the screen. One message either way, and no
     * panel round-trip, so the customer gets their link even while the panel is down.
     */
    private function link(Context $ctx, Subscription $subscription): void
    {
        $this->card->show($ctx, $subscription, BotText::LinkRequested, ['reply_markup' => $this->buttons->backOnly(self::serviceCallback($subscription->id))]);
    }

    /**
     * «تمدید خودکار»: the switch flips and only the buttons are redrawn — no panel round-trip, and the numbers
     * on the screen stay as they were. Turning it on says what will happen: when, and what the wallet pays.
     */
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
            // Offered, the service has its plan: what the wallet pays.
            'amount' => Money::format($subscription->plan->price ?? 0),
        ]) : $this->texts->get(BotText::AutoRenewDisabled), alert: $on);
        $ctx->editKeyboard($this->keyboard($subscription, $this->provisioning->rotates($subscription)));
    }

    /** «تغییر لینک» asks first — and the conversation waits for that one confirmation (rotate() claims it). */
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
     * The confirmed change: new ids on the panel, then the confirm message goes and the new link arrives
     * like a delivery — nothing else: the link is what the customer came for (Amir's call), the list is a
     * menu tap away. The confirmation is spent first: a second tap on the same button — or one after the
     * customer went elsewhere — changes nothing, or it would kill the link just delivered. Once the panel has
     * changed the link the customer must hear something: a delivery Telegram refuses is reported as such, with
     * the button that sends the link again.
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

        // The service's own values as every service text words them, and the screen's.
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

        // A renewal queued behind this period, what it leaves unused not carried: say how much goes, and when.
        return BotTexts::paragraphs(
            $text,
            $this->card->periodNote($subscription),
            $rotates ? $this->texts->part(BotText::ServiceRotateHint) : '',
            $live ? '' : $this->texts->part(BotText::ServiceStale),
        );
    }

    /** The row's state first (what sync() concluded), then the panel's own switch. */
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
     * Rows as Telegram lays them out, left to right — for a right-to-left reader the first button of a
     * pair is the one on the right: refresh; renew | link; the «تمدید خودکار» switch (when it is offered,
     * green while on); report | change link (only when the panel can rotate a client's credentials); back
     * to the list.
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

        return InlineKeyboard::make()
            ->row(InlineKeyboard::callback($this->texts->get(BotText::ServiceRefresh), self::serviceCallback($id, 'refresh'), 'primary'))
            ->row($this->linkButton($subscription), InlineKeyboard::callback($this->texts->get(BotText::ServiceRenew), self::serviceCallback($id, 'renew'), 'success'))
            ->row(...$this->autoRenewal->offeredFor($subscription) ? [$autoRenew] : [])
            ->row(...$rotates ? [InlineKeyboard::callback($this->texts->get(BotText::ServiceRotate), self::serviceCallback($id, 'rotate')), $report] : [$report])
            ->row($this->backToList(MenuHandler::subscriptionsCallbackFor($subscription)))
            ->build();
    }

    /** @return array<string, mixed> «لینک اشتراک» — on the screen, and under a new link Telegram would not take. */
    private function linkButton(Subscription $subscription): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::ServiceLink), self::serviceCallback($subscription->id, 'link'), 'primary');
    }

    /** @return array<string, mixed> The red "back to the list" button — worded for the list, unlike the plain back. */
    private function backToList(string $screen): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::ServiceBack), $screen, 'danger');
    }
}
