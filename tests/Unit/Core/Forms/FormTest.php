<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Forms;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Amount;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\EmailAddress;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Numbers;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Fields\Url;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Support\Validation;
use PHPUnit\Framework\TestCase;

/**
 * The one field engine every settings screen and every driver's form stands on: how each kind of field reads a form and
 * what is kept, the one wording of its refusals, a form refused as a whole with every refusal at once — saved whole or
 * in part —, a field read only while the one that shows it holds a value it names, and a kept secret that goes nowhere
 * but where it was given.
 */
final class FormTest extends TestCase
{
    public function testASwitchIsABooleanOrRefusedNeverOff(): void
    {
        $toggle = new Toggle('enabled', 'bot.enabled', default: true);

        self::assertTrue($toggle->read(['enabled' => '1'], null));
        self::assertFalse($toggle->read(['enabled' => false], null));
        self::assertSame([Validation::NOT_A_SWITCH, Validation::NOT_A_SWITCH], [self::refusal($toggle, ['enabled' => 'maybe']), self::refusal($toggle, [])], 'garbage and a missing switch are both refused');
        self::assertSame([false, true, true], [$toggle->cast(false), $toggle->cast(null), $toggle->cast('garbage')], 'what is kept, else the default');
    }

    public function testANumberTakesAnyDigitsWithinItsBoundsAndSaysThem(): void
    {
        $days = new Number('days', 'reminders.days', default: 3, label: 'تعداد روز', min: 1, max: 30);
        $timeout = new Number('timeout', 'HTTP_TIMEOUT', default: 30, label: 'تایم‌اوت', min: 5, max: 120, unit: 'ثانیه');

        self::assertSame([7, 7], [$days->read(['days' => '۷'], null), $days->read(['days' => 7], null)]);
        $least = new Number('least', 'bot.topup_min', default: 10_000, label: 'حداقل شارژ', min: 1_000, max: 500_000_000);
        self::assertSame([10_000, 10_000], [$least->read(['least' => '10,000'], null), $least->read(['least' => '۱۰٬۰۰۰'], null)], 'its thousands set apart, as an amount\'s');
        self::assertSame('حداقل شارژ باید عددی بین 1000 تا 500000000 باشد.', self::refusal($least, ['least' => '10,00']), 'a separator only between groups of three');
        self::assertSame('تعداد روز باید عددی بین 1 تا 30 باشد.', self::refusal($days, ['days' => 31]));
        self::assertSame('تعداد روز باید عددی بین 1 تا 30 باشد.', self::refusal($days, ['days' => 'two']));
        self::assertSame('تایم‌اوت باید عددی بین 5 تا 120 ثانیه باشد.', self::refusal($timeout, []));
        self::assertSame([12, 3, 3], [$days->cast('12'), $days->cast(45), $days->cast(null)], 'kept out of bounds reads as the default');
    }

    public function testAnAmountIsMoneyBetweenItsBounds(): void
    {
        $credit = new Amount('credit', 'agency.default_credit', default: '0', label: 'اعتبار اولیه', min: '0', max: '1000000');

        self::assertSame('250000.00', $credit->read(['credit' => '۲۵۰٬۰۰۰'], null));
        self::assertSame('اعتبار اولیه باید مبلغی بین ۰ تومان تا ۱٬۰۰۰٬۰۰۰ تومان باشد.', self::refusal($credit, ['credit' => '2000000']));
        self::assertSame('اعتبار اولیه باید مبلغی بین ۰ تومان تا ۱٬۰۰۰٬۰۰۰ تومان باشد.', self::refusal($credit, ['credit' => '5.5']), 'whole Toman, the only unit');
        self::assertSame(['5000.00', '0.00', '0.00'], [$credit->cast('5000.00'), $credit->cast('5.5'), $credit->cast('-1')], 'kept: whole Toman, else the default');
    }

