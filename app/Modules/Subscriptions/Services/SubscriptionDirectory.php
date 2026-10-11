<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * The subscriptions screen («اشتراک‌ها»: both panels and the website's admin API): a searchable, filterable page of the
 * shop's services as last seen on their panels, each with what its modal may do now (SubscriptionActions::allowed()).
 */
final class SubscriptionDirectory
{
    /** The tab that is not a status: active services whose deadline is at most EXPIRING_DAYS away. */
    public const EXPIRING = 'expiring';

    /** The window of that tab, and of the dashboard's card that links to it. */
    public const EXPIRING_DAYS = 3;

    public function __construct(private readonly SubscriptionActions $actions) {}

    /**
     * The page asked for, filtered by `status` (a SubscriptionStatus, or EXPIRING), `server`, `user` and the search box
     * (applySearch()); newest first, or sorted by its end (no deadline after every deadline) or the traffic used. With
     * the expiring tab's count and window.
     */
    public function search(PageRequest $list): Page
    {
        $query = $list->text('status') === self::EXPIRING ? Subscription::expiringWithin(self::EXPIRING_DAYS) : Subscription::query();
        $status = $list->enum('status', SubscriptionStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $server = $list->id('server');
        if ($server !== null) {
            $query->where('server_id', $server);
        }
        $user = $list->id('user');
        if ($user !== null) {
            $query->where('user_id', $user);
        }
        $list->search($query, self::applySearch(...));

        $sort = $list->sort([
            'created' => 'id',
            'expires' => [new Expression('expires_at IS NULL'), 'expires_at'],
            'used' => new Expression('upload_bytes + download_bytes'),
        ], 'created');

        return Page::fetch($query->with(['user', 'plan', 'server']), $list, $this->present(...), $sort)
            ->with(['expiring' => Subscription::expiringWithin(self::EXPIRING_DAYS)->count(), 'expiring_days' => self::EXPIRING_DAYS]);
    }

    /** @return array<string, mixed> One row of the screen. */
    public function present(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status->value,
            'name' => $subscription->remote_name,
            'link' => $subscription->subscription_url,
            'user' => UserDirectory::presentRef($subscription->user),
            'plan' => $subscription->plan === null ? null : ['id' => $subscription->plan->id, 'name' => $subscription->plan->name],
            'server' => ['id' => $subscription->server->id, 'name' => $subscription->server->name],
            'traffic' => ['limit' => $subscription->traffic_limit_bytes, 'used' => $subscription->usedBytes()],
            'ip_limit' => $subscription->ip_limit,
            'duration_days' => ServiceTerms::daysOf($subscription->duration_hours),
            'duration_hours' => $subscription->duration_hours,
            'auto_renew' => $subscription->auto_renew,
            // A renewal queued behind the period in use: when it begins, and the most it leaves then.
            'next_period' => $subscription->period_ends_at === null ? null : [
                'starts_at' => $subscription->period_ends_at->toIso8601String(),
                'traffic' => $subscription->next_period_bytes ?? 0,
            ],
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'expiring_soon' => $subscription->isExpiringWithin(self::EXPIRING_DAYS),
            'last_synced_at' => $subscription->last_synced_at?->toIso8601String(),
            'disabled_at' => $subscription->disabled_at?->toIso8601String(),
            'created_at' => $subscription->created_at->toIso8601String(),
            'actions' => $this->actions->allowed($subscription),
        ];
    }

    /**
     * Search by a bare service number, the client's name on the panel, a piece of its link (a customer pastes theirs), or
     * the customer (User::idsMatching()). «#12» alone is service 12: PageRequest::search().
     *
     * @param Builder<Subscription> $query
     */
    private static function applySearch(Builder $query, string $term, ?int $number): void
    {
        $users = User::idsMatching($term);

        $query->where(static function (Builder $q) use ($term, $users, $number): void {
            $q->whereIn('user_id', $users);
            Page::orWhereContains($q, 'remote_name', $term);
            Page::orWhereContains($q, 'subscription_url', $term);
            if ($number !== null) {
                $q->orWhere('id', $number);
            }
        });
    }
}
