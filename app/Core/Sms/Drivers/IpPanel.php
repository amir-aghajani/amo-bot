<?php

declare(strict_types=1);

namespace App\Core\Sms\Drivers;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Url;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Core\Sms\IpPanelSender;
use App\Core\Sms\SmsDriver;
use App\Core\Sms\SmsSender;
use App\Core\Sms\SmsSettingsException;
use App\Support\Persian;
use GuzzleHttp\ClientInterface;

/**
 * IPPanel (ippanel.com) through its Edge API: a code goes out as a pattern IPPanel approved, whose one variable the code
 * fills — the words around it are the pattern's, set up on IPPanel's panel. Its settings: the access key
 * (SMS_IPPANEL_API_KEY), one of the account's lines (SMS_IPPANEL_FROM), the pattern's code and its variable, and the
 * API's address; the key is bound to that address, so a kept one never goes to another.
 */
final class IpPanel implements SmsDriver
{
    /** IPPanel chosen without its key: no code could go. */
    public const KEY_MISSING = 'برای ارسال با IPPanel، کلید دسترسی آن را وارد کنید.';

    /** A blank key while the API's address moved to another server. */
    public const KEY_MOVED = 'آدرس API عوض شده است؛ کلید دسترسی IPPanel را دوباره وارد کنید: کلید ذخیره‌شده به آدرس دیگری فرستاده نمی‌شود.';

    /** The variable a pattern names its code by, unless the owner says otherwise. */
    public const VARIABLE = 'code';

    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return 'ippanel';
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'IPPanel (ای‌پی پنل)',
            description: 'سامانه پیامک IPPanel: کد ورود با یک الگوی (پترن) تاییدشده در پنل IPPanel فرستاده می‌شود؛ پیامک الگو از خط خدماتی می‌رود و به شماره‌هایی که پیامک تبلیغاتی را بسته‌اند هم می‌رسد.',
            form: new Form($this->key(), [
                new Secret(
                    'api_key',
                    'SMS_IPPANEL_API_KEY',
                    '',
                    pattern: '/^[A-Za-z0-9+\/=_.-]{16,512}$/',
                    mismatch: 'کلید دسترسی را همان‌طور که پنل IPPanel در «توسعه‌دهندگان ← کلیدهای دسترسی» نشان می‌دهد کپی کنید؛ بدون فاصله و بدون Bearer.',
                    missing: self::KEY_MISSING,
                    boundTo: ['api_url' => Secret::byOrigin()],
                    moved: self::KEY_MOVED,
                    spec: new FieldSpec('کلید دسترسی', hint: 'در پنل IPPanel، «توسعه‌دهندگان ← کلیدهای دسترسی» یک کلید بسازید.'),
                ),
                new Text(
                    'from',
                    'SMS_IPPANEL_FROM',
                    '',
                    label: 'شماره فرستنده',
                    max: 20,
                    required: true,
                    pattern: '/^\+[1-9][0-9]{6,14}$/',
                    mismatch: 'شماره فرستنده را مثل 3000505 یا +983000505 بنویسید؛ فقط عدد، با + یا بی آن.',
                    normalize: self::line(...),
                    spec: new FieldSpec('شماره فرستنده', hint: 'یکی از خط‌های همین حساب IPPanel؛ مثل 3000505 یا +983000505.', placeholder: '+983000505', ltr: true),
                ),
                new Text(
                    'pattern',
                    'SMS_IPPANEL_PATTERN',
                    '',
                    label: 'کد الگو',
                    max: 64,
                    required: true,
                    pattern: '/^[A-Za-z0-9_-]{2,64}$/',
                    mismatch: 'کد الگو را همان‌طور که پنل IPPanel کنار الگوی تاییدشده نشان می‌دهد کپی کنید؛ فقط حروف انگلیسی، عدد، _ و -.',
                    spec: new FieldSpec('کد الگو', hint: 'کد الگوی تاییدشده در پنل IPPanel؛ متن پیامک همان متن الگوست.', ltr: true),
                ),
                new Text(
                    'variable',
                    'SMS_IPPANEL_VARIABLE',
                    self::VARIABLE,
                    label: 'نام متغیر کد',
                    max: 40,
                    required: true,
                    pattern: '/^[A-Za-z][A-Za-z0-9_]{0,39}$/',
                    mismatch: 'نام متغیر را همان‌طور که در متن الگو آمده بنویسید، با حروف انگلیسی و بدون %؛ مثل code.',
                    normalize: static fn(string $name): string => trim($name, "% \t"),
                    spec: new FieldSpec('نام متغیر کد', hint: 'متغیری از الگو که کد ورود جای آن می‌نشیند؛ برای %code% در متن الگو، code.', placeholder: self::VARIABLE, ltr: true),
                ),
                new Url(
                    'api_url',
                    'SMS_IPPANEL_URL',
                    IpPanelSender::BASE_URL,
                    label: 'آدرس API',
                    refusal: 'آدرس API باید یک URL کامل باشد (پیش‌فرض ' . IpPanelSender::BASE_URL . ').',
                    max: 255,
                    spec: new FieldSpec('آدرس API', hint: 'فقط اگر IPPanel آدرس دیگری داده باشد؛ پیش‌فرض ' . IpPanelSender::BASE_URL . '.', advanced: true, ltr: true),
                ),
            ]),
            notes: [
                'الگو باید در پنل IPPanel تایید شده باشد و فقط یک متغیر، برای کد ورود، داشته باشد.',
                'شماره فرستنده باید یکی از خط‌های همان حساب IPPanel باشد.',
                'هزینه هر پیامک از اعتبار همین حساب IPPanel کم می‌شود.',
            ],
        );
    }

    public function sender(array $values): SmsSender
    {
        $key = is_string($values['api_key'] ?? null) ? trim($values['api_key']) : '';
        $from = is_string($values['from'] ?? null) ? self::line($values['from']) : '';
        $pattern = is_string($values['pattern'] ?? null) ? trim($values['pattern']) : '';
        $variable = is_string($values['variable'] ?? null) && trim($values['variable'], "% \t") !== '' ? trim($values['variable'], "% \t") : self::VARIABLE;
        $url = is_string($values['api_url'] ?? null) && $values['api_url'] !== '' ? $values['api_url'] : IpPanelSender::BASE_URL;

        foreach (['SMS_IPPANEL_API_KEY' => $key, 'SMS_IPPANEL_FROM' => $from, 'SMS_IPPANEL_PATTERN' => $pattern] as $setting => $value) {
            if ($value === '') {
                throw new SmsSettingsException("SMS_DRIVER ippanel has no {$setting}");
            }
        }

        return new IpPanelSender($this->http, $key, $from, $pattern, $variable, $url);
    }

    /**
     * A sender line as IPPanel takes it, in E.164: «3000505» and «۰۲۱…» are Iran's (+98…), «0098…» and «98…» already
     * name it, «+…» stays; spaces, dashes and brackets go. Blank stays blank.
     */
    public static function line(string $typed): string
    {
        $text = trim(Persian::latinDigits($typed));
        if ($text === '') {
            return '';
        }
        $plus = str_starts_with($text, '+');
        $digits = (string) preg_replace('/[\s\-().]+/u', '', ltrim($text, '+'));

        return match (true) {
            $plus => '+' . $digits,
            str_starts_with($digits, '00') => '+' . substr($digits, 2),
            str_starts_with($digits, '0') => '+98' . substr($digits, 1),
            str_starts_with($digits, '98') && strlen($digits) >= 9 => '+' . $digits,
            default => '+98' . $digits,
        };
    }
}
