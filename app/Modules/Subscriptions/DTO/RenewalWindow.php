<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * Whether the shop's window lets a customer renew a service now (Services\CustomerRenewal::window()) and, while it does
 * not, what will: a date (its deadline less the window's days), the window's days before the deadline a service still
 * waiting for its first connection will have, or its traffic falling to the window's percent. None of them: its end.
 */
final class RenewalWindow
{
    public function __construct(
        public readonly bool $open,
        /** Closed: when its deadline comes within the window's days. */
        public readonly ?Carbon $opensAt = null,
        /** Closed, its clock not started: within so many days of the deadline it will have. */
        public readonly ?int $daysBefore = null,
        /** Closed: once at most this percent of its traffic is left. */
        public readonly ?int $percent = null,
    ) {}

    /**
     * From when it may be renewed, worded to stand in «تمدید این سرویس … ممکن است»: «از ۲۵ مهر ۱۴۰۵، ۱۴:۳۰», «از ۳ روز
     * مانده به پایان آن», «وقتی ٪۲۰ از حجم یا کمتر مانده باشد» — those that apply, joined by «یا» —, else «پس از پایان آن».
     * Empty while it is open.
     */
    public function when(): string
    {
        if ($this->open) {
            return '';
        }
        $ways = array_filter([
            $this->opensAt === null ? '' : 'از ' . Persian::date($this->opensAt, withTime: true),
            $this->daysBefore === null ? '' : 'از ' . Persian::digits($this->daysBefore) . ' روز مانده به پایان آن',
            $this->percent === null ? '' : 'وقتی ٪' . Persian::digits($this->percent) . ' از حجم یا کمتر مانده باشد',
        ]);

        return $ways === [] ? 'پس از پایان آن' : implode(' یا ', $ways);
    }
}
