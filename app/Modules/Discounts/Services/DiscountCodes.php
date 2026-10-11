<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\DTO\CodeTarget;
use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Discounts\Enums\CodeState;
use App\Modules\Discounts\Enums\DiscountSource;
use App\Modules\Discounts\Enums\DiscountType;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Exceptions\DiscountInUseException;
use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Discount codes («کدهای تخفیف»): kept by the panels, and judged at every checkout by apply(), which decides whether
 * a code holds for what the customer buys and what it takes off.
 *
 * A use is an order made with the code that is not cancelled. A quote counts uses without the customer's own unpaid
 * orders (their checkout may be reopening one); the order that takes a use counts again under the code's row lock
 * (reserve()), so the last use goes to one customer only. Wrong codes are rate-limited per customer (tried()).
 */
final class DiscountCodes
{
    /** A customer's codes that did not hold, in WRONG_WINDOW seconds — then a 429 until the window has room. */
    public const WRONG_CODES = 20;
    public const WRONG_WINDOW = 3600;

    public const CODE_MIN = 3;
    public const CODE_MAX = 32;
    public const NOTE_MAX = 191;
    /** The most Toman a code takes off, or an order may be asked to reach. */
    public const AMOUNT_MAX = 1_000_000_000;
    public const USES_MAX = 1_000_000;

    public const NOT_FOUND = 'این کد تخفیف پیدا نشد.';
    public const OFF = 'این کد تخفیف فعال نیست.';
    public const NOT_YET = 'این کد تخفیف از %s فعال می‌شود.';
    public const ENDED = 'مهلت استفاده از این کد تخفیف تمام شده است.';
    public const WRONG_KIND = 'این کد تخفیف برای %s نیست.';
    public const WRONG_PLAN = 'این کد تخفیف برای این پلن نیست.';
    public const WRONG_SERVER = 'این کد تخفیف برای این سرور نیست.';
    public const FIRST_ONLY = 'این کد تخفیف فقط برای اولین خرید است.';
    public const TOO_LITTLE = 'این کد تخفیف برای سفارش‌های دست‌کم %s است.';
    public const NOTHING_TO_TAKE = 'مبلغ این سفارش صفر است و کد تخفیف لازم ندارد.';
    public const USED_UP = 'ظرفیت استفاده از این کد تخفیف تمام شده است.';
    public const USED_BY_YOU = 'شما از این کد تخفیف به اندازه مجاز استفاده کرده‌اید.';

    private const CODE_PATTERN = '/^[\p{L}\p{N}_-]+$/u';
    private const TAKEN = 'این کد در فروشگاه هست؛ کد دیگری بنویسید.';

    public function __construct(private readonly RateLimiter $limiter) {}

    /** A code as typed, as it is kept and matched: trimmed, Latin digits, Persian «ی» and «ک», upper case. */
    public static function normalize(string $typed): string
    {
        return mb_strtoupper(str_replace(['ي', 'ك'], ['ی', 'ک'], Persian::latinDigits(trim($typed))));
    }

    /**
     * What the code `$typed` takes off `$amount` (the order's price after its automatic discount) for `$target`.
     *
     * @throws DiscountCodeRefusedException the code does not hold, worded for the customer
     */
    public function apply(User $user, string $typed, CodeTarget $target, string $amount): DiscountLine
    {
        $text = self::normalize($typed);
        $code = $text === '' || mb_strlen($text) > self::CODE_MAX ? null : DiscountCode::query()->where('code', $text)->first();
        if ($code === null) {
            throw new DiscountCodeRefusedException(self::NOT_FOUND);
        }
        self::judge($code, $user, $target, $amount);
        self::assertUsesLeft($code, ...self::uses($code, $user, sparingPending: true));

        return self::line($code, $amount);
    }

