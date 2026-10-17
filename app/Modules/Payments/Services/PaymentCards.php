<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\FieldRefused;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Drivers\Manual\CardNumber;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Input;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cards of a card-to-card method (PaymentCard): kept by the method's form as one list (`cards`), and one picked at
 * random for each payment among the enabled ones whose daily cap leaves room for its amount — what came to a card today,
 * on the shop's clock, being its payments made today that are paid, refunded or still open. A payment keeps the card it
 * was shown. A card-to-card top-up is also made a few Toman larger at random (WalletSettings::uniqueMax()), so no two
 * open payments to one card ask the same sum and a receipt is told apart at a glance — never past the method's
 * `max_amount` (extra()); its card is picked with room for the most it may ask.
 *
 * While the shop takes card payments in support's private chat (toSupport()), no card is picked: a payment is shown none
 * (payments.card_id null — "sent to support's chat" wherever it is shown), takes no card's room, and the method is
 * offered whatever its cards' switches and caps. A top-up still asks its few Toman more, a sum no other open payment of
 * the method shown no card asks: support tells the receipts sent to their chat apart by it. Shown a card later, it keeps
 * that sum unless another open payment to the card asks it — then it is given a fresh one.
 */
final class PaymentCards
{
    /** The most cards a method keeps. */
    public const MOST = 20;

    private const HOLDER_MAX = 100;
    private const BANK_MAX = 60;

    /** The payments that count against a card's day: the money that came, or may still come, to it. */
    private const TAKEN = [PaymentStatus::Pending, PaymentStatus::AwaitingReview, PaymentStatus::Paid, PaymentStatus::Refunded];

    public function __construct(
        private readonly WalletSettings $wallet,
        private readonly PaymentSettings $settings,
    ) {}

    /**
     * Whether a new card payment is shown no card: the shop takes card payments in support's private chat now
     * (PaymentSettings::sendsToSupport()), where support gives the customer one.
     */
    public function toSupport(): bool
    {
        return $this->settings->sendsToSupport();
    }

    /**
     * Whether the method takes a `$type` order of `$amount` now: an enabled card of it has room for it today (a top-up's
     * few Toman more counted, most()) — or `$customer` holds a payment of it shown a card, waiting for its transfer, on an
     * open order of that kind and amount: asking again shows them that payment again, its card switched off or full since
     * or not (PaymentService reuses the order and its payment), and takes no more room. For any amount (null), an enabled
     * card, or such a payment of any amount. While the shop takes card payments in support's private chat (toSupport()),
     * any: no card is picked.
     */
    public function offers(PaymentMethod $method, OrderType $type, ?string $amount, ?User $customer = null): bool
    {
        if ($this->toSupport()) {
            return true;
        }
        $amount = $amount === null ? null : Money::normalize($amount);
        if ($customer !== null && self::holds($method, $customer, $type, $amount)) {
            return true;
        }
        $cards = $method->cards()->where('enabled', true)->get()->all();

        return $amount === null ? $cards !== [] : self::withRoom($cards, $this->most($method, $type, $amount)) !== [];
    }

    /**
     * A card for a new payment of a `$type` order of `$amount`: one at random among the method's enabled cards with room
     * for it (a top-up's few Toman more counted, most()); null when none has. Inside the caller's transaction the cards
     * are locked as they are read, and what they took read as it stands (a locking read): of two payments at once, the
     * second waits for the first and is held to what it took.
     */
    public function pick(PaymentMethod $method, OrderType $type, string $amount): ?PaymentCard
    {
        $cards = self::withRoom($method->cards()->where('enabled', true)->lockForUpdate()->get()->all(), $this->most($method, $type, $amount), lock: true);

        return $cards === [] ? null : $cards[random_int(0, count($cards) - 1)];
    }

