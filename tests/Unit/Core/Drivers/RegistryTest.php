<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Drivers;

use App\Core\Drivers\Descriptor;
use App\Core\Drivers\Driver;
use App\Core\Drivers\Registry;
use App\Core\Drivers\UnknownDriverException;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use PHPUnit\Framework\TestCase;

/**
 * The one driver kernel every family stands on: a family's drivers found by key in the order they were registered, a
 * key no driver has the code's mistake, and a driver described to the panels — its words, its form's fields described,
 * and what its family says of it beyond that.
 */
final class RegistryTest extends TestCase
{
    public function testADriverIsFoundByItsKeyInTheOrderTheyAreRegistered(): void
    {
        $registry = new Registry([self::driver('smtp'), self::driver('resend')]);

        self::assertSame(['smtp', 'resend'], array_map(static fn(Driver $driver): string => $driver->key(), $registry->all()));
        self::assertTrue($registry->has('resend'));
        self::assertFalse($registry->has('sendgrid'));
        self::assertSame('resend', $registry->get('resend')->key());
        self::assertSame('smtp', $registry->find('smtp')?->key());
        self::assertNull($registry->find('sendgrid'));
    }

    public function testAKeyNoDriverHasIsTheCodesMistake(): void
    {
        $registry = new Registry((static function (): \Generator {
            yield self::driver('mysql');
            yield self::driver('sqlite');
        })());

        try {
            $registry->get('pgsql');
            self::fail('no driver of that key');
        } catch (UnknownDriverException $e) {
            self::assertSame('No driver is registered as "pgsql" (mysql, sqlite).', $e->getMessage());
            self::assertInstanceOf(\LogicException::class, $e);
        }

        $this->expectException(\LogicException::class);
        new Registry([self::driver('smtp'), self::driver('smtp')]);
    }

    public function testADriverIsDescribedWithItsFormsFieldsAndItsFamilysTraits(): void
    {
        $descriptor = self::driver('smtp')->describe();

        $described = $descriptor->toArray();
        self::assertSame(['key', 'label', 'description', 'notes', 'fields'], array_slice(array_keys($described), 0, 5));
        self::assertSame(['smtp', 'SMTP', 'یک حساب ایمیل.', ['پورت 587']], [$described['key'], $described['label'], $described['description'], $described['notes']]);
        self::assertSame(['host', 'password'], array_column($described['fields'], 'name'));
        self::assertSame([false, true], array_column($described['fields'], 'secret'));
        self::assertSame('{"sender":"یکی از حساب‌های همان سرور."}', json_encode($described['traits'], JSON_UNESCAPED_UNICODE));
        self::assertSame('{}', json_encode((new Descriptor('native', 'native', '', new Form('native', [])))->toArray()['traits']), 'no traits: an empty object');
    }

    private static function driver(string $key): Driver
    {
        return new class ($key) implements Driver {
            public function __construct(private readonly string $key) {}

            public function key(): string
            {
                return $this->key;
            }

            public function describe(): Descriptor
            {
                return new Descriptor($this->key, strtoupper($this->key), 'یک حساب ایمیل.', new Form($this->key, [
                    new Text('host', 'MAIL_HOST', '', label: 'سرور', max: 255, required: true, spec: new FieldSpec('سرور')),
                    new Secret('password', 'MAIL_PASSWORD', '', pattern: '/^.+$/', mismatch: 'x', spec: new FieldSpec('رمز')),
                ]), notes: ['پورت 587'], traits: ['sender' => 'یکی از حساب‌های همان سرور.']);
            }
        };
    }
}
