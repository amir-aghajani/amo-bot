<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Ruled;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\FieldType;
use App\Core\Forms\Form;
use App\Core\Http\Urls;
use App\Core\Support\Files;
use App\Core\Support\Picture;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Services\Bots;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Contracts\MiniAppAddress;
use App\Support\Persian;
use App\Support\Validation;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * A shop's Telegram mini app as both panels set it («مینی‌اپ تلگرام»): the switch, how it looks, its announcement, and
 * the bot's menu button that opens it. The row is made, switched off with a random key, the first time a panel asks
 * (current()). The app lives on the shop's own address — `{APP_URL}/app/{key}/`, the panels' build — and Telegram opens
 * only an https one, so switching it on needs APP_URL on https.
 *
 * Every save sets the bot's menu button again (applyMenuButton()): the app's while it is on with its button; otherwise
 * Telegram's own in place of the app's, a button the shop set at @BotFather left as it is. Telegram out of reach or
 * refusing never undoes the save: the answer says why the button is not set.
 *
 * Its logo is a picture a panel uploads (storeLogo()), kept as a file of the shop's own under storage/uploads/logos — its
 * name on the row (`logo_path`) — and answered by both panels and the Store API (logo()).
 */
final class MiniApps implements MiniAppAddress
{
    /** The menu button's words while the shop sets none. */
    public const MENU_BUTTON_TEXT = 'فروشگاه';

    /** What switching it on without an https address answers. */
    public const HTTPS_NEEDED = 'تلگرام مینی‌اپ را فقط از آدرس https باز می‌کند؛ اول آدرس برنامه (APP_URL) را در تنظیمات پنل › برنامه با https بنویسید.';

    /** Where the app is served under the shop's address: the panels' build writes it to public/app. */
    private const PATH = '/app/';

    private const TITLE_MAX = 64;
    private const ANNOUNCEMENT_MAX = 500;
    private const LINK_MAX = 255;
    private const BUTTON_TEXT_MAX = 32;

    /** The largest logo taken. */
    public const LOGO_MAX_BYTES = 2 * 1024 * 1024;

    /** The longest side a logo is kept at: the app draws it small, and a small file opens fast on a phone. */
    public const LOGO_SIDE = 512;

    /** Accepted logos, by their sniffed type — what GD reads on a shared host. */
    public const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** The quality a logo is written again with (a JPEG, a WebP). */
    private const LOGO_QUALITY = 90;

    /** @param string $logos Where logos go — the container's `miniapp.logos` (storage/uploads/logos) */
    public function __construct(
        private readonly Urls $urls,
        private readonly Bots $bots,
        private readonly BotApi $telegram,
        private readonly LoggerInterface $logger,
        private readonly string $logos,
    ) {}

    /** The current shop's mini app, made switched off the first time it is asked for. */
    public function current(): MiniApp
    {
        // Of two first asks at once, one makes the row (one a shop: its unique bot_id) and the other finds it.
        return MiniApp::query()->first() ?? MiniApp::query()->createOrFirst([], ['key' => bin2hex(random_bytes(12))]);
    }

    /**
     * The mini app the Store API answers under `$key`, looked up across shops: switched on and its bot running (an
     * agent's whose agency ended is closed with their panel). Null otherwise.
     */
    public function open(string $key): ?MiniApp
    {
        $app = $this->previewable($key);

        return $app !== null && $app->enabled ? $app : null;
    }

    /**
     * The mini app under `$key` whatever its switch, looked up across shops — its bot running —, for a panel's preview of
     * it (Http\StoreMiddleware); null otherwise.
     */
    public function previewable(string $key): ?MiniApp
    {
        $app = CurrentBot::everywhere(static fn(): ?MiniApp => MiniApp::query()->where('key', $key)->first());

        return $app !== null && $app->shop()->status() === BotStatus::Active ? $app : null;
    }

    /** Where `$app` opens: `{APP_URL}/app/{key}/`. */
    public function address(MiniApp $app): string
    {
        return $this->urls->base() . self::folder($app);
    }

    /**
     * Where `$app` opens on this host, from its root: `/app/{key}/` under `$basePath`, the folder requests arrive under
     * (APP_BASE_PATH, or as detected; '' at the root) — a panel opens it beside itself.
     */
    public static function path(MiniApp $app, string $basePath): string
    {
        return rtrim($basePath, '/') . self::folder($app);
    }

    /** Whether Telegram can open the app at all: APP_URL is an https address. */
    public function secure(): bool
    {
        return str_starts_with(strtolower($this->urls->base()), 'https://');
    }

    /** The current shop's app's address while it is on and can be opened (MiniAppAddress, the bot's buttons). */
    public function url(): ?string
    {
        $app = MiniApp::query()->first();

        return $app !== null && $app->enabled && $this->secure() ? $this->address($app) : null;
    }

