<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\Urls;
use App\Modules\Payments\Contracts\RegistersWebhook;
use App\Modules\Payments\Enums\GatewayRefusal;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use Psr\Log\LoggerInterface;

/**
 * The webhook address a method's gateway keeps for the merchant's account, when the shop sets it itself
 * (Contracts\RegistersWebhook: Rialit): where the gateway sends its webhooks now beside the shop's own address for the
 * driver (Urls::paymentWebhook(), the same for every shop and method of the installation) — `ours`, `none`, `elsewhere`,
 * or `unknown` while the gateway cannot be asked —, and the shop's address registered on request. The merchant may use
 * the same account for another site, so another address is never replaced unless the owner says so (`$replace`); and
 * when a method is saved the shop registers its own only where there is none (afterSave()). Nothing here fails for the
 * gateway's sake: what it would not do is said in the owner's words (`message`) and logged as the driver words it,
 * never a secret. The words are Rialit's, the one such gateway so far.
 */
final class GatewayWebhooks
{
    /** Where the gateway sends its webhooks: the shop's address, nowhere, another address, or not known. */
    public const OURS = 'ours';
    public const NONE = 'none';
    public const ELSEWHERE = 'elsewhere';
    public const UNKNOWN = 'unknown';

    public const NOT_REGISTERING = 'درگاه این روش آدرس وب‌هوک را از فروشگاه نمی‌گیرد؛ این کار فقط برای ریالیت است.';
    public const REGISTERED = 'آدرس وب‌هوک فروشگاه در ریالیت ثبت شد؛ ریالیت تغییر را به صاحب حساب در تلگرام و ایمیل خبر می‌دهد.';
    public const REPLACED = 'آدرس وب‌هوک فروشگاه به جای آدرس قبلی در ریالیت ثبت شد؛ ریالیت تغییر را به صاحب حساب در تلگرام و ایمیل خبر می‌دهد.';
    public const TAKEN_ELSEWHERE = 'وب‌هوک این حساب ریالیت الان به آدرس دیگری می‌رود؛ اگر این حساب فقط برای همین فروشگاه است، آدرس فروشگاه را جایگزین کنید.';
    public const NOT_HTTPS = 'آدرس سایت فروشگاه با https شروع نمی‌شود و ریالیت وب‌هوک را فقط به آدرس https عمومی می‌فرستد؛ «آدرس سایت» در «تنظیمات پنل» باید آدرس https سایت باشد.';
    public const BAD_KEY = 'ریالیت کلید API این روش را نپذیرفت؛ کلید را دوباره از داشبورد ریالیت بردارید و در همین روش وارد کنید.';
    public const IP_NOT_ALLOWED = 'IP سرور فروشگاه میان IPهای مجاز داشبورد ریالیت نیست؛ آن را از پشتیبانی هاست بپرسید و در داشبورد ریالیت اضافه کنید.';
    public const BLOCKED = 'حساب ریالیت فعال نیست یا مسدود شده است؛ از پشتیبانی ریالیت بپرسید.';
    public const BUSY = 'ریالیت الان درخواست بیشتری نمی‌پذیرد؛ کمی بعد دوباره امتحان کنید.';
    public const TOO_MANY_CHANGES = 'ریالیت در هر ساعت بیشتر از ۲۰ تغییر امنیتی نمی‌پذیرد و تغییرهای داشبورد هم حساب می‌شوند؛ کمی بعد دوباره امتحان کنید.';
    public const ADDRESS_REFUSED = 'ریالیت آدرس فروشگاه را نپذیرفت: آدرس سایت باید آدرس https عمومی باشد که از اینترنت باز شود، نه آدرس داخلی یا محلی.';
    public const OUT_OF_REACH = 'ریالیت الان در دسترس نیست؛ کمی بعد دوباره امتحان کنید.';

    /** The longest address the gateway takes. */
    private const URL_MAX = 2000;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly Urls $urls,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Where the method's gateway sends its webhooks now, beside the shop's address: asked, nothing changed — and so no
     * words but why it could not be asked (the state says the rest).
     *
     * @return array{state: string, url: string|null, ours: string, message: string|null}
     * @throws ValidationException 422 on `driver`: a gateway the shop does not set the address of
     */
    public function state(PaymentMethod $method): array
    {
        $gateway = $this->gateway($method);
        $ours = $this->ours($method);
        try {
            $url = $gateway->webhook();
        } catch (GatewayUnavailableException $e) {
            return $this->unknown($method, $ours, $e);
        }

        return self::answer($url, $ours, null);
    }

