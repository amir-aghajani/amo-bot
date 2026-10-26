<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Referrals\Models\ReferralAlert;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The admin's view of the referral program («زیرمجموعه‌گیری»): the program's numbers, the customers whose links
 * brought someone (most referrals first), the customers who came through a link (newest first — who brought whom,
 * the first thing to look at when a link brings a crowd), and the commissions paid out (newest first) — each in
 * another order its table's headers ask for (`sort`). Each list is a page with one search box over the customers on
 * either side (the way every directory searches users); the commissions also take a payment's number («#12»).
 */
final class ReferralDirectory
{
    public function __construct(private readonly ReferralSettings $settings) {}

    /**
     * The program's rules and numbers, above the lists — the alerts that stand among them.
     *
     * @return array{enabled: bool, rate: int, renewal_rate: int, first_only: bool, referrers: int, referred: int, commissions: int, paid: string, alerts: int}
     */
    public function summary(): array
    {
        // The customers who came through a link, and the distinct links that brought them: one pass over the index of
        // who brought whom.
        $referred = User::query()->whereNotNull('referred_by')->toBase()
            ->selectRaw('COUNT(*) AS referred, COUNT(DISTINCT ' . User::query()->getQuery()->getGrammar()->wrap('referred_by') . ') AS referrers')
            ->first();
        [$commissions, $paid] = Page::tally(ReferralCommission::query(), 'commission');

        return [
            'enabled' => $this->settings->enabled(),
            'rate' => $this->settings->rate(),
            'renewal_rate' => $this->settings->renewalRate(),
            'first_only' => $this->settings->firstOnly(),
            'referrers' => (int) ($referred->referrers ?? 0),
            'referred' => (int) ($referred->referred ?? 0),
            'commissions' => $commissions,
            'paid' => $paid,
            'alerts' => ReferralAlert::query()->count(),
        ];
    }

    /**
     * Customers whose link brought someone: how many, what it earned them (the commissions credited to them), when the
     * last one came, and how many alerts on them stand (ReferralAlerts: their start gifts and plan rewards withheld) —
     * the most referrals first, or by either of the others.
     */
    public function referrers(PageRequest $list): Page
    {
        $query = User::query()
            ->whereHas('referrals')
            ->withCount('referrals')
            ->withMax('referrals', 'created_at')
            ->addSelect(['earned' => self::earned(ReferralCommission::query())->whereColumn('referral_commissions.referrer_id', 'users.id')])
            ->addSelect(['open_alerts' => ReferralAlert::query()->selectRaw('COUNT(*)')->whereColumn('referral_alerts.referrer_id', 'users.id')]);
        if ($list->term !== '') {
            User::matching($query, $list->term);
        }

        $sort = $list->sort(['referrals' => 'referrals_count', 'earned' => 'earned', 'last_referral' => 'referrals_max_created_at'], 'referrals');

        return Page::fetch($query, $list, static fn(User $user): array => [
            'id' => $user->id,
            'user' => UserDirectory::presentRef($user),
            'status' => $user->status->value,
            'referrals' => (int) $user->getAttribute('referrals_count'),
            'earned' => self::money($user->getAttribute('earned')),
            'last_referral_at' => self::iso($user->getAttribute('referrals_max_created_at')),
            'alerts' => (int) $user->getAttribute('open_alerts'),
        ], $sort);
    }

