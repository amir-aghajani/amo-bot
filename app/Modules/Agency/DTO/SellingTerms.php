<?php

declare(strict_types=1);

namespace App\Modules\Agency\DTO;

use App\Core\Forms\Fields\FieldRefused;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Bots\Models\Bot;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * An agent's terms of selling (Services\AgencyStanding): when their agency ends — their selling with it — and the Toman
 * of traffic they buy each Jalali month at least; null for never, and for no minimum.
 */
final class SellingTerms
{
    /** The end as the panels send it: a day and an hour on the shop's clock (LocalTime::moment()). */
    private const NOT_A_MOMENT = 'تاریخ پایان نمایندگی را به شکل روز و ساعت بنویسید؛ برای بدون پایان، خالی بگذارید.';

    public function __construct(
        public readonly ?Carbon $expiresAt,
        public readonly ?string $minimum,
    ) {}

    /** The terms the agent's shop has now. */
    public static function of(Bot $bot): self
    {
        return new self($bot->agency_expires_at, $bot->monthly_minimum === null ? null : Money::normalize($bot->monthly_minimum));
    }

    /** Whether these are the terms `$other` gives: the same end to the minute, the same minimum. */
    public function same(self $other): bool
    {
        $sameEnd = $this->expiresAt === null || $other->expiresAt === null
            ? $this->expiresAt === $other->expiresAt
            : intdiv($this->expiresAt->getTimestamp(), 60) === intdiv($other->expiresAt->getTimestamp(), 60);
        $sameMinimum = $this->minimum === null || $other->minimum === null ? $this->minimum === $other->minimum : Money::compare($this->minimum, $other->minimum) === 0;

        return $sameEnd && $sameMinimum;
    }

    /**
     * The terms the agents page gives, over `$kept`: `expires_at` — "2026-10-12T18:00" on the shop's clock, no later than
     * the shop keeps (LocalTime::LAST_DAY), blank or null for never — and `monthly_minimum` — the program's own field, whole Toman, 0 for none (AgencySettings::minimumOf())
     * —, each left out keeping `$kept`'s. Null while the input names neither. Each refusal goes under its field into
     * `$errors`.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    public static function fromInput(array $input, self $kept, array &$errors): ?self
    {
        if (!array_key_exists('expires_at', $input) && !array_key_exists('monthly_minimum', $input)) {
            return null;
        }

        $expiresAt = $kept->expiresAt;
        if (array_key_exists('expires_at', $input)) {
            $expiresAt = LocalTime::moment($input['expires_at']);
            if ($expiresAt === false) {
                $errors['expires_at'][] = self::NOT_A_MOMENT;
                $expiresAt = null;
            } elseif ($expiresAt !== null && LocalTime::tooLate($expiresAt)) {
                $errors['expires_at'][] = LocalTime::tooLateFor('تاریخ پایان نمایندگی') . '؛ برای بدون پایان، خالی بگذارید.';
                $expiresAt = null;
            }
        }

        $minimum = $kept->minimum;
        if (array_key_exists('monthly_minimum', $input)) {
            try {
                $minimum = AgencySettings::minimumOf($input);
            } catch (FieldRefused $e) {
                $errors['monthly_minimum'][] = $e->getMessage();
            }
        }

        return new self($expiresAt, $minimum);
    }
}
