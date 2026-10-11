<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui;

use App\Core\Security\Totp;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP transport for the 3x-ui v3 panel API (its own description: tests/Fixtures/3x-ui-openapi.json).
 *
 * Every /panel/api endpoint answers the envelope {"success": bool, "msg": string, "obj": mixed}; this class signs in and
 * unwraps `obj`. It signs in with a Bearer API token, or with a session cookie from POST /login. The login sits behind
 * the panel's CSRF guard: GET /csrf-token opens the anonymous session and hands out the token every unsafe request
 * replays. An expired session is signed in again once. Every request is marked XHR, because only then does 3x-ui answer
 * refused credentials with a 401 (otherwise a bare 404, indistinguishable from a wrong base path).
 *
 * Failures are typed so the owner can be told what to fix: ConnectionException (nothing answered),
 * AuthenticationException (token or login refused), UnexpectedResponseException (a 404, a redirect or a web page
 * instead of the API — usually a wrong web base path) and ThreeXuiApiException (the panel said no, and why). A remedy in
 * 3x-ui's own terms rides as the exception's `hint`.
 */
final class ThreeXuiClient
{
    /** What to do about a refused token, in 3x-ui's own terms — appended to the generic explanation. */
    private const HINT_TOKEN = 'توکن را از مسیر Settings → Security → API Token دوباره کپی کنید یا اگر منقضی یا غیرفعال شده، توکن جدیدی بسازید.';
    private const HINT_SCOPE = 'توکنی با دسترسی Admin بسازید؛ توکن‌های Monitor و Node فقط وضعیت را می‌خوانند.';
    private const HINT_TWO_FACTOR = 'به‌جای رمز می‌توانید از توکن API استفاده کنید.';
    /** A 404 from a 3x-ui host is almost always the web base path missing from the URL, or a panel before v3. */
    private const HINT_BASE_PATH = 'آدرس باید شامل مسیر پایه وب باشد (مثل https://host:2053/AbCdEf) و پنل باید نسخه 3 یا بالاتر باشد.';
    private const HINT_REDIRECT = 'معمولا https به‌جای http، یا آدرس با مسیر پایه وب.';
    private const HINT_NOT_API = 'احتمالا آدرس پایه اشتباه است یا نسخه پنل قدیمی است.';

    private readonly CookieJar $cookies;
    private bool $sessionReady = false;
    private ?string $csrfToken = null;

    public function __construct(
        private readonly PanelHttp $http,
        private readonly PanelConnection $connection,
        private readonly PanelCredentials $credentials,
        /** Base32 TOTP secret, for a cookie login on a panel with two-factor authentication on. */
        private readonly ?string $totpSecret = null,
    ) {
        $this->cookies = new CookieJar();
    }

    /**
     * The `obj` of an envelope endpoint, read with GET.
     *
     * @throws ProviderException
     */
    public function get(string $path): mixed
    {
        return $this->request('GET', $path, []);
    }

    /**
     * The `obj` of an envelope endpoint, POSTed a JSON body (none when null).
     *
     * @param array<string, mixed>|list<mixed>|null $json
     * @throws ProviderException
     */
    public function post(string $path, ?array $json = null): mixed
    {
        return $this->request('POST', $path, $json === null ? [] : ['json' => $json]);
    }

    /**
     * Send an authenticated request and unwrap the envelope's `obj`.
     *
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    private function request(string $method, string $path, array $options): mixed
    {
        $response = $this->authenticated($method, $path, $options);
        $body = $this->decode($response);

        if (!($body['success'] ?? false)) {
            throw new ThreeXuiApiException($method, $path, $response->getStatusCode(), (string) ($body['msg'] ?? ''));
        }

        return $body['obj'] ?? null;
    }

    /**
     * Send with the token, or with the session — signing in first when there is none, and once more when it expired.
     *
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    private function authenticated(string $method, string $path, array $options): ResponseInterface
    {
        if ($this->credentials->usesToken()) {
            return $this->guard($this->send($method, $path, PanelHttp::withHeaders($options, ['Authorization' => 'Bearer ' . $this->credentials->token])));
        }

        if (!$this->sessionReady) {
            $this->login();
        }
        $response = $this->send($method, $path, $this->sessionOptions($method, $options));

        if ($this->sessionExpired($response)) {
            $this->http->logger->info('The 3x-ui session at {host} expired; signing in again', ['host' => parse_url($this->connection->baseUrl, PHP_URL_HOST)]);
            $this->login();
            $response = $this->send($method, $path, $this->sessionOptions($method, $options));

            if ($this->sessionExpired($response)) {
                throw AuthenticationException::sessionRejected($response->getStatusCode());
            }
        }

        return $this->guard($response);
    }

    /**
     * Establish the session cookie: the anonymous session's CSRF token first (it survives the login, so it is fetched
     * once), then the login with it — and the one-time code when a TOTP secret is set.
     *
     * @throws ProviderException
     */
    private function login(): void
    {
        if (!$this->credentials->canLogin()) {
            throw AuthenticationException::missingCredentials();
        }

        $payload = ['username' => $this->credentials->username, 'password' => $this->credentials->password];
        if (($this->totpSecret ?? '') !== '') {
            $payload['twoFactorCode'] = Totp::code((string) $this->totpSecret);
        }

        $this->cookies->clear();
        $this->sessionReady = false;
        $this->csrfToken = $this->fetchCsrfToken();

        $response = $this->send('POST', '/login', $this->sessionOptions('POST', ['json' => $payload]));
        if ($response->getStatusCode() === 403) {
            throw AuthenticationException::csrfRejected();
        }

        $body = $this->decode($response);
        if (!($body['success'] ?? false)) {
            $twoFactor = !isset($payload['twoFactorCode']) && $this->twoFactorEnabled();

            throw AuthenticationException::loginFailed((string) ($body['msg'] ?? ''), $twoFactor, $twoFactor ? self::HINT_TWO_FACTOR : '');
        }

        $this->sessionReady = true;
    }

