<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Rewards\Models\WheelSpin;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;

/** The lucky wheel's spins as the panels list them: newest first, one customer's or all, searched by customer. */
final class WheelSpins
{
    public function search(PageRequest $list): Page
    {
        $query = WheelSpin::query();
        $user = $list->id('user');
        if ($user !== null) {
            $query->where('user_id', $user);
        }
        $list->search($query, static function (Builder $query, string $term): void {
            $query->whereIn('user_id', User::idsMatching($term));
        });

        return Page::fetch($query->with(['user', 'order', 'subscription'])->orderByDesc('id'), $list, self::present(...));
    }

    /** @return array<string, mixed> One spin, its customer, order and service loaded with it. */
    public static function present(WheelSpin $spin): array
    {
        return [
            'id' => $spin->id,
            'customer' => UserDirectory::presentRef($spin->user),
            'label' => $spin->label,
            'kind' => $spin->kind->value,
            'amount' => $spin->amount,
            'given' => $spin->outcome === null,
            'outcome' => $spin->outcome,
            'order' => $spin->order === null ? null : ['id' => $spin->order->id, 'status' => $spin->order->status->value],
            'subscription' => $spin->subscription === null ? null : ['id' => $spin->subscription->id, 'name' => $spin->subscription->remote_name],
            'created_at' => $spin->created_at?->toIso8601String(),
        ];
    }
}
