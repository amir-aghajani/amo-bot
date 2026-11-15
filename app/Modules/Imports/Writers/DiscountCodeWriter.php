<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Orders\Enums\OrderType;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;

/**
 * The discount codes stage: each code of the other bot that still has a use and a day left, made through the codes' own
 * form (DiscountCodes) with the uses it has left as its cap, its note kept. What it was for comes as the shop's plans
 * and servers it maps to; a code whose plans, panels or kinds of order all stayed behind comes switched off, named in
 * the report — so it never holds for everything instead. A code the shop has already is left as it is.
 */
final class DiscountCodeWriter
{
    private const KIND = 'discount_codes';

    public function __construct(
        private readonly DiscountCodes $codes,
        private readonly Links $links,
        private readonly ConnectionInterface $db,
    ) {}

    public function write(Run $run, ImportedDiscountCode $code): void
    {
        if ($run->broughtBefore(Links::DISCOUNT_CODE, self::KIND, $code->key, DiscountCode::class)) {
            return;
        }
        $text = DiscountCodes::normalize($code->code);
        $reason = match (true) {
            $text === '' => ProblemReason::Unreadable,
            $code->usedUp() => ProblemReason::UsedUp,
            $code->ended() => ProblemReason::Expired,
            DiscountCode::query()->where('code', $text)->exists() => ProblemReason::CodeTaken,
            default => null,
        };
        if ($reason !== null) {
            $run->skipped(self::KIND, $code->key, $reason, $text === '' ? null : Run::isolate($text));

            return;
        }

        [$plans, $plansLost] = $this->plans($run, $code->planKeys);
        [$servers, $serversLost] = self::servers($run, $code->panelKeys);
        $types = $code->orderTypes === null ? null : array_values(array_intersect(OrderType::FOR_THEMSELVES, array_map(static fn(OrderType $type): string => $type->value, $code->orderTypes)));
        $lost = $plansLost || $serversLost || $types === [];
        $ends = $code->endsAt === null || LocalTime::tooLate($code->endsAt) ? '' : LocalTime::of($code->endsAt)->format(LocalTime::MOMENT);
        $form = [
            'code' => $text,
            'type' => $code->type,
            'value' => self::whole($code->value),
            'max_uses' => $code->usesLeft === null ? '' : min($code->usesLeft, DiscountCodes::USES_MAX),
            'per_user' => $code->perUser === null || $code->perUser <= 0 ? '' : min($code->perUser, DiscountCodes::USES_MAX),
            'first_purchase_only' => $code->firstPurchaseOnly,
            'ends_at' => $ends,
            'order_types' => $lost ? null : $types,
            'plan_ids' => $lost ? null : $plans,
            'server_ids' => $lost ? null : $servers,
            'enabled' => $code->enabled && !$lost,
            'note' => mb_substr(trim((string) $code->note), 0, DiscountCodes::NOTE_MAX),
        ];
        try {
            // The code and its link together: a code made is never one a later import does not know.
            $this->db->transaction(function () use ($run, $code, $form): void {
                $this->links->add($run->import, Links::DISCOUNT_CODE, $code->key, $this->codes->create($form)->id);
            });
        } catch (ValidationException $e) {
            $run->skipped(self::KIND, $code->key, ProblemReason::Refused, Run::isolate($text) . ': ' . Run::words($e));

            return;
        }
        $run->created(self::KIND);
        if ($lost) {
            $run->problem(self::KIND, $code->key, ProblemReason::ScopeLost, Run::isolate($text));
        }
    }

    /**
     * The shop's plans the code's plans became; null for any plan — and whether it named plans none of which came.
     *
     * @param list<string>|null $keys
     * @return array{list<int>|null, bool}
     */
    private function plans(Run $run, ?array $keys): array
    {
        if ($keys === null) {
            return [null, false];
        }
        $targets = array_values(array_unique($this->links->targets($run->import, Links::PLAN, $keys)));
        $ids = $targets === [] ? [] : array_map('intval', Plan::query()->whereIn('id', $targets)->pluck('id')->all());

        return $ids === [] ? [null, true] : [$ids, false];
    }

    /**
     * The shop's servers the code's panels map to; null for any server — and whether it named panels none of which maps.
     *
     * @param list<string>|null $keys
     * @return array{list<int>|null, bool}
     */
    private static function servers(Run $run, ?array $keys): array
    {
        if ($keys === null) {
            return [null, false];
        }
        $ids = [];
        foreach ($keys as $key) {
            $server = $run->server($key);
            if ($server !== null) {
                $ids[$server->id] = $server->id;
            }
        }

        return $ids === [] ? [null, true] : [array_values($ids), false];
    }

    /** A percent or Toman as the form takes it: whole, from 0. */
    private static function whole(string $value): string
    {
        return is_numeric($value) && Money::isPositive($value) ? explode('.', Money::normalize($value))[0] : '0';
    }
}
