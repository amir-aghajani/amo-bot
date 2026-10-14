<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Modules\Orders\Enums\OrderType;
use App\Support\Input;
use App\Support\LocalTime;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The fields a discount code's and a sale's forms share: the window, the kinds of order and the plans named. Each reader
 * adds its refusals to `$errors` under its field, so a form reports every mistake at once.
 */
final class DiscountForm
{
    private const MOMENT = 'Y-m-d\TH:i';
    private const NOT_A_MOMENT = 'زمان را به شکل روز و ساعت بنویسید.';
    private const BACKWARDS = 'زمان پایان باید بعد از زمان شروع باشد.';

    /**
     * `starts_at` and `ends_at` as the panels send them — "2026-03-20T18:00" on the shop's clock (APP_TIMEZONE), whatever
     * the admin's browser says —, returned in UTC; blank or null is open on that side.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     * @return array{starts_at: Carbon|null, ends_at: Carbon|null}
     */
    public static function window(array $input, array &$errors): array
    {
        $window = ['starts_at' => null, 'ends_at' => null];
        foreach (array_keys($window) as $field) {
            $value = $input[$field] ?? null;
            $text = is_string($value) ? Persian::latinDigits(trim($value)) : '';
            if ($value === null || $text === '') {
                continue;
            }
            $moment = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $text) === 1 ? Carbon::createFromFormat(self::MOMENT, $text, LocalTime::zone()) : null;
            // A date that does not exist (the 30th of February) parses into another one: compare it back.
            if (!$moment instanceof Carbon || $moment->format(self::MOMENT) !== $text) {
                $errors[$field][] = self::NOT_A_MOMENT;

                continue;
            }
            $window[$field] = $moment->utc();
        }
        if ($window['starts_at'] !== null && $window['ends_at'] !== null && $window['ends_at']->lessThanOrEqualTo($window['starts_at'])) {
            $errors['ends_at'][] = self::BACKWARDS;
        }

        return $window;
    }

    /** A kept moment as the APIs answer it: ISO 8601; null for none. */
    public static function moment(?\DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : Carbon::instance($moment)->toIso8601String();
    }

    /**
     * The kinds of order named: null (or left out) for every kind a customer buys for themselves — naming them all is kept
     * as null too; naming none is refused.
     *
     * @param array<string, list<string>> $errors
     * @return list<string>|null
     */
    public static function kinds(mixed $value, array &$errors): ?array
    {
        if ($value === null) {
            return null;
        }
        $allowed = OrderType::FOR_THEMSELVES;
        if (!is_array($value) || !array_is_list($value) || count(array_filter($value, 'is_string')) !== count($value) || array_diff($value, $allowed) !== []) {
            $errors['order_types'][] = 'نوع سفارش باید ' . OrderType::forThemselvesInWords() . ' باشد.';

            return null;
        }
        $named = array_values(array_intersect($allowed, $value));
        if ($named === []) {
            $errors['order_types'][] = 'دست‌کم یک نوع سفارش را انتخاب کنید.';

            return null;
        }

        return count($named) === count($allowed) ? null : $named;
    }

    /**
     * Rows named by id — the shop's plans, the servers —, each once and sorted; null (an empty list, or left out) for
     * every one.
     *
     * @param Builder<covariant Model> $rows
     * @param array<string, list<string>> $errors
     * @return list<int>|null
     */
    public static function ids(mixed $value, string $field, Builder $rows, string $noun, array &$errors): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }
        $ids = is_array($value) && array_is_list($value) ? array_map(Input::integerOf(...), $value) : [null];
        if (in_array(null, $ids, true)) {
            $errors[$field][] = "{$noun} را از لیست انتخاب کنید.";

            return null;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($rows->whereIn('id', $ids)->count() !== count($ids)) {
            $errors[$field][] = "یکی از {$noun}‌های انتخاب‌شده دیگر وجود ندارد.";

            return null;
        }
        sort($ids);

        return $ids;
    }

    /**
     * The rows a code or a sale names, as the panels show them — those deleted since left out; null for "every one".
     *
     * @param list<int>|null $ids
     * @param array<mixed> $names Names by id, read once for a whole list (a `pluck('name', 'id')`)
     * @return list<array{id: int, name: string}>|null
     */
    public static function named(?array $ids, array $names): ?array
    {
        if ($ids === null) {
            return null;
        }

        return array_values(array_map(
            static fn(int $id): array => ['id' => $id, 'name' => (string) $names[$id]],
            array_filter($ids, static fn(int $id): bool => isset($names[$id])),
        ));
    }
}
