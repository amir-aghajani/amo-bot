<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Captcha\CaptchaDriver;
use App\Core\Captcha\Verifier;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Ruled;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Fields\Url;
use App\Core\Forms\Form;
use App\Core\Http\Urls;
use App\Core\Mail\Mailer;
use App\Core\Sms\Sms;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Services\Bots;
use App\Modules\Store\Forms\Grants;
use App\Modules\Store\Forms\Origins;
use App\Modules\Store\Models\Website;
use App\Support\Input;
use App\Support\Persian;

/**
 * A shop's website as both panels set it («تنظیمات وب‌سایت»): the switch, its address and other allowed origins, the
 * store key, the ways to sign in, the captcha, reviews, and the door for the shop's admins (Http\StaffMiddleware).
 *
 * The row is made, switched off with a random key, the first time a panel asks (current()). Each settings card sends only
 * its own fields (update(): a partial save on the field engine), and a website's needs are judged on the row as the save
 * would leave it — switched on needs an address, Telegram sign-in a Client ID, switching email sign-up on a working mail
 * setup, switching sign-in by SMS on SMS going out for the site. A new key ends the old address at once. Every website it
 * hands out knows whether SMS goes out for it (Website::smsOpen()).
 */
final class Websites
{
    /** The most origins a website lists besides its own address. */
    public const ORIGINS_MAX = 10;

    /** The refusal of email sign-up switched on while no email goes out: the owner sets mail up in their panel. */
    public const MAIL_OFF = 'ارسال ایمیل در این نصب راه نیفتاده است؛ مالک فروشگاه باید آن را در تنظیمات پنل › ایمیل راه بیندازد.';

    /** The refusal of sign-in by SMS switched on while the installation sends none: the owner sets SMS up in their panel. */
    public const SMS_OFF = 'ارسال پیامک در این نصب راه نیفتاده است؛ مالک فروشگاه باید آن را در تنظیمات پنل › پیامک راه بیندازد.';

    /** The refusal of sign-in by SMS switched on on an agent's site the owner does not text codes for. */
    public const SMS_NOT_FOR_AGENTS = 'مالک فروشگاه پیامک را برای سایت نماینده‌ها باز نکرده است.';

    /** Whether SMS goes out for a website (present()'s `phone.sms`): it does; none is set up; not for an agent's site. */
    public const SMS_READY = 'ready';
    public const SMS_NOT_SET_UP = 'not_set_up';
    public const SMS_AGENTS_SHUT = 'not_for_agents';

    /** The captcha card's driver while the forms ask none. */
    public const NO_CAPTCHA = 'none';

    public const URL_NEEDED = 'آدرس وب‌سایت را وارد کنید؛ وب‌سایت بدون آدرس روشن نمی‌شود.';
    public const CLIENT_ID_NEEDED = 'برای ورود با تلگرام، Client ID را از BotFather وارد کنید.';

    private const NO_SUCH_CAPTCHA = 'تایید امنیتی را از گزینه‌ها انتخاب کنید.';

    /** What a Google client id is: the number of its project, a dash, and Google's own name for it. */
    private const GOOGLE_CLIENT_ID = '/^[0-9]+-[0-9a-z]+\.apps\.googleusercontent\.com$/';

    /** The longest address, Telegram Client ID and Google client id kept. */
    private const URL_MAX = 255;
    private const CLIENT_ID_MAX = 32;
    private const GOOGLE_CLIENT_ID_MAX = 191;

    public function __construct(
        private readonly Urls $urls,
        private readonly Bots $bots,
        private readonly Mailer $mailer,
        private readonly Verifier $captchas,
        private readonly Sms $sms,
    ) {}

    /** The current shop's website, made switched off the first time it is asked for. */
    public function current(): Website
    {
        // Of two first asks at once, one makes the row (one a shop: its unique bot_id) and the other finds it.
        $website = Website::query()->first() ?? Website::query()->createOrFirst([], ['key' => self::newKey()]);

        return $website->smsOpen($this->smsFor($website) === self::SMS_READY);
    }

    /**
     * The website the Store API answers under `$key`, looked up across shops: switched on and its bot running (an agent's
     * whose agency ended is closed with their panel). Null otherwise.
     */
    public function open(string $key): ?Website
    {
        $website = CurrentBot::everywhere(static fn(): ?Website => Website::query()->where('key', $key)->first());

        return $website !== null && $website->enabled && $website->shop()->status() === BotStatus::Active ? $website->smsOpen($this->smsFor($website) === self::SMS_READY) : null;
    }

