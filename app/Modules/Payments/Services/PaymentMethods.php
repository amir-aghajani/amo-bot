<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Drivers\Outside\OutsideGateway;
use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\Exceptions\BuiltinMethodException;
use App\Modules\Payments\Exceptions\MethodInUseException;
use App\Modules\Payments\Exceptions\MethodUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\MethodSettings;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The payment-method rows: kept by the panels (a row's settings are its driver's form, a card method's cards a list of
 * their own — PaymentCards) and offered at checkout for the amounts within their bounds. A built-in method (the wallet,
 * the outside row) has one row per shop, never added or deleted; a row with payments is never deleted either. The outside
 * row is support's alone: never offered, nor listed.
 */
final class PaymentMethods
{
    private const LABEL_MAX = 60;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly PaymentCards $cards,
        private readonly MethodSettings $settings,
        private readonly ConnectionInterface $db,
    ) {}

    /** @return list<array<string, mixed>> Every row in checkout order, for the admin screen — never the outside row, support's alone. */
    public function all(): array
    {
        return $this->present(self::listed()->withCount('payments')->oldest('sort')->oldest('id')->get()->all());
    }

    /**
     * One of the screen's rows (all()) by id, to edit, switch or delete: the outside row is no more found here than listed.
     *
     * @throws ModelNotFoundException 404
     */
    public function find(int $id): PaymentMethod
    {
        return self::listed()->findOrFail($id);
    }

    /**
     * The methods offered for this kind of order at `$amount`, in checkout order: enabled, with an installed driver, able
     * to pay it (pays()), the amount within their bounds (within()) and — a card method — with a card that has room for it
     * today — or one `$customer` holds a payment of waiting on such an order, which asking again shows them; any while
     * the shop takes card payments in support's private chat (PaymentCards::offers()). A null amount is any: the bounds
     * and the caps are not asked.
     *
     * @return list<PaymentMethod>
     */
    public function forOrder(OrderType $type, ?string $amount, ?User $customer = null): array
    {
        return array_values(array_filter(PaymentMethod::enabled()->get()->all(), fn(PaymentMethod $method): bool => $this->offers($method, $type) && $this->takes($method, $type, $amount, $customer)));
    }

    /** One of forOrder()'s methods by id; null when it is not offered for that kind of order at that amount (null: any). */
    public function payable(int $id, OrderType $type, ?string $amount, ?User $customer = null): ?PaymentMethod
    {
        $method = PaymentMethod::enabled()->find($id);

        return $method !== null && $this->offers($method, $type) && $this->takes($method, $type, $amount, $customer) ? $method : null;
    }

    /**
     * Refuse a `$type` order of `$amount` the method does not take now (takes()), in the customer's words: the one check
     * of every checkout (Checkout::pay()), the bot's and the website's.
     *
     * @throws MethodUnavailableException 422 on `method_id`: outside its bounds, or no card of it with room today
     */
    public function allow(PaymentMethod $method, OrderType $type, string $amount, ?User $customer = null): void
    {
        $refusal = $this->refusal($method, $type, $amount, $customer);
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /**
     * Whether `$amount` is within the method's bounds (`min_amount`, `max_amount`). Nothing to pay is within any: a free
     * order is settled whatever was picked (Checkout::pay()).
     */
    public static function within(PaymentMethod $method, string $amount): bool
    {
        return !Money::isPositive($amount)
            || (($method->min_amount === null || Money::compare($amount, $method->min_amount) >= 0)
                && ($method->max_amount === null || Money::compare($amount, $method->max_amount) <= 0));
    }

    /** The method's bounds in the customer's words: «برای مبلغ‌های بین ۱۰٬۰۰۰ تومان و ۵۰۰٬۰۰۰ تومان است». */
    public static function boundsInWords(PaymentMethod $method): string
    {
        return match (true) {
            $method->min_amount !== null && $method->max_amount !== null => sprintf('برای مبلغ‌های بین %s و %s است', Money::format($method->min_amount), Money::format($method->max_amount)),
            $method->min_amount !== null => sprintf('برای مبلغ‌های از %s به بالا است', Money::format($method->min_amount)),
            $method->max_amount !== null => sprintf('برای مبلغ‌های تا %s است', Money::format($method->max_amount)),
            default => 'برای هر مبلغی است',
        };
    }

    /**
     * Whether the method takes a `$type` order of `$amount` (null: any): within its bounds, and — a card method — a card of
     * it with room for it today (PaymentCards::offers(), with `$customer`). Nothing to pay it takes whatever it is: the
     * wallet settles a free order (Checkout::pay()).
     */
    private function takes(PaymentMethod $method, OrderType $type, ?string $amount, ?User $customer = null): bool
    {
        return $amount === null
            ? !$this->gateways->isManual($method) || $this->cards->offers($method, $type, null, $customer)
            : $this->refusal($method, $type, $amount, $customer) === null;
    }

    /** Why the method does not take a `$type` order of `$amount` now (takes()); null when it does. */
    private function refusal(PaymentMethod $method, OrderType $type, string $amount, ?User $customer): ?MethodUnavailableException
    {
        return match (true) {
            !Money::isPositive($amount) => null,
            !self::within($method, $amount) => MethodUnavailableException::outOfBounds($method),
            $this->gateways->isManual($method) && !$this->cards->offers($method, $type, $amount, $customer) => MethodUnavailableException::noCard($method),
            default => null,
        };
    }

    /** Whether the method may pay that kind of order: any, except the wallet paying its own top-up. */
    public static function pays(PaymentMethod $method, OrderType $type): bool
    {
        return !($method->isWallet() && $type === OrderType::WalletTopUp);
    }

    /**
     * Whether an enabled method is offered for that kind of order: its driver is installed and it pays it. Never the
     * outside row: support records money taken outside the shop with it, no customer picks it.
     */
    private function offers(PaymentMethod $method, OrderType $type): bool
    {
        return $this->gateways->find($method->driver) !== null && !$method->isOutside() && self::pays($method, $type);
    }

    /** @return Builder<PaymentMethod> The rows the payment methods screen keeps: every one but the outside row. */
    private static function listed(): Builder
    {
        return PaymentMethod::query()->where('driver', '!=', OutsideGateway::key());
    }

    /** The built-in wallet, while it is switched on — what an automatic renewal pays with. */
    public function enabledWallet(): ?PaymentMethod
    {
        return PaymentMethod::enabled()->where('driver', WalletGateway::key())->first();
    }

    /** The built-in wallet, switched on or not: what settles a free order (Checkout::pay()). */
    public function wallet(): PaymentMethod
    {
        return PaymentMethod::query()->where('driver', WalletGateway::key())->firstOrFail();
    }

    /** The built-in outside row: money support took outside the shop for an order it made (Orders\Services\ManualOrders). */
    public function outside(): PaymentMethod
    {
        return PaymentMethod::query()->where('driver', OutsideGateway::key())->firstOrFail();
    }

    /** @return list<array<string, mixed>> The drivers for the "add method" picker, each described with its form — never the outside one. */
    public function drivers(): array
    {
        $listed = array_filter($this->gateways->all(), static fn(GatewayDriver $driver): bool => $driver->key() !== OutsideGateway::key());

        return array_values(array_map(static fn(GatewayDriver $driver): array => $driver->describe()->toArray(), $listed));
    }

    /**
     * Creates the built-in methods (the wallet, the outside row) in the current shop, when an agent's shop opens (the main
     * shop's come with database/schema.php).
     */
    public function createBuiltins(): void
    {
        foreach ($this->gateways->all() as $driver) {
            if ($this->gateways->builtin($driver->key())) {
                PaymentMethod::query()->create(['driver' => $driver->key(), 'label' => $driver->describe()->label, 'config' => [], 'enabled' => true, 'sort' => Sorting::next(PaymentMethod::class)]);
            }
        }
    }

    /**
     * @param array<string, mixed> $input `driver`, `label`, `enabled` + the driver's own fields
     * @throws ValidationException
     */
    public function create(array $input): PaymentMethod
    {
        $key = Input::text($input, 'driver');
        $driver = $this->gateways->find($key) ?? throw ValidationException::on('driver', 'این نوع روش پرداخت وجود ندارد.');
        if ($this->gateways->builtin($key)) {
            throw ValidationException::on('driver', 'این روش داخلی است و یک بار بیشتر وجود ندارد.');
        }

        [$attributes, $cards] = $this->form($driver, $input, null);

        return $this->db->transaction(function () use ($attributes, $key, $cards): PaymentMethod {
            $method = PaymentMethod::query()->create($attributes + [
                'driver' => $key,
                'enabled' => true,
                'sort' => Sorting::next(PaymentMethod::class),
            ]);
            if ($cards !== null) {
                $this->cards->keep($method, $cards);
            }

            return $method;
        });
    }

    /**
     * The whole form again: label, bounds, the driver's fields (a card method's cards too), the switch when sent. The
     * method's cards are locked before they are read: a payment shown one of them at this moment (PaymentCards::pick())
     * is made before the form is judged — so its card keeps its number and stays — or after the edit, shown it as saved.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(PaymentMethod $method, array $input): PaymentMethod
    {
        $driver = $this->gateways->find($method->driver) ?? throw ValidationException::on('driver', 'درایور این روش نصب نیست؛ فقط می‌شود آن را خاموش یا حذف کرد.');
        $this->db->transaction(function () use ($method, $driver, $input): void {
            $method->cards()->lockForUpdate()->get();
            [$attributes, $cards] = $this->form($driver, $input, $method);
            $method->fill($attributes)->save();
            if ($cards !== null) {
                $this->cards->keep($method, $cards);
            }
        });

        return $method;
    }

    public function setEnabled(PaymentMethod $method, bool $enabled): PaymentMethod
    {
        $method->forceFill(['enabled' => $enabled])->save();

        return $method;
    }

    /**
     * @throws BuiltinMethodException
     * @throws MethodInUseException once payments were made with it — switch it off instead
     */
    public function delete(PaymentMethod $method): void
    {
        if ($this->gateways->builtin($method->driver)) {
            throw new BuiltinMethodException($method);
        }
        $payments = $method->payments()->count();
        if ($payments > 0) {
            throw new MethodInUseException($method, $payments);
        }

        $method->delete();
    }

    /**
     * New checkout order: the ids as they should appear; rows left out keep their place after the listed ones.
     *
     * @param list<int> $ids
     */
    public function reorder(array $ids): void
    {
        Sorting::reorder(PaymentMethod::class, $ids);
    }

    /** @return array<string, mixed> One row of the screen (all()), after an edit: its payments counted now. */
    public function presentOne(PaymentMethod $method): array
    {
        return $this->present([$method->loadCount('payments')])[0];
    }

    /**
     * The screen's rows of `$methods`, their payments counted with them. A row's settings are shown as its driver's form
     * presents them — none for a driver no longer installed, since nothing then says which are secrets —; a card method's
     * cards beside them (PaymentCards::present(), read for every method at once), with whether a card is shown at all now
     * (`to_support`: not while the shop takes card payments in support's private chat, PaymentCards::toSupport()).
     *
     * @param list<PaymentMethod> $methods
     * @return list<array<string, mixed>>
     */
    private function present(array $methods): array
    {
        $manual = array_values(array_filter($methods, $this->gateways->isManual(...)));
        $cards = $this->cards->present($manual);
        $toSupport = $manual !== [] && $this->cards->toSupport();

        return array_map(function (PaymentMethod $method) use ($cards, $toSupport): array {
            $driver = $this->gateways->find($method->driver);
            $descriptor = $driver?->describe();
            $config = $driver === null ? [] : $this->settings->read($driver, $method->config);

            return [
                'id' => $method->id,
                'driver' => $method->driver,
                'driver_label' => $descriptor->label ?? $method->driver,
                'kind' => $this->gateways->kind($method->driver)?->value,
                'builtin' => $this->gateways->builtin($method->driver),
                'label' => $method->label,
                'summary' => $this->gateways->summary($method),
                // An object even when empty (the wallet's): JSON would make an empty PHP array a list.
                'config' => (object) ($descriptor?->form->present(static fn(Field $field): mixed => $config[$field->key] ?? null) ?? []),
                'min_amount' => $method->min_amount,
                'max_amount' => $method->max_amount,
                'enabled' => $method->enabled,
                'sort' => $method->sort,
                'counts' => ['payments' => (int) $method->getAttribute('payments_count')],
                'created_at' => $method->created_at->toIso8601String(),
                'updated_at' => $method->updated_at->toIso8601String(),
            ] + ($this->gateways->isManual($method) ? ['cards' => $cards[$method->id] ?? [], 'to_support' => $toSupport] : []);
        }, $methods);
    }

    /**
     * The method's form as it would leave it — its row's attributes, and a card method's cards (null for another
     * driver's) —, every refusal at once.
     *
     * @param array<string, mixed> $input
     * @return array{array<string, mixed>, list<array{id: int|null, number: string, holder: string, bank: string|null, enabled: bool, daily_cap: string|null}>|null}
     * @throws ValidationException
     */
    private function form(GatewayDriver $driver, array $input, ?PaymentMethod $existing): array
    {
        $errors = [];
        $attributes = [];
        $cards = null;
        try {
            $attributes = $this->attributes($driver, $input, $existing);
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        if (($driver->describe()->traits[GatewayDriver::KIND] ?? null) === GatewayKind::Manual->value) {
            try {
                $cards = $this->cards->read($input, $existing);
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }
        ValidationException::ifAny($errors);

        return [$attributes, $cards];
    }

    /**
     * The label, the bounds, the settings as the driver's form checks them (a blank secret keeps the stored one), its
     * secrets encrypted to be kept (MethodSettings), and the switch when sent; every refusal at once. A label or a bound
     * left out keeps the stored one.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function attributes(GatewayDriver $driver, array $input, ?PaymentMethod $existing): array
    {
        $label = array_key_exists('label', $input) ? Input::text($input, 'label') : ($existing->label ?? '');

        $errors = [];
        if ($label === '') {
            $errors['label'][] = 'نامی بنویسید که مشتری هنگام پرداخت می‌بیند؛ مثلا «کارت به کارت (ملت)».';
        } elseif (mb_strlen($label) > self::LABEL_MAX) {
            $errors['label'][] = Validation::tooLong('نام', self::LABEL_MAX);
        }
        $switch = array_key_exists('enabled', $input) ? $input['enabled'] : null;
        if ($switch !== null && !Input::isBoolean($switch)) {
            $errors['enabled'][] = Validation::NOT_A_SWITCH;
        }
        $min = self::bound($input, 'min_amount', 'کمترین مبلغ', $existing?->min_amount, $errors);
        $max = self::bound($input, 'max_amount', 'بیشترین مبلغ', $existing?->max_amount, $errors);
        if ($min !== null && $max !== null && Money::compare($min, $max) > 0) {
            $errors['max_amount'][] = 'بیشترین مبلغ نباید از کمترین مبلغ کمتر باشد.';
        }

        $kept = $this->settings->read($driver, $existing->config ?? []);
        try {
            $config = $driver->describe()->form->check($input, static fn(Field $field): mixed => $kept[$field->key] ?? null) + $kept;
        } catch (ValidationException $e) {
            throw new ValidationException($errors + $e->errors());
        }
        ValidationException::ifAny($errors);

        return ['label' => $label, 'config' => $this->settings->write($driver, $config), 'min_amount' => $min, 'max_amount' => $max] + ($switch === null ? [] : ['enabled' => Input::truthy($switch)]);
    }

    /**
     * A bound the form sends: whole Toman from 1 up, blank or null for none (Input::optionalAmountOf()); left out, `$kept`.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function bound(array $input, string $field, string $label, ?string $kept, array &$errors): ?string
    {
        if (!array_key_exists($field, $input)) {
            return $kept;
        }
        $amount = Input::optionalAmountOf($input[$field], 1, Money::AMOUNT_MAX);
        if ($amount === false) {
            $errors[$field][] = Validation::notWholeToman($label, 'بدون محدودیت');

            return null;
        }

        return $amount;
    }
}
