<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Core\Database\Drivers\ProbeFailedException;
use App\Core\Database\Drivers\ProbeResult;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Fields\Url;
use App\Core\Forms\Form;
use App\Core\Http\Urls;
use App\Core\Mail\MailBody;
use App\Core\Mail\Mailer;
use App\Core\Mail\MailFailedException;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Settings\Exceptions\MailNotReadyException;
use App\Modules\Settings\Exceptions\UnknownGroupException;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\Api\BotTokenException;
use App\Support\Email;
use App\Support\Input;
use App\Support\Timezones;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The panel's own configuration — the application, the database, the main bot, the shop's email, the rest — as
 * config.php holds it, for the owner's settings screen and the installer: never the database's settings table, since
 * these are needed before a database answers. The groups `app`, `telegram` and `advanced` are declared here on the field
 * engine (a setting the file lacks shows what the app runs with: config/*.php's value); `database` is DatabaseSettings'
 * — its fields are its driver's, and nothing is written before a database answers to them —, `mail` MailSettings' — a
 * mail driver's form and who the emails come from. A secret is never sent back: the screen gets whether one is kept and
 * a hint, a blank one keeps it and `clear_<field>` empties it — and a kept one goes nowhere but where it was given (the
 * main bot's token, to the Bot API's address it was saved with). Moving that address to another origin sends every
 * bot's token there — agents' too, which the screen never shows —, so the save that moves it asks the owner's current
 * password (AdminAccount::confirm()), as a change of their login does.
 */
final class ConfigSettings
{
    private const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error'];

    /** The bot's token left blank while the Bot API's address moved: no bot's token goes to another host unasked. */
    public const TOKEN_MOVED = 'آدرس API تلگرام عوض شده است؛ توکن ربات اصلی را دوباره وارد کنید: توکن ذخیره‌شده به آدرس دیگری فرستاده نمی‌شود.';

    public function __construct(
        private readonly ConfigFile $file,
        private readonly Config $config,
        private readonly DatabaseSettings $database,
        private readonly MailSettings $mail,
        private readonly BotApi $bot,
        private readonly Mailer $mailer,
        private readonly AdminAccount $owner,
        private readonly Urls $urls,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array<string, mixed> The screen: the file, every group, and what the screen needs to show them */
    public function present(): array
    {
        $file = $this->file->all();
        $groups = [];
        foreach ($this->groups() as $group) {
            $groups[$group->key] = $group->present(static fn(Field $field): mixed => $file[$field->key] ?? null);
        }
        $groups['database'] = $this->database->present();
        $groups['mail'] = $this->mail->present();

        return [
            'file' => [
                'path' => basename($this->file->path()),
                'exists' => $this->file->exists(),
                'writable' => $this->file->isWritable(),
            ],
            'groups' => $groups,
            'meta' => [
                'timezones' => Timezones::options($groups['app']['timezone']),
                'log_levels' => self::LOG_LEVELS,
                // The trigger address with its token masked, like the token itself — cronUrl() is the whole of it, asked for on purpose.
                'cron_url' => $groups['advanced']['cron_token']['set'] ? $this->urls->masked(Urls::CRON) : null,
                'webhook_url' => $this->urls->masked(Urls::TELEGRAM_WEBHOOK),
            ],
        ];
    }

    /**
     * One group of the screen, checked and written to config.php — the database's only once a database answers to it;
     * the main bot's, when it moves the Bot API's address to another origin, only with the owner's current password
     * (`current_password`, judged with the form's other refusals: a wrong one is a failed sign-in of theirs).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> The screen as it is now (present())
     * @throws ValidationException|UnknownGroupException
     * @throws TooManyAttemptsException 429: the owner's password tried too often, from this address or for the account
     */
    public function update(string $group, array $input, ServerRequestInterface $request): array
    {
        if ($group === 'database') {
            $this->saveDatabase($input);

            return $this->present();
        }
        if ($group === 'mail') {
            $this->write($this->mail->validate($input));

            return $this->present();
        }

        $errors = [];
        $settings = [];
        try {
            $settings = $this->check($this->group($group), $input);
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        if ($group === 'telegram' && $this->movesBotApi($input)) {
            $unproven = $this->owner->confirm($request, Input::password($input, 'current_password'));
            if ($unproven !== null) {
                $errors['current_password'][] = $unproven;
            }
        }
        ValidationException::ifAny($errors);
        $this->write($settings);

        return $this->present();
    }

    /**
     * The database the shop runs on — the installer's step, the screen's group —: checked by its driver's form, and
     * written only once a database answers to it.
     *
     * @param array<string, mixed> $input {driver?, …the driver's fields}
     * @throws ValidationException
     */
    public function saveDatabase(array $input): void
    {
        $settings = $this->database->validate($input);
        // Never write a database that does not answer: the panel would lock itself out.
        $this->assertDatabaseWorks($settings);
        $this->write($settings);
    }

    /**
     * The shop's name and public address alone (the installer's site step).
     *
     * @param array<string, mixed> $input {name, url}
     * @throws ValidationException
     */
    public function saveSite(array $input): void
    {
        $this->write($this->check($this->group('app')->only('name', 'url'), $input));
    }

    /**
     * The main bot's token, once Telegram says whose it is, with that bot's @username (the installer's site step).
     *
     * @return array{id: int, username: string, name: string}
     * @throws BotTokenException|ValidationException
     */
    public function saveBotToken(string $token): array
    {
        $bot = BotToken::identify($this->bot, $token);
        $this->write(['TELEGRAM_BOT_TOKEN' => trim($token), 'TELEGRAM_BOT_USERNAME' => $bot['username']]);

        return $bot;
    }

    /**
     * Whose a token is, as Telegram says (getMe) — the one typed, else the one kept. Also how the screen learns the
     * bot's @username. It is asked at the Bot API's address the shop runs with — the saved one: a token kept goes
     * nowhere else.
     *
     * @return array{id: int, username: string, name: string}
     * @throws BotTokenException|ValidationException
     */
    public function testTelegram(string $token): array
    {
        $token = trim($token) !== '' ? $token : $this->botToken()->cast($this->file->get('TELEGRAM_BOT_TOKEN'));
        if (trim($token) === '') {
            throw ValidationException::on('token', 'توکن ربات را وارد کنید.');
        }

        return BotToken::identify($this->bot, $token);
    }

    /**
     * Try a database configuration without saving it.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function testDatabase(array $input): ProbeResult
    {
        return $this->assertDatabaseWorks($this->database->validate($input));
    }

    /**
     * A short email to `$to` by the mail settings as config.php holds them — the saved ones, which a request reads as it
     * starts: whether the shop's email goes out, and if not, why.
     *
     * @throws ValidationException 422 on `to`: no address
     * @throws MailNotReadyException 422: no transport, or no address to send from, saved
     * @throws MailFailedException 502: the transport did not take it — its reason said
     */
    public function testMail(string $to): void
    {
        $problem = Email::problem($to, 'ایمیل گیرنده');
        if ($problem !== null) {
            throw ValidationException::on('to', $problem);
        }
        if (!$this->mailer->ready()) {
            throw new MailNotReadyException();
        }

        $shop = (string) $this->config->get('app.name', '');
        try {
            $this->mailer->send(MailBody::message((string) Email::of($to), "ایمیل تست {$shop}", $shop, 'ارسال ایمیل کار می‌کند', [
                "این ایمیل تست را پنل {$shop} فرستاد: کدهای ثبت‌نام و بازیابی رمز عبور وب‌سایت، و اعلان‌های فروشگاه به مشتری‌هایی که تلگرام ندارند یا ربات را بسته‌اند، از همین راه به آن‌ها می‌رسند.",
                'اگر آن را در پوشه هرزنامه (Spam) پیدا کردید، تنظیمات SPF و DKIM دامنه ایمیل فرستنده را در هاست بررسی کنید.',
            ]));
        } catch (MailFailedException $e) {
            throw $e->explained();
        }
    }

    /** The whole cron trigger address, for the screen's copy action; null while there is no token. */
    public function cronUrl(): ?string
    {
        $token = $this->cronToken()->cast($this->file->get('CRON_TOKEN'));

        return $token === '' ? null : $this->urls->cron($token);
    }

    /** @return list<Form> The groups declared here, a setting the file lacks reading as what the app runs with */
    private function groups(): array
    {
        return [
            new Form('app', [
                new Text('name', 'APP_NAME', (string) $this->config->get('app.name'), label: 'نام برنامه', max: 64, required: true),
                new Url('url', 'APP_URL', (string) $this->config->get('app.url'), label: 'آدرس سایت', refusal: 'آدرس سایت باید با http:// یا https:// شروع شود، مثل https://shop.example.com'),
                new Toggle('debug', 'APP_DEBUG', (bool) $this->config->get('app.debug')),
                new Choice('timezone', 'APP_TIMEZONE', (string) $this->config->get('app.timezone'), \DateTimeZone::listIdentifiers(), refusal: 'منطقه زمانی معتبر نیست.'),
            ]),
            new Form('telegram', [
                $this->botToken(),
                new Text(
                    'username',
                    'TELEGRAM_BOT_USERNAME',
                    (string) $this->config->get('telegram.username'),
                    label: 'نام کاربری ربات',
                    max: 32,
                    pattern: '/^[A-Za-z][A-Za-z0-9_]{3,31}$/',
                    mismatch: 'نام کاربری ربات فقط می‌تواند حروف انگلیسی، عدد و _ داشته باشد (بدون @).',
                    normalize: static fn(string $username): string => ltrim($username, '@'),
                ),
                $this->apiUrl(),
                new Number('poll_timeout', 'TELEGRAM_POLL_TIMEOUT', (int) $this->config->get('telegram.poll_timeout'), label: 'تایم‌اوت long polling', min: 1, max: 60, unit: 'ثانیه'),
                // The secret is the whole lock on the bot's door (whoever knows the address can post updates): long enough.
                new Secret('webhook_secret', 'TELEGRAM_WEBHOOK_SECRET', (string) $this->config->get('telegram.webhook_secret'), pattern: '/^[A-Za-z0-9_-]{16,256}$/', mismatch: 'رمز Webhook باید دست‌کم 16 کاراکتر باشد و فقط حروف انگلیسی، عدد، _ و - داشته باشد.'),
            ]),
            new Form('advanced', [
                new Choice('log_level', 'LOG_LEVEL', (string) $this->config->get('logging.level'), self::LOG_LEVELS, refusal: 'سطح لاگ معتبر نیست.'),
                new Number('session_lifetime', 'SESSION_LIFETIME', (int) $this->config->get('session.lifetime'), label: 'طول Session', min: 5, max: 43200, unit: 'دقیقه'),
                new Toggle('session_secure_cookie', 'SESSION_SECURE_COOKIE', (bool) $this->config->get('session.cookie.secure')),
                new Number('http_timeout', 'OUTGOING_HTTP_TIMEOUT', (int) $this->config->get('app.http_timeout'), label: 'تایم‌اوت درخواست‌های خروجی', min: 5, max: 120, unit: 'ثانیه'),
                $this->cronToken(),
            ]),
        ];
    }

    /** @throws UnknownGroupException */
    private function group(string $key): Form
    {
        foreach ($this->groups() as $group) {
            if ($group->key === $key) {
                return $group;
            }
        }

        throw new UnknownGroupException();
    }

    /**
     * The main bot's token: shown as the bot's id and its last characters, and kept with the Bot API's address it was
     * saved for — every bot's token goes there (BotApi): moved to another host, it is typed again.
     */
    private function botToken(): Secret
    {
        return new Secret(
            'token',
            'TELEGRAM_BOT_TOKEN',
            (string) $this->config->get('telegram.token'),
            pattern: BotToken::PATTERN,
            mismatch: BotToken::MALFORMED,
            hint: static fn(string $token): string => BotToken::botId($token) . ':••••••••' . substr($token, -4),
            boundTo: ['api_url' => Secret::byOrigin()],
            moved: self::TOKEN_MOVED,
        );
    }

    /** The Bot API's address — every bot's token goes there, agents' too (BotApi). */
    private function apiUrl(): Url
    {
        return new Url('api_url', 'TELEGRAM_API_URL', (string) $this->config->get('telegram.api_url'), label: 'آدرس API تلگرام', refusal: 'آدرس API تلگرام باید یک URL کامل باشد (پیش‌فرض https://api.telegram.org).');
    }

    /**
     * Whether the save moves the Bot API's address to another origin — scheme, host or port (Secret::byOrigin()) — from
     * the one config.php holds: every bot's token goes there from then on.
     *
     * @param array<string, mixed> $input
     */
    private function movesBotApi(array $input): bool
    {
        $origin = Secret::byOrigin();
        $to = $origin(Input::text($input, 'api_url'));

        return $to !== null && $to !== $origin($this->apiUrl()->cast($this->file->get('TELEGRAM_API_URL')));
    }

    private function cronToken(): Secret
    {
        return new Secret('cron_token', 'CRON_TOKEN', (string) $this->config->get('shop.cron_token'), pattern: '/^[A-Za-z0-9_-]{16,128}$/', mismatch: 'توکن Cron باید دست‌کم 16 کاراکتر از حروف انگلیسی، عدد، _ و - باشد.');
    }

    /**
     * A group's form checked against what the file holds (a secret left blank keeps it): the settings to write.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function check(Form $group, array $input): array
    {
        $file = $this->file->all();

        return $group->check($input, static fn(Field $field): mixed => $file[$field->key] ?? null);
    }

    /**
     * @param array<string, mixed> $settings
     * @throws ValidationException
     */
    private function assertDatabaseWorks(array $settings): ProbeResult
    {
        try {
            return $this->database->probe($settings);
        } catch (ProbeFailedException $e) {
            throw new ValidationException(['connection' => [$e->getMessage()]], 'اتصال به دیتابیس برقرار نشد؛ چیزی ذخیره نشد.');
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @throws ValidationException
     */
    private function write(array $settings): void
    {
        try {
            $this->file->setMany(array_map(
                static fn(mixed $value): string|int|float|bool => is_scalar($value) ? $value : throw new \LogicException('config.php keeps text, numbers and switches.'),
                $settings,
            ));
        } catch (\RuntimeException $e) {
            $this->logger->error('{file} could not be written: {message}', ['file' => $this->file->path(), 'message' => $e->getMessage()]);

            throw ValidationException::on('file', ConfigFile::UNWRITABLE);
        }
    }
}
