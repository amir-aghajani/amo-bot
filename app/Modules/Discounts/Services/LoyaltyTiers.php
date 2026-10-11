<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Core\Database\Sorting;
use App\Core\Database\UniqueName;
use App\Core\Exceptions\ValidationException;
use App\Modules\Discounts\Models\LoyaltyTier;
use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;
use App\Support\Validation;

/**
 * The loyalty tiers as the panels keep them («سطوح وفاداری»): a unique name, a threshold of Toman spent (no two tiers at
 * one threshold), a discount percent and a cashback percent. Listed lowest threshold first, each with how many customers
 * are on it now.
 */
final class LoyaltyTiers
{
    public const NAME_MAX = 32;

    /** The highest threshold a tier may have, Toman. */
    public const SPENT_MAX = 10_000_000_000;

    private const TAKEN = 'سطحی با این نام وجود دارد.';
    private const SAME_THRESHOLD = 'سطح دیگری با همین حد خرید هست؛ هر سطح حد خرید خودش را دارد.';

    public function __construct(private readonly Loyalty $loyalty) {}

    /**
     * Every tier, lowest threshold first, with its customers counted.
     *
     * @return list<array{id: int, name: string, min_spent: string, discount_percent: int, cashback_percent: int, customers: int}>
     */
    public function all(): array
    {
        $tiers = $this->loyalty->tiers();
        $customers = self::population($tiers);

        return array_map(static fn(LoyaltyTier $tier): array => self::present($tier, $customers[$tier->id] ?? 0), $tiers);
    }

    /**
     * @param array<string, mixed> $input {name, min_spent, discount_percent, cashback_percent}
     * @throws ValidationException
     */
    public function create(array $input): LoyaltyTier
    {
        $tier = new LoyaltyTier(self::validate($input, new LoyaltyTier()) + ['sort' => Sorting::next(LoyaltyTier::class)]);
        UniqueName::save($tier, self::TAKEN);

        return $tier;
    }

    /**
     * @param array<string, mixed> $input {name, min_spent, discount_percent, cashback_percent}
     * @throws ValidationException
     */
    public function update(LoyaltyTier $tier, array $input): LoyaltyTier
    {
        UniqueName::save($tier->fill(self::validate($input, $tier)), self::TAKEN);

        return $tier;
    }

    /** Deletes a tier: its customers fall to the tier below, or to none; no order changes. */
    public function delete(LoyaltyTier $tier): void
    {
        $tier->delete();
    }

    /**
     * One tier as the list shows it — every tier is read to count its customers, since each customer is on one.
     *
     * @return array{id: int, name: string, min_spent: string, discount_percent: int, cashback_percent: int, customers: int}
     */
    public function presentOne(LoyaltyTier $tier): array
    {
        return self::present($tier, self::population($this->loyalty->tiers())[$tier->id] ?? 0);
    }

    /** @return array{id: int, name: string, min_spent: string, discount_percent: int, cashback_percent: int, customers: int} */
    private static function present(LoyaltyTier $tier, int $customers): array
    {
        return [
            'id' => $tier->id,
            'name' => $tier->name,
            'min_spent' => Money::normalize($tier->min_spent),
            'discount_percent' => $tier->discount_percent,
            'cashback_percent' => $tier->cashback_percent,
            'customers' => $customers,
        ];
    }

    /**
     * How many customers each tier holds now (by tier id): every customer's spending read in one grouped query, each put
     * on their tier; a tier from 0 Toman also holds every customer who has spent nothing.
     *
     * @param list<LoyaltyTier> $tiers The lowest threshold first
     * @return array<int, int>
     */
    private static function population(array $tiers): array
    {
        if ($tiers === []) {
            return [];
        }

        $counts = [];
        $spent = Order::spending()->toBase()->selectRaw('SUM(amount) AS spent')->groupBy('user_id')->pluck('spent');
        foreach ($spent as $sum) {
            $tier = Loyalty::tierFor(Money::normalize(is_numeric($sum) ? (string) $sum : '0'), $tiers);
            if ($tier !== null) {
                $counts[$tier->id] = ($counts[$tier->id] ?? 0) + 1;
            }
        }
        if (!Money::isPositive($tiers[0]->min_spent)) {
            $counts[$tiers[0]->id] = ($counts[$tiers[0]->id] ?? 0) + max(0, User::query()->count() - $spent->count());
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, min_spent: string, discount_percent: int, cashback_percent: int}
     * @throws ValidationException every refusal at once
     */
    private static function validate(array $input, LoyaltyTier $tier): array
    {
        $errors = [];

        $name = Input::text($input, 'name');
        if ($name === '') {
            $errors['name'][] = 'نام سطح را وارد کنید؛ مثلا «برنزی» یا «طلایی».';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'][] = Validation::tooLong('نام سطح', self::NAME_MAX);
        } elseif (UniqueName::taken($tier, $name)) {
            $errors['name'][] = self::TAKEN;
        }

        $spent = Input::amount($input, 'min_spent');
        if ($spent === null || $spent > self::SPENT_MAX) {
            $errors['min_spent'][] = 'حد خرید سطح را به تومان و بدون اعشار وارد کنید؛ صفر یعنی همه مشتری‌ها از ابتدا روی این سطح هستند.';
        } elseif (LoyaltyTier::query()->where('min_spent', Money::normalize($spent))->when($tier->exists, static fn($query) => $query->whereKeyNot($tier->id))->exists()) {
            $errors['min_spent'][] = self::SAME_THRESHOLD;
        }

        $percents = [];
        foreach (['discount_percent' => 'درصد تخفیف', 'cashback_percent' => 'درصد کش‌بک'] as $field => $label) {
            $percent = Input::wholeOf($input[$field] ?? null);
            if ($percent === null || $percent > 100) {
                $errors[$field][] = "{$label} باید عددی بین 0 تا 100 باشد.";
            }
            $percents[$field] = (int) $percent;
        }

        ValidationException::ifAny($errors);

        return ['name' => $name, 'min_spent' => Money::normalize((int) $spent), 'discount_percent' => $percents['discount_percent'], 'cashback_percent' => $percents['cashback_percent']];
    }
}
