<?php

declare(strict_types=1);

namespace App\Modules\Providers\Support;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Ruled;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Fields\Url;
use App\Core\Forms\FieldSpec;
use App\Modules\Providers\Forms\ProxyHost;
use App\Modules\Providers\Models\Server;

/**
 * Where a panel is and how to talk to it, whatever the panel: its URL, the request timeout, whether its TLS certificate
 * is checked (off for a self-signed one), for a panel behind a reverse proxy its own settings do not describe, the
 * prefix its subscription links start with, and for a panel the shop's host cannot reach directly, the SOCKS5 proxy its
 * requests go through. Kept in `servers.base_url`, `servers.meta` and the `proxy_*` columns (its password encrypted),
 * read back by of(). Every connector's form uses the same fields for them — address() with the connector's own rule,
 * options() under «تنظیمات پیشرفته» — and binds the way in's secrets to the address's host and the way there (bound()).
 */
final class PanelConnection
{
    /** The field of the proxy switch, which the proxy's other fields are shown by. */
    public const PROXY = 'proxy_enabled';

    /** The `when` of every field of the proxy (FieldSpec::$when). */
    private const WITH_PROXY = [self::PROXY => ['true']];

    /** The port a SOCKS5 proxy listens on unless it says otherwise. */
    public const PROXY_PORT = 1080;

    /** The longest proxy login kept (servers.proxy_username; RFC 1929 allows 255 bytes for each half). */
    private const PROXY_LOGIN_MAX = 255;

    /** The field of the panel's address, which the way in's secrets are bound to. */
    public const ADDRESS = 'base_url';

    /** How a refusal of a way in's secret left blank while what it is bound to changed (bound()) begins: the secret's words follow. */
    public const MOVED = 'آدرس پنل، پراکسی یا بررسی گواهی TLS عوض شده است؛ ';

    /** The longest address kept (servers.base_url, and the subscription links' prefix). */
    private const ADDRESS_MAX = 255;

    /** Seconds a request may take unless the server sets its own. */
    public const DEFAULT_TIMEOUT = 30;
    private const MIN_TIMEOUT = 5;

