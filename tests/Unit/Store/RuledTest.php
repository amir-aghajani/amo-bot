<?php

declare(strict_types=1);

namespace Tests\Unit\Store;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Form;
use App\Modules\Store\Forms\Ruled;
use PHPUnit\Framework\TestCase;

/**
 * A field of the website's form with a rule of the form's own: its rule first, then everything its field says — a
 * kept secret's staying with what it belongs with among it.
 */
final class RuledTest extends TestCase
{
    public function testItsRuleComesFirstAndItsFieldSaysTheRest(): void
    {
        $form = new Form('site', [
            new Text('host', 'host', '', label: 'سرور', max: 255),
            new Ruled(
                new Secret('password', 'password', '', pattern: '/^.+$/', mismatch: 'x', boundTo: ['host' => null], moved: 'رمز را دوباره وارد کنید.'),
                static fn(array $values): ?string => $values['host'] === 'refused.example' ? 'این سرور پذیرفته نیست.' : null,
            ),
        ]);
        $kept = static fn(Field $field): mixed => ['host' => 'mail.example.com', 'password' => 'kept'][$field->key];

        self::assertSame(['host' => 'mail.example.com', 'password' => 'kept'], $form->check(['host' => 'mail.example.com'], $kept), 'kept while what it belongs with stays');
        self::assertSame(['password' => ['این سرور پذیرفته نیست.']], self::refused($form, ['host' => 'refused.example', 'password' => 'new'], $kept), 'its own rule');
        self::assertSame(['password' => ['رمز را دوباره وارد کنید.']], self::refused($form, ['host' => 'elsewhere.example'], $kept), "its field's: the kept one goes nowhere new");
    }

    /**
     * What the form refused, field by field.
     *
     * @param array<string, mixed> $input
     * @param \Closure(Field<mixed>): mixed $kept
     * @return array<string, list<string>>
     */
    private static function refused(Form $form, array $input, \Closure $kept): array
    {
        try {
            $form->check($input, $kept);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        self::fail('taken');
    }
}
