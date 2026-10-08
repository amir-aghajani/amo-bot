<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use App\Core\Drivers\Driver;
use App\Core\Drivers\Registry;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\FieldType;
use App\Modules\Payments\Contracts\GatewayDriver;
use cebe\openapi\spec\Schema;
use Tests\HttpTestCase;
use Tests\TestCase;

/**
 * A driver's form is what its request takes: the panels draw a driver's form from its description and send its fields by
 * their names — a secret with its `clear_<name>` —, and the API description holds each driver's request to a closed
 * shape of its own (resources/api/openapi.yaml). The two name the same fields, both ways, and agree on what a form
 * cannot leave out; a family's shapes are its registered drivers', and nothing else.
 */
final class DriverFormsTest extends TestCase
{
    /** What a server's form has beside its connector's: the server's own columns (Providers\Services\ServerService). */
    private const SERVER = ['name', 'capacity', 'notes', 'is_active'];

    /**
     * Each request a family's drivers are saved by — a oneOf of a shape per driver — with its registry's container entry,
     * the field of it that names the driver, the fields every shape has beside the driver's own, and the words a shape may
     * name that are no driver (mail's and the website captcha's `none`). A server is added and changed by one, and tried
     * by another — a saved one's under its `id`. A payment method's are held below: a new one and a saved one differ.
     */
    private const FAMILIES = [
        'DatabaseRequest' => ['entry' => 'database.drivers', 'names' => 'driver', 'beside' => [], 'none' => []],
        'ConfigMailRequest' => ['entry' => 'mail.drivers', 'names' => 'transport', 'beside' => ['from_address', 'from_name'], 'none' => ['none']],
        'WebsiteCaptchaRequest' => ['entry' => 'captcha.drivers', 'names' => 'driver', 'beside' => [], 'none' => ['none']],
        'ServerRequest' => ['entry' => 'panel.drivers', 'names' => 'driver', 'beside' => self::SERVER, 'none' => []],
        'ServerTestRequest' => ['entry' => 'panel.drivers', 'names' => 'driver', 'beside' => ['id', ...self::SERVER], 'none' => []],
    ];

    /** What a payment method's form has beside its driver's: the label the customer picks it by, and its switch. */
    private const METHOD = ['label', 'enabled'];

    public function testEveryDriversRequestTakesItsFormsFieldsAndNothingElse(): void
    {
        foreach (self::FAMILIES as $request => $family) {
            ['entry' => $entry, 'names' => $names, 'beside' => $beside] = $family;
            $shapes = self::shapes($request, $names);
            $drivers = $this->drivers($entry);

            foreach ($drivers as $driver) {
                $shape = $shapes[$driver->key()] ?? self::fail("{$request} has no shape for the driver {$driver->key()}.");
                [$fields, $required] = self::form($driver);

                self::assertEqualsCanonicalizing($fields, array_values(array_diff(array_keys($shape->properties), [$names, ...$beside])), "{$request}: {$driver->key()}'s fields are its form's, both ways");
                self::assertEqualsCanonicalizing($required, array_values(array_diff($shape->required ?? [], [$names, ...$beside])), "{$request}: what {$driver->key()}'s form cannot leave out");
                self::assertSame([], array_values(array_diff([$names, ...$beside], array_keys($shape->properties))), "{$request}: {$driver->key()}'s shape names it, beside the rest");
            }

            $keys = array_map(static fn(Driver $driver): string => $driver->key(), $drivers);
            self::assertEqualsCanonicalizing([...$keys, ...$family['none']], array_keys($shapes), "{$request}: a shape per driver registered, and nothing else");
            foreach ($family['none'] as $word) {
                self::assertSame([$names], array_keys($shapes[$word]->properties), "{$request}: {$word} takes nothing but itself");
            }
        }
    }

    public function testTheWaysEmailGoesOutAreNoneAndTheMailDrivers(): void
    {
        $keys = array_map(static fn(Driver $driver): string => $driver->key(), $this->drivers('mail.drivers'));

        self::assertSame(['none', ...$keys], HttpTestCase::apiDescription()->components->schemas['MailTransport']->enum ?? null);
    }

    public function testEveryKindOfFieldIsOneTheDescriptionNames(): void
    {
        self::assertSame(
            array_map(static fn(FieldType $type): string => $type->value, FieldType::cases()),
            HttpTestCase::apiDescription()->components->schemas['FieldType']->enum ?? null,
            'what a field holds is a kind the panels draw',
        );
    }

    public function testTheCaptchasAWebsiteMayAskAreTheCaptchaDrivers(): void
    {
        $keys = array_map(static fn(Driver $driver): string => $driver->key(), $this->drivers('captcha.drivers'));
        $schemas = HttpTestCase::apiDescription()->components->schemas;

        self::assertSame($keys, $schemas['CaptchaDriver']->enum ?? null, 'what a page draws its widget for');
        self::assertSame(['none', ...$keys], $schemas['Website']->properties['captcha']->properties['driver']->enum ?? null, 'what the panels show the website asks');
    }

