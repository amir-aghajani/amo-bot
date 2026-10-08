<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Captcha\CaptchaDriver;
use App\Core\Captcha\Verifier;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Fields\Url;
use App\Core\Forms\Form;
use App\Core\Http\Urls;
use App\Core\Mail\Mailer;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Services\Bots;
use App\Modules\Store\Forms\Grants;
use App\Modules\Store\Forms\Origins;
use App\Modules\Store\Forms\Ruled;
use App\Modules\Store\Models\Website;
use App\Support\Input;
use App\Support\Persian;

/**
 * A shop's website as its panel sets it («وب‌سایت», both panels — an agent's shop has one too): the switch, the site's
 * address and the other origins a browser may call the API from, the store key — the base address the site's developer
 * is handed —, Telegram sign-in with the Client ID and secret @BotFather shows for the site, email sign-up (it takes the
 * installation's mail: not switched on while no email goes out), Google sign-in with the site's OAuth client id, the
 * captcha its forms ask — a driver of Core\Captcha (Cloudflare Turnstile, ALTCHA) and its form, or none —, whether it
 * shows its customers' reviews and takes new ones, and the door it opens to the shop's admins (Http\StaffMiddleware):
 * whether it lets them in, whether it asks them a strong sign-in, what it grants them beyond the shop's daily work. Each of the panel's cards changes its own fields and leaves the rest
 * as they are kept. The row is made, switched off with a key of its own, the first time the panel asks; a new key ends
 * the old address at once.
 */
final class Websites
{
    /** The most origins a website lists besides its own address. */
    public const ORIGINS_MAX = 10;

    /** Said of email sign-up switched on while no email goes out: the owner's to set up, in their panel's settings. */
    public const MAIL_OFF = 'ارسال ایمیل در این نصب راه نیفتاده است؛ مالک فروشگاه باید آن را در تنظیمات پنل › ایمیل راه بیندازد.';

    /** The captcha card's driver while the forms ask none. */
    public const NO_CAPTCHA = 'none';

    public const URL_NEEDED = 'آدرس وب‌سایت را وارد کنید؛ وب‌سایت بدون آدرس روشن نمی‌شود.';
    public const CLIENT_ID_NEEDED = 'برای ورود با تلگرام، Client ID را از BotFather وارد کنید.';

    private const NO_SUCH_CAPTCHA = 'تایید امنیتی را از گزینه‌ها انتخاب کنید.';

    /** What a Google client id is: the number of its project, a dash, and Google's own name for it. */
    private const GOOGLE_CLIENT_ID = '/^[0-9]+-[0-9a-z]+\.apps\.googleusercontent\.com$/';

    /** The longest address, Client ID and Client Secret kept. */
    private const URL_MAX = 255;
    private const CLIENT_ID_MAX = 32;
    private const GOOGLE_CLIENT_ID_MAX = 191;

    public function __construct(
        private readonly Urls $urls,
        private readonly Bots $bots,
        private readonly Mailer $mailer,
        private readonly Verifier $captchas,
    ) {}

    /** The current shop's website — made, switched off, the first time it is asked for. */
    public function current(): Website
    {
        // Of two first asks at once, one makes the row (one a shop: its unique bot_id) and the other finds it.
        return Website::query()->first() ?? Website::query()->createOrFirst([], ['key' => self::newKey()]);
    }

    /**
     * The website the Store API answers under this key, whichever shop is being worked in: switched on, its shop's bot
     * running (an agent's whose agency ended is closed with it, as their panel is) — null for any other key.
     */
    public function open(string $key): ?Website
    {
        $website = CurrentBot::everywhere(static fn(): ?Website => Website::query()->where('key', $key)->first());

        return $website !== null && $website->enabled && $website->shop()->status() === BotStatus::Active ? $website : null;
    }

