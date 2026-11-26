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
use App\Core\Forms\Fields\FieldRefused;
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
use App\Core\Sms\Sms;
use App\Core\Sms\SmsCredit;
use App\Core\Sms\SmsFailedException;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Settings\Exceptions\MailNotReadyException;
use App\Modules\Settings\Exceptions\SmsCreditUnreadException;
use App\Modules\Settings\Exceptions\SmsNotReadyException;
use App\Modules\Settings\Exceptions\UnknownGroupException;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\Api\BotTokenException;
use App\Support\Email;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Timezones;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The owner's «تنظیمات پنل» and the installer's view of config.php — never the settings table, since these are needed
 * before a database answers. Groups `app`, `telegram` and `advanced` are declared here; `database` is
 * DatabaseSettings' (written only once a database answers), `mail` MailSettings', `sms` SmsSettings'. Moving the Bot API's address to
 * another origin would send every bot's token there — agents' too — so that save asks the owner's current password.
 * What hands over the main bot or the owner's seat is the owner's themselves alone (refuseOwnersOnly()): one of the main
 * shop's admins, working the bot's management with the owner's seat, saves the rest of these groups but not that. The
 * installer's own saves (saveSite(), saveBotToken(), saveDatabase()) come before any owner signs in and ask no one.
 */
final class ConfigSettings
{
    private const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error'];

    /** The main bot's token left blank while the Bot API's address moved. */
    public const TOKEN_MOVED = 'آدرس API تلگرام عوض شده است؛ توکن ربات اصلی را دوباره وارد کنید: توکن ذخیره‌شده به آدرس دیگری فرستاده نمی‌شود.';