    public function testNumbersAreReadFromAListOrAsTypedSortedWithoutRepeats(): void
    {
        $min = new Number('min', 'bot.topup_min', default: 10, label: 'حداقل شارژ', min: 1, max: 1000);
        $presets = new Numbers('presets', 'bot.topup_presets', default: [50], label: 'مبلغ‌های پیشنهادی', min: 1, max: 1000, most: 3, unit: 'تومان', atLeast: $min);

        self::assertSame([20, 50, 100], $presets->read(['presets' => '100، 20 ,, 50 50'], null), 'Persian commas, spaces, a blank between separators');
        $amounts = new Numbers('amounts', 'bot.topup_presets', default: [], label: 'مبلغ‌های پیشنهادی', min: 1, max: 1_000_000, most: 3);
        self::assertSame([50_000, 100_000], $amounts->read(['amounts' => '50,000, 100,000'], null), 'a comma between groups of three sets thousands apart: one number, not two');
        self::assertSame([50_000, 100_000], $amounts->read(['amounts' => ['50,000', '۱۰۰٬۰۰۰']], null), 'in a list sent as one too');
        self::assertSame([30, 40], $presets->read(['presets' => [40, '۳۰']], null));
        self::assertSame([], $presets->read(['presets' => ''], null), 'no buttons at all');
        self::assertSame('مبلغ‌های پیشنهادی باید عددهایی بین 1 تا 1000 تومان باشند.', self::refusal($presets, ['presets' => '20, abc']));
        self::assertSame('مبلغ‌های پیشنهادی حداکثر 3 عدد است.', self::refusal($presets, ['presets' => '1 2 3 4']));
        self::assertSame('هیچ‌کدام از مبلغ‌های پیشنهادی نمی‌تواند از حداقل شارژ کمتر باشد.', $presets->conflict(['min' => 30, 'presets' => [20, 50]]));
        self::assertNull($presets->conflict(['presets' => [20]]), 'nothing to compare with a minimum that was refused');
        self::assertSame([[10, 20], [50]], [$presets->cast([20, 10]), $presets->cast([10, 'x'])]);
    }

    public function testTextIsTrimmedRequiredWhenItMustBeAndOfItsShape(): void
    {
        $contact = new Text('contact', 'bot.support_contact', default: '', label: 'راه ارتباط', max: 5);
        $username = new Text('username', 'TELEGRAM_BOT_USERNAME', default: '', label: 'نام کاربری', max: 32, pattern: '/^[a-z_]{4,32}$/', mismatch: 'نام کاربری درست نیست.', normalize: static fn(string $value): string => ltrim($value, '@'));
        $name = new Text('name', 'APP_NAME', default: 'AmoBot', label: 'نام برنامه', max: 64, required: true);

        self::assertSame(['@amo', ''], [$contact->read(['contact' => ' @amo '], null), $contact->read([], null)]);
        self::assertSame(Validation::tooLong('راه ارتباط', 5), self::refusal($contact, ['contact' => '@amo_support']));
        self::assertSame('amo_bot', $username->read(['username' => '@amo_bot'], null));
        self::assertSame('نام کاربری درست نیست.', self::refusal($username, ['username' => 'a b']));
        self::assertSame('نام برنامه را وارد کنید.', self::refusal($name, ['name' => '  ']));
    }