    /** The longest a server may give its panel's requests: what the longest delivery is sized by (ProvisioningService). */
    public const MAX_TIMEOUT = 120;

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
        /** The SOCKS5 proxy every request to the panel goes through, its login in it (proxy()); null = straight there. */
        #[\SensitiveParameter]
        public readonly ?string $proxy = null,
    ) {}

    /** The connection a stored server describes. */
    public static function of(Server $server): self
    {
        [$baseUrl, $subscriptionUrl] = self::addresses($server);
        $proxied = $server->proxy_enabled === true && ($server->proxy_host ?? '') !== '' && $server->proxy_port !== null;

        return new self(
            baseUrl: $baseUrl,
            verifyTls: (bool) ($server->meta['verify_tls'] ?? true),
            timeout: (int) ($server->meta['timeout'] ?? self::DEFAULT_TIMEOUT),
            subscriptionUrl: $subscriptionUrl !== '' ? $subscriptionUrl : null,
            proxy: $proxied ? self::proxy((string) $server->proxy_host, (int) $server->proxy_port, $server->proxy_username, $server->proxy_password) : null,
        );
    }

    /**
     * The panel's address and its subscription links' prefix ('' for the panel's own), each without a trailing slash —
     * read from `base_url` and `meta` alone, so a row of those two columns will do, and no proxy password is decrypted
     * for them.
     *
     * @return array{string, string}
     */
    public static function addresses(Server $server): array
    {
        $subscriptionUrl = $server->meta['subscription_url'] ?? null;

        return [self::bare($server->base_url), is_string($subscriptionUrl) ? self::bare($subscriptionUrl) : ''];
    }

    /** An address as the links are built on it: trimmed, without its trailing slash. */
    private static function bare(string $address): string
    {
        return rtrim(trim($address), '/');
    }

    /**
     * A SOCKS5 proxy's address as cURL takes it: `socks5h`, so the proxy looks the panel's name up (the shop's host may
     * not resolve it either), its login URL-encoded in it — a password only with a username —, an IPv6 host in brackets.
     */
    public static function proxy(string $host, int $port, ?string $username = null, #[\SensitiveParameter] ?string $password = null): string
    {
        $login = '';
        if (($username ?? '') !== '') {
            $login = rawurlencode((string) $username) . (($password ?? '') !== '' ? ':' . rawurlencode((string) $password) : '') . '@';
        }

        return 'socks5h://' . $login . (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . $port;
    }

    /**
     * The form's field of the panel's address: an http(s) URL with a host and no credentials, query or fragment, kept in
     * `base_url`, worded by the connector (`$hint`, `$placeholder`) and refusing the panel's own pages (`$pages`, a
     * pattern on the path, refused with `$pagesRefusal`).
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
     * The form's fields of the connection's options, under «تنظیمات پیشرفته»: the TLS check, the timeout, and the
     * subscription links' prefix (`$subscriptionHint` says where the connector's links take it), kept in `meta`; then the
     * SOCKS5 proxy, kept in its columns — its host, port and optional login, shown while it is switched on (switched off,
     * they are emptied). The proxy's password is a secret kept while the proxy's host and port stay, and only with a
     * username.
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
            new Toggle(self::PROXY, 'proxy_enabled', false, spec: new FieldSpec(
                'اتصال از طریق پراکسی SOCKS5',
                hint: 'وقتی هاست فروشگاه به پنل دسترسی مستقیم ندارد: همه درخواست‌های فروشگاه به این پنل از این پراکسی می‌گذرند.',
                advanced: true,
            )),
            new ProxyHost('proxy_host', 'proxy_host', spec: new FieldSpec('آدرس پراکسی', hint: 'نام هاست یا IP، بدون socks5:// و بدون پورت.', placeholder: 'proxy.example.com', advanced: true, when: self::WITH_PROXY, ltr: true)),
            new Number('proxy_port', 'proxy_port', self::PROXY_PORT, label: 'پورت پراکسی', min: 1, max: 65535, spec: new FieldSpec('پورت پراکسی', placeholder: (string) self::PROXY_PORT, advanced: true, when: self::WITH_PROXY)),
            new Text(
                'proxy_username',
                'proxy_username',
                '',
                label: 'نام کاربری پراکسی',
                max: self::PROXY_LOGIN_MAX,
                pattern: '/^[^\x00-\x1f\x7f]+$/u',
                mismatch: 'نام کاربری پراکسی نباید کاراکتر کنترلی داشته باشد.',
                spec: new FieldSpec('نام کاربری پراکسی', hint: 'برای پراکسی بدون ورود خالی بگذارید.', advanced: true, when: self::WITH_PROXY, ltr: true),
            ),
            new Ruled(
                new Secret(
                    'proxy_password',
                    'proxy_password',
                    '',
                    pattern: '/^[^\x00-\x1f\x7f]{1,255}$/u',
                    mismatch: 'رمز پراکسی حداکثر 255 کاراکتر است، بدون کاراکترهای کنترلی.',
                    hint: Secret::hidden(),
                    boundTo: ['proxy_host' => null, 'proxy_port' => null],
                    moved: 'آدرس یا پورت پراکسی عوض شده است؛ رمز پراکسی را دوباره وارد کنید.',
                    spec: new FieldSpec('رمز پراکسی', advanced: true, when: self::WITH_PROXY),
                ),
                // A login is a username with its password: a password alone is never sent.
                static fn(array $values): ?string => ($values['proxy_password'] ?? '') !== '' && ($values['proxy_username'] ?? null) === ''
                    ? 'رمز پراکسی بدون نام کاربری فرستاده نمی‌شود؛ نام کاربری پراکسی را وارد کنید یا رمز را پاک کنید.'
                    : null,
            ),
        ];
    }

    /**
     * The refusal of an address with credentials in it: the way in has encrypted fields of its own, while the address is
     * shown on the servers screen and in every error about the panel.
     */
    private static function credentials(string $label): string
    {
        return "{$label} نباید نام کاربری یا رمز داشته باشد؛ آن‌ها را در فیلدهای خودشان وارد کنید.";
    }

    /**
     * What a secret of the way in is bound to (Secret::$boundTo): the panel's address by its origin (scheme, host, port;
     * another path is the same panel), and the way a request takes there — the proxy's host and port, and the TLS check,
     * without which a proxy could read what goes through it. When one of them changes, a kept secret is never sent the
     * new way: it must be typed again. A proxy switched off sends it straight to the panel it was given for: no change.
     *
     * @return array<string, (\Closure(mixed): ?string)|null>
     */
    public static function bound(): array
    {
        return [self::ADDRESS => Secret::byOrigin(), 'proxy_host' => null, 'proxy_port' => null, 'verify_tls' => null];
    }

    /**
     * The Guzzle options that tune a request to the panel (PanelHttp::send()), its proxy among them: a request's own
     * option, never the shop's client's, which Telegram, mail and every other outgoing call share.
     *
     * @return array{verify: bool, timeout: float, connect_timeout: float, proxy?: string}
     */
    public function requestOptions(): array
    {
        $options = [
            'verify' => $this->verifyTls,
            'timeout' => (float) $this->timeout,
            'connect_timeout' => (float) min(self::CONNECT_TIMEOUT, $this->timeout),
        ];
        if ($this->proxy !== null) {
            $options['proxy'] = $this->proxy;
        }

        return $options;
    }
}