    /** Whether the shop has a code a customer might type now (switched on, not ended): checkouts offer one only then. */
    public function offered(): bool
    {
        $now = now()->utc();

        return DiscountCode::query()
            ->where('enabled', true)
            ->where(static fn(Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->exists();
    }

    /**
     * Runs `$judge` — something judging a code the customer typed — against their budget of wrong codes: the try is
     * counted first, and given back unless the code was refused. A spent budget is a 429 whatever is typed.
     *
     * @template T
     * @param \Closure(): T $judge
     * @param-immediately-invoked-callable $judge
     * @return T
     * @throws TooManyAttemptsException
     * @throws DiscountCodeRefusedException `$judge`'s: the code does not hold (the try kept)
     */
    public function tried(User $user, \Closure $judge): mixed
    {
        $key = 'discount-code|' . $user->id;
        $this->limiter->enforce([[$key, self::WRONG_CODES, self::WRONG_WINDOW]], 'کد تخفیف نادرست زیادی فرستاده‌اید');

        try {
            $result = $judge();
        } catch (\Throwable $e) {
            if (!$e instanceof DiscountCodeRefusedException) {
                $this->limiter->release($key);
            }
            throw $e;
        }
        $this->limiter->release($key);

        return $result;
    }

    /**
     * Takes one use of the code for the order the customer's checkout is creating. Called inside OrderService::open()'s
     * transaction: the code's row is locked and its uses counted again, so two customers cannot both take its last use.
     *
     * @throws DiscountCodeRefusedException switched off, gone, or no use left
     */
    public function reserve(int $codeId, User $user): void
    {
        $code = DiscountCode::query()->whereKey($codeId)->lockForUpdate()->first();
        if ($code === null || !$code->enabled) {
            throw new DiscountCodeRefusedException($code === null ? self::NOT_FOUND : self::OFF);
        }
        self::assertUsesLeft($code, ...self::uses($code, $user, sparingPending: false));
    }

    /** The panels' list: every code, newest first, searched by its text or its note, with its uses. */
    public function page(PageRequest $list): Page
    {
        $query = DiscountCode::query()->withCount(['orders as uses' => self::counted(...)])->orderByDesc('id');
        $list->search($query, static function (Builder $query, string $term): void {
            $query->where(static function (Builder $match) use ($term): void {
                Page::whereContains($match, 'code', self::normalize($term));
                Page::orWhereContains($match, 'note', $term);
            });
        });

        return Page::fetchTogether($query, $list, $this->presentAll(...));
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException every refusal at once
     */
    public function create(array $input): DiscountCode
    {
        $code = new DiscountCode();
        $this->save($code, $input);

        return $code;
    }

    /**
     * @param array<string, mixed> $input The whole form
     * @throws ValidationException every refusal at once
     */
    public function update(DiscountCode $code, array $input): DiscountCode
    {
        $this->save($code, $input);

        return $code;
    }

    public function setEnabled(DiscountCode $code, bool $enabled): DiscountCode
    {
        $code->update(['enabled' => $enabled]);

        return $code;
    }

    /**
     * Deletes a code nobody used; one with uses is kept for its orders' history (switch it off instead).
     *
     * @throws DiscountInUseException
     */
    public function delete(DiscountCode $code): void
    {
        if ($code->orders()->where(self::counted(...))->exists()) {
            throw new DiscountInUseException();
        }
        $code->delete();
    }

    /** @return array<string, mixed> One code as the panels show it, its uses read with it. */
    public function present(DiscountCode $code): array
    {
        $code->loadCount(['orders as uses' => self::counted(...)]);

        return $this->presentAll(new Collection([$code]))[0];
    }

    /**
     * Codes as the panels show them, with their uses (withCount `uses`); the plans and servers they name are read once
     * for the whole list.
     *
     * @param Collection<int, DiscountCode> $codes
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $codes): array
    {
        $planIds = $codes->flatMap(static fn(DiscountCode $code): array => $code->plan_ids ?? [])->unique()->values()->all();
        $serverIds = $codes->flatMap(static fn(DiscountCode $code): array => $code->server_ids ?? [])->unique()->values()->all();
        $plans = $planIds === [] ? [] : Plan::query()->whereIn('id', $planIds)->pluck('name', 'id')->all();
        $servers = $serverIds === [] ? [] : Server::query()->whereIn('id', $serverIds)->pluck('name', 'id')->all();

        return array_values($codes->map(static fn(DiscountCode $code): array => [
            'id' => $code->id,
            'code' => $code->code,
            'note' => $code->note,
            'type' => $code->type->value,
            'value' => $code->type === DiscountType::Free ? null : $code->value,
            'max_discount' => $code->max_discount,
            'min_amount' => $code->min_amount,
            'order_types' => $code->order_types,
            'plans' => DiscountForm::named($code->plan_ids, $plans),
            'servers' => DiscountForm::named($code->server_ids, $servers),
            'first_purchase_only' => $code->first_purchase_only,
            'starts_at' => DiscountForm::moment($code->starts_at),
            'ends_at' => DiscountForm::moment($code->ends_at),
            'max_uses' => $code->max_uses,
            'per_user' => $code->per_user,
            'enabled' => $code->enabled,
            'uses' => (int) $code->uses,
            'state' => self::state($code)->value,
            'created_at' => $code->created_at->toIso8601String(),
        ])->all());
    }

    /** The panels' badge: its window's state, or used up while that is active. */
    private static function state(DiscountCode $code): CodeState
    {
        $state = $code->windowState();

        return $state === CodeState::Active && $code->max_uses !== null && (int) $code->uses >= $code->max_uses ? CodeState::UsedUp : $state;
    }

    /**
     * Whether the code holds for the order — everything but its uses.
     *
     * @throws DiscountCodeRefusedException
     */
    private static function judge(DiscountCode $code, User $user, CodeTarget $target, string $amount): void
    {
        $refusal = match (true) {
            !$code->enabled => self::OFF,
            $code->starts_at !== null && $code->starts_at > now() => sprintf(self::NOT_YET, Persian::date($code->starts_at, withTime: true)),
            $code->ends_at !== null && $code->ends_at <= now() => self::ENDED,
            !$code->takesKind($target->kind) => sprintf(self::WRONG_KIND, $target->kind->label()),
            !$code->takesPlan($target->planId) => self::WRONG_PLAN,
            !$code->takesServer($target->serverId) => self::WRONG_SERVER,
            !Money::isPositive($amount) => self::NOTHING_TO_TAKE,
            $code->min_amount !== null && Money::compare($amount, $code->min_amount) < 0 => sprintf(self::TOO_LITTLE, Money::format($code->min_amount)),
            $code->first_purchase_only && Order::boughtBy($user->id)->exists() => self::FIRST_ONLY,
            default => null,
        };
        if ($refusal !== null) {
            throw new DiscountCodeRefusedException($refusal);
        }
    }

    /**
     * The code's uses in all and the customer's own. With `$sparingPending`, the customer's own unpaid orders are left
     * out: a checkout opened again finds its open order and takes no new use.
     *
     * @return array{int, int}
     */
    private static function uses(DiscountCode $code, User $user, bool $sparingPending): array
    {
        $row = Order::query()
            ->where('discount_code_id', $code->id)
            ->where(self::counted(...))
            ->when($sparingPending, static fn(Builder $query) => $query->where(static fn(Builder $spared) => $spared
                ->where('user_id', '!=', $user->id)
                ->orWhere('status', '!=', OrderStatus::Pending->value)))
            ->toBase()
            ->selectRaw('COUNT(*) AS uses_all, COALESCE(SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END), 0) AS uses_theirs', [$user->id])
            ->first();

        return [(int) ($row->uses_all ?? 0), (int) ($row->uses_theirs ?? 0)];
    }

    /** @throws DiscountCodeRefusedException */
    private static function assertUsesLeft(DiscountCode $code, int $all, int $theirs): void
    {
        if ($code->max_uses !== null && $all >= $code->max_uses) {
            throw new DiscountCodeRefusedException(self::USED_UP);
        }
        if ($code->per_user !== null && $theirs >= $code->per_user) {
            throw new DiscountCodeRefusedException(self::USED_BY_YOU);
        }
    }

    /** What the code takes off `$amount`: its percent (up to its cap), its Toman, or all of it — never more than `$amount`. */
    private static function line(DiscountCode $code, string $amount): DiscountLine
    {
        $off = match ($code->type) {
            DiscountType::Percent => Money::percentOf($amount, min(100, (int) $code->value)),
            DiscountType::Fixed => Money::normalize($code->value),
            DiscountType::Free => Money::normalize($amount),
        };
        if ($code->type === DiscountType::Percent && $code->max_discount !== null && Money::compare($off, $code->max_discount) > 0) {
            $off = Money::normalize($code->max_discount);
        }
        if (Money::compare($off, $amount) > 0) {
            $off = Money::normalize($amount);
        }

        return new DiscountLine(
            DiscountSource::Code,
            'کد ' . $code->code,
            $off,
            $code->type === DiscountType::Percent ? (int) $code->value : null,
            $code->id,
            code: $code->code,
        );
    }

    /**
     * An order that counts as a use of its code: anything but cancelled.
     *
     * @param Builder<Order> $query
     */
    private static function counted(Builder $query): void
    {
        $query->where('status', '!=', OrderStatus::Cancelled->value);
    }

    /**
     * The form checked whole, every refusal at once, then kept. A code taken by another save at the same moment is
     * refused by the table's unique index the same way.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    private function save(DiscountCode $code, array $input): void
    {
        $errors = [];

        $text = self::normalize(Input::text($input, 'code'));
        if ($text === '') {
            $errors['code'][] = 'کد را بنویسید.';
        } elseif (mb_strlen($text) < self::CODE_MIN || mb_strlen($text) > self::CODE_MAX || preg_match(self::CODE_PATTERN, $text) !== 1) {
            $errors['code'][] = sprintf('کد باید %d تا %d حرف، رقم، خط تیره یا زیرخط باشد، بدون فاصله.', self::CODE_MIN, self::CODE_MAX);
        } elseif (DiscountCode::query()->where('code', $text)->when($code->exists, static fn(Builder $query) => $query->whereKeyNot($code->id))->exists()) {
            $errors['code'][] = self::TAKEN;
        }

        $type = DiscountType::tryFrom(Input::text($input, 'type'));
        if ($type === null) {
            $errors['type'][] = 'نوع تخفیف باید درصدی، مبلغ ثابت یا رایگان باشد.';
        }
        $value = '0.00';
        $maxDiscount = null;
        if ($type === DiscountType::Percent) {
            $percent = Input::wholeOf($input['value'] ?? null);
            if ($percent === null || $percent < 1 || $percent > 100) {
                $errors['value'][] = 'درصد تخفیف باید عددی بین 1 تا 100 باشد.';
            } else {
                $value = Money::normalize($percent);
            }
            $maxDiscount = self::amountOrNull($input, 'max_discount', 'سقف تخفیف', $errors, least: 1);
        } elseif ($type === DiscountType::Fixed) {
            $amount = self::amountOrNull($input, 'value', 'مبلغ تخفیف', $errors, least: 1);
            if ($amount !== null) {
                $value = $amount;
            } elseif (!isset($errors['value'])) {
                $errors['value'][] = 'مبلغ تخفیف را به تومان بنویسید.';
            }
        }
        $minAmount = self::amountOrNull($input, 'min_amount', 'حداقل مبلغ سفارش', $errors, least: 0);

        $orderTypes = DiscountForm::kinds($input['order_types'] ?? null, $errors);
        $planIds = DiscountForm::ids($input['plan_ids'] ?? null, 'plan_ids', Plan::query(), 'پلن', $errors);
        $serverIds = DiscountForm::ids($input['server_ids'] ?? null, 'server_ids', Server::query(), 'سرور', $errors);
        if (!Input::isBoolean($input['first_purchase_only'] ?? null)) {
            $errors['first_purchase_only'][] = Validation::NOT_A_SWITCH;
        }
        if (!Input::isBoolean($input['enabled'] ?? null)) {
            $errors['enabled'][] = Validation::NOT_A_SWITCH;
        }
        $window = DiscountForm::window($input, $errors);
        $maxUses = self::countOrNull($input, 'max_uses', 'سقف کل استفاده', $errors);
        $perUser = self::countOrNull($input, 'per_user', 'سقف استفاده هر مشتری', $errors);
        $note = Input::text($input, 'note');
        if (mb_strlen($note) > self::NOTE_MAX) {
            $errors['note'][] = Validation::tooLong('یادداشت', self::NOTE_MAX);
        }
        if ($errors !== [] || $type === null) {
            throw new ValidationException($errors);
        }

        $code->fill([
            'code' => $text,
            'note' => $note === '' ? null : $note,
            'type' => $type,
            'value' => $value,
            'max_discount' => $maxDiscount,
            'min_amount' => $minAmount,
            'order_types' => $orderTypes,
            'plan_ids' => $planIds,
            'server_ids' => $serverIds,
            'first_purchase_only' => Input::truthy($input['first_purchase_only']),
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'max_uses' => $maxUses,
            'per_user' => $perUser,
            'enabled' => Input::truthy($input['enabled']),
        ]);
        try {
            $code->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::on('code', self::TAKEN);
        }
    }

    /**
     * Whole Toman from `$least` up, or null when blank or left out; refused under `$field` in `$label`'s words.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function amountOrNull(array $input, string $field, string $label, array &$errors, int $least): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $amount = Input::amountOf($value);
        if ($amount === null || $amount < $least || $amount > self::AMOUNT_MAX) {
            $errors[$field][] = "{$label} را به تومان و بدون اعشار بنویسید.";

            return null;
        }

        return Money::normalize($amount);
    }

    /**
     * A count of uses from 1 up, or null (no limit) when blank or left out.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function countOrNull(array $input, string $field, string $label, array &$errors): ?int
    {
        $value = $input[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $count = Input::wholeOf($value);
        if ($count === null || $count < 1 || $count > self::USES_MAX) {
            $errors[$field][] = "{$label} باید عددی از 1 به بالا باشد؛ خالی یعنی بدون سقف.";

            return null;
        }

        return $count;
    }
}
