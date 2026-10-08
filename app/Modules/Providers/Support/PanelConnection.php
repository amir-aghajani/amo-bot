<?php

declare(strict_types=1);

namespace App\Modules\Providers\Support;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Fields\Url;
use App\Core\Forms\FieldSpec;
use App\Modules\Providers\Models\Server;

/**
 * Where a panel is and how to talk to it, whatever the panel: its URL, how long a request may take, whether its TLS
 * certificate is checked (off for a self-signed one) and — for a panel behind a reverse proxy its own settings do not
 * describe — the prefix its subscription links start with. Kept in `servers.base_url` and `servers.meta` and read back
 * by of(); every connector's form takes them by the same fields — the address with the connector's own rule
 * (address()), the options under «تنظیمات پیشرفته» (options()) —, and a secret of the way in goes only to the host it
 * was given for (bound()).
 */
final class PanelConnection
{
    /** The field the panel's address is: the one the way in's secrets are bound to. */
    public const ADDRESS = 'base_url';

    /** The longest address kept (servers.base_url; the subscription links' prefix alike). */
    private const ADDRESS_MAX = 255;

    /** Seconds a request may take while the server says nothing else. */
    public const DEFAULT_TIMEOUT = 30;
    private const MIN_TIMEOUT = 5;
    private const MAX_TIMEOUT = 120;

    /** A host that does not answer at all is given up on early; one that answers slowly gets the whole timeout. */
    private const CONNECT_TIMEOUT = 10;

    public function __construct(
        /** Scheme, host, port and any path prefix, without a trailing slash. */
        public readonly string $baseUrl,
        public readonly bool $verifyTls = true,
        /** Seconds a request may take. */
        public readonly int $timeout = self::DEFAULT_TIMEOUT,
        /** What subscription links start with instead of what the panel says; null = the panel's own. */
        public readonly ?string $subscriptionUrl = null,
    ) {}

    /** The connection a stored server describes. */
    public static function of(Server $server): self
    {
        $subscriptionUrl = $server->meta['subscription_url'] ?? null;

        return new self(
            baseUrl: rtrim(trim($server->base_url), '/'),
            verifyTls: (bool) ($server->meta['verify_tls'] ?? true),
            timeout: (int) ($server->meta['timeout'] ?? self::DEFAULT_TIMEOUT),
            subscriptionUrl: is_string($subscriptionUrl) && $subscriptionUrl !== '' ? $subscriptionUrl : null,
        );
    }

    /**
     * The form's field of the panel's address — an http(s) URL with a host and nothing secret or stray in it, kept in
     * `base_url` — in the connector's words (`$hint`, `$placeholder`), held to its rule beside: none of the panel's own
     * pages (`$pages`, a pattern on the address's path, refused in `$pagesRefusal`'s words).
     */
    public static function address(string $hint, string $placeholder, string $pages, string $pagesRefusal): Url
    {
        return new Url(
            self::ADDRESS,
            'base_url',
            '',
            label: 'آدرس پنل',
            refusal: 'آدرس پنل باید با http:// یا https:// شروع شود و شامل نام هاست باشد.',
            max: self::ADDRESS_MAX,
            credentials: self::credentials('آدرس پنل'),
            pages: $pages,
            pagesRefusal: $pagesRefusal,
            spec: new FieldSpec('آدرس پنل', hint: $hint, placeholder: $placeholder),
        );
    }

    /**
     * The form's fields of the connection's options, under «تنظیمات پیشرفته» and kept in `meta`: the TLS check, the
     * timeout, and the prefix of the subscription links — where the connector's links take it (`$subscriptionHint`).
     *
     * @return list<Field<mixed>>
     */
    public static function options(string $subscriptionHint, string $subscriptionPlaceholder): array
    {
        return [
            new Toggle('verify_tls', 'meta.verify_tls', true, spec: new FieldSpec('بررسی گواهی TLS', hint: 'برای پنل‌هایی با گواهی خودامضا خاموش کنید.', advanced: true)),
            new Number(
                'timeout',
                'meta.timeout',
                self::DEFAULT_TIMEOUT,
                label: 'تایم‌اوت اتصال',
                min: self::MIN_TIMEOUT,
                max: self::MAX_TIMEOUT,
                unit: 'ثانیه',
                spec: new FieldSpec('تایم‌اوت اتصال', hint: sprintf('بین %d تا %d ثانیه.', self::MIN_TIMEOUT, self::MAX_TIMEOUT), advanced: true),
            ),
            new Url(
                'subscription_url',
                'meta.subscription_url',
                '',
                label: 'آدرس اشتراک',
                refusal: 'آدرس اشتراک باید یک URL کامل باشد (مثل https://sub.example.com/sub).',
                required: false,
                max: self::ADDRESS_MAX,
                credentials: self::credentials('آدرس اشتراک'),
                spec: new FieldSpec('آدرس اشتراک', hint: $subscriptionHint, placeholder: $subscriptionPlaceholder, advanced: true),
            ),
        ];
    }

    /**
     * What an address of the panel's with credentials in it is told: the way in has fields of its own, kept encrypted —
     * the address is shown on the servers screen and in every error about the panel.
     */
    private static function credentials(string $label): string
    {
        return "{$label} نباید نام کاربری یا رمز داشته باشد؛ آن‌ها را در فیلدهای خودشان وارد کنید.";
    }

    /**
     * What a secret of the way in is bound to (Secret::$boundTo): the panel's address, by its origin — its scheme, host
     * and port; another path is the same panel. Moved to another host, a secret kept is never sent there: it is typed
     * again.
     *
     * @return array<string, \Closure(mixed): ?string>
     */
    public static function bound(): array
    {
        return [self::ADDRESS => Secret::byOrigin()];
    }

    /**
     * The Guzzle options that tune a request to the panel (PanelHttp::send()).
     *
     * @return array{verify: bool, timeout: float, connect_timeout: float}
     */
    public function requestOptions(): array
    {
        return [
            'verify' => $this->verifyTls,
            'timeout' => (float) $this->timeout,
            'connect_timeout' => (float) min(self::CONNECT_TIMEOUT, $this->timeout),
        ];
    }
}
