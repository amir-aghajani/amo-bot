<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Rewards\Enums\GiftCodeStatus;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The gift codes as the panels list them: codes by batch and status, searched by code or redeemer; the batches with
 * their counts; and a batch's unused codes to copy and hand out.
 */
final class GiftCodeDirectory
{
    /** The codes the list asks for, newest first, each with its plan, its redeemer and its order. */
    public function search(PageRequest $list): Page
    {
        $query = GiftCode::query();
        $batch = $list->text('batch');
        if ($batch !== '') {
            $query->where('batch', $batch);
        }
        match ($list->enum('status', GiftCodeStatus::class)) {
            GiftCodeStatus::Unused => GiftCode::whereUsable($query),
            GiftCodeStatus::Redeemed => $query->whereNotNull('redeemed_at'),
            GiftCodeStatus::Expired => $query->whereNull('redeemed_at')->where('expires_at', '<=', now()->utc()),
            null => null,
        };
        $list->search($query, self::applySearch(...));

        return Page::fetch($query->with(['plan', 'redeemer', 'order'])->orderByDesc('id'), $list, self::present(...));
    }

    /**
     * Every batch, newest first, with how many codes it has, how many were taken and how many ran out untaken.
     *
     * @return list<array{batch: string|null, total: int, redeemed: int, expired: int, unused: int, created_at: string}>
     */
    public function batches(): array
    {
        $now = now()->utc();
        $rows = GiftCode::query()->toBase()
            ->selectRaw('batch, COUNT(*) AS codes, SUM(CASE WHEN redeemed_at IS NOT NULL THEN 1 ELSE 0 END) AS taken, SUM(CASE WHEN redeemed_at IS NULL AND expires_at IS NOT NULL AND expires_at <= ? THEN 1 ELSE 0 END) AS ran_out, MAX(id) AS newest, MAX(created_at) AS made', [$now])
            ->groupBy('batch')
            ->orderByDesc('newest')
            ->get();

        $batches = [];
        foreach ($rows as $row) {
            $total = (int) $row->codes;
            $redeemed = (int) $row->taken;
            $expired = (int) $row->ran_out;
            $batches[] = [
                'batch' => is_string($row->batch) ? $row->batch : null,
                'total' => $total,
                'redeemed' => $redeemed,
                'expired' => $expired,
                'unused' => $total - $redeemed - $expired,
                'created_at' => Carbon::parse((string) $row->made, 'UTC')->toIso8601String(),
            ];
        }

        return $batches;
    }

    /**
     * The batch's codes still to be taken, oldest first.
     *
     * @return list<string>
     */
    public function unused(string $batch): array
    {
        return array_values(GiftCode::usable()->where('batch', $batch)->orderBy('id')->pluck('code')->all());
    }

    /** @return array<string, mixed> One code as the panels list it — its plan, its customer and its order loaded with it. */
    public static function present(GiftCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'batch' => $code->batch,
            'kind' => $code->kind->value,
            'amount' => $code->amount,
            'plan' => $code->plan === null ? null : ['id' => $code->plan->id, 'name' => $code->plan->name],
            'expires_at' => $code->expires_at?->toIso8601String(),
            'status' => $code->status()->value,
            'redeemed_by' => $code->redeemer === null ? null : UserDirectory::presentRef($code->redeemer),
            'redeemed_at' => $code->redeemed_at?->toIso8601String(),
            'order' => $code->order === null ? null : ['id' => $code->order->id, 'status' => $code->order->status->value],
            'created_at' => $code->created_at->toIso8601String(),
        ];
    }

    /**
     * A search by a piece of the code (typed any way a person types it) or by the customer who took it.
     *
     * @param Builder<GiftCode> $query
     */
    private static function applySearch(Builder $query, string $term): void
    {
        $code = GiftCodes::normalize($term);
        $users = User::idsMatching($term);

        $query->where(static function (Builder $q) use ($code, $users): void {
            $q->whereIn('redeemed_by', $users);
            if ($code !== '') {
                Page::orWhereContains($q, 'code', $code);
            }
        });
    }
}
