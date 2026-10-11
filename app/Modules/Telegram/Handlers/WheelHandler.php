<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Rewards\DTO\Spun;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Enums\WheelRefusal;
use App\Modules\Rewards\Exceptions\WheelRefusedException;
use App\Modules\Rewards\Models\WheelPrize;
use App\Modules\Rewards\Services\LuckyWheel;
use App\Modules\Rewards\Services\WheelSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * «🎡 گردونه شانس»: home() shows the prizes, the cost and «🎡 بچرخان» (or when the next spin is). `wheel:spin` spins it
 * through Rewards\Services\LuckyWheel and answers at once, in one message: the wheel's slices with the one it landed on
 * marked, and what came of it under them. There is no animation: a handler never sleeps, since under bot:poll every other
 * chat would wait. A spin refused before anything was spent says why (too soon, a short wallet, off, nothing to win).
 */
final class WheelHandler implements Handler
{
    public const PREFIX = 'wheel:';

    private const SPIN = 'spin';

    public function __construct(
        private readonly LuckyWheel $wheel,
        private readonly WheelSettings $settings,
        private readonly MainMenu $menu,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    public function handle(Context $ctx): void
    {
        if ($ctx->update->callbackArgs(self::PREFIX) === [self::SPIN]) {
            $this->spin($ctx);

            return;
        }

        $this->home($ctx);
    }

    /** The menu's «🎡 گردونه شانس»: the slices, the cost, and «بچرخان» — or when the next spin is. */
    public function home(Context $ctx): void
    {
        try {
            $state = $this->wheel->state($ctx->user);
        } catch (WheelRefusedException $e) {
            $this->menu->screen($ctx, $this->refusal($e->reason));

            return;
        }
        if ($state->prizes === []) {
            $this->menu->screen($ctx, $this->texts->get(BotText::WheelEmpty));

            return;
        }

        $lines = array_map(fn(WheelPrize $prize): Html => $this->texts->part(BotText::WheelPrizeLine, ['prize' => $prize->label]), $state->prizes);
        $every = Messages::duration($state->cooldownHours);
        $text = $this->texts->render(BotText::WheelScreen, [
            'prizes' => Html::lines(...$lines),
            'cost' => $state->cost > 0
                ? $this->texts->part(BotText::WheelCost, ['amount' => Money::format($state->cost), 'every' => $every])
                : $this->texts->part(BotText::WheelFree, ['every' => $every]),
            'turn' => $this->turn($state->nextAt),
        ]);
        $keyboard = $state->nextAt === null
            ? InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::WheelSpinButton), CallbackData::build(self::PREFIX, self::SPIN), 'success'))
            : null;

        $this->menu->screen($ctx, $text, $keyboard);
    }

    /** `wheel:spin`: the wheel turned and what came of it — or why it did not turn. */
    private function spin(Context $ctx): void
    {
        try {
            $spun = $this->wheel->spin($ctx->user);
        } catch (WheelRefusedException $e) {
            $this->refused($ctx, $e);

            return;
        }

        $ctx->edit(BotTexts::paragraphs($this->landing($spun), $this->result($ctx, $spun)), ['reply_markup' => $this->buttons->backOnly(MainMenu::WHEEL)]);
    }

    /** A spin refused before anything was spent on it. */
    private function refused(Context $ctx, WheelRefusedException $e): void
    {
        match ($e->reason) {
            WheelRefusal::TooSoon => $ctx->answer($this->texts->render(BotText::WheelTooSoon, ['left' => self::left($e->nextAt ?? now())]), alert: true),
            WheelRefusal::Short => $ctx->edit($this->texts->render(BotText::WheelShort, [
                'amount' => Money::format($this->settings->cost()),
                'missing' => Money::format(Money::subtract($this->settings->cost(), $e->balance ?? '0')),
            ]), ['reply_markup' => InlineKeyboard::make()->row($this->buttons->topUp())->row($this->buttons->back(MainMenu::WHEEL))->build()]),
            default => $ctx->edit($this->refusal($e->reason), ['reply_markup' => $this->buttons->backOnly(MainMenu::WHEEL)]),
        };
    }

    /** The wheel's slices, one a line, the one it landed on marked. */
    private function landing(Spun $spun): Html
    {
        $lines = [];
        foreach ($spun->slices as $index => $label) {
            $lines[] = $this->texts->part($index === $spun->landed ? BotText::WheelSliceCurrent : BotText::WheelSlice, ['prize' => $label]);
        }

        return Html::lines(...$lines);
    }

    /** What came of the spin, with when the next one is. */
    private function result(Context $ctx, Spun $spun): string
    {
        $label = $spun->spin->label;
        if ($spun->given === null) {
            return $this->texts->render(BotText::WheelFailed, [
                'prize' => $label,
                'reason' => (string) $spun->failure,
                'refund' => $spun->refunded ? $this->texts->part(BotText::WheelRefunded) : '',
            ]);
        }

        $turn = $this->turn($this->wheel->nextSpinAt($ctx->user));
        $reward = $spun->given->reward;
        $given = match ($reward->kind) {
            RewardKind::Nothing => null,
            RewardKind::Wallet => $this->texts->part(BotText::WheelGivenWallet, [
                'amount' => Money::format($reward->amount ?? '0'),
                'balance' => Messages::balance($ctx->user->balance()),
            ]),
            RewardKind::Traffic, RewardKind::Days => $this->texts->part(BotText::WheelGivenService, [
                'extra' => $reward->words(),
                'client' => (string) $spun->given->subscription?->remote_name,
            ]),
            RewardKind::Plan => $spun->given->order?->status === OrderStatus::Fulfilled
                ? $this->texts->part(BotText::WheelGivenPlan, ['plan' => (string) $reward->plan?->name])
                : $this->texts->part(BotText::WheelGivenPlanPending, ['plan' => (string) $reward->plan?->name, 'order' => (string) $spun->given->order?->id]),
        };

        return $given === null
            ? $this->texts->render(BotText::WheelNothing, ['turn' => $turn])
            : $this->texts->render(BotText::WheelWon, ['prize' => $label, 'given' => $given, 'turn' => $turn]);
    }

    /** When the next spin is — or nothing, while one may spin now. */
    private function turn(?Carbon $at): Html|string
    {
        return $at === null ? '' : $this->texts->part(BotText::WheelNext, ['when' => Persian::date($at, withTime: true), 'left' => self::left($at)]);
    }

    private function refusal(WheelRefusal $reason): string
    {
        return $this->texts->get(match ($reason) {
            WheelRefusal::Off => BotText::WheelOff,
            WheelRefusal::BuyersOnly => BotText::WheelBuyersOnly,
            default => BotText::WheelEmpty,
        });
    }

    /** The time until `$at` worded for the customer: «۴۵ دقیقه», «۵ ساعت», «۱ روز و ۶ ساعت». */
    private static function left(Carbon $at): string
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($at, absolute: true) / 60));

        return $minutes < 60 ? Persian::minutes($minutes) : Messages::duration((int) ceil($minutes / 60));
    }
}