    /**
     * Save what a panel's card sends — a field sent is changed, one left out kept (Form::checkSent()) —, then set the bot's
     * menu button as the app now stands. Every refusal comes at once, before anything is kept. Answers why the menu
     * button could not be set (Telegram refused it or did not answer), null when it was.
     *
     * @param array<string, mixed> $input any of {enabled, title, accent_color, announcement, announcement_url, fullscreen, reviews_enabled, menu_button, menu_button_text}
     * @throws ValidationException
     */
    public function update(MiniApp $app, array $input): ?string
    {
        $values = $this->form($app)->checkSent($input, static fn(Field $field): mixed => $app->getAttribute($field->key));
        foreach ($values as $key => $value) {
            $app->setAttribute($key, $value === '' ? null : $value);
        }
        $app->save();

        return $this->applyMenuButton($app);
    }

    /**
     * Set the bot's menu button as `$app` stands: the app's, with its words, while it is on, can be opened and keeps its
     * button. Otherwise the app's button left from before (Telegram asked what it is now) gives Telegram's own back, and
     * a button the shop set itself at @BotFather — commands, or a page of its own — stays as it is. Answers why it could
     * not be set; null when it was, or was left alone.
     */
    public function applyMenuButton(MiniApp $app): ?string
    {
        try {
            if ($app->enabled && $app->menu_button && $this->secure()) {
                $this->telegram->setChatMenuButton(['type' => 'web_app', 'text' => $app->menu_button_text ?? self::MENU_BUTTON_TEXT, 'web_app' => ['url' => $this->address($app)]]);
            } elseif (self::opens($app, $this->telegram->getChatMenuButton())) {
                $this->telegram->setChatMenuButton(['type' => 'default']);
            }

            return null;
        } catch (TelegramApiException $e) {
            $this->logger->warning('The bot\'s menu button could not be set for its mini app: {message}', ['message' => $e->getMessage()]);

            return $e->is(Refusal::Unreachable, Refusal::FloodWait)
                ? 'دکمه منوی ربات تنظیم نشد: تلگرام در دسترس نبود؛ کمی بعد دوباره ذخیره کنید.'
                : 'دکمه منوی ربات تنظیم نشد: تلگرام آن را نپذیرفت؛ توکن ربات و آدرس برنامه را بررسی کنید و دوباره ذخیره کنید.';
        }
    }

    /**
     * The mini app as its panel shows it: its switch and address (and whether Telegram can open it there), how it looks
     * — with the shop's name its title falls back to —, its announcement, and the bot's menu button.
     *
     * @return array{enabled: bool, url: string, secure: bool, bot: array{username: string, url: string}|null, title: string|null, shop_name: string, accent_color: string|null, logo: bool, announcement: string|null, announcement_url: string|null, fullscreen: bool, reviews_enabled: bool, menu_button: bool, menu_button_text: string|null, menu_button_default: string}
     */
    public function present(MiniApp $app): array
    {
        $username = $this->bots->username($app->shop());

        return [
            'enabled' => $app->enabled,
            'url' => $this->address($app),
            'secure' => $this->secure(),
            'bot' => $username === '' ? null : ['username' => $username, 'url' => 'https://t.me/' . $username],
            'title' => $app->title,
            'shop_name' => $this->bots->name($app->shop()),
            'accent_color' => $app->accent_color,
            'logo' => $app->logo_path !== null,
            'announcement' => $app->announcement,
            'announcement_url' => $app->announcement_url,
            'fullscreen' => $app->fullscreen,
            'reviews_enabled' => $app->reviews_enabled,
            'menu_button' => $app->menu_button,
            'menu_button_text' => $app->menu_button_text,
            'menu_button_default' => self::MENU_BUTTON_TEXT,
        ];
    }

    /**
     * The app's logo as kept — its bytes and their type —; null while it has none, or its file is gone.
     *
     * @return array{body: string, mime: string}|null
     */
    public function logo(MiniApp $app): ?array
    {
        $path = $this->logoPath($app);
        $body = $path === null ? null : Files::read($path);

        return $body === null ? null : ['body' => $body, 'mime' => Picture::typeOf($body)];
    }

    /**
     * Take a panel's upload as the app's logo, in place of the one before: a picture by its content (LOGO_TYPES), up to
     * LOGO_MAX_BYTES, kept no larger than LOGO_SIDE a side (fittedLogo()). The file is the shop's own, named after it.
     *
     * @throws ValidationException 422 on `file`
     */
    public function storeLogo(MiniApp $app, UploadedFileInterface $file): void
    {
        $failure = Validation::uploadFailure($file);
        if ($failure !== null) {
            throw ValidationException::on('file', $failure);
        }
        $tooLarge = 'حجم لوگو حداکثر ' . Persian::number(intdiv(self::LOGO_MAX_BYTES, 1024 * 1024)) . ' مگابایت می‌تواند باشد.';
        if (($file->getSize() ?? 0) > self::LOGO_MAX_BYTES) {
            throw ValidationException::on('file', $tooLarge);
        }

        $bytes = (string) $file->getStream();
        // An upload that did not say its size is judged by what it holds.
        if (strlen($bytes) > self::LOGO_MAX_BYTES) {
            throw ValidationException::on('file', $tooLarge);
        }
        $mime = Picture::typeOf($bytes);
        $extension = self::LOGO_TYPES[$mime] ?? null;
        $size = Picture::size($bytes);
        if ($extension === null || $size === null) {
            throw ValidationException::on('file', 'لوگو باید تصویر ' . Picture::FORMATS . ' باشد.');
        }
        $bytes = self::fittedLogo($bytes, $mime, $size);

        // Written whole, then remembered, then the old one deleted: a failure on the way leaves the logo as it was.
        $previous = $this->logoPath($app);
        $name = 'logo-' . $app->shop()->id . '.' . $extension;
        Files::writeAtomically($this->logos . '/' . $name, $bytes);
        // Touched even when the name stays (a PNG after a PNG): the address the app is given changes with it (Storefront).
        $app->forceFill(['logo_path' => $name])->touch();
        if ($previous !== null && $previous !== $this->logos . '/' . $name) {
            Files::delete($previous);
        }
    }

