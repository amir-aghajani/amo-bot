<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Users\Services\UserDirectory;

/** The nightly draws as the «قرعه‌کشی» page lists them: newest first, each with its prize and winners, eager-loaded. */
final class LotteryDraws
{
    public function page(PageRequest $request): Page
    {
        $query = LotteryDraw::query()->with(['prizePlan', 'winners.user'])->orderByDesc('day')->orderByDesc('id');

        return Page::fetch($query, $request, self::present(...));
    }

    /** @return array<string, mixed> */
    private static function present(LotteryDraw $draw): array
    {
        $prize = $draw->reward();

        return [
            'id' => $draw->id,
            'day' => $draw->day,
            'entries' => $draw->entries,
            'prize' => [
                'kind' => $draw->prize_kind->value,
                'amount' => $draw->prize_amount,
                'plan' => $draw->prizePlan === null ? null : ['id' => $draw->prizePlan->id, 'name' => $draw->prizePlan->name],
                // A plan deleted since: its kind alone.
                'words' => $prize?->words() ?? $draw->prize_kind->label(),
            ],
            'winners' => $draw->winners->map(static fn(LotteryWinner $winner): array => [
                'id' => $winner->id,
                'customer' => UserDirectory::presentRef($winner->user),
                'outcome' => $winner->outcome,
                'order_id' => $winner->order_id,
                'subscription_id' => $winner->subscription_id,
            ])->values()->all(),
            'created_at' => $draw->created_at?->toIso8601String(),
        ];
    }
}
