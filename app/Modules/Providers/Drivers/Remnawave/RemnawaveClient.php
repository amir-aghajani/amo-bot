<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Remnawave;

use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use App\Modules\Providers\Support\Raw;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP transport for the Remnawave API (NestJS, under /api at the panel's root): JSON in, JSON out, each answer inside
 * `{"response": …}` — what the calls return —, an API token made in the panel as the bearer on every call. An admin's
 * own sign-in token is refused for API calls, and its password sign-in wants a browser: the token is the one way in.
 *
 * Remnawave drops — no answer at all — every request that did not come through an HTTPS reverse proxy (no
 * `X-Forwarded-For`, or `X-Forwarded-Proto` other than https). Through the owner's https address the proxy sets them;
 * to a plain http address (the panel's own port) the client sets them itself. A panel behind an auth portal (Caddy's,
 * TinyAuth) lets API calls through only with the portal's key in `X-Api-Key`: the gate key, sent as it was given.
 *
 * Failures are typed: ConnectionException (nothing answered — a dropped request too, with how Remnawave is reached),
 * AuthenticationException (a token
 * refused: 401; an admin's sign-in token, or one without the scope a call needs or deleted: 403 — never "another admin's
 * user": Remnawave has one kind of admin), NotFoundException (only Remnawave's own "user not found"),
 * UnexpectedResponseException (a route the panel does not have, a redirect, a web page: a wrong address, a portal in
 * front, a Remnawave before 2.1) and RemnawaveApiException (the panel said no, in its `message`). The token and the gate
 * key travel in headers only and reach no message; nor does a subscription's short id a lookup carries in its path.
 */
final class RemnawaveClient
{
    /** Remnawave's codes for a user it does not have: by its id (A025), by a name or short id asked for (A063). */
    private const USER_NOT_FOUND = ['A025', 'A063'];

    /** How Nest words a 404 for a route it does not have: "Cannot GET /api/…". */
    private const UNKNOWN_ROUTE = '/^Cannot [A-Z]+ \//';

    /** Remnawave's 403 for an admin's sign-in token used as an API token. */
    private const ADMIN_TOKEN = 'For API requests you must create own API-token in the admin dashboard.';

    /** What the shop's calls carry in a path that is a customer's secret: a subscription's short id. */
    private const SECRET_PATH = '#/by-short-uuid/([^/?]+)#';

    /** What to do about a refusal, in Remnawave's own terms — appended to the generic explanation. */
    private const HINT_TOKEN = "توکن \u{2068}API\u{2069} اشتباه است، مال پنل دیگری است یا تاریخش گذشته (هر توکن \u{2068}Remnawave\u{2069} مدت اعتبار دارد)؛ در «\u{2068}Remnawave Settings\u{2069} ← \u{2068}API Tokens\u{2069}» پنل توکن تازه‌ای بسازید.";
    private const HINT_ADMIN_TOKEN = "این توکن ورود یک ادمین است، نه توکن \u{2068}API\u{2069}؛ در «\u{2068}Remnawave Settings\u{2069} ← \u{2068}API Tokens\u{2069}» پنل یک توکن \u{2068}API\u{2069} بسازید و همان را وارد کنید.";
    private const HINT_SCOPE = "توکن \u{2068}API\u{2069} دسترسی این کار را ندارد یا در پنل حذف شده است؛ در «\u{2068}Remnawave Settings\u{2069} ← \u{2068}API Tokens\u{2069}» توکن تازه‌ای با دسترسی‌های \u{2068}users:*\u{2069}، \u{2068}internal-squads:read\u{2069} و \u{2068}system:read\u{2069} بسازید.";
    private const HINT_ADDRESS = "آدرس پنل همان آدرس دامنه‌ای است که داشبورد \u{2068}Remnawave\u{2069} با آن باز می‌شود، بدون مسیر صفحه‌ها (مثل \u{2068}https://panel.example.com\u{2069})، و نسخه پنل باید 2.1 یا بالاتر باشد.";
    private const HINT_REDIRECT = "معمولا \u{2068}https\u{2069} به‌جای \u{2068}http\u{2069}، آدرس دیگری برای پنل، یا دروازه ورودی (\u{2068}Caddy\u{2069} یا \u{2068}TinyAuth\u{2069}) جلوی پنل که «کلید دروازه» نگرفت یا آن را نپذیرفت.";
    private const HINT_NOT_API = 'احتمالا آدرس پنل اشتباه است، مثلا آدرس یکی از صفحه‌های پنل، صفحه اشتراک یا سایتی دیگر.';
    private const HINT_GATE = "احتمالا دروازه ورودی (\u{2068}Caddy\u{2069} یا \u{2068}TinyAuth\u{2069}) جلوی پنل درخواست را نپذیرفت؛ کلید \u{2068}API\u{2069} همان دروازه را در «کلید دروازه» بدهید.";
    private const HINT_DROPPED = "\u{2068}Remnawave\u{2069} فقط به درخواستی جواب می‌دهد که از ریورس‌پراکسی \u{2068}HTTPS\u{2069} آمده باشد؛ آدرس پنل را همان آدرس دامنه با \u{2068}https\u{2069} بدهید. پورت خود پنل را فقط با \u{2068}http\u{2069} و از همان سرور یا شبکه خصوصی آن می‌شود داد.";
    private const HINT_GATEWAY = "پراکسی جلوی پنل از \u{2068}Remnawave\u{2069} جوابی نگرفت: پنل خاموش است، یا پراکسی هدرهای \u{2068}X-Forwarded-For\u{2069} و \u{2068}X-Forwarded-Proto\u{2069} را نمی‌فرستد و \u{2068}Remnawave\u{2069} درخواست بدون آن‌ها را بی‌جواب می‌گذارد.";

    /** The usual port of an https address: a TLS failure there is its certificate's, not Remnawave's plain port. */
    private const HTTPS_PORT = 443;

    /** cURL's failed TLS handshake — what speaking TLS to a plain http port gets; a certificate refused is another (60). */
    private const HANDSHAKE_FAILED = 'cURL error 35';

    /** Names no public resolver answers for: a home or office network's, or the machine's own. */
    private const PRIVATE_SUFFIXES = ['.localhost', '.local', '.lan', '.internal', '.home.arpa', '.localdomain'];

    /** A proxy's answer for a panel that gave it none. */
    private const BAD_GATEWAY = 502;

    public function __construct(
        private readonly PanelHttp $http,
        private readonly PanelConnection $connection,
        private readonly PanelCredentials $credentials,
        /** The auth portal's key in front of the panel (`X-Api-Key`); null for a panel without one. */
        #[\SensitiveParameter]
        private readonly ?string $gateKey = null,
    ) {}

    /**
     * @param array<string, scalar> $query
     * @return array<mixed> The answer's `response`
     * @throws ProviderException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<mixed>
     * @throws ProviderException
     */
    public function post(string $path, ?array $json = null): array
    {
        return $this->request('POST', $path, $json === null ? [] : ['json' => $json]);
    }

    /**
     * @param array<string, mixed> $json
     * @return array<mixed>
     * @throws ProviderException
     */
    public function patch(string $path, array $json): array
    {
        return $this->request('PATCH', $path, ['json' => $json]);
    }

    /** @throws ProviderException */
    public function delete(string $path): void
    {
        $this->request('DELETE', $path, []);
    }

    /**
     * @param array<string, mixed> $options Guzzle request options
     * @return array<mixed>
     * @throws ProviderException
     */
    private function request(string $method, string $path, array $options): array
    {
        if (!$this->credentials->usesToken()) {
            throw AuthenticationException::missingCredentials();
        }

        try {
            $response = $this->http->send($this->connection, $method, $path, PanelHttp::withHeaders($options, $this->headers()));
        } catch (ConnectionException $e) {
            throw $this->dropped($e) ? $e->withHint(self::HINT_DROPPED) : $e;
        }

        $secret = preg_match(self::SECRET_PATH, $path, $match) === 1 ? $match[1] : null;

        return $this->decode($method, preg_replace(self::SECRET_PATH, '/by-short-uuid/…', $path) ?? '', $response, $secret);
    }

    /**
     * Whether a host is this server or on its private network: localhost, a loopback, private or shared (100.64.0.0/10,
     * a Tailscale-style link's) address, a name of one word (a Docker service's, as the panel's own compose file names its
     * containers), or a name under a private suffix (PRIVATE_SUFFIXES).
     */
    private static function nearby(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$ip) {
            return $host !== '' && (!str_contains($host, '.') || array_filter(self::PRIVATE_SUFFIXES, static fn(string $suffix): bool => str_ends_with($host, $suffix)) !== []);
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (ip2long($host) & 0xFFC00000) === 0x64400000);
    }

    /**
     * Whether a connection failure may be Remnawave dropping the request: cut off with no answer, or — to an https address
     * on a port of its own — a failed TLS handshake, what the panel's plain port gets. One on 443, or a certificate
     * refused, is the proxy's certificate.
     */
    private function dropped(ConnectionException $e): bool
    {
        $port = parse_url($this->connection->baseUrl, PHP_URL_PORT);

        return $e->failure === ConnectionFailure::Other
            || ($e->failure === ConnectionFailure::Tls && is_int($port) && $port !== self::HTTPS_PORT && str_contains($e->detail, self::HANDSHAKE_FAILED));
    }

    /**
     * Every call's headers: the token; to a plain http address on this server or its private network (the panel reached
     * on its own port, no proxy in front) what an HTTPS reverse proxy would have added, without which Remnawave drops the
     * request — never across the internet, where the token and the customers' secrets would travel unencrypted —; the
     * gate key when there is one.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['Authorization' => 'Bearer ' . (string) $this->credentials->token, 'Accept' => 'application/json'];
        if (str_starts_with(strtolower($this->connection->baseUrl), 'http://') && self::nearby((string) parse_url($this->connection->baseUrl, PHP_URL_HOST))) {
            $headers += ['X-Forwarded-For' => '127.0.0.1', 'X-Forwarded-Proto' => 'https'];
        }
        if (($this->gateKey ?? '') !== '') {
            $headers['X-Api-Key'] = (string) $this->gateKey;
        }

        return $headers;
    }

    /**
     * The answer's `response`, or the typed failure it stands for. `$method` and `$shown` name the call in the log (no
     * customer's secret in it); `$secret`, the short id a lookup carried, is masked in whatever of the answer a failure
     * quotes — a redirect's address or a page's start may repeat the path asked for (TinyAuth's login redirect does).
     * A 5xx in Remnawave's words to a write may come after the write went through — its create reads the new user back,
     * and fails so when that read does —: the caller asks the panel how it stands.
     *
     * @return array<mixed>
     * @throws ProviderException
     */
    private function decode(string $method, string $shown, ResponseInterface $response, ?string $secret = null): array
    {
        $status = $response->getStatusCode();
        $hide = static fn(string $text): string => $secret === null ? $text : str_replace([$secret, rawurlencode($secret)], '…', $text);
        if (PanelHttp::isRedirect($response)) {
            throw new UnexpectedResponseException($status, location: $hide($response->getHeaderLine('Location')), hint: self::HINT_REDIRECT);
        }

        // Every answer of the API is JSON, its refusals included; anything else came from something else.
        $raw = trim((string) $response->getBody());
        if ($raw === '' && $status < 300) {
            return [];
        }
        $body = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($body)) {
            $page = PanelHttp::notTheApi($response, self::HINT_ADDRESS, self::HINT_NOT_API);

            // A portal in front of the panel turns a call without its key away with a page of its own; a proxy that got no
            // answer — the panel down, or dropping what the proxy sent without its forwarded headers — answers with its own.
            throw new UnexpectedResponseException($status, $hide($page->excerpt), hint: match (true) {
                in_array($status, [401, 403], true) => self::HINT_GATE,
                $status === self::BAD_GATEWAY => self::HINT_GATEWAY,
                default => $page->hint,
            });
        }
        if ($status < 300) {
            if (!array_key_exists('response', $body)) {
                $page = PanelHttp::notTheApi($response, self::HINT_ADDRESS, self::HINT_NOT_API);

                throw new UnexpectedResponseException($status, $hide($page->excerpt), hint: $page->hint);
            }

            return is_array($body['response']) ? $body['response'] : [];
        }

        // Remnawave words every refusal with Nest's `statusCode` or its own `errorCode`. A 401 or 403 in JSON with neither
        // is a portal's in front of the panel, turning away an API client without its key in JSON of its own — TinyAuth's
        // {"status": 401, "message": "Unauthorized"} to a caller that asks for no page —: the gate key's failure, not the
        // token's.
        if (in_array($status, [401, 403], true) && !isset($body['statusCode']) && !isset($body['errorCode'])) {
            throw new UnexpectedResponseException($status, $hide(PanelHttp::notTheApi($response, '', '')->excerpt), hint: self::HINT_GATE);
        }

        $said = self::said($body);
        $code = Raw::string($body, 'errorCode');

        $failure = match (true) {
            $status === 401 => new AuthenticationException("The panel refused the API token (HTTP 401): {$said}", AuthenticationFailure::Token, $said, self::HINT_TOKEN),
            // A valid token all the same — an admin's sign-in — that may call no API route: a scope it lacks, in effect.
            $status === 403 => AuthenticationException::insufficientScope($said, $said === self::ADMIN_TOKEN ? self::HINT_ADMIN_TOKEN : self::HINT_SCOPE),
            $status === 404 && in_array($code, self::USER_NOT_FOUND, true) => new NotFoundException("Remnawave {$method} {$shown}: {$said}"),
            // Nest's words name the route asked for: the panel's address, which no message repeats.
            $status === 404 && preg_match(self::UNKNOWN_ROUTE, $said) === 1 => new UnexpectedResponseException(404, hint: self::HINT_ADDRESS),
            default => RemnawaveApiException::refused($method, $shown, $status, $said, $code),
        };

        throw $failure instanceof RemnawaveApiException && $status >= 500 && $method !== 'GET' ? $failure->afterWrite() : $failure;
    }

    /**
     * The panel's `message` as one line: Remnawave's sentence, or its validation's — "Validation failed", then each
     * field it refused with why.
     *
     * @param array<mixed> $body
     */
    private static function said(array $body): string
    {
        $message = $body['message'] ?? $body['error'] ?? null;
        $said = match (true) {
            is_array($message) => implode('; ', Raw::stringsOf($message)),
            is_scalar($message) => (string) $message,
            default => '',
        };

        $issues = [];
        foreach (Raw::objectsOf($body['errors'] ?? null) as $issue) {
            $field = implode('.', Raw::stringsOf($issue['path'] ?? null));
            $issues[] = ($field !== '' ? "{$field}: " : '') . Raw::string($issue, 'message');
        }

        return $issues === [] ? $said : ltrim("{$said}: " . implode('; ', $issues), ': ');
    }
}