    /** Remove the app's logo: the app shows the shop's initial in its place. */
    public function removeLogo(MiniApp $app): void
    {
        $previous = $this->logoPath($app);
        $app->forceFill(['logo_path' => null])->save();
        if ($previous !== null) {
            Files::delete($previous);
        }
    }

    /**
     * The upload as kept: decoded once — one too large to decode in the memory PHP has left, or one GD cannot read, is
     * refused —, no larger than LOGO_SIDE a side, turned the way its camera held it (a JPEG's EXIF) and written again in
     * its own type, so nothing of the sender's file but its pixels stays (no camera metadata). Without GD it stays as it
     * came.
     *
     * @param array{int, int} $size Its width and height, off its header
     * @throws ValidationException 422 on `file`
     */
    private static function fittedLogo(string $bytes, string $mime, array $size): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        // Read from the header, before anything is decoded: a small file can hold a picture no host's memory takes.
        if (!Picture::decodable($size[0], $size[1], self::LOGO_SIDE, strlen($bytes))) {
            throw ValidationException::on('file', 'ابعاد این تصویر برای لوگو بیش از حد بزرگ است؛ تصویر کوچک‌تری بفرستید.');
        }

        $image = Picture::decode($bytes) ?? throw ValidationException::on('file', 'این تصویر خوانده نشد؛ تصویر دیگری بفرستید.');
        $fitted = Picture::fitted($image, self::LOGO_SIDE);
        if ($fitted === null) {
            return $bytes;
        }
        $fitted = $mime === 'image/jpeg' ? Picture::upright($fitted, $bytes) : $fitted;

        return Picture::encoded($fitted, $mime, self::LOGO_QUALITY) ?? $bytes;
    }

    /** Where the app's logo is kept; null while it has none, or its file is gone. */
    private function logoPath(MiniApp $app): ?string
    {
        if ($app->logo_path === null || $app->logo_path === '') {
            return null;
        }
        $path = $this->logos . '/' . basename($app->logo_path);

        return is_file($path) ? $path : null;
    }

    /**
     * Whether the menu button Telegram has (getChatMenuButton()) opens `$app` — at its address under any APP_URL the shop
     * had, the key being the app's own.
     *
     * @param array<string, mixed> $button
     */
    private static function opens(MiniApp $app, array $button): bool
    {
        $webApp = $button['web_app'] ?? null;
        $url = is_array($webApp) ? $webApp['url'] ?? null : null;

        return ($button['type'] ?? null) === 'web_app' && is_string($url) && str_contains($url, self::folder($app));
    }

    /** The app's folder under the shop's address: `/app/{key}/`. */
    private static function folder(MiniApp $app): string
    {
        return self::PATH . $app->key . '/';
    }

    /** The app's fields, and the one rule between them and the shop: switching it on needs an https address. */
    private function form(MiniApp $app): Form
    {
        return new Form('mini_app', [
            // Only switching it on asks for https: one already on is not refused because APP_URL changed since.
            new Ruled(new Toggle('enabled', 'enabled', false), fn(array $values): ?string => $values['enabled'] === true && !$app->enabled && !$this->secure() ? self::HTTPS_NEEDED : null),
            new Text('title', 'title', '', label: 'نام مینی‌اپ', max: self::TITLE_MAX),
            new Text('accent_color', 'accent_color', '', label: 'رنگ', max: 7, pattern: '/^#[0-9a-f]{6}$/', mismatch: 'رنگ را به شکل #RRGGBB بنویسید؛ مثل #2481cc.', normalize: static fn(string $color): string => strtolower($color)),
            new Text('announcement', 'announcement', '', label: 'اطلاعیه', max: self::ANNOUNCEMENT_MAX, type: FieldType::Textarea),
            new Text('announcement_url', 'announcement_url', '', label: 'لینک اطلاعیه', max: self::LINK_MAX, pattern: '~^https://[^\s/?#]+\.[^\s]*$~i', mismatch: 'لینک اطلاعیه باید یک آدرس https باشد؛ مثل https://t.me/channel.'),
            new Toggle('fullscreen', 'fullscreen', true),
            new Toggle('reviews_enabled', 'reviews_enabled', false),
            new Toggle('menu_button', 'menu_button', true),
            new Text('menu_button_text', 'menu_button_text', '', label: 'متن دکمه منو', max: self::BUTTON_TEXT_MAX),
        ]);
    }
}
