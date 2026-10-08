<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Input;
use App\Support\Money;

/**
 * Charging the wallet:
 *   topup:start                → the preset amounts (bot settings) and "any amount" — an amount typed straight away
 *                                taken as after it
 *   topup:custom               → state `topup.amount`: the customer types an amount (Input::amountOf(): Persian digits,
 *                                separators and «تومان» fine)
 *   topup:amt:{toman}          → the checkout (CheckoutScreen): that amount, and every way to pay it but the wallet itself
 *   topup:amt:{toman}:{method} → pay with that method: the top-up is ordered now (OrderService::openTopUp())
 * Once paid, the amount lands on the balance (OrderService::deliver()).
 */
final class TopUpHandler implements Handler
{
    public const CALLBACK = 'topup:';
    public const STATE = 'topup';
    /** The top-up's first screen — the wallet's «افزایش موجودی» (Keyboard\Buttons::topUp()). */
    public const START = 'topup:start';

    private const AMOUNT = 'amt';
    private const CUSTOM = 'custom';
    private const AMOUNT_STATE = 'topup.amount';

    public function __construct(
        private readonly WalletSettings $settings,
        private readonly OrderService $orders,
        private readonly PaymentMethods $methods,
        private readonly CheckoutScreen $screen,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** The checkout of a top-up of `$amount` Toman. */
    public static function checkoutCallback(int $amount): string
    {
        return CallbackData::build(self::CALLBACK, self::AMOUNT, $amount);
    }

    public function handle(Context $ctx): void
    {
        $args = $ctx->update->callbackArgs(self::CALLBACK);

        match (true) {
            $args === null => $this->typed($ctx),
            $ctx->update->callbackData() === self::START => $this->start($ctx),
            $args === [self::CUSTOM] => $this->askAmount($ctx),
            count($args) === 2 && $args[0] === self::AMOUNT => $this->checkout($ctx, (int) $args[1]),
            count($args) === 3 && $args[0] === self::AMOUNT => $this->buy($ctx, (int) $args[1], (int) $args[2]),
            default => $ctx->edit($this->texts->get(BotText::Unknown)),
        };
    }

    /**
     * The checkout of a top-up of `$amount` Toman — the presets instead, when it is outside the bot's bounds (they changed
     * since the button was made).
     */
    public function checkout(Context $ctx, int $amount): void
    {
        if (!$this->orders->topUpAllowed($amount)) {
            $this->start($ctx);

            return;
        }

        $this->screen->show(
            $ctx,
            $this->texts->render(BotText::TopupCheckout, ['amount' => Money::format($amount)]),
            OrderType::WalletTopUp,
            static fn(PaymentMethod $method): string => CallbackData::build(self::CALLBACK, self::AMOUNT, $amount, $method->id),
            self::START,
        );
    }

    /**
     * The amounts offered as buttons, and «مبلغ دلخواه» — and, as the screen invites, an amount typed in their place
     * straight away: it is awaited here (state `topup.amount`) as after «مبلغ دلخواه».
     */
    private function start(Context $ctx): void
    {
        $presets = array_map(static fn(int $amount): array => InlineKeyboard::callback(Money::format($amount), self::checkoutCallback($amount)), $this->settings->topUpPresets());
        $keyboard = InlineKeyboard::make()
            ->grid($presets, 2)
            ->row(InlineKeyboard::callback($this->texts->get(BotText::TopupCustom), CallbackData::build(self::CALLBACK, self::CUSTOM)))
            ->row($this->buttons->back(MainMenu::WALLET));

        $ctx->session->enter(self::AMOUNT_STATE);
        $ctx->edit($this->texts->render(BotText::TopupPick, $this->minimum()), ['reply_markup' => $keyboard->build()]);
    }

    private function askAmount(Context $ctx): void
    {
        $ctx->session->enter(self::AMOUNT_STATE);
        $ctx->edit($this->texts->render(BotText::TopupAsk, $this->minimum()), ['reply_markup' => $this->buttons->backOnly(self::START)]);
    }

    /** State `topup.amount`: whatever the customer typed. */
    private function typed(Context $ctx): void
    {
        $amount = Input::amountOf($ctx->update->text());
        if ($amount === null || !$this->orders->topUpAllowed($amount)) {
            $ctx->reply($this->texts->render(BotText::TopupInvalid, $this->minimum()), ['reply_markup' => $this->buttons->backOnly(self::START)]);

            return;
        }

        $this->checkout($ctx, $amount);
    }

    /** A way to pay picked: the top-up ordered — or the open one of that amount found — and paid. */
    private function buy(Context $ctx, int $amount, int $methodId): void
    {
        $method = $this->methods->payable($methodId, OrderType::WalletTopUp);
        if ($method === null || !$this->orders->topUpAllowed($amount)) {
            // Switched off since the checkout was shown, or the bounds moved: the checkout as it stands now.
            $this->checkout($ctx, $amount);

            return;
        }

        $this->screen->buy($ctx, $method, Money::normalize($amount), fn(): Order => $this->orders->openTopUp($ctx->user, $amount), self::checkoutCallback($amount));
    }

    /** @return array{min: string} The smallest charge the bot takes, for the texts that mention it. */
    private function minimum(): array
    {
        return ['min' => Money::format($this->settings->topUpMin())];
    }
}
