<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Models\WheelPrize;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Input;
use App\Support\Money;
use App\Support\Validation;

/**
 * The lucky wheel's slices as the panels keep them, in the admin's order: a label, a reward and a weight. Deleting one
 * changes no spin (a spin keeps the slice as it was).
 */
final class WheelPrizes
{
    public const LABEL_MAX = 32;

    /** The largest weight: a slice's share is its weight over the enabled slices' together. */
    public const WEIGHT_MAX = 10_000;

    public const TRAFFIC_MAX = 10_000;

    public const DAYS_MAX = 3650;

    /** @return list<array<string, mixed>> Every slice in the admin's order. */
    public function all(): array
    {
        return array_values(WheelPrize::query()->with('plan')->oldest('sort')->oldest('id')->get()->map(self::present(...))->all());
    }

    /**
     * @param array<string, mixed> $input {label, kind, amount?, plan_id?, weight, enabled?}
     * @throws ValidationException
     */
    public function create(array $input): WheelPrize
    {
        $prize = new WheelPrize(self::validate($input, null) + ['sort' => Sorting::next(WheelPrize::class)]);
        $prize->save();

        return $prize->load('plan');
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(WheelPrize $prize, array $input): WheelPrize
    {
        $prize->fill(self::validate($input, $prize))->save();

        return $prize->load('plan');
    }

    public function setEnabled(WheelPrize $prize, bool $enabled): WheelPrize
    {
        $prize->forceFill(['enabled' => $enabled])->save();

        return $prize->load('plan');
    }

    public function delete(WheelPrize $prize): void
    {
        $prize->delete();
    }

    /** @param list<int> $ids The new order; slices left out keep their place after the listed ones. */
    public function reorder(array $ids): void
    {
        Sorting::reorder(WheelPrize::class, $ids);
    }

    /** @return array<string, mixed> One slice, its plan loaded with it. */
    public static function present(WheelPrize $prize): array
    {
        return [
            'id' => $prize->id,
            'label' => $prize->label,
            'kind' => $prize->kind->value,
            'amount' => $prize->amount,
            'plan' => $prize->plan === null ? null : ['id' => $prize->plan->id, 'name' => $prize->plan->name],
            'weight' => $prize->weight,
            'enabled' => $prize->enabled,
            'sort' => $prize->sort,
        ];
    }

    /**
     * The slice's fields, every refusal at once; `enabled` left out keeps the slice's switch (a new one is on).
     *
     * @param array<string, mixed> $input
     * @return array{label: string, kind: RewardKind, amount: string|null, plan_id: int|null, weight: int, enabled: bool}
     * @throws ValidationException
     */
    private static function validate(array $input, ?WheelPrize $prize): array
    {
        $errors = [];

        $label = Input::text($input, 'label');
        $message = match (true) {
            $label === '' => 'متنی که گردونه نشان می‌دهد را بنویسید؛ مثلا «۱۰ گیگ حجم».',
            mb_strlen($label) > self::LABEL_MAX => Validation::tooLong('متن جایزه', self::LABEL_MAX),
            default => null,
        };
        if ($message !== null) {
            $errors['label'][] = $message;
        }

        $kind = RewardKind::tryFrom(Input::text($input, 'kind'));
        $amount = null;
        $planId = null;
        if ($kind === null) {
            $errors['kind'][] = 'نوع جایزه را انتخاب کنید.';
        } elseif ($kind === RewardKind::Wallet) {
            $value = Input::amount($input, 'amount');
            if ($value === null || $value < Reward::WALLET_MIN || $value > WalletSettings::TOPUP_MAX) {
                $errors['amount'][] = sprintf('مبلغ جایزه باید مبلغی بین %s تا %s باشد.', Money::format(Reward::WALLET_MIN), Money::format(WalletSettings::TOPUP_MAX));
            } else {
                $amount = Money::normalize($value);
            }
        } elseif ($kind === RewardKind::Traffic) {
            $value = Input::decimal($input, 'amount');
            if ($value === null || (float) $value <= 0 || (float) $value > self::TRAFFIC_MAX) {
                $errors['amount'][] = 'حجم جایزه باید عددی بزرگ‌تر از صفر و حداکثر ' . self::TRAFFIC_MAX . ' گیگابایت باشد.';
            } else {
                $amount = $value;
            }
        } elseif ($kind === RewardKind::Days) {
            $value = Input::integer($input, 'amount');
            if ($value === null || $value < 1 || $value > self::DAYS_MAX) {
                $errors['amount'][] = 'تعداد روز جایزه باید عددی بین 1 تا ' . self::DAYS_MAX . ' باشد.';
            } else {
                $amount = (string) $value;
            }
        } elseif ($kind === RewardKind::Plan) {
            $id = Input::integer($input, 'plan_id');
            $plan = $id === null ? null : Plan::query()->find($id);
            if ($plan === null) {
                $errors['plan_id'][] = 'پلنی را انتخاب کنید که سرویسش جایزه داده می‌شود.';
            } else {
                $planId = $plan->id;
            }
        }

        $weight = Input::integer($input, 'weight');
        if ($weight === null || $weight < 1 || $weight > self::WEIGHT_MAX) {
            $errors['weight'][] = 'وزن جایزه باید عددی بین 1 تا ' . self::WEIGHT_MAX . ' باشد.';
        }

        $enabled = $prize === null || $prize->enabled;
        if (array_key_exists('enabled', $input)) {
            if (Input::isBoolean($input['enabled'])) {
                $enabled = Input::truthy($input['enabled']);
            } else {
                $errors['enabled'][] = Validation::NOT_A_SWITCH;
            }
        }
        ValidationException::ifAny($errors);

        return ['label' => $label, 'kind' => $kind ?? RewardKind::Nothing, 'amount' => $amount, 'plan_id' => $planId, 'weight' => (int) $weight, 'enabled' => $enabled];
    }
}