    /**
     * A change of the website as its panel makes it — each card sends its own fields: a field sent is changed, one left
     * out stays as it is kept (Form::checkSent()); a secret sent blank is kept too, and `clear_<secret>` empties it. What
     * is sent is checked as it is typed, and what a website needs is asked of the website as it would stand after the
     * change — switched on, an address: the one sent, or the one kept —; the captcha (`captcha`: a driver, or `none`, and
     * its form's fields) by its driver's form, a secret left blank keeping the one kept while the driver and the fields
     * it belongs with stay (refused under `captcha.<field>`); every refusal at once, under its field, before anything is
     * kept. Nothing sent, nothing changes.
     *
     * @param array<string, mixed> $input any of {enabled, url, origins, telegram_login, telegram_client_id, telegram_client_secret, clear_telegram_client_secret, email_signup, google_client_id, captcha, reviews_enabled, staff_enabled, staff_strong_sign_in, staff_grants}
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
            // A secret only when it changes: encrypted afresh, the one kept would be written again for nothing.
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
     * The website as its panel shows it — never a secret, only whether one is kept. `base_url` is what the site's
     * developer is handed; `telegram.bot_id` the shop's bot's own id, a hint beside the Client ID @BotFather shows;
     * `email.mail_ready` whether the installation's email goes out (email sign-up answers nobody without it); `captcha`
     * the driver its forms ask (`none`), every captcha described — its form — and each one's fields as the website keeps
     * them, else their defaults (a secret as whether one is kept, Form::present()); `reviews` whether it shows its
     * customers' reviews and takes new ones; `staff` whether the shop's admins work it from the website, the strong
     * sign-in asked of them and what it grants them.
     *
     * @return array{enabled: bool, key: string, base_url: string, url: string|null, origins: list<string>, telegram: array{enabled: bool, client_id: string|null, bot_id: int|null, has_secret: bool}, email: array{enabled: bool, mail_ready: bool}, google: array{client_id: string|null}, captcha: array{driver: string, drivers: list<array<string, mixed>>, values: object}, reviews: array{enabled: bool}, staff: array{enabled: bool, strong_sign_in: bool, grants: list<string>}}
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
     * The website's own fields, each its rule — and what one needs of another: the website on, its address; Telegram
     * sign-in on, its Client ID; email sign-up switched on (not kept on), the installation's email going out.
     */
    private function form(Website $website): Form
    {
        return new Form('website', [
            new Toggle('enabled', 'enabled', false),
            // The site's own address: its origin is the first a browser may call the Store API from.
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
            // Only switching it on asks for the email: kept on, it is not turned down because the email stopped going out since.
            new Ruled(new Toggle('email_signup', 'email_signup', false), fn(array $values): ?string => $values['email_signup'] === true && !$website->email_signup && !$this->mailer->ready() ? self::MAIL_OFF : null),
            new Text('google_client_id', 'google_client_id', '', label: 'Client ID گوگل', max: self::GOOGLE_CLIENT_ID_MAX, pattern: self::GOOGLE_CLIENT_ID, mismatch: 'Client ID گوگل را همان‌طور که Google Cloud نشان می‌دهد کپی کنید؛ مثل 1234567890-abc123.apps.googleusercontent.com.'),
            new Toggle('reviews_enabled', 'reviews_enabled', false),
            new Toggle('staff_enabled', 'staff_enabled', false),
            new Toggle('staff_strong_sign_in', 'staff_strong_sign_in', true),
            new Grants('staff_grants', 'staff_grants'),
        ]);
    }

    /**
     * The captcha the card sends — its `driver` (NO_CAPTCHA: none asked) and the driver's form's fields —, checked by
     * the driver's own form: what it keeps for the website stands in for a secret left blank while the driver stays.
     * The driver's key and its settings; null and nothing for none.
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

    /** A store key: 24 lower-case hex characters, 96 random bits. */
    private static function newKey(): string
    {
        return bin2hex(random_bytes(12));
    }
}