    /**
     * What a new payment of the method shown `$card` asks for a `$type` order of `$amount`: the amount itself — or, for a
     * top-up, the amount and 1 to extra() Toman more at random, a sum no other open payment to the card asks (any, should
     * every one be asked). Shown no card (toSupport()), no other open payment of the method shown none. `$keeping`: the
     * sum a payment shown no card asked before it is shown `$card` now — kept, unless another open payment to the card
     * asks it too. In the caller's transaction, after pick(): those payments are read as they stand — with no card to
     * hold, the method's row is locked first, so of two payments at once the second waits for the first's sum.
     */
    public function amountFor(PaymentMethod $method, ?PaymentCard $card, OrderType $type, string $amount, ?string $keeping = null): string
    {
        $most = $this->extra($method, $type, $amount);
        if ($most <= 0) {
            return Money::normalize($keeping ?? $amount);
        }

        if ($card === null) {
            PaymentMethod::query()->whereKey($method->id)->lockForUpdate()->first();
        }
        $open = $card === null ? $method->payments()->whereNull('card_id') : $card->payments();
        $asked = array_flip($open->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::AwaitingReview->value])
            ->lockForUpdate()->pluck('amount')->map(static fn(mixed $sum): string => Money::normalize((string) $sum))->all());
        if ($keeping !== null && !isset($asked[Money::normalize($keeping)])) {
            return Money::normalize($keeping);
        }
        $free = array_values(array_filter(range(1, $most), static fn(int $extra): bool => !isset($asked[Money::add($amount, $extra)])));
        $extra = $free === [] ? random_int(1, $most) : $free[random_int(0, count($free) - 1)];

        return Money::add($amount, $extra);
    }

    /**
     * The cards of `$methods` for their rows, each with what it took today and its payments counted — read for every
     * method at once: the methods screen lists them all.
     *
     * @param list<PaymentMethod> $methods
     * @return array<int, list<array{id: int, number: string, holder: string, bank: string|null, summary: string, enabled: bool, daily_cap: string|null, today: string, payments: int}>> By method id, in their order; a method without cards left out
     */
    public function present(array $methods): array
    {
        if ($methods === []) {
            return [];
        }
        $cards = PaymentCard::query()->whereIn('payment_method_id', array_map(static fn(PaymentMethod $method): int => $method->id, $methods))
            ->withCount(self::counted())->orderBy('sort')->orderBy('id')->get()->all();
        $today = self::takenToday(array_map(static fn(PaymentCard $card): int => $card->id, $cards));

        $rows = [];
        foreach ($cards as $card) {
            $rows[$card->payment_method_id][] = [
                'id' => $card->id,
                'number' => $card->number,
                'holder' => $card->holder,
                'bank' => $card->bank,
                'summary' => $card->summary(),
                'enabled' => $card->enabled,
                'daily_cap' => $card->daily_cap,
                'today' => $today[$card->id] ?? Money::normalize(0),
                'payments' => (int) $card->getAttribute('payments_count'),
            ];
        }

        return $rows;
    }

    /**
     * The method form's `cards` as it would leave them, every refusal under `cards` at once: one to MOST rows, each a
     * card's number (16 digits passing the Luhn check, as CardNumber reads one), its holder, its bank (optional), its
     * switch (on when left out) and its daily cap (blank: none) — a kept card by its `id`. No number twice; a card
     * payments were made to keeps its number, and is switched off rather than left out.
     *
     * @param array<string, mixed> $input
     * @return list<array{id: int|null, number: string, holder: string, bank: string|null, enabled: bool, daily_cap: string|null}>
     * @throws ValidationException
     */
    public function read(array $input, ?PaymentMethod $method): array
    {
        $rows = $input['cards'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw ValidationException::on('cards', 'کارت‌های این روش را به صورت فهرست بفرستید.');
        }
        if ($rows === []) {
            throw ValidationException::on('cards', 'دست کم یک کارت وارد کنید.');
        }
        if (count($rows) > self::MOST) {
            throw ValidationException::on('cards', 'یک روش حداکثر ' . Persian::number(self::MOST) . ' کارت دارد.');
        }

        $kept = $method === null ? [] : $method->cards()->withCount(self::counted())->get()->keyBy('id')->all();
        $number = new CardNumber('number', 'number', label: 'شماره کارت');
        $errors = [];
        $read = [];
        $seen = [];
        $ids = [];
        foreach ($rows as $index => $row) {
            $place = 'کارت ' . Persian::number($index + 1) . ': ';
            if (!is_array($row)) {
                $errors[] = $place . 'اطلاعات این کارت درست نیست.';
                continue;
            }
            $id = array_key_exists('id', $row) && $row['id'] !== null ? Input::integerOf($row['id']) : null;
            $card = $id === null ? null : ($kept[$id] ?? null);
            if (array_key_exists('id', $row) && $row['id'] !== null && $card === null) {
                $errors[] = $place . 'این کارت در این روش نیست؛ صفحه را دوباره باز کنید.';
                continue;
            }
            // One row a card: a kept card listed twice would be saved as whichever came last.
            if ($card !== null && isset($ids[$card->id])) {
                $errors[] = $place . 'این کارت دو بار در فهرست آمده است؛ صفحه را دوباره باز کنید.';
                continue;
            }
            if ($card !== null) {
                $ids[$card->id] = true;
            }

            try {
                $digits = $number->read($row, null);
            } catch (FieldRefused $e) {
                $errors[] = $place . $e->getMessage();
                $digits = null;
            }
            if ($digits !== null && isset($seen[$digits])) {
                $errors[] = $place . 'این شماره کارت دو بار آمده است.';
            } elseif ($digits !== null && $card !== null && $card->number !== $digits && (int) $card->getAttribute('payments_count') > 0) {
                $errors[] = $place . 'با این کارت پرداخت شده و شماره‌اش عوض نمی‌شود؛ کارت تازه‌ای اضافه و این یکی را خاموش کنید.';
            }
            if ($digits !== null) {
                $seen[$digits] = true;
            }

            $holder = Input::text($row, 'holder');
            if ($holder === '') {
                $errors[] = $place . 'نام صاحب کارت را بنویسید.';
            } elseif (mb_strlen($holder) > self::HOLDER_MAX) {
                $errors[] = $place . Validation::tooLong('نام صاحب کارت', self::HOLDER_MAX);
            }
            $bank = Input::text($row, 'bank');
            if (mb_strlen($bank) > self::BANK_MAX) {
                $errors[] = $place . Validation::tooLong('نام بانک', self::BANK_MAX);
            }
            $switch = $row['enabled'] ?? true;
            if (!Input::isBoolean($switch)) {
                $errors[] = $place . Validation::NOT_A_SWITCH;
            }
            $cap = Input::optionalAmountOf($row['daily_cap'] ?? null, 1, Money::AMOUNT_MAX);
            if ($cap === false) {
                $errors[] = $place . Validation::notWholeToman('سقف روزانه', 'بدون سقف');
            }

            $read[] = [
                'id' => $card?->id,
                'number' => (string) $digits,
                'holder' => $holder,
                'bank' => $bank === '' ? null : $bank,
                'enabled' => Input::truthy($switch),
                'daily_cap' => $cap === false ? null : $cap,
            ];
        }

        $listed = array_filter(array_column($read, 'id'));
        foreach ($kept as $id => $card) {
            if (!in_array($id, $listed, true) && (int) $card->getAttribute('payments_count') > 0) {
                $errors[] = sprintf('با کارت %s پرداخت شده و حذف نمی‌شود؛ به جای حذف، خاموشش کنید.', $card->summary());
            }
        }
        if ($errors !== []) {
            throw new ValidationException(['cards' => $errors], $errors[0]);
        }

        return $read;
    }

    /**
     * Keeps the method's cards as read(): each listed card saved in its place, a new one added, one left out deleted
     * (read() let only a card without payments go). Inside the caller's transaction.
     *
     * @param list<array{id: int|null, number: string, holder: string, bank: string|null, enabled: bool, daily_cap: string|null}> $rows
     */
    public function keep(PaymentMethod $method, array $rows): void
    {
        $kept = $method->cards()->get()->keyBy('id');
        foreach ($rows as $index => $row) {
            $values = ['number' => $row['number'], 'holder' => $row['holder'], 'bank' => $row['bank'], 'enabled' => $row['enabled'], 'daily_cap' => $row['daily_cap'], 'sort' => $index + 1];
            $card = $row['id'] === null ? null : $kept->get($row['id']);
            if ($card === null) {
                $method->cards()->create($values);
            } else {
                $card->fill($values)->save();
            }
        }

        $listed = array_filter(array_column($rows, 'id'));
        $kept->reject(static fn(PaymentCard $card): bool => in_array($card->id, $listed, true))->each(static fn(PaymentCard $card) => $card->delete());
    }

    /**
     * Whether `$customer` has a payment of the method shown a card and waiting for its transfer, on an open `$type` order
     * of `$amount` (null: any).
     */
    private static function holds(PaymentMethod $method, User $customer, OrderType $type, ?string $amount): bool
    {
        return $method->payments()->whereNotNull('card_id')->where('status', PaymentStatus::Pending->value)
            ->whereHas('order', static fn(Builder $order): Builder => $order->where(['user_id' => $customer->id, 'type' => $type->value, 'status' => OrderStatus::Pending->value])
                ->when($amount !== null, static fn(Builder $sized): Builder => $sized->where('amount', $amount)))
            ->exists();
    }

    /** The most a payment of the method for a `$type` order of `$amount` may ask: with the few Toman amountFor() may add. */
    private function most(PaymentMethod $method, OrderType $type, string $amount): string
    {
        return Money::add($amount, $this->extra($method, $type, $amount));
    }

    /**
     * The most Toman amountFor() may add to a `$type` order of `$amount` paid with the method: a top-up's
     * WalletSettings::uniqueMax(), held so the sum stays within the method's `max_amount` — none when the amount is its
     * most already —; nothing for any other order.
     */
    private function extra(PaymentMethod $method, OrderType $type, string $amount): int
    {
        $extra = $type === OrderType::WalletTopUp ? $this->wallet->uniqueMax() : 0;
        if ($extra > 0 && $method->max_amount !== null) {
            $extra = min($extra, max(0, (int) Money::subtract($method->max_amount, $amount)));
        }

        return $extra;
    }

    /**
     * The cards of `$cards` whose daily cap leaves room for `$amount` today (takenToday(), with `$lock`).
     *
     * @param list<PaymentCard> $cards
     * @return list<PaymentCard>
     */
    private static function withRoom(array $cards, string $amount, bool $lock = false): array
    {
        $capped = array_values(array_filter($cards, static fn(PaymentCard $card): bool => $card->daily_cap !== null));
        $today = self::takenToday(array_map(static fn(PaymentCard $card): int => $card->id, $capped), $lock);

        return array_values(array_filter($cards, static fn(PaymentCard $card): bool => $card->daily_cap === null
            || Money::compare(Money::add($today[$card->id] ?? 0, $amount), $card->daily_cap) <= 0));
    }

    /**
     * withCount()'s `payments` of a card: counted from their index alone (payments.card_id …), with no shop filter — a
     * card's payments are its shop's.
     *
     * @return array{payments: \Closure(Builder<Payment>): Builder<Payment>}
     */
    private static function counted(): array
    {
        return ['payments' => static fn(Builder $payments): Builder => $payments->withoutGlobalScope(CurrentBot::SCOPE)];
    }

    /**
     * What came to each card today on the shop's clock: its payments made since midnight that are paid, refunded or
     * still open — summed from their index alone (payments.card_id …), with no shop filter: a card's payments are its
     * shop's. With `$lock`, a locking read: the rows as they stand, not as the transaction first saw them.
     *
     * @param list<int> $cards
     * @return array<int, string> By card id; a card with none is left out
     */
    private static function takenToday(array $cards, bool $lock = false): array
    {
        if ($cards === []) {
            return [];
        }
        $taken = Payment::query()
            ->withoutGlobalScope(CurrentBot::SCOPE)
            ->whereIn('card_id', $cards)
            ->where('created_at', '>=', LocalTime::midnight())
            ->whereIn('status', array_map(static fn(PaymentStatus $status): string => $status->value, self::TAKEN));

        return ($lock ? $taken->lockForUpdate() : $taken)
            ->groupBy('card_id')
            ->selectRaw('card_id, sum(amount) as taken')
            ->pluck('taken', 'card_id')
            ->map(static fn(mixed $taken): string => Money::normalize((string) $taken))
            ->all();
    }
}
