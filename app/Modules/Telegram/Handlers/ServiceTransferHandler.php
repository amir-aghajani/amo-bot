<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;

/**
 * «🔁 انتقال سرویس» on a service's screen, while the shop lets customers hand their services on (ServiceTransfers):
 *   transfer:{id}             → the recipient's numeric Telegram id asked for in place of the screen (state
 *                               `transfer`), «بازگشت» to it — or, for a service that may not go now, why not
 *   state `transfer`          → the id typed: who that is, «✅ تایید انتقال» | «❌ انصراف» under it — the chat then waits
 *                               on that one confirmation (`transfer.{id}.{recipient}`) and still takes another id; a
 *                               recipient refused says why and another is awaited
 *   transfer:{id}:{recipient} → the confirmation, claimed once (a second tap, or one after the chat moved on, hands
 *                               nothing over): the service given, said in place of the question, «بازگشت» to the list
 */
final class ServiceTransferHandler implements Handler
{
    public const PREFIX = 'transfer:';
    public const STATE = 'transfer';

    /** The scratch key of the service while its recipient is awaited. */
    private const SERVICE = 'service';

    public function __construct(
        private readonly ServiceTransfers $transfers,
        private readonly TypedRecipients $recipients,
        private readonly CustomerServices $services,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** «انتقال سرویس» for the service: its recipient asked for. */
    public static function askCallback(int $subscriptionId): string
    {
        return CallbackData::build(self::PREFIX, $subscriptionId);
    }

    /**
     * The service screen's «🔁 انتقال سرویس», while its customer is offered it (ServiceTransfers::offeredFor()).
     *
     * @return list<array<string, mixed>>
     */
    public function buttons(Subscription $subscription): array
    {
        return $this->transfers->offeredFor($subscription) ? [InlineKeyboard::callback($this->texts->get(BotText::ServiceTransfer), self::askCallback($subscription->id))] : [];
    }

    public function handle(Context $ctx): void
    {
        if (!$ctx->update->isCallback()) {
            $this->typed($ctx);

            return;
        }

        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $service = $this->services->find($ctx, (int) ($args[0] ?? 0), MenuHandler::subscriptionsCallback(1));
        if ($service === null) {
            return;
        }

        isset($args[1]) ? $this->confirm($ctx, $service, (int) $args[1]) : $this->ask($ctx, $service);
    }

    /** `transfer:{id}` — the recipient's id asked for, while the service may go now; otherwise why not. */
    private function ask(Context $ctx, Subscription $service): void
    {
        try {
            $this->transfers->check($service, Actor::customer($ctx->user));
        } catch (ValidationException $e) {
            $ctx->session->clear();
            $ctx->edit($this->texts->render(BotText::TransferRefused, ['reason' => $e->getMessage()]), $this->backToService($service));

            return;
        }

        $ctx->session->enter(self::STATE, [self::SERVICE => $service->id]);
        $ctx->edit($this->texts->render(BotText::TransferAsk, ['client' => $service->remote_name]), $this->backToService($service));
    }

    /**
     * State `transfer`: the id typed — the recipient it names, to confirm, as a new message; a recipient refused says why
     * and another id is awaited. A service that may not go any more, or a customer who looked up too many, ends the step.
     */
    private function typed(Context $ctx): void
    {
        $service = CustomerServices::of($ctx->user)->find((int) $ctx->session->get(self::SERVICE, 0));
        if ($service === null) {
            $ctx->session->clear();
            $ctx->reply($this->texts->get(BotText::ServiceNotFound), ['reply_markup' => $this->buttons->backOnly(MenuHandler::subscriptionsCallback(1))]);

            return;
        }

        $recipient = $this->recipients->read(
            $ctx,
            fn(string $typed): User => $this->transfers->recipient($service, $typed, Actor::customer($ctx->user)),
            BotText::TransferRecipientRefused,
            BotText::TransferRefused,
            $this->backToService($service),
        );
        if ($recipient === null) {
            return;
        }

        $ctx->session->enter(self::confirmation($service, $recipient->id), [self::SERVICE => $service->id]);
        $ctx->reply($this->texts->render(BotText::TransferConfirm, ['client' => $service->remote_name, 'name' => $recipient->shownAs()]), [
            'reply_markup' => $this->buttons->confirmChange($service->id, BotText::TransferYes, CallbackData::build(self::PREFIX, $service->id, $recipient->id)),
        ]);
    }

    /**
     * `transfer:{id}:{recipient}` — the confirmation, claimed first: the service handed over (every rule asked again), said
     * in place of the question; a refusal says why.
     */
    private function confirm(Context $ctx, Subscription $service, int $recipientId): void
    {
        $this->recipients->confirm($ctx, self::confirmation($service, $recipientId), $recipientId, function (User $recipient) use ($ctx, $service): void {
            $this->transfers->transfer($service, $recipient, Actor::customer($ctx->user));
            $ctx->edit(
                $this->texts->render(BotText::TransferDone, ['client' => $service->remote_name, 'name' => $recipient->shownAs()]),
                ['reply_markup' => $this->buttons->backOnly(MenuHandler::subscriptionsCallback(1))],
            );
        }, BotText::TransferExpired, BotText::TransferRefused, $this->backToService($service));
    }

    /** @return array{reply_markup: array<string, mixed>} «بازگشت» to the service's screen. */
    private function backToService(Subscription $service): array
    {
        return ['reply_markup' => $this->buttons->backOnly(SubscriptionHandler::serviceCallback($service->id))];
    }

    /** The state that waits on the one confirmation of handing the service to that recipient. */
    private static function confirmation(Subscription $service, int $recipientId): string
    {
        return self::STATE . '.' . $service->id . '.' . $recipientId;
    }
}
