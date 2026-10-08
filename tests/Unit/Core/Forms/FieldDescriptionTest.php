<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Forms;

use App\Core\Forms\Fields\Amount;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\EmailAddress;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Numbers;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Fields\Url;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Core\Forms\Form;
use PHPUnit\Framework\TestCase;

/**
 * A field described to a generic form (Field::describe()): what it holds, its words, where it is shown, its bounds and
 * its default — never a secret's —, the same shape for every kind; and a field without a spec is no field a form draws.
 */
final class FieldDescriptionTest extends TestCase
{
    public function testATextSaysItsWordsWhetherItIsRequiredAndItsDefault(): void
    {
        $host = new Text('host', 'MAIL_HOST', 'localhost', label: 'سرور SMTP', max: 255, required: true, spec: new FieldSpec('سرور', hint: 'بدون http://', placeholder: 'mail.example.com', ltr: true));

        self::assertEquals([
            'name' => 'host',
            'type' => 'text',
            'label' => 'سرور',
            'hint' => 'بدون http://',
            'placeholder' => 'mail.example.com',
            'required' => true,
            'secret' => false,
            'bound_to' => [],
            'moved' => null,
            'advanced' => false,
            'options' => [],
            'when' => (object) [],
            'unit' => null,
            'min' => null,
            'max' => null,
            'ltr' => true,
            'default' => 'localhost',
        ], $host->describe());
        self::assertSame('{}', json_encode($host->describe()['when']), 'no condition is an empty object, as the API describes it');
    }

    public function testEveryKindSaysWhatItHolds(): void
    {
        $spec = new FieldSpec('x');
        $kinds = [
            new Text('a', 'A', '', label: 'x', max: 9, spec: $spec),
            new Text('b', 'B', '', label: 'x', max: 9, type: FieldType::Path, spec: $spec),
            new Text('c', 'C', '', label: 'x', max: 900, type: FieldType::Textarea, spec: $spec),
            new Number('d', 'D', 1, label: 'x', min: 1, max: 9, spec: $spec),
            new Amount('e', 'E', '0', label: 'x', min: '0', max: '100', spec: $spec),
            new Numbers('f', 'F', [], label: 'x', min: 1, max: 9, most: 3, spec: $spec),
            new Toggle('g', 'G', true, spec: $spec),
            new Choice('h', 'H', 'on', ['on'], refusal: 'x', spec: new FieldSpec('x', options: ['on' => 'روشن'])),
            new Secret('i', 'I', '', pattern: '/^.+$/', mismatch: 'x', spec: $spec),
            new EmailAddress('j', 'J', '', label: 'x', spec: $spec),
            new Url('k', 'K', 'https://example.com', label: 'x', refusal: 'x', spec: $spec),
        ];

        self::assertSame(
            ['text', 'path', 'textarea', 'number', 'amount', 'list', 'toggle', 'choice', 'secret', 'email', 'url'],
            array_column((new Form('kinds', $kinds))->describe(), 'type'),
        );
        self::assertSame([false, false, false, true, true, false, true, true, false, false, true], array_column((new Form('kinds', $kinds))->describe(), 'required'), 'what a form refuses left empty');
    }

    public function testANumberSaysItsBoundsAndItsUnit(): void
    {
        $timeout = new Number('timeout', 'HTTP_TIMEOUT', 30, label: 'تایم‌اوت', min: 5, max: 120, unit: 'ثانیه', spec: new FieldSpec('تایم‌اوت'));
        $port = new Number('port', 'MAIL_PORT', 587, label: 'پورت', min: 1, max: 65535, spec: new FieldSpec('پورت', unit: 'شماره'));
        $presets = new Numbers('presets', 'P', [50, 100], label: 'مبلغ‌ها', min: 1, max: 1000, most: 3, unit: 'تومان', spec: new FieldSpec('مبلغ‌ها'));
        $credit = new Amount('credit', 'C', '0', label: 'اعتبار', min: '0', max: '1000000', spec: new FieldSpec('اعتبار', unit: 'تومان'));

        self::assertSame([5, 120, 'ثانیه', 30], [$timeout->describe()['min'], $timeout->describe()['max'], $timeout->describe()['unit'], $timeout->describe()['default']], "the number's own unit, when its spec names none");
        self::assertSame('شماره', $port->describe()['unit'], "the spec's unit first");
        self::assertSame([1, 1000, 'تومان', [50, 100]], [$presets->describe()['min'], $presets->describe()['max'], $presets->describe()['unit'], $presets->describe()['default']], "each of a list's numbers");
        self::assertSame([0.0, 1000000.0, '0.00'], [$credit->describe()['min'], $credit->describe()['max'], $credit->describe()['default']]);
    }

    public function testAChoiceWordsEachOfItsValuesInItsOrder(): void
    {
        $encryption = new Choice('encryption', 'MAIL_ENCRYPTION', 'tls', ['tls', 'ssl', 'none'], refusal: 'x', spec: new FieldSpec('رمزنگاری', options: ['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => 'بدون رمزنگاری']));

        self::assertSame([['value' => 'tls', 'label' => 'STARTTLS'], ['value' => 'ssl', 'label' => 'SSL'], ['value' => 'none', 'label' => 'بدون رمزنگاری']], $encryption->describe()['options']);

        $unworded = new Choice('encryption', 'MAIL_ENCRYPTION', 'tls', ['tls', 'ssl'], refusal: 'x', spec: new FieldSpec('رمزنگاری', options: ['tls' => 'STARTTLS']));
        $this->expectException(\LogicException::class);
        $unworded->describe();
    }

    public function testASecretSaysItIsOneWhatItBelongsWithAndNeverItsValue(): void
    {
        $password = new Secret('password', 'MAIL_PASSWORD', 'stored-by-default', pattern: '/^.+$/', mismatch: 'x', missing: 'رمز را وارد کنید.', boundTo: ['host' => null, 'port' => null], moved: 'سرور عوض شد؛ رمز را دوباره وارد کنید.', spec: new FieldSpec('رمز عبور'));
        $token = new Secret('token', 'TOKEN', '', pattern: '/^.+$/', mismatch: 'x', spec: new FieldSpec('توکن'));

        $described = $password->describe();
        self::assertSame(['secret', true, ['host', 'port'], 'سرور عوض شد؛ رمز را دوباره وارد کنید.', null, true], [$described['type'], $described['secret'], $described['bound_to'], $described['moved'], $described['default'], $described['required']]);
        self::assertStringNotContainsString('stored-by-default', (string) json_encode($described));
        self::assertSame([[], null, false], [$token->describe()['bound_to'], $token->describe()['moved'], $token->describe()['required']], 'bound to nothing, it may be left without one');
    }

    public function testAFieldSaysWhereItIsShownAndWhetherItIsAdvanced(): void
    {
        $socket = new Text('socket', 'DB_SOCKET', '', label: 'سوکت', max: 255, type: FieldType::Path, spec: new FieldSpec('سوکت', advanced: true, when: ['driver' => ['mysql']]));

        self::assertTrue($socket->describe()['advanced']);
        self::assertSame('{"driver":["mysql"]}', json_encode($socket->describe()['when']));
    }

    public function testAFieldWithoutASpecIsNoneAFormDraws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"days"');

        (new Number('days', 'reminders.days', 3, label: 'تعداد روز', min: 1, max: 30))->describe();
    }

    public function testATextHoldsTextAPathOrLinesAlone(): void
    {
        $this->expectException(\LogicException::class);

        new Text('port', 'P', '', label: 'x', max: 5, type: FieldType::Number);
    }
}