    /**
     * Save what a panel's card sends: a field sent is changed, one left out is kept (Form::checkSent()); a secret sent
     * blank is kept, `clear_<secret>` empties it. The captcha (`captcha`: a driver or `none`, with its form's fields) is
     * checked by its driver's form, refused under `captcha.<field>`. Every refusal comes at once, before anything is kept.
     *
     * @param array<string, mixed> $input any of {enabled, url, origins, telegram_login, telegram_client_id, telegram_client_secret, clear_telegram_client_secret, email_signup, phone_login, google_client_id, captcha, reviews_enabled, staff_enabled, staff_strong_sign_in, staff_grants}
     * @throws ValidationException
     */
    public function update(Website $website, array $input): Website
    {
        $errors = [];
        $values = [];
        try {
            $values = $this->form($website)->checkSent($input, static fn(Field $field): mixed => $website->getAttribute($field->key));
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        $captcha = null;
        if (array_key_exists('captcha', $input)) {
            try {
                $captcha = $this->captcha($website, $input['captcha']);
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }
        ValidationException::ifAny($errors);

        foreach ($values as $key => $value) {
            // The secret only when it changes: it is encrypted afresh, so an unchanged one would be rewritten for nothing.
            if ($key === 'telegram_client_secret' && $value === (string) $website->telegram_client_secret) {
                continue;
            }
            $website->setAttribute($key, $value === '' || $value === [] ? null : $value);
        }
        if ($captcha !== null) {
            [$driver, $config] = $captcha;
            $website->forceFill(['captcha_driver' => $driver, 'captcha_config' => $config === [] ? null : $config]);
        }
        $website->save();

        return $website;
    }

    /** A new store key: the old base address stops working at once. */
    public function rotateKey(Website $website): Website
    {
        $website->forceFill(['key' => self::newKey()])->save();

        return $website;
    }

    /**
     * The website as its panel shows it, never a secret (only whether one is kept). `base_url` is what the site's
     * developer is handed, `telegram.bot_id` a hint beside the Client ID, `email.mail_ready` whether mail goes out,
     * `phone.sms` whether SMS goes out for this site (smsFor()), and `captcha` every driver described with each one's
     * values as kept (else defaults).
     *
     * @return array{enabled: bool, key: string, base_url: string, url: string|null, origins: list<string>, telegram: array{enabled: bool, client_id: string|null, bot_id: int|null, has_secret: bool}, email: array{enabled: bool, mail_ready: bool}, phone: array{enabled: bool, sms: string}, google: array{client_id: string|null}, captcha: array{driver: string, drivers: list<array<string, mixed>>, values: object}, reviews: array{enabled: bool}, staff: array{enabled: bool, strong_sign_in: bool, grants: list<string>}}
     */
    public function present(Website $website): array
    {
        $kept = $website->captchaConfig();
        $values = [];
        foreach ($this->captchas->drivers() as $driver) {
            $mine = $driver->key() === $website->captchaDriver();
            $values[$driver->key()] = (object) $driver->describe()->form->present(static fn(Field $field): mixed => $mine ? $kept[$field->key] ?? null : null);
        }

        return [
            'enabled' => $website->enabled,
            'key' => $website->key,
            'base_url' => $this->urls->store($website->key),
            'url' => $website->url,
            'origins' => $website->origins ?? [],
            'telegram' => [
                'enabled' => $website->telegram_login,
                'client_id' => $website->telegram_client_id,
                'bot_id' => $this->bots->telegramId($website->shop()),
                'has_secret' => $website->hasTelegramSecret(),
            ],
            'email' => ['enabled' => $website->email_signup, 'mail_ready' => $this->mailer->ready()],
            'phone' => ['enabled' => $website->phone_login, 'sms' => $this->smsFor($website)],
            'google' => ['client_id' => $website->google_client_id],
            'captcha' => [
                'driver' => $website->captchaDriver() ?? self::NO_CAPTCHA,
                'drivers' => array_map(static fn(CaptchaDriver $driver): array => $driver->describe()->toArray(), $this->captchas->drivers()),
                'values' => (object) $values,
            ],
            'reviews' => ['enabled' => $website->reviews_enabled],
            'staff' => [
                'enabled' => $website->staff_enabled,
                'strong_sign_in' => $website->staff_strong_sign_in,
                'grants' => $website->staffGrants(),
            ],
        ];
    }

    /**
     * The website's fields and the rules between them (Ruled): switched on needs an address, Telegram sign-in a Client ID,
     * switching email sign-up on (not keeping it on) needs mail going out, and switching sign-in by SMS on SMS going out
     * for the site.
     */
    private function form(Website $website): Form
    {
        return new Form('website', [
            new Toggle('enabled', 'enabled', false),
            // The site's address: its origin is the first one allowed to call the Store API from a browser.
            new Ruled(
                new Url('url', 'url', '', label: 'آدرس وب‌سایت', refusal: 'آدرس وب‌سایت باید با http:// یا https:// شروع شود و شامل نام دامنه باشد، بدون نام کاربری و رمز.', required: false, max: self::URL_MAX),
                static fn(array $values): ?string => ($values['enabled'] ?? false) === true && $values['url'] === '' ? self::URL_NEEDED : null,
            ),
            new Origins('origins', 'origins', self::ORIGINS_MAX),
            new Toggle('telegram_login', 'telegram_login', false),
            new Ruled(
                new Text('telegram_client_id', 'telegram_client_id', '', label: 'Client ID', max: self::CLIENT_ID_MAX, pattern: '/^\d+$/', mismatch: 'Client ID باید فقط عدد باشد.', normalize: Persian::latinDigits(...)),
                static fn(array $values): ?string => ($values['telegram_login'] ?? false) === true && $values['telegram_client_id'] === '' ? self::CLIENT_ID_NEEDED : null,
            ),
            // It goes to Telegram as HTTP Basic: printable Latin characters, as @BotFather shows it.
            new Secret('telegram_client_secret', 'telegram_client_secret', '', pattern: '/^[\x21-\x7e]{1,255}$/', mismatch: 'Client Secret را همان‌طور که BotFather نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.'),
            // Only switching it on asks for mail: one already on is not refused because mail stopped going out since.
            new Ruled(new Toggle('email_signup', 'email_signup', false), fn(array $values): ?string => $values['email_signup'] === true && !$website->email_signup && !$this->mailer->ready() ? self::MAIL_OFF : null),
            // Only switching it on asks for SMS, as email sign-up asks for mail.
            new Ruled(new Toggle('phone_login', 'phone_login', false), fn(array $values): ?string => $values['phone_login'] === true && !$website->phone_login ? $this->smsRefusal($website) : null),
            new Text('google_client_id', 'google_client_id', '', label: 'Client ID گوگل', max: self::GOOGLE_CLIENT_ID_MAX, pattern: self::GOOGLE_CLIENT_ID, mismatch: 'Client ID گوگل را همان‌طور که Google Cloud نشان می‌دهد کپی کنید؛ مثل 1234567890-abc123.apps.googleusercontent.com.'),
            new Toggle('reviews_enabled', 'reviews_enabled', false),
            new Toggle('staff_enabled', 'staff_enabled', false),
            new Toggle('staff_strong_sign_in', 'staff_strong_sign_in', true),
            new Grants('staff_grants', 'staff_grants'),
        ]);
    }

    /**
     * The captcha the card sends — `driver` (NO_CAPTCHA: none) and that driver's fields — checked by the driver's form;
     * a secret left blank keeps the one kept while the driver stays. Answers the driver's key and settings; null for none.
     *
     * @return array{string|null, array<string, mixed>}
     * @throws ValidationException under `captcha.<field>`
     */
    private function captcha(Website $website, mixed $sent): array
    {
        $fields = is_array($sent) ? $sent : [];
        $key = Input::text($fields, 'driver');
        if ($key === self::NO_CAPTCHA) {
            return [null, []];
        }
        $driver = $this->captchas->driver($key) ?? throw ValidationException::on('captcha.driver', self::NO_SUCH_CAPTCHA);

        try {
            return [$key, $driver->describe()->form->check($fields, Form::keptFor($key, $website->captchaDriver(), $website->captchaConfig()))];
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors["captcha.{$field}"] = $messages;
            }

            throw new ValidationException($errors);
        }
    }

    /**
     * Whether SMS goes out for the website's sign-in: SMS_READY — the installation sends it and the site is the main
     * shop's, or the owner lets agents' sites text too (they pay for every code); SMS_NOT_SET_UP — the main shop's, and no
     * SMS is set up; SMS_AGENTS_SHUT — an agent's, which the owner does not text for (set up or not: the agent can do
     * nothing about either).
     */
    private function smsFor(Website $website): string
    {
        if ($website->shop()->isMain()) {
            return $this->sms->available() ? self::SMS_READY : self::SMS_NOT_SET_UP;
        }

        return $this->sms->forAgents() ? self::SMS_READY : self::SMS_AGENTS_SHUT;
    }

    /** Why sign-in by SMS cannot be switched on for the website; null while it can. */
    private function smsRefusal(Website $website): ?string
    {
        return match ($this->smsFor($website)) {
            self::SMS_NOT_SET_UP => self::SMS_OFF,
            self::SMS_AGENTS_SHUT => self::SMS_NOT_FOR_AGENTS,
            default => null,
        };
    }

    /** A store key: 24 lower-case hex characters, 96 random bits. */
    private static function newKey(): string
    {
        return bin2hex(random_bytes(12));
    }
}
