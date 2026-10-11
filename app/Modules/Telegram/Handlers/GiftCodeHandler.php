<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Rewards\DTO\Redeemed;
use App\Modules\Rewards\Enums\GiftCodeRefusal;
use App\Modules\Rewards\Exceptions\GiftCodeRefusedException;
use App\Modules\Rewards\Services\GiftCodes;
use App\Modules\Rewards\Services\GiftCodeSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Support\Money;

/**
 * «🎁 کد هدیه»: home() asks for a code and awaits it (state `gift.code`); what the customer types is redeemed through
 * Rewards\Services\GiftCodes — Toman into the wallet, or a plan's service (delivered on its own). A refused code is said
 * why and another is awaited until the customer goes elsewhere.
 */
final class GiftCodeHandler implements Handler
{
    public const STATE = 'gift';

    private const CODE_STATE = 'gift.code';

    public function __construct(
        private readonly GiftCodes $codes,
        private readonly GiftCodeSettings $settings,
        private readonly MainMenu $menu,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** The menu's «🎁 کد هدیه»: asks for the code — or, while the shop takes none, says so. */
    public function home(Context $ctx): void
    {
        if (!$this->settings->enabled()) {
            $this->menu->screen($ctx, $this->texts->get(BotText::GiftCodeOff));

            return;
        }

        $ctx->session->enter(self::CODE_STATE);
        $this->menu->screen($ctx, $this->texts->get(BotText::GiftCodeAsk));
    }

    /** State `gift.code`: the code the customer typed. */
    public function handle(Context $ctx): void
    {
        $typed = trim((string) $ctx->update->text());
        if ($typed === '') {
            $ctx->reply($this->texts->get(BotText::GiftCodeAsk), $this->back());

            return;
        }

        try {
            $redeemed = $this->codes->redeem($ctx->user, $typed);
        } catch (GiftCodeRefusedException $e) {
            if ($e->reason === GiftCodeRefusal::Off) {
                $ctx->session->clear();
            }
            $ctx->reply($this->refusal($e), $this->back());

            return;
        } catch (TooManyAttemptsException $e) {
            $ctx->reply($this->texts->render(BotText::GiftCodeWait, ['minutes' => TooManyAttemptsException::minutes($e->retryAfter)]), $this->back());

            return;
        }

        $ctx->session->clear();
        $ctx->reply($this->redeemed($ctx, $redeemed), $this->back());
    }

    /** What the code gave: the Toman and the new balance, or the plan's service (delivered, or on its way). */
    private function redeemed(Context $ctx, Redeemed $redeemed): string
    {
        $order = $redeemed->given->order;
        if ($order === null) {
            return $this->texts->render(BotText::GiftCodeWallet, [
                'amount' => Money::format($redeemed->code->amount ?? '0'),
                'balance' => Messages::balance($ctx->user->balance()),
            ]);
        }

        $plan = (string) $redeemed->given->reward->plan?->name;

        return $order->status === OrderStatus::Fulfilled
            ? $this->texts->render(BotText::GiftCodePlan, ['plan' => $plan])
            : $this->texts->render(BotText::GiftCodePlanPending, ['plan' => $plan, 'order' => (string) $order->id]);
    }

    private function refusal(GiftCodeRefusedException $e): string
    {
        return match ($e->reason) {
            GiftCodeRefusal::Off => $this->texts->get(BotText::GiftCodeOff),
            GiftCodeRefusal::Empty => $this->texts->get(BotText::GiftCodeAsk),
            GiftCodeRefusal::Unknown => $this->texts->get(BotText::GiftCodeUnknown),
            GiftCodeRefusal::Taken => $this->texts->get(BotText::GiftCodeTaken),
            GiftCodeRefusal::Yours => $this->texts->get(BotText::GiftCodeYours),
            GiftCodeRefusal::Expired => $this->texts->get(BotText::GiftCodeExpired),
            GiftCodeRefusal::Unavailable => $this->texts->render(BotText::GiftCodeUnavailable, ['reason' => $e->getMessage()]),
        };
    }

    /** @return array{reply_markup: array<string, mixed>} «بازگشت» to the menu under the answer. */
    private function back(): array
    {
        return ['reply_markup' => $this->buttons->backOnly(MainMenu::HOME)];
    }
}