    /**
     * The alerts on referrers whose referrals look made up, the newest first: who, why and how many as it was raised —
     * each standing until support clears it. Searched by the referrer; the list numbers its rows by them, «#12» being the
     * alerts on customer 12 alone (the referrers list's way here).
     */
    public function alerts(PageRequest $list): Page
    {
        $query = ReferralAlert::query()->with('referrer')->latest('id');
        $list->search($query, static function (Builder $query, string $term): void {
            $query->whereIn('referrer_id', User::idsMatching($term));
        }, 'referrer_id');

        return Page::fetch($query, $list, static fn(ReferralAlert $alert): array => [
            'id' => $alert->id,
            'referrer' => UserDirectory::presentRef($alert->referrer),
            'status' => $alert->referrer->status->value,
            'reason' => $alert->reason->value,
            'count' => $alert->count,
            'created_at' => $alert->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Customers who came through someone's link: who brought them, when, and what their payments earned that one (the
     * commissions of their payments credited to the referrer they have) — newest first, or by what they earned —; one
     * referrer's alone (`referrer`: the ones their link brought, never they themselves), as the referrers list and a
     * customer's page link here.
     */
    public function invitees(PageRequest $list): Page
    {
        $query = User::query()
            ->whereNotNull('referred_by')
            ->with('referrer')
            ->addSelect(['earned' => self::earned(ReferralCommission::withPayer())
                ->whereColumn(ReferralCommission::PAYER . '.id', 'users.id')
                ->whereColumn('referral_commissions.referrer_id', 'users.referred_by')]);
        $referrer = $list->id('referrer');
        if ($referrer !== null) {
            $query->where('referred_by', $referrer);
        }
        if ($list->term !== '') {
            $users = User::idsMatching($list->term);
            $query->where(static fn(Builder $either) => $either->whereIn('id', $users)->orWhereIn('referred_by', $users));
        }
        $sort = $list->sort(['joined' => 'id', 'earned' => 'earned'], 'joined');

        return Page::fetch($query, $list, static fn(User $user): array => [
            'id' => $user->id,
            'user' => UserDirectory::presentRef($user),
            'status' => $user->status->value,
            'referrer' => $user->referrer === null ? null : UserDirectory::presentRef($user->referrer),
            'earned' => self::money($user->getAttribute('earned')),
            'joined_at' => $user->created_at?->toIso8601String(),
        ], $sort);
    }

    /**
     * The commissions paid out — newest first, or the largest —: who earned what (whom it was credited to) from whose
     * payment; searched by the customers on either side or a bare payment number, «#12» being the commission of payment
     * 12 alone (the list numbers its rows by their payments).
     */
    public function commissions(PageRequest $list): Page
    {
        $payer = ReferralCommission::PAYER;
        $query = ReferralCommission::withPayer()->select('referral_commissions.*')->with(['payment.order.user', 'referrer']);
        $list->search($query, static function (Builder $query, string $term, ?int $number) use ($payer): void {
            $users = User::idsMatching($term);
            $query->where(static function (Builder $match) use ($users, $number, $payer): void {
                $match->whereIn('referral_commissions.referrer_id', $users)->orWhereIn("{$payer}.id", $users);
                if ($number !== null) {
                    $match->orWhere('referral_commissions.payment_id', $number);
                }
            });
        }, 'referral_commissions.payment_id');
        $sort = $list->sort(['created' => 'referral_commissions.id', 'commission' => 'referral_commissions.commission'], 'created');

        return Page::fetch($query, $list, static function (ReferralCommission $commission): array {
            $payment = $commission->payment;
            $customer = $payment->order->user;

            return [
                'id' => $commission->id,
                'referrer' => UserDirectory::presentRef($commission->referrer),
                'customer' => UserDirectory::presentRef($customer),
                'payment' => ['id' => $payment->id, 'amount' => $payment->amount],
                'order' => ['id' => $payment->order->id, 'type' => $payment->order->type->value],
                'rate' => $commission->rate,
                'commission' => $commission->commission,
                'created_at' => $commission->created_at?->toIso8601String(),
            ];
        }, $sort);
    }

    /**
     * What a row's commissions add up to, beside it (a subquery of a users list): none is 0. The column as the grammar
     * names it, with the tables' prefix (DB_PREFIX).
     *
     * @param Builder<ReferralCommission> $commissions
     * @return Builder<ReferralCommission>
     */
    private static function earned(Builder $commissions): Builder
    {
        $commission = $commissions->getQuery()->getGrammar()->wrap('referral_commissions.commission');

        return $commissions->selectRaw("COALESCE(SUM({$commission}), 0)");
    }

    private static function money(mixed $sum): string
    {
        return Money::normalize(is_numeric($sum) ? (string) $sum : '0');
    }

    private static function iso(mixed $timestamp): ?string
    {
        return is_string($timestamp) && $timestamp !== '' ? Carbon::parse($timestamp)->toIso8601String() : null;
    }
}
