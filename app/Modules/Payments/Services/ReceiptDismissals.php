<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Telegram\Reports\ShopReports;
use App\Support\Input;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * Support dismisses receipts waiting for review in bulk (the payments screen's «رد رسیدهای انتخاب‌شده» and «رد همه
 * رسیدهای در انتظار»): each is refused as «رد کردن» refuses one (PaymentActions::reject()) — the payment fails with
 * support's note, its order stays open to be paid again or to expire on its own, the customer is told, the receipt's
 * report loses its buttons — and its uploaded picture is deleted (one sent in the bot stays Telegram's). The report group
 * gets one line for them all. «همه» goes MOST at a time, each round held to the receipts that had come when it began.
 */
final class ReceiptDismissals
{
    /** The most receipts one dismissal refuses: each tells its customer, so one request stays short. */
    public const MOST = 100;

    private const NONE = 'رسیدهایی را که رد می‌شوند انتخاب کنید.';
    /** More than MOST named, %s being it. */
    private const TOO_MANY = 'هر بار حداکثر %s رسید یکجا رد می‌شود.';
    private const NO_UNTIL = 'زمان (until) را همان‌طور که پاسخ قبلی داده است بفرستید.';

    public function __construct(
        private readonly PaymentActions $actions,
        private readonly Receipts $receipts,
        private readonly ShopReports $reports,
    ) {}

    /**
     * Which receipts a dismissal asks for: the payments `ids` names (1 to MOST, each once) — or, `all` true without `ids`,
     * every receipt that had come by `until` (the moment the first round answered, sent back by the next; now when left
     * out, and never later), so one sent while it runs is left for support to see.
     *
     * @param array<string, mixed> $input
     * @return list<int>|Carbon The payment ids, or the moment «all» is held to
     * @throws ValidationException 422 on `ids`: none named nor all asked, both, or more than MOST; on `until`
     */
    public function selection(array $input): array|Carbon
    {
        $ids = $input['ids'] ?? null;
        $all = Input::isBoolean($input['all'] ?? null) && Input::truthy($input['all']);
        if ($ids === null && $all) {
            return self::until($input['until'] ?? null);
        }
        if (!is_array($ids) || $ids === [] || $all) {
            throw ValidationException::on('ids', self::NONE);
        }
        $named = array_values(array_unique(array_filter(array_map(static fn(mixed $id): int => Input::integerOf($id) ?? 0, $ids), static fn(int $id): bool => $id > 0)));

        return match (true) {
            $named === [] => throw ValidationException::on('ids', self::NONE),
            count($named) > self::MOST => throw ValidationException::on('ids', sprintf(self::TOO_MANY, Persian::number(self::MOST))),
            default => $named,
        };
    }

    /**
     * Refuse the receipts waiting for review that `$which` names — or, a moment, the shop's oldest MOST that had come by
     * then — as `$actor`, with `$note` for their customers. A payment no longer waiting (decided meanwhile, never a
     * receipt, another shop's) is skipped. The answer says how many were refused and skipped, how many still wait in the
     * shop — for «all», of those that had come by `until` —, and that moment, for the next round to send back.
     *
     * @param list<int>|Carbon $which Distinct payment ids, MOST at most; or the moment «all» is held to
     * @return array{dismissed: int, skipped: int, left: int, until: string|null}
     */
    public function dismiss(array|Carbon $which, Actor $actor, ?string $note): array
    {
        $waiting = static function () use ($which) {
            $query = Payment::query()->where('status', PaymentStatus::AwaitingReview->value);

            return $which instanceof Carbon ? $query->where('receipt_at', '<=', $which) : $query;
        };
        $payments = $which instanceof Carbon
            ? $waiting()->with(PaymentDirectory::RELATIONS)->oldest('receipt_at')->oldest('id')->limit(self::MOST)->get()
            : $waiting()->with(PaymentDirectory::RELATIONS)->whereKey($which)->orderBy('id')->get();

        $dismissed = 0;
        foreach ($payments as $payment) {
            try {
                $this->actions->reject($payment, $actor, $note);
            } catch (ValidationException) {
                // Decided by someone else in the same moment.
                continue;
            }
            if ($payment->receipt_path !== null) {
                $this->receipts->discard($payment->receipt_path);
            }
            $dismissed++;
        }
        if ($dismissed > 0) {
            $this->reports->receiptsDismissed($dismissed, $actor, $note);
        }

        return [
            'dismissed' => $dismissed,
            'skipped' => ($which instanceof Carbon ? $payments->count() : count($which)) - $dismissed,
            'left' => $waiting()->count(),
            'until' => $which instanceof Carbon ? $which->toIso8601String() : null,
        ];
    }

    /**
     * The moment «all» is held to: `$until` as an earlier answer wrote it, never later than now; now without one.
     *
     * @throws ValidationException 422 on `until`
     */
    private static function until(mixed $until): Carbon
    {
        $now = now();
        if ($until === null) {
            return $now;
        }
        $moment = is_string($until) ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $until) : false;
        if ($moment === false) {
            throw ValidationException::on('until', self::NO_UNTIL);
        }
        $moment = Carbon::instance($moment)->setTimezone($now->getTimezone());

        return $moment->lt($now) ? $moment : $now;
    }
}