    public function testAChoiceAndAUrlTakeOnlyWhatTheyMayBe(): void
    {
        $level = new Choice('level', 'LOG_LEVEL', default: 'info', options: ['debug', 'info'], refusal: 'سطح لاگ معتبر نیست.');
        $url = new Url('url', 'APP_URL', default: 'http://localhost', label: 'آدرس سایت', refusal: 'آدرس درست نیست.');

        self::assertSame('debug', $level->read(['level' => 'debug'], null));
        self::assertSame('سطح لاگ معتبر نیست.', self::refusal($level, ['level' => 'verbose']));
        self::assertSame('info', $level->cast('verbose'));
        self::assertSame('https://shop.example.com/store', $url->read(['url' => 'https://shop.example.com/store/'], null));
        self::assertSame('آدرس درست نیست.', self::refusal($url, ['url' => 'shop.example.com']));
        self::assertSame('آدرس سایت را وارد کنید.', self::refusal($url, ['url' => ' ']));
        self::assertSame('آدرس درست نیست.', self::refusal($url, ['url' => 'https://user:pass@shop.example.com']), 'no credentials in an address');
        self::assertSame('آدرس سایت نباید شامل پارامتر (?) یا قطعه (#) باشد.', self::refusal($url, ['url' => 'https://shop.example.com/?ref=1']));
        self::assertSame('آدرس سایت نباید شامل پارامتر (?) یا قطعه (#) باشد.', self::refusal($url, ['url' => 'https://shop.example.com/#top']));

        $panel = new Url('base_url', 'base_url', '', label: 'آدرس پنل', refusal: 'x', required: false, max: 40, credentials: 'رمز را در فیلد خودش بنویسید.', pages: '#/panel(/|$)#', pagesRefusal: 'آدرس پایه پنل را بنویسید.');
        self::assertSame('', $panel->read(['base_url' => ''], null), 'blank while not required');
        self::assertSame('رمز را در فیلد خودش بنویسید.', self::refusal($panel, ['base_url' => 'https://admin:pw@panel.example']), 'words of their own, where the form keeps the credentials apart');
        self::assertSame(Validation::tooLong('آدرس پنل', 40), self::refusal($panel, ['base_url' => 'https://panel.example/' . str_repeat('a', 20)]));
        self::assertSame('آدرس پایه پنل را بنویسید.', self::refusal($panel, ['base_url' => 'https://panel.example/panel']));
        self::assertSame('https://panel.example:2053/base', $panel->read(['base_url' => 'https://panel.example:2053/base/'], null));
    }

    public function testASecretIsNeverShownKeptWhenLeftBlankAndClearedOnRequest(): void
    {
        $secret = new Secret('token', 'CRON_TOKEN', default: '', pattern: '/^[a-z0-9]{8,}$/', mismatch: 'کوتاه است.');

        self::assertSame('keptsecret', $secret->read([], 'keptsecret'));
        self::assertSame('newsecret1', $secret->read(['token' => 'newsecret1'], 'keptsecret'));
        self::assertSame('', $secret->read(['clear_token' => true], 'keptsecret'));
        self::assertSame('کوتاه است.', self::refusal($secret, ['token' => 'short']));
        self::assertSame(['set' => true, 'hint' => '••••••cret'], $secret->present('keptsecret'));
        self::assertSame(['set' => false, 'hint' => ''], $secret->present(''));
    }

    public function testAFormIsRefusedAsAWholeWithEveryRefusalAtOnce(): void
    {
        $min = new Number('min', 'bot.topup_min', default: 10, label: 'حداقل شارژ', min: 1, max: 1000);
        $form = new Form('wallet', [
            new Toggle('on', 'bot.on', default: true),
            $min,
            new Numbers('presets', 'bot.presets', default: [], label: 'مبلغ‌های پیشنهادی', min: 1, max: 1000, most: 8, atLeast: $min),
        ]);
        $nothingKept = static fn(Field $field): mixed => null;

        try {
            $form->check(['on' => 'maybe', 'min' => 5000, 'presets' => '20'], $nothingKept);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['on', 'min'], array_keys($e->errors()), 'each field on its own; the presets have nothing to compare with');
        }