    /**
     * A payment method is its driver's form beside the label the customer picks it by and its switch: a new one names its
     * driver — but a built-in driver's, which is never added —, a method's form again names none (its row's driver
     * stands) and is one shape per driver all the same, and its row shows the form's fields as the row keeps them.
     */
    public function testAPaymentMethodsRequestsAndRowAreItsDriversForm(): void
    {
        $drivers = $this->drivers('payment.drivers');
        $added = self::shapes('PaymentMethodCreateRequest', 'driver');
        $changed = [];
        foreach (HttpTestCase::apiDescription()->components->schemas['PaymentMethodUpdateRequest']->oneOf ?? [] as $shape) {
            self::assertInstanceOf(Schema::class, $shape);
            self::assertFalse($shape->additionalProperties, 'PaymentMethodUpdateRequest: every shape is closed');
            $changed[] = $shape;
        }
        // The rows by the driver each names: the one of a driver no longer installed names none.
        $rows = [];
        foreach (HttpTestCase::apiDescription()->components->schemas['PaymentMethodRow']->oneOf ?? [] as $row) {
            self::assertInstanceOf(Schema::class, $row);
            foreach ($row->properties['driver']->enum ?? [] as $key) {
                $rows[(string) $key] = $row;
            }
        }

        $addable = [];
        foreach ($drivers as $driver) {
            [$fields, $required] = self::form($driver);
            $key = $driver->key();
            if (($driver->describe()->traits[GatewayDriver::BUILTIN] ?? false) !== true) {
                $addable[] = $key;
                $shape = $added[$key] ?? self::fail("PaymentMethodCreateRequest has no shape for the driver {$key}.");
                self::assertEqualsCanonicalizing($fields, array_values(array_diff(array_keys($shape->properties), ['driver', ...self::METHOD])), "PaymentMethodCreateRequest: {$key}'s fields are its form's, both ways");
                self::assertEqualsCanonicalizing(['driver', 'label', ...$required], $shape->required ?? [], "PaymentMethodCreateRequest: what a new {$key} method cannot leave out");
            }

            $own = array_values(array_filter($changed, static fn(Schema $shape): bool => self::sorted(array_diff(array_keys($shape->properties), self::METHOD)) === self::sorted($fields)));
            self::assertCount(1, $own, "PaymentMethodUpdateRequest: one shape is {$key}'s form, both ways");
            self::assertEqualsCanonicalizing($required, $own[0]->required ?? [], "PaymentMethodUpdateRequest: what {$key}'s form cannot leave out");

            $row = $rows[$key] ?? self::fail("PaymentMethodRow has no shape for the driver {$key}.");
            $names = array_map(static fn(Field $field): string => $field->name, $driver->describe()->form->fields);
            self::assertEqualsCanonicalizing($names, array_keys($row->properties['config']->properties ?? []), "PaymentMethodRow: {$key}'s settings are its form's fields");
        }

        self::assertEqualsCanonicalizing($addable, array_keys($added), 'PaymentMethodCreateRequest: a shape per driver a method is added of, and nothing else');
        self::assertCount(count($drivers), $changed, 'PaymentMethodUpdateRequest: a shape per driver, and nothing else');
        self::assertEqualsCanonicalizing(array_map(static fn(Driver $driver): string => $driver->key(), $drivers), array_keys($rows), 'PaymentMethodRow: a shape per driver, beside the one of a driver gone');
    }

    /**
     * @param array<string> $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    /**
     * A request's shapes by the driver each names.
     *
     * @return array<string, Schema>
     */
    private static function shapes(string $request, string $names): array
    {
        $shapes = [];
        foreach (HttpTestCase::apiDescription()->components->schemas[$request]->oneOf ?? [] as $shape) {
            self::assertInstanceOf(Schema::class, $shape);
            $named = $shape->properties[$names]->enum ?? [];
            self::assertCount(1, $named, "{$request}: every shape names one driver by `{$names}`");
            self::assertFalse($shape->additionalProperties, "{$request}: every shape is closed");
            $shapes[(string) $named[0]] = $shape;
        }

        return $shapes;
    }

    /** @return list<Driver> */
    private function drivers(string $entry): array
    {
        $registry = $this->app()->container()->get($entry);
        self::assertInstanceOf(Registry::class, $registry);

        return $registry->all();
    }

    /**
     * What a driver's request carries by its form — every field, a secret's clear flag beside it — and what it cannot
     * leave out: a required field that is no secret (a blank secret keeps the stored one) and shown always.
     *
     * @return array{list<string>, list<string>}
     */
    private static function form(Driver $driver): array
    {
        $fields = [];
        $required = [];
        foreach ($driver->describe()->form->fields as $field) {
            $fields[] = $field->name;
            if ($field->type() === FieldType::Secret) {
                $fields[] = 'clear_' . $field->name;
            } elseif ($field->required() && ($field->spec->when ?? []) === []) {
                $required[] = $field->name;
            }
        }

        return [$fields, $required];
    }
}
