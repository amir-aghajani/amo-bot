<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Drivers\Registry;
use App\Core\Drivers\UnknownDriverException;
use App\Core\Forms\Fields\Field;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;

/**
 * The gateway drivers (the container's `payment.drivers`: register another in bootstrap/container.php), and what the
 * shop reads of a method row through its driver: how it settles, its one line, and its gateway — made from the row's
 * settings, what its driver's form reads of the row's config: every field, one the row lacks (kept before the field
 * existed) at its default.
 */
final class GatewayRegistry
{
    /** @param Registry<GatewayDriver> $drivers */
    public function __construct(private readonly Registry $drivers) {}

    /** @return list<GatewayDriver> Every driver, in the order they are registered: the "add method" picker's. */
    public function all(): array
    {
        return $this->drivers->all();
    }

    /** The driver of that key; null for one no longer installed — a row of it can only be switched off or deleted. */
    public function find(string $driver): ?GatewayDriver
    {
        return $this->drivers->find($driver);
    }

    /** How the driver settles, as its `kind` trait says; null for a driver no longer installed. */
    public function kind(string $driver): ?GatewayKind
    {
        $kind = $this->find($driver)?->describe()->traits[GatewayDriver::KIND] ?? null;

        return is_string($kind) ? GatewayKind::tryFrom($kind) : null;
    }

    /** Whether the driver is part of the shop itself, as its `builtin` trait says: the wallet, one row in every shop. */
    public function builtin(string $driver): bool
    {
        return ($this->find($driver)?->describe()->traits[GatewayDriver::BUILTIN] ?? false) === true;
    }

    /**
     * Whether the method is a card transfer (GatewayKind::Manual): paid outside the shop, its receipt what a person — or
     * its review window — accepts. Not the wallet, which settles at once, nor a driver no longer installed.
     */
    public function isManual(PaymentMethod $method): bool
    {
        return $this->kind($method->driver) === GatewayKind::Manual;
    }

    /** The driver's one line about the method — the card the customer pays to —; null for none, or no driver installed. */
    public function summary(PaymentMethod $method): ?string
    {
        $driver = $this->find($method->driver);

        return $driver === null ? null : $driver->summary(self::settings($driver, $method));
    }

    /** @throws UnknownDriverException for a row whose driver is no longer installed */
    public function forMethod(PaymentMethod $method): GatewayInterface
    {
        $driver = $this->drivers->get($method->driver);

        return $driver->gateway(self::settings($driver, $method));
    }

    /** The gateway a payment was started on: its method's (a method that has payments is never deleted). */
    public function forPayment(Payment $payment): GatewayInterface
    {
        return $this->forMethod($payment->method);
    }

    /**
     * The method's settings as its driver reads them: each field of its form from the row's config.
     *
     * @return array<string, mixed> By field name
     */
    private static function settings(GatewayDriver $driver, PaymentMethod $method): array
    {
        $config = $method->config;

        return $driver->describe()->form->values(static fn(Field $field): mixed => $config[$field->key] ?? null);
    }
}