        try {
            $form->check(['on' => true, 'min' => 50, 'presets' => '20'], $nothingKept);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['presets'], array_keys($e->errors()));
        }

        self::assertSame(['bot.on' => true, 'bot.topup_min' => 50, 'bot.presets' => [60]], $form->check(['on' => '1', 'min' => '50', 'presets' => '60'], $nothingKept), 'by the keys they are kept under');
        self::assertSame(['on' => false, 'min' => 10, 'presets' => []], $form->present(static fn(Field $field): mixed => $field->key === 'bot.on' ? false : null));
        self::assertSame(['on' => false, 'min' => 10, 'presets' => []], $form->values(static fn(Field $field): mixed => $field->key === 'bot.on' ? 'false' : null), 'what is kept as each field reads it');
    }

    public function testASecretThatMustBeThereIsRefusedWhenNoneEndsUpKept(): void
    {
        $key = new Secret('key', 'RESEND_KEY', '', pattern: '/^re_\w+$/', mismatch: 'کلید درست نیست.', missing: 'کلید را وارد کنید.');

        self::assertSame('re_kept', $key->read([], 're_kept'), 'one kept is there');
        self::assertSame(['کلید را وارد کنید.', 'کلید را وارد کنید.'], [self::refusal($key, []), self::refusal($key, ['key' => '', 'clear_key' => true])], 'none kept, or the one kept cleared');
        self::assertTrue($key->required());
    }

    public function testAPartialSaveReadsWhatItSendsAndKeepsTheRest(): void
    {
        $min = new Number('min', 'bot.topup_min', default: 10, label: 'حداقل شارژ', min: 1, max: 1000);
        $form = new Form('wallet', [
            new Toggle('on', 'bot.on', default: true),
            $min,
            new Numbers('presets', 'bot.presets', default: [], label: 'مبلغ‌های پیشنهادی', min: 1, max: 1000, most: 8, atLeast: $min),
            new Secret('token', 'bot.token', '', pattern: '/^\w+$/', mismatch: 'x'),
        ]);
        $kept = static fn(Field $field): mixed => ['bot.on' => true, 'bot.topup_min' => 40, 'bot.presets' => [50], 'bot.token' => 'kept'][$field->key];

        self::assertSame([], $form->checkSent([], $kept), 'nothing sent, nothing changes');
        self::assertSame(['bot.presets' => [45, 90]], $form->checkSent(['presets' => '90 45'], $kept), 'the field sent alone');
        self::assertSame(['bot.token' => ''], $form->checkSent(['clear_token' => true], $kept), 'a secret named by its clear alone');
        self::assertSame(['bot.on' => false, 'bot.token' => 'kept'], $form->checkSent(['on' => false, 'token' => ''], $kept), 'a secret sent blank keeps the one kept');

        try {
            $form->checkSent(['presets' => '20', 'on' => 'maybe'], $kept);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['on', 'presets'], array_keys($e->errors()), 'every refusal at once — against the minimum kept, not sent');
        }

        try {
            $form->checkSent(['min' => 100], $kept);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['presets'], array_keys($e->errors()), 'judged on the whole form: the presets kept are now below the minimum sent');
        }
    }

    public function testAFieldShownByAnotherIsReadOnlyWhileThatOneHoldsAValueItNames(): void
    {
        $form = new Form('mail', [
            new Choice('transport', 'MAIL_TRANSPORT', 'none', ['none', 'smtp', 'native'], refusal: 'روش درست نیست.'),
            new Text('host', 'MAIL_HOST', '', label: 'سرور', max: 255, required: true, spec: new FieldSpec('سرور', when: ['transport' => ['smtp']])),
            new Toggle('auth', 'MAIL_AUTH', false, spec: new FieldSpec('ورود', when: ['transport' => ['smtp']])),
            new Text('username', 'MAIL_USERNAME', '', label: 'نام کاربری', max: 255, required: true, spec: new FieldSpec('نام کاربری', when: ['auth' => ['true']])),
            new EmailAddress('from', 'MAIL_FROM', '', label: 'فرستنده', required: true, spec: new FieldSpec('فرستنده', when: ['transport' => ['smtp', 'native']])),
        ]);
        $kept = static fn(Field $field): mixed => ['MAIL_HOST' => 'mail.example.com', 'MAIL_USERNAME' => 'kept'][$field->key] ?? null;

        self::assertSame(['MAIL_TRANSPORT' => 'none'], $form->check(['transport' => 'none', 'host' => 'bad host!'], $kept), 'what is not shown is neither read nor refused, and stays as it is kept');
        self::assertSame(['MAIL_TRANSPORT' => 'native', 'MAIL_FROM' => 'shop@example.com'], $form->check(['transport' => 'native', 'from' => 'Shop@Example.com'], $kept));
        self::assertSame(
            ['MAIL_TRANSPORT' => 'smtp', 'MAIL_HOST' => 'smtp.example.com', 'MAIL_AUTH' => true, 'MAIL_USERNAME' => 'me', 'MAIL_FROM' => 'shop@example.com'],
            $form->check(['transport' => 'smtp', 'host' => 'smtp.example.com', 'auth' => true, 'username' => 'me', 'from' => 'shop@example.com'], $kept),
            'a switch is "true" to the field it shows',
        );

        try {
            $form->check(['transport' => 'smtp', 'auth' => false], $kept);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['host', 'from'], array_keys($e->errors()), 'required once shown; the username hidden behind the switch');
        }

        try {
            $form->check(['transport' => 'carrier-pigeon', 'host' => ''], $kept);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['transport'], array_keys($e->errors()), 'a field refused shows none of the fields it names');
        }

        self::assertSame(['MAIL_HOST' => 'smtp.example.com'], $form->checkSent(['host' => 'smtp.example.com'], static fn(Field $field): mixed => $field->key === 'MAIL_TRANSPORT' ? 'smtp' : null), 'a partial save shows by what is kept');
        self::assertSame([], $form->checkSent(['host' => 'smtp.example.com'], $kept), 'hidden by what is kept: not read');
    }

    public function testAFieldIsShownOnlyByAFieldBeforeIt(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"host"');

        new Form('mail', [
            new Text('host', 'MAIL_HOST', '', label: 'سرور', max: 255, spec: new FieldSpec('سرور', when: ['transport' => ['smtp']])),
            new Choice('transport', 'MAIL_TRANSPORT', 'none', ['none', 'smtp'], refusal: 'x'),
        ]);
    }

    public function testAKeptSecretStaysWithTheFieldsItBelongsWith(): void
    {
        $moved = 'سرور عوض شده است؛ رمز را دوباره وارد کنید.';
        $form = new Form('smtp', [
            new Text('host', 'MAIL_HOST', '', label: 'سرور', max: 255, required: true),
            new Number('port', 'MAIL_PORT', 587, label: 'پورت', min: 1, max: 65535),
            new Text('from_name', 'MAIL_FROM_NAME', '', label: 'نام', max: 64),
            new Secret('password', 'MAIL_PASSWORD', '', pattern: '/^.+$/', mismatch: 'x', boundTo: ['host' => null, 'port' => null], moved: $moved),
        ]);
        $kept = static fn(Field $field): mixed => ['MAIL_HOST' => 'mail.example.com', 'MAIL_PORT' => '587', 'MAIL_PASSWORD' => 'kept p@ss'][$field->key] ?? null;
        $smtp = ['host' => 'mail.example.com', 'port' => '587', 'from_name' => 'امو'];

        self::assertSame('kept p@ss', $form->check(['port' => '۵۸۷'] + $smtp, $kept)['MAIL_PASSWORD'], 'the same server, however its port is written');
        foreach (['host' => 'smtp.elsewhere.example', 'port' => '465'] as $field => $elsewhere) {
            try {
                $form->check([$field => $elsewhere] + $smtp, $kept);
                self::fail("{$field} moved");
            } catch (ValidationException $e) {
                self::assertSame(['password' => [$moved]], $e->errors(), "{$field} moved: the kept one goes nowhere new");
            }
        }
        self::assertSame('a new one', $form->check(['host' => 'smtp.elsewhere.example', 'password' => 'a new one'] + $smtp, $kept)['MAIL_PASSWORD'], 'typed again');
        self::assertSame('kept p@ss', $form->check(['host' => 'smtp.elsewhere.example', 'password' => 'kept p@ss'] + $smtp, $kept)['MAIL_PASSWORD'], 'typed again as it was');
        self::assertSame('', $form->check(['host' => 'smtp.elsewhere.example', 'clear_password' => true] + $smtp, $kept)['MAIL_PASSWORD'], 'or cleared');
        self::assertSame('', $form->check(['host' => 'smtp.elsewhere.example'] + $smtp, static fn(Field $field): mixed => null)['MAIL_PASSWORD'], 'nothing kept, nothing to take elsewhere');

        try {
            $form->check(['port' => 'not a port'] + $smtp, $kept);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['port'], array_keys($e->errors()), 'a field refused moves nowhere: the secret is not asked again for it');
        }

        try {
            $form->checkSent(['host' => 'smtp.elsewhere.example'], $kept);
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame(['password' => [$moved]], $e->errors(), 'a partial save that moves the server and does not send the secret');
        }
    }

    /** What one driver keeps stands in for a field left blank while that driver stays chosen; another starts with nothing kept. */
    public function testADriversKeptValuesAreItsOwnAlone(): void
    {
        $form = new Form('captcha', [new Secret('secret_key', 'secret_key', '', pattern: '/^.+$/', mismatch: 'x', missing: 'کلید را وارد کنید.')]);
        $kept = ['secret_key' => 'kept-for-turnstile'];

        self::assertSame(['secret_key' => 'kept-for-turnstile'], $form->check([], Form::keptFor('turnstile', 'turnstile', $kept)), 'the same driver: kept');
        try {
            $form->check([], Form::keptFor('hcaptcha', 'turnstile', $kept));
            self::fail('another driver took a secret kept for this one');
        } catch (ValidationException $e) {
            self::assertSame(['secret_key' => ['کلید را وارد کنید.']], $e->errors(), 'another driver: nothing kept');
        }
    }

    public function testWhatIdentifiesABoundFieldIsWhatMustStay(): void
    {
        $form = new Form('telegram', [
            new Url('api_url', 'TELEGRAM_API_URL', 'https://api.telegram.org', label: 'آدرس API تلگرام', refusal: 'x'),
            new Secret('token', 'TELEGRAM_BOT_TOKEN', '', pattern: '/^.+$/', mismatch: 'x', boundTo: ['api_url' => Secret::byOrigin()], moved: 'توکن را دوباره وارد کنید.'),
        ]);
        $kept = static fn(Field $field): mixed => ['TELEGRAM_API_URL' => 'https://api.telegram.org', 'TELEGRAM_BOT_TOKEN' => '123:kept'][$field->key];

        self::assertSame('123:kept', $form->check(['api_url' => 'https://api.telegram.org/'], $kept)['TELEGRAM_BOT_TOKEN']);
        self::assertSame('123:kept', $form->check(['api_url' => 'https://api.telegram.org/mirror'], $kept)['TELEGRAM_BOT_TOKEN'], 'another path of the same origin');

        $this->expectException(ValidationException::class);
        $form->check(['api_url' => 'https://evil.example'], $kept);
    }

    public function testASecretIsBoundToFieldsOfItsForm(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"host"');

        new Form('smtp', [new Secret('password', 'MAIL_PASSWORD', '', pattern: '/^.+$/', mismatch: 'x', boundTo: ['host' => null], moved: 'x')]);
    }

    public function testASecretBoundToFieldsSaysWhatMovingThemMeans(): void
    {
        $this->expectException(\LogicException::class);

        new Secret('password', 'MAIL_PASSWORD', '', pattern: '/^.+$/', mismatch: 'x', boundTo: ['host' => null]);
    }

    /**
     * What the field says to the form, or null when it took it.
     *
     * @param Field<mixed> $field
     * @param array<string, mixed> $input
     */
    private static function refusal(Field $field, array $input): ?string
    {
        try {
            $field->read($input, null);
        } catch (FieldRefused $refused) {
            return $refused->getMessage();
        }

        return null;
    }
}