    /**
     * The shop's address registered with the method's gateway where it has none — where it has another, only when
     * `$replace` —, and where the gateway sends its webhooks after. Nothing is changed where the gateway cannot be asked
     * first, or where it sends them to the shop already.
     *
     * @return array{state: string, url: string|null, ours: string, message: string|null}
     * @throws ValidationException 422 on `driver`: a gateway the shop does not set the address of
     */
    public function register(PaymentMethod $method, bool $replace): array
    {
        $gateway = $this->gateway($method);
        $ours = $this->ours($method);
        try {
            $before = $gateway->webhook();
        } catch (GatewayUnavailableException $e) {
            return $this->unknown($method, $ours, $e);
        }
        if (self::same($before, $ours)) {
            return self::answer($before, $ours, null);
        }
        if ($before !== null && !$replace) {
            return self::answer($before, $ours, self::TAKEN_ELSEWHERE);
        }
        if (!str_starts_with(strtolower($ours), 'https://') || strlen($ours) > self::URL_MAX) {
            return self::answer($before, $ours, self::NOT_HTTPS);
        }

        try {
            $after = $gateway->hook($ours);
        } catch (GatewayUnavailableException $e) {
            $this->logger->warning('Payment method {id}\'s webhook address could not be set to the shop\'s at its gateway: {detail}', ['id' => $method->id, 'detail' => $e->detail]);

            return self::answer($before, $ours, self::refusal($e, change: true));
        }
        $this->logger->info('Payment method {id}\'s gateway was told to send its webhooks to the shop\'s address {url} (it sent them to {before}); it says {after} now', ['id' => $method->id, 'url' => $ours, 'before' => $before ?? 'nowhere', 'after' => $after ?? 'nowhere']);

        return self::answer($after, $ours, match (true) {
            !self::same($after, $ours) => $after === null ? null : self::TAKEN_ELSEWHERE,
            $before === null => self::REGISTERED,
            default => self::REPLACED,
        });
    }

    /**
     * After the method was saved (made, or its form sent again): the shop's address registered where its gateway has
     * none (register(), never replacing another) — and that state; null for a method whose gateway the shop does not set
     * the address of.
     *
     * @return array{state: string, url: string|null, ours: string, message: string|null}|null
     */
    public function afterSave(PaymentMethod $method): ?array
    {
        return $this->gateways->registersWebhook($method->driver) ? $this->register($method, false) : null;
    }

    /** @throws ValidationException 422 on `driver` */
    private function gateway(PaymentMethod $method): RegistersWebhook
    {
        $gateway = $this->gateways->registersWebhook($method->driver) ? $this->gateways->forMethod($method) : null;

        return $gateway instanceof RegistersWebhook ? $gateway : throw ValidationException::on('driver', self::NOT_REGISTERING);
    }

    /** The shop's address for the method's driver's webhooks. */
    private function ours(PaymentMethod $method): string
    {
        return $this->urls->paymentWebhook($method->driver);
    }

    /**
     * The state while the gateway cannot be asked: why, in the owner's words, and in the log.
     *
     * @return array{state: string, url: null, ours: string, message: string}
     */
    private function unknown(PaymentMethod $method, string $ours, GatewayUnavailableException $e): array
    {
        $this->logger->warning('Payment method {id}\'s webhook address could not be read at its gateway: {detail}', ['id' => $method->id, 'detail' => $e->detail]);

        return ['state' => self::UNKNOWN, 'url' => null, 'ours' => $ours, 'message' => self::refusal($e, change: false)];
    }

    /** Why the gateway would not read or change (`$change`) its address, in the owner's words. */
    private static function refusal(GatewayUnavailableException $e, bool $change): string
    {
        return match ($e->refusal) {
            GatewayRefusal::Key => self::BAD_KEY,
            GatewayRefusal::Ip => self::IP_NOT_ALLOWED,
            GatewayRefusal::Blocked => self::BLOCKED,
            GatewayRefusal::Limited => $change ? self::TOO_MANY_CHANGES : self::BUSY,
            GatewayRefusal::Invalid => $change ? self::ADDRESS_REFUSED : self::OUT_OF_REACH,
            GatewayRefusal::Trouble, null => self::OUT_OF_REACH,
        };
    }

    /**
     * The state of the gateway's address `$url` beside the shop's.
     *
     * @return array{state: string, url: string|null, ours: string, message: string|null}
     */
    private static function answer(?string $url, string $ours, ?string $message): array
    {
        $state = match (true) {
            $url === null => self::NONE,
            self::alike($url, $ours) => self::OURS,
            default => self::ELSEWHERE,
        };

        return ['state' => $state, 'url' => $url, 'ours' => $ours, 'message' => $message];
    }

    /** Whether the gateway's address, if any, is the shop's (alike()). */
    private static function same(?string $url, string $ours): bool
    {
        return $url !== null && self::alike($url, $ours);
    }

    /** Two addresses alike, a closing slash and the case of the letters aside — as the gateway may write one back. */
    private static function alike(string $a, string $b): bool
    {
        return strcasecmp(rtrim($a, '/'), rtrim($b, '/')) === 0;
    }
}