    /**
     * Whether the panel asks for a one-time code at login — only to word a refused login, so a panel that cannot say
     * (an older build without the endpoint) counts as no.
     */
    private function twoFactorEnabled(): bool
    {
        try {
            $body = $this->decode($this->send('POST', '/getTwoFactorEnable', $this->sessionOptions('POST')));
        } catch (ProviderException) {
            return false;
        }

        return (bool) ($body['obj'] ?? false);
    }

    /**
     * The anonymous session's CSRF token, which the login and every later unsafe request replay; '' from a panel without
     * the guard (no such route: 404).
     *
     * @throws ProviderException
     */
    private function fetchCsrfToken(): string
    {
        $response = $this->send('GET', '/csrf-token', ['cookies' => $this->cookies]);
        if ($response->getStatusCode() === 404) {
            return '';
        }

        $token = $this->decode($response)['obj'] ?? null;

        return is_string($token) ? $token : '';
    }

    /** A 401, or (on builds that ignore the XHR marker) a redirect to the panel's own login page. */
    private function sessionExpired(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() === 401) {
            return true;
        }
        if (!PanelHttp::isRedirect($response)) {
            return false;
        }

        $location = rtrim($response->getHeaderLine('Location'), '/');
        $base = $this->connection->baseUrl;

        return $location === $base || $location === rtrim((string) parse_url($base, PHP_URL_PATH), '/');
    }

    /**
     * Answers that are not the panel's API, as typed failures. Requests are marked XHR, so every v3 build answers a 401
     * for credentials it does not take: a 404 really is an unknown route, and a redirect really is one.
     *
     * @throws AuthenticationException|UnexpectedResponseException
     */
    private function guard(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();

        if ($status === 401) {
            throw $this->credentials->usesToken() ? AuthenticationException::rejectedToken($status, self::HINT_TOKEN) : AuthenticationException::sessionRejected($status);
        }
        if ($status === 403 && $this->credentials->usesToken()) {
            throw AuthenticationException::insufficientScope(self::panelMessage($response), self::HINT_SCOPE);
        }
        if (PanelHttp::isRedirect($response)) {
            throw PanelHttp::redirected($response, self::HINT_REDIRECT);
        }
        if (str_contains($response->getHeaderLine('Content-Type'), 'text/html')) {
            throw self::notTheApi($response);
        }

        return $response;
    }

    /** An answer that is not the API, with 3x-ui's likely cause: a 404 is a missing web base path, or a panel before v3. */
    private static function notTheApi(ResponseInterface $response): UnexpectedResponseException
    {
        return PanelHttp::notTheApi($response, self::HINT_BASE_PATH, self::HINT_NOT_API);
    }

    /**
     * The session's cookies, and the CSRF token on an unsafe request.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function sessionOptions(string $method, array $options = []): array
    {
        $headers = $method !== 'GET' && ($this->csrfToken ?? '') !== '' ? ['X-CSRF-Token' => (string) $this->csrfToken] : [];

        return PanelHttp::withHeaders($options, $headers) + ['cookies' => $this->cookies];
    }

    /**
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    private function send(string $method, string $path, array $options): ResponseInterface
    {
        return $this->http->send($this->connection, $method, $path, PanelHttp::withHeaders($options, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']));
    }

    /**
     * The JSON body, or the failure of an answer that is not JSON.
     *
     * @return array<string, mixed>
     * @throws UnexpectedResponseException
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : throw self::notTheApi($response);
    }

    /** The panel's own words in a refusal's body, when it gave some. */
    private static function panelMessage(ResponseInterface $response): string
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) && is_scalar($decoded['msg'] ?? null) ? (string) $decoded['msg'] : '';
    }
}