    public function __construct(
        private readonly ConfigFile $file,
        private readonly Config $config,
        private readonly DatabaseSettings $database,
        private readonly MailSettings $mail,
        private readonly SmsSettings $sms,
        private readonly BotApi $bot,
        private readonly Mailer $mailer,
        /** How codes go out by SMS as config.php had it when the request began: the test SMS goes by it. */
        private readonly Sms $smsSender,
        private readonly AdminAccount $owner,
        private readonly Urls $urls,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The screen: the file, every group, and what the screen needs to show them. To anyone but the owner themselves
     * (`$owner` false: one of the main shop's admins in the bot) the database's settings are not told — where it is and
     * who signs in to it are the owner's alone, as its save is — and the webhook's secret is said only to be kept.
     *
     * @return array<string, mixed>
     */
    public function present(bool $owner = true): array
    {
        $file = $this->file->all();
        $groups = [];
        foreach ($this->groups() as $group) {
            $groups[$group->key] = $group->present(static fn(Field $field): mixed => $file[$field->key] ?? null);
        }
        $groups['database'] = $this->database->present();
        if (!$owner) {
            $groups['database']['values'] = new \stdClass();
            $secret = $groups['telegram']['webhook_secret'] ?? null;
            if (is_array($secret) && ($secret['set'] ?? false) === true) {
                $groups['telegram']['webhook_secret'] = ['set' => true, 'hint' => Secret::KEPT];
            }
        }
        $groups['mail'] = $this->mail->present();
        $groups['sms'] = $this->sms->present();

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
     * Check one group and write it to config.php. Moving the Bot API's address to another origin also asks
     * `current_password`, judged with the form's other errors (a wrong one counts as a failed sign-in).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> The screen as it is now (present())
     * @throws ValidationException|UnknownGroupException
     * @throws TooManyAttemptsException 429: the owner's password tried too often, from this address or for the account
     * @throws ActorRefusedException 403: one of the main shop's admins changing what is the owner's alone
     */
    public function update(string $group, array $input, ServerRequestInterface $request): array
    {
        if ($group === 'database') {
            self::theOwner($request, ActorRefusedException::ownersDatabase(...));
            $this->saveDatabase($input);

            return $this->present(true);
        }
        if ($group === 'mail') {
            $this->write($this->mail->validate($input));

            return $this->present(Principal::of($request)->isTheOwner());
        }
        if ($group === 'sms') {
            $this->write($this->sms->validate($input));

            return $this->present(Principal::of($request)->isTheOwner());
        }

        $errors = [];
        $settings = [];
        $form = $this->group($group);
        try {
            $settings = $this->check($form, $input);
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        $owner = Principal::of($request)->isTheOwner();
        if (!$owner) {
            $this->refuseOwnersOnly($form, $settings);
        }
        if ($group === 'telegram' && $this->movesBotApi($input)) {
            // Refused before the password is judged — an admin's wrong tries would count against the owner's sign-in —, in
            // the Bot API address's own words even when the save failed its checks (a kept token left behind by the move).
            if (!$owner) {
                throw ActorRefusedException::ownersApi();
            }
            $unproven = $this->owner->confirm($request, Input::password($input, 'current_password'));
            if ($unproven !== null) {
                $errors['current_password'][] = $unproven;
            }
        }
        ValidationException::ifAny($errors);
        $this->write($settings);

        return $this->present($owner);
    }

    /**
     * Check the database settings by the driver's form and write them only once a database answers to them.
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
     * Save the main bot's token once Telegram says whose it is, with its @username (the installer's site step).
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
     * Ask Telegram (getMe) whose a token is — the one typed, else the kept one — at the saved Bot API address, where a
     * kept token may go.
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
     * Try a database configuration without saving it — the owner's alone, as its save is.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     * @throws ActorRefusedException 403: one of the main shop's admins, before anything is tried
     */
    public function testDatabase(array $input, ServerRequestInterface $request): ProbeResult
    {
        self::theOwner($request, ActorRefusedException::ownersDatabase(...));

        return $this->assertDatabaseWorks($this->database->validate($input));
    }

    /**
     * Send a test email to `$to` with the saved mail settings; a failure says why (the transport's own words).
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

    /**
     * Send a test code to the phone `$to` with the saved SMS settings — through the approved pattern, as a sign-in code
     * goes —; a failure says why (the provider's own words).
     *
     * @return array{code: string} The code sent, for the owner to match against the SMS
     * @throws ValidationException 422 on `to`: no number a phone has
     * @throws SmsNotReadyException 422: no driver, or not its settings, saved
     * @throws SmsFailedException 502: the provider did not take it — its reason said
     */
    public function testSms(string $to): array
    {
        $phone = self::phone($to);
        if ($phone === null) {
            throw ValidationException::on('to', trim($to) === '' ? 'شماره موبایل گیرنده را وارد کنید.' : 'شماره موبایل گیرنده درست نیست؛ آن را مثل 09121234567 یا +989121234567 بنویسید.');
        }
        if (!$this->smsSender->available()) {
            throw new SmsNotReadyException();
        }

        $code = (string) random_int(100000, 999999);
        try {
            $this->smsSender->sendCode($phone, $code);
        } catch (SmsFailedException $e) {
            throw $e->explained();
        }

        return ['code' => $code];
    }

    /**
     * The SMS account's credit by the saved settings, asked of its provider; null when the driver tells none.
     *
     * @throws SmsNotReadyException 422: no driver, or not its settings, saved
     * @throws SmsCreditUnreadException 502: the provider could not be asked, or refused the key — its words said
     */
    public function smsCredit(): ?SmsCredit
    {
        return $this->sms->credit();
    }

    /** The whole cron trigger address, for the screen's copy action; null while there is no token. */
    public function cronUrl(): ?string
    {
        $token = $this->cronToken()->cast($this->file->get('CRON_TOKEN'));

        return $token === '' ? null : $this->urls->cron($token);
    }

    /** @return list<Form> The groups declared here; an unset setting defaults to what the app runs with */
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

    /** The main bot's token, hinted as its bot id and last characters, bound to the Bot API's origin. */
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
     * Refuses one of the main shop's admins what would hand them the main bot or the owner's seat, with the group's other
     * fields left as they are or not: the site's address (every bot's webhook is registered under it, its secret in the
     * path — a path is refused too), the webhook's secret (whoever knows it sends updates in anyone's name), the main
     * bot's token (another bot's getWebhookInfo shows the address with its secret) and its @username (the owner's
     * one-time link opens there, OwnerLinks), the Bot API's address (every bot's token goes there — a path on the same
     * server is refused too), and the panels' session settings (the owner's own sign-in made longer-lived, or a cookie
     * the host cannot keep). Each is judged on the value the save would write, as its field reads it (Persian digits,
     * spaces and a trailing slash as the field takes them), never as typed. A save that fails its checks writes nothing
     * and is not judged here.
     *
     * @param array<string, mixed> $settings What the save would write
     * @throws ActorRefusedException
     */
    private function refuseOwnersOnly(Form $group, array $settings): void
    {
        $same = static fn(string $value): string => $value;
        $address = static fn(string $url): string => rtrim(trim($url), '/');
        if ($this->changes($group, $settings, 'APP_URL', $address)) {
            throw ActorRefusedException::ownersAddress();
        }
        if ($this->changes($group, $settings, 'TELEGRAM_WEBHOOK_SECRET', $same)) {
            throw ActorRefusedException::ownersSecret();
        }
        // Telegram's @usernames are one whatever their letters' case.
        if ($this->changes($group, $settings, 'TELEGRAM_BOT_TOKEN', $same) || $this->changes($group, $settings, 'TELEGRAM_BOT_USERNAME', static fn(string $name): string => strtolower(ltrim(trim($name), '@')))) {
            throw ActorRefusedException::ownersBot();
        }
        if ($this->changes($group, $settings, 'TELEGRAM_API_URL', $address)) {
            throw ActorRefusedException::ownersApi();
        }
        // A switch as config.php may spell it (true, "true", "1"), a number as its digits.
        $switch = static fn(string $value): string => filter_var(trim($value), FILTER_VALIDATE_BOOLEAN) ? 'on' : 'off';
        if ($this->changes($group, $settings, 'SESSION_LIFETIME', static fn(string $minutes): string => (string) (int) trim($minutes)) || $this->changes($group, $settings, 'SESSION_SECURE_COOKIE', $switch)) {
            throw ActorRefusedException::ownersSession();
        }
    }

    /**
     * Whether the save writes `$key` unlike both what config.php holds and what the app runs with now (its field's kept
     * value: a secret left blank reads as the first, a text left out of config.php as the second), each as `$spelled`
     * writes it — so a group sent back whole, as the screen showed it, changes nothing.
     *
     * @param array<string, mixed> $settings
     * @param \Closure(string): string $spelled
     */
    private function changes(Form $group, array $settings, string $key, \Closure $spelled): bool
    {
        if (!array_key_exists($key, $settings)) {
            return false;
        }
        $kept = $this->file->get($key);
        $written = $spelled(is_scalar($settings[$key]) ? (string) $settings[$key] : '');
        if ($written === $spelled(is_scalar($kept) ? (string) $kept : '')) {
            return false;
        }
        foreach ($group->fields as $field) {
            if ($field->key === $key) {
                $now = $field->cast($kept);

                return $written !== $spelled(is_scalar($now) ? (string) $now : '');
            }
        }

        return true;
    }

    /**
     * Refuses anyone but the owner themselves (`$refusal`): one of the main shop's admins working the bot's management
     * with the owner's seat.
     *
     * @param \Closure(): ActorRefusedException $refusal
     * @throws ActorRefusedException
     */
    private static function theOwner(ServerRequestInterface $request, \Closure $refusal): void
    {
        if (!Principal::of($request)->isTheOwner()) {
            throw $refusal();
        }
    }

    /**
     * Whether the save moves the Bot API's address to another origin (scheme, host or port) — judged on the address as
     * the field writes it (a port in Persian digits is that port), never as typed. One the field refuses moves nothing:
     * the save is refused with it.
     *
     * @param array<string, mixed> $input
     */
    private function movesBotApi(array $input): bool
    {
        $origin = Secret::byOrigin();
        try {
            $to = $origin($this->apiUrl()->read($input, null));
        } catch (FieldRefused) {
            return false;
        }

        return $to !== null && $to !== $origin($this->apiUrl()->cast($this->file->get('TELEGRAM_API_URL')));
    }

    /**
     * A phone number as typed — «09121234567», «۹۱۲ ۱۲۳ ۴۵۶۷», «+98 912…», «0098…» — in E.164; null for none a phone has.
     */
    private static function phone(string $typed): ?string
    {
        $text = Persian::latinDigits(trim($typed));
        $plus = str_starts_with($text, '+');
        $digits = (string) preg_replace('/[\s\-().]+/u', '', ltrim($text, '+'));
        if ($digits === '' || !ctype_digit($digits)) {
            return null;
        }
        $e164 = match (true) {
            $plus => '+' . $digits,
            str_starts_with($digits, '00') => '+' . substr($digits, 2),
            str_starts_with($digits, '09') && strlen($digits) === 11 => '+98' . substr($digits, 1),
            str_starts_with($digits, '9') && strlen($digits) === 10 => '+98' . $digits,
            default => '+' . $digits,
        };

        return preg_match('/^\+[1-9][0-9]{7,14}$/', $e164) === 1 ? $e164 : null;
    }

    private function cronToken(): Secret
    {
        return new Secret('cron_token', 'CRON_TOKEN', (string) $this->config->get('shop.cron_token'), pattern: '/^[A-Za-z0-9_-]{16,128}$/', mismatch: 'توکن Cron باید دست‌کم 16 کاراکتر از حروف انگلیسی، عدد، _ و - باشد.');
    }

    /**
     * Check a group's form against what the file holds: the settings to write.
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
