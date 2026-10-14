<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerLookup;
use App\Modules\Users\Services\WalletTransfers;
use App\Support\Money;

/**
 * «💸 انتقال موجودی» on the wallet's screen, while the shop lets its customers send money (WalletTransfers):
 *   wsend:              → the recipient's numeric Telegram id asked for in place of the screen (state `wsend`),
 *                         «بازگشت» to the wallet — or, when they may send nothing now, why not
 *   state `wsend`       → the id typed: the amount asked for, from the least to the most they may send now (state
 *                         `wsend.{recipient}`); a recipient refused says why and another id is awaited
 *   state `wsend.{r}`   → the amount typed (TypedAmounts; «۵۰٬۰۰۰ تومان» is fine): whom, how much and the balance left,
 *                         «✅ تایید انتقال» | «❌ انصراف» under it — the chat then waits on that one confirmation
 *                         (`wsend.{r}.{amount}`) and still takes another amount; one out of range says so and waits,
 *                         and once nothing may go now, why, the step over
 *   wsend:{r}:{amount}  → the confirmation, claimed once (a second tap, or one after the chat moved on, sends nothing):
 *                         the money sent (every rule asked again), said in place of the question, «بازگشت» to the wallet
 */
final class WalletTransferHandler implements Handler
{
    public const PREFIX = 'wsend:';
    public const STATE = 'wsend';

    public function __construct(
        private readonly WalletTransfers $transfers,
        private readonly TypedRecipients $recipients,
        private readonly TypedAmounts $amounts,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /**
     * «💸 انتقال موجودی» beside the wallet's «افزایش موجودی», while the shop lets its customers send money.
     *
     * @return list<array<string, mixed>>
     */
    public function button(): array
    {
        return $this->transfers->offered() ? [InlineKeyboard::callback($this->texts->get(BotText::WalletSend), self::PREFIX)] : [];
    }

    public function handle(Context $ctx): void
    {
        if (!$ctx->update->isCallback()) {
            $this->typed($ctx);

            return;
        }

        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        count($args) === 2 ? $this->confirm($ctx, (int) $args[0], (int) $args[1]) : $this->ask($ctx);
    }

    /** `wsend:` — the recipient's id asked for, while the customer may send the least now; otherwise why not. */
    private function ask(Context $ctx): void
    {
        try {
            $this->transfers->check($ctx->user);
        } catch (ValidationException $e) {
            $ctx->session->clear();
            $ctx->edit($this->texts->render(BotText::WalletSendRefused, ['reason' => $e->getMessage()]), $this->backToWallet());

            return;
        }

        $ctx->session->enter(self::STATE);
        $ctx->edit($this->texts->render(BotText::WalletSendAsk, $this->range($ctx->user)), $this->backToWallet());
    }

    /** A message at a step: the recipient's id (state `wsend`), or an amount for the one found (`wsend.{r}[.{amount}]`). */
    private function typed(Context $ctx): void
    {
        $recipientId = (int) (explode('.', (string) $ctx->session->state())[1] ?? 0);
        $recipientId === 0 ? $this->recipientTyped($ctx) : $this->amountTyped($ctx, $recipientId);
    }

    /**
     * State `wsend`: the id typed — the amount asked for the recipient it names, as a new message; a recipient refused
     * says why and another id is awaited. The shop switching it off, the customer left nothing to send meanwhile (asked
     * before the id is looked up), or one who looked up too many, ends the step.
     */
    private function recipientTyped(Context $ctx): void
    {
        $recipient = $this->recipients->read($ctx, fn(string $typed): User => $this->transfers->recipient($ctx->user, $typed), BotText::WalletSendRecipientRefused, BotText::WalletSendRefused, $this->backToWallet());
        if ($recipient === null) {
            return;
        }

        $ctx->session->enter(self::STATE . '.' . $recipient->id);
        $ctx->reply($this->texts->render(BotText::WalletSendAmount, ['name' => $recipient->shownAs(), 'balance' => Messages::balance($ctx->user->balance())] + $this->range($ctx->user)), $this->backToWallet());
    }

    /**
     * State `wsend.{r}`: the amount typed, while it is within what the customer may send now — whom and how much to
     * confirm, as a new message; otherwise the range again, and another amount awaited. Once they may send nothing (the
     * balance spent, the day's cap reached or the shop switching it off meanwhile), why, and the step is over.
     */
    private function amountTyped(Context $ctx, int $recipientId): void
    {
        $recipient = User::query()->find($recipientId);
        if ($recipient === null) {
            $this->recipients->stop($ctx, CustomerLookup::UNKNOWN, BotText::WalletSendRefused, $this->backToWallet());

            return;
        }
        try {
            $this->transfers->check($ctx->user);
        } catch (ValidationException $e) {
            $this->recipients->stop($ctx, $e->getMessage(), BotText::WalletSendRefused, $this->backToWallet());

            return;
        }

        $refusal = $this->texts->render(BotText::WalletSendAmountInvalid, $this->range($ctx->user));
        $amount = $this->amounts->read($ctx, fn(int $amount): bool => $this->transfers->allows($ctx->user, $amount), $refusal, MainMenu::WALLET);
        if ($amount === null) {
            return;
        }

        $ctx->session->enter(self::confirmation($recipient->id, $amount));
        $ctx->reply($this->texts->render(BotText::WalletSendConfirm, [
            'amount' => Money::format($amount),
            'name' => $recipient->shownAs(),
            'balance' => Messages::balance(Money::subtract($ctx->user->balance(), $amount)),
        ]), ['reply_markup' => $this->buttons->confirm(MainMenu::WALLET, BotText::WalletSendYes, CallbackData::build(self::PREFIX, $recipient->id, $amount))]);
    }

    /**
     * `wsend:{r}:{amount}` — the confirmation, claimed first: the money sent (every rule asked again), said in place of the
     * question; a refusal says why.
     */
    private function confirm(Context $ctx, int $recipientId, int $amount): void
    {
        $this->recipients->confirm($ctx, self::confirmation($recipientId, $amount), $recipientId, function (User $recipient) use ($ctx, $amount): void {
            $transfer = $this->transfers->transfer($ctx->user, $recipient, $amount);
            $ctx->edit($this->texts->render(BotText::WalletSendDone, [
                'amount' => Money::format($transfer->amount),
                'name' => $recipient->shownAs(),
                'balance' => Messages::balance($ctx->user->balance()),
            ]), $this->backToWallet());
        }, BotText::WalletSendExpired, BotText::WalletSendRefused, $this->backToWallet());
    }

    /** @return array{min: string, sendable: string} The least one transfer moves and the most the customer may send now. */
    private function range(User $user): array
    {
        [$min] = $this->transfers->bounds();

        return ['min' => Money::format($min), 'sendable' => Money::format($this->transfers->most($user))];
    }

    /** @return array{reply_markup: array<string, mixed>} «بازگشت» to the wallet. */
    private function backToWallet(): array
    {
        return ['reply_markup' => $this->buttons->backOnly(MainMenu::WALLET)];
    }

    /** The state that waits on the one confirmation of sending that recipient that amount. */
    private static function confirmation(int $recipientId, int $amount): string
    {
        return self::STATE . '.' . $recipientId . '.' . $amount;
    }
}
