<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Rewards\DTO\Spun;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Enums\WheelRefusal;
use App\Modules\Rewards\Exceptions\WheelRefusedException;
use App\Modules\Rewards\Models\WheelPrize;
use App\Modules\Rewards\Services\LuckyWheel;
use App\Modules\Rewards\Services\WheelSettings;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Persian;

/**
 * The lucky wheel («گردونه شانس») on the website and in the mini app, as the bot's «🎡 گردونه شانس» spins it
 * (Rewards\Services\LuckyWheel): the wheel as the customer finds it, and a spin — where it landed, for the screen's
 * animation, and what came of it in the bot's words. The screen shows the prize, so nothing is sent in Telegram (a plan's
 * service is among their services once delivered). A spin refused before anything was spent is a 422 on `status` — and
 * so is one the bot would hold back first (CustomerGates: a Telegram account, their number, the shop's channels).
 */
final class CustomerWheel
{
    public function __construct(
        private readonly LuckyWheel $wheel,
        private readonly WheelSettings $settings,
        private readonly CustomerGates $gates,
    ) {}

    /**
     * The wheel's slices in order, a spin's cost and cooldown, and when they may spin next.
     *
     * @return array{slices: list<array{label: string, kind: string}>, cost: string, cooldown_hours: int, next_spin_at: string|null}
     * @throws ValidationException 422 on `status`: switched off, or for buyers only and they have bought nothing
     */
    public function show(User $customer): array
    {
        try {
            $state = $this->wheel->state($customer);
        } catch (WheelRefusedException $e) {
            throw $this->refused($e);
        }

        return [
            'slices' => self::slices($state->prizes),
            'cost' => Money::normalize($state->cost),
            'cooldown_hours' => $state->cooldownHours,
            'next_spin_at' => $state->nextAt?->toIso8601String(),
        ];
    }

    /**
     * Spin for the customer (LuckyWheel::spin()): the slices it turned among, the one it landed on, what came of it, when
     * they may spin next and their wallet after it. The slices are those read just before it turned, the one it drew
     * found among them by its id; should support change them in that very moment, they are answered as the spin drew
     * them, each kind by its label.
     *
     * @return array{slices: list<array{label: string, kind: string}>, landed: int, given: bool, words: string, failure: string|null, refunded: bool, next_spin_at: string|null, balance: string}
     * @throws ValidationException 422 on `status`: nothing spent — the bot's gates, switched off, buyers only, too soon, no
     *                             slice can reach them, a wallet short of its cost
     */
    public function spin(User $customer): array
    {
        $held = $this->gates->refusal($customer);
        if ($held !== null) {
            throw ValidationException::on('status', $held);
        }
        try {
            $prizes = $this->wheel->state($customer)->prizes;
            $spun = $this->wheel->spin($customer, tell: false);
        } catch (WheelRefusedException $e) {
            throw $this->refused($e);
        }

        $landed = array_search($spun->spin->wheel_prize_id, array_map(static fn(WheelPrize $prize): int => $prize->id, $prizes), true);
        if ($landed === false) {
            $kinds = [];
            foreach ($prizes as $prize) {
                $kinds[$prize->label] ??= $prize->kind;
            }
            $slices = array_map(static fn(string $label): array => ['label' => $label, 'kind' => ($kinds[$label] ?? $spun->spin->kind)->value], $spun->slices);
            $landed = $spun->landed;
        } else {
            $slices = self::slices($prizes);
        }

        return [
            'slices' => $slices,
            'landed' => $landed,
            'given' => $spun->given !== null && $spun->given->reward->kind !== RewardKind::Nothing,
            'words' => $this->words($customer, $spun),
            'failure' => $spun->failure,
            'refunded' => $spun->refunded,
            'next_spin_at' => $this->wheel->nextSpinAt($customer)?->toIso8601String(),
            'balance' => $customer->balance(),
        ];
    }

    /**
     * @param list<WheelPrize> $prizes
     * @return list<array{label: string, kind: string}>
     */
    private static function slices(array $prizes): array
    {
        return array_map(static fn(WheelPrize $prize): array => ['label' => $prize->label, 'kind' => $prize->kind->value], $prizes);
    }

    /** What came of the spin, as the bot says it under the wheel: the prize and where it went, nothing won, or why not. */
    private function words(User $customer, Spun $spun): string
    {
        $label = $spun->spin->label;
        if ($spun->given === null) {
            return "گردونه روی «{$label}» ایستاد، ولی جایزه الان داده نشد: {$spun->failure}"
                . ($spun->refunded ? ' هزینه چرخش به کیف پول شما برگشت.' : '')
                . ' این چرخش حساب نشد؛ می‌توانید دوباره بچرخانید.';
        }

        $reward = $spun->given->reward;
        $plan = (string) $reward->plan?->name;
        $given = match ($reward->kind) {
            RewardKind::Nothing => null,
            RewardKind::Wallet => Money::format($reward->amount ?? '0') . ' به کیف پول شما اضافه شد؛ موجودی: ' . Money::format($customer->balance()) . '.',
            RewardKind::Traffic, RewardKind::Days => $reward->words() . ' به سرویس ' . (string) $spun->given->subscription?->shownAs() . ' اضافه شد.',
            RewardKind::Plan => $spun->given->order?->status === OrderStatus::Fulfilled
                ? "سرویس «{$plan}» برای شما ساخته شد و در سرویس‌های شماست."
                : "سرویس «{$plan}» (سفارش \u{200E}#{$spun->given->order?->id}) برای شما ثبت شد و به‌زودی تحویل داده می‌شود.",
        };

        return $given === null ? 'پوچ! این بار جایزه‌ای نبود؛ دفعه بعد شانس با شماست.' : "بردی: {$label}. {$given}";
    }

    /** A spin refused before anything was spent, in the bot's words: a 422 on `status`. */
    private function refused(WheelRefusedException $e): ValidationException
    {
        return ValidationException::on('status', match ($e->reason) {
            WheelRefusal::TooSoon => $e->nextAt === null
                ? $e->getMessage()
                : 'هنوز نوبت چرخش بعدی شما نرسیده است؛ از ' . Persian::date($e->nextAt, withTime: true) . ' می‌توانید دوباره بچرخانید.',
            WheelRefusal::Short => $this->short($e->balance ?? '0'),
            default => $e->getMessage(),
        });
    }

    /** A wallet holding `$balance`, short of a spin's cost: what a spin costs and how much is missing. */
    private function short(string $balance): string
    {
        $cost = $this->settings->cost();

        return 'موجودی کیف پول برای چرخش کافی نیست: هزینه هر چرخش ' . Money::format($cost) . ' است و ' . Money::format(Money::subtract($cost, $balance)) . ' کم دارید.';
    }
}
