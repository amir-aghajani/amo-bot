<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use App\Modules\Payments\Exceptions\BuiltinMethodException;
use App\Modules\Payments\Exceptions\MethodInUseException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\Input;
use App\Support\Validation;

/**
 * The payment-method rows: what the admin lists, adds (pick a driver, fill its form), edits, switches and orders, and
 * what the bot offers at checkout. A row's settings are its driver's form (GatewayDriver): checked by it as a whole,
 * kept in the row's config, shown as it reads them. Built-in drivers (the wallet) have exactly one row in every shop —
 * made with the shop —, never added again nor deleted; a row payments were made with is not deleted either — switched
 * off, it stays their method.
 */
final class PaymentMethods
{
    private const LABEL_MAX = 60;

    public function __construct(private readonly GatewayRegistry $gateways) {}

    /** @return list<array<string, mixed>> Every row in checkout order, for the admin screen. */
    public function all(): array
    {
        return PaymentMethod::query()->withCount('payments')->oldest('sort')->oldest('id')->get()->map($this->present(...))->values()->all();
    }

    /**
     * What the customer may pay this kind of order with, in checkout order: every method switched on whose driver is
     * installed — a row of one no longer installed has no gateway to pay with; the panel says so beside it — but the
     * wallet for a wallet top-up, which it cannot pay.
     *
     * @return list<PaymentMethod>
     */
    public function forOrder(OrderType $type): array
    {
        return array_values(array_filter(PaymentMethod::enabled()->get()->all(), fn(PaymentMethod $method): bool => $this->offers($method, $type)));
    }

    /**
     * One of forOrder()'s methods by its id; null once it was switched off or deleted, its driver is no longer installed,
     * or it cannot pay that kind of order.
     */
    public function payable(int $id, OrderType $type): ?PaymentMethod
    {
        $method = PaymentMethod::enabled()->find($id);

        return $method !== null && $this->offers($method, $type) ? $method : null;
    }

    /** Whether the method may pay that kind of order: anything pays anything, but the wallet does not charge itself. */
    public static function pays(PaymentMethod $method, OrderType $type): bool
    {
        return !($method->isWallet() && $type === OrderType::WalletTopUp);
    }

    /** Whether the checkout offers the (switched on) method for that kind of order: its driver is installed, and it pays it. */
    private function offers(PaymentMethod $method, OrderType $type): bool
    {
        return $this->gateways->find($method->driver) !== null && self::pays($method, $type);
    }

    /** The built-in wallet, while it is switched on — what an automatic renewal pays with. */
    public function enabledWallet(): ?PaymentMethod
    {
        return PaymentMethod::enabled()->where('driver', WalletGateway::key())->first();
    }

    /** @return list<array<string, mixed>> The drivers for the "add method" picker, each described with its form. */
    public function drivers(): array
    {
        return array_map(static fn(GatewayDriver $driver): array => $driver->describe()->toArray(), $this->gateways->all());
    }

    /**
     * A new shop's built-in methods (the wallet), made with the shop: the main bot's are database/schema.php's starting
     * rows, an agent's are made here when their shop opens — in the current bot's shop.
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

        return PaymentMethod::query()->create($this->attributes($driver, $input, null) + [
            'driver' => $key,
            'enabled' => true,
            'sort' => Sorting::next(PaymentMethod::class),
        ]);
    }

    /**
     * The whole form again: label, the driver's fields, the switch when sent.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(PaymentMethod $method, array $input): PaymentMethod
    {
        $driver = $this->gateways->find($method->driver) ?? throw ValidationException::on('driver', 'درایور این روش نصب نیست؛ فقط می‌شود آن را خاموش یا حذف کرد.');
        $method->fill($this->attributes($driver, $input, $method))->save();

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

    /**
     * One row of the screen, its payments counted with it (withCount()/loadCount()): the driver's own line about it
     * (GatewayDriver::summary(), the payments screen's too) and its settings as its driver's form shows them — every
     * field, one the row lacks (kept before the field existed) at its default —; none for a driver no longer installed,
     * since nothing then says which of them are secrets.
     *
     * @return array<string, mixed>
     */
    public function present(PaymentMethod $method): array
    {
        $descriptor = $this->gateways->find($method->driver)?->describe();
        $config = $method->config;

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
            'enabled' => $method->enabled,
            'sort' => $method->sort,
            'counts' => ['payments' => (int) $method->getAttribute('payments_count')],
            'created_at' => $method->created_at->toIso8601String(),
            'updated_at' => $method->updated_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> One row after an edit, its payments counted now. */
    public function presentOne(PaymentMethod $method): array
    {
        return $this->present($method->loadCount('payments'));
    }

    /**
     * The admin's label, the settings its driver's form checked — what the row keeps standing for a secret left blank,
     * and staying for a field the form does not show —, and the switch when the form sent it: every refusal at once.
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

        $kept = $existing->config ?? [];
        try {
            $config = $driver->describe()->form->check($input, static fn(Field $field): mixed => $kept[$field->key] ?? null) + $kept;
        } catch (ValidationException $e) {
            throw new ValidationException($errors + $e->errors());
        }
        ValidationException::ifAny($errors);

        return ['label' => $label, 'config' => $config] + ($switch === null ? [] : ['enabled' => Input::truthy($switch)]);
    }
}
