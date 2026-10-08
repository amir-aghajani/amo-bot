<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\PasarGuard;

use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP transport for the PasarGuard API (FastAPI, under /api at the panel's root): JSON in, JSON out.
 *
 * It signs in with an API key (`X-Api-Key`, PasarGuard 5.1+), or with an admin's username and password traded at
 * POST /api/admin/token (an OAuth2 password form) for a bearer token it keeps — the driver instance lives a while
 * (ProviderRegistry), so the panel is not signed in to, nor its admins notified of a login, on every call — and signs in
 * again once when the panel refuses the token it kept (expired, or the password changed since).
 *
 * Failures are typed: ConnectionException (nothing answered), AuthenticationException (credentials refused),
 * NotFoundException (the panel has no such user — its "User not found", the only 404 that means so),
 * UnexpectedResponseException (an unknown route, a redirect, a web page instead of the API: a wrong address or a panel
 * too old) and PasarGuardApiException (the panel said no, in its `detail`). No secret reaches a message or a log line:
 * the key, the token and the password travel in headers and the sign-in form only, and a sign-in answer is never quoted.
 */
final class PasarGuardClient
{
    /** The `detail` of PasarGuard's 404 for a user it does not have. */
    private const USER_NOT_FOUND = 'User not found';

    /** The `detail` of the framework's 404 for a route the panel does not have. */
    private const ROUTE_NOT_FOUND = 'Not Found';

    /** What to do about a refused API key, in PasarGuard's own terms — appended to the generic explanation. */
    private const HINT_KEY = 'کلید API پیدا نشد یا منقضی یا لغو شده است؛ از بخش API Keys پنل کلید تازه‌ای بسازید. کلید API از نسخه 5.1 پنل به بعد کار می‌کند؛ در نسخه‌های قدیمی‌تر با نام کاربری و رمز وصل شوید.';
    private const HINT_KEY_SCOPE = 'کلید را با دسترسی کامل به کاربران و دسترسی خواندن گروه‌ها و سیستم بسازید (یا دسترسی‌های ادمین صاحب کلید را به آن بدهید).';
    private const HINT_DISABLED = 'حساب این ادمین در PasarGuard غیرفعال است؛ آن را در بخش Admins پنل فعال کنید.';
    private const HINT_ENV_ADMIN = 'ادمینی که در فایل env پنل تعریف شده (SUDO_USERNAME) بیرون از حالت Debug وارد نمی‌شود؛ در داشبورد یک ادمین بسازید و با آن وصل شوید.';
    private const HINT_ADDRESS = 'آدرس پنل همان آدرس داشبورد بدون /dashboard است (مثل https://panel.example.com:8000) و نسخه پنل باید 3.1 یا بالاتر باشد.';
    private const HINT_REDIRECT = 'معمولا https به‌جای http، یا آدرس دیگری برای پنل.';
    private const HINT_NOT_API = 'احتمالا آدرس پنل اشتباه است، مثلا آدرس داشبورد یا سایتی دیگر.';

    private const SIGN_IN = '/api/admin/token';

    /** The bearer token of the last sign-in, while the panel takes it. */
    private ?string $token = null;

    public function __construct(
        private readonly PanelHttp $http,
        private readonly PanelConnection $connection,
        private readonly PanelCredentials $credentials,
    ) {}

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function post(string $path, ?array $json = null): array
    {
        return $this->request('POST', $path, $json === null ? [] : ['json' => $json]);
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     * @throws ProviderException
     */
    public function put(string $path, array $json): array
    {
        return $this->request('PUT', $path, ['json' => $json]);
    }

    /** @throws ProviderException */
    public function delete(string $path): void
    {
        $this->request('DELETE', $path, []);
    }

    /**
     * @param array<string, mixed> $options Guzzle request options
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function request(string $method, string $path, array $options): array
    {
        return $this->decode($method, $path, $this->authorized($method, $path, $options));
    }

    /**
     * Send with the API key, or with the kept token — a fresh one when there is none, or when the panel refused the kept
     * one. A fresh token refused too is the end of it.
     *
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    private function authorized(string $method, string $path, array $options): ResponseInterface
    {
        if ($this->credentials->usesToken()) {
            return $this->send($method, $path, self::withHeader($options, 'X-Api-Key', (string) $this->credentials->token));
        }

        $kept = $this->token;
        $response = $this->send($method, $path, self::withHeader($options, 'Authorization', 'Bearer ' . ($kept ?? $this->signIn())));
        if ($response->getStatusCode() === 401 && $kept !== null) {
            $this->http->logger->info('PasarGuard at {host} refused the kept token; signing in again', ['host' => parse_url($this->connection->baseUrl, PHP_URL_HOST)]);
            $response = $this->send($method, $path, self::withHeader($options, 'Authorization', 'Bearer ' . $this->signIn()));
        }
        if ($response->getStatusCode() === 401) {
            $this->token = null;

            throw AuthenticationException::sessionRejected(401);
        }

        return $response;
    }

    /**
     * Trade the admin's username and password for a token, and keep it.
     *
     * @throws ProviderException
     */
    private function signIn(): string
    {
        $this->token = null;
        if (!$this->credentials->canLogin()) {
            throw AuthenticationException::missingCredentials();
        }

        $response = $this->send('POST', self::SIGN_IN, ['form_params' => ['grant_type' => 'password', 'username' => $this->credentials->username, 'password' => $this->credentials->password]]);

        // 401: wrong username or password; 403: the admin is disabled; 400: an env-defined admin outside debug mode.
        $status = $response->getStatusCode();
        $refusal = in_array($status, [400, 401, 403], true) ? json_decode((string) $response->getBody(), true) : null;
        if (is_array($refusal) && isset($refusal['detail'])) {
            throw AuthenticationException::loginFailed(self::detail($refusal), false, match ($status) {
                400 => self::HINT_ENV_ADMIN,
                403 => self::HINT_DISABLED,
                default => '',
            });
        }

        // A sign-in answer is never quoted in an error: whatever it holds may be a credential.
        $token = $this->decode('POST', self::SIGN_IN, $response)['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new UnexpectedResponseException($status, hint: self::HINT_NOT_API);
        }

        return $this->token = $token;
    }

    /**
     * The answer's JSON body, or the typed failure it stands for.
     *
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function decode(string $method, string $path, ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            throw new UnexpectedResponseException($status, location: $response->getHeaderLine('Location'), hint: self::HINT_REDIRECT);
        }

        // Every answer of the API is JSON, its refusals included; anything else came from something else.
        $raw = (string) $response->getBody();
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body) || ($raw === '' && $status >= 400)) {
            throw self::unexpected($response);
        }
        if ($status < 300) {
            return $body;
        }

        $detail = self::detail($body);

        throw match (true) {
            $status === 401 => AuthenticationException::rejectedToken($status, self::HINT_KEY),
            $status === 403 && $this->credentials->usesToken() => AuthenticationException::insufficientScope($detail, self::HINT_KEY_SCOPE),
            $status === 404 && $detail === self::USER_NOT_FOUND => new NotFoundException("PasarGuard {$method} {$path}: {$detail}"),
            $status === 404 && $detail === self::ROUTE_NOT_FOUND => self::unexpected($response),
            default => new PasarGuardApiException($method, $path, $status, $detail),
        };
    }

    /**
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    private function send(string $method, string $path, array $options): ResponseInterface
    {
        return $this->http->send($this->connection, $method, $path, self::withHeader($options, 'Accept', 'application/json'));
    }

    /** An answer that is not the API, with PasarGuard's guess at why. */
    private static function unexpected(ResponseInterface $response): UnexpectedResponseException
    {
        $status = $response->getStatusCode();

        return new UnexpectedResponseException($status, PanelHttp::excerpt($response), hint: match (true) {
            $status === 404 => self::HINT_ADDRESS,
            $status < 400 => self::HINT_NOT_API,
            default => '',
        });
    }

    /**
     * The panel's `detail`: a sentence, a field → message map (its own 422s), or the framework's list of errors — as one
     * line.
     *
     * @param array<mixed> $body
     */
    private static function detail(array $body): string
    {
        $detail = $body['detail'] ?? null;
        if (!is_array($detail)) {
            return is_scalar($detail) ? (string) $detail : '';
        }

        $lines = [];
        foreach ($detail as $field => $message) {
            if (is_array($message)) {
                $location = is_array($message['loc'] ?? null) ? $message['loc'] : [];
                $field = end($location);
                $message = $message['msg'] ?? '';
            }
            $text = is_scalar($message) ? (string) $message : '';
            $lines[] = is_string($field) && $field !== '' ? "{$field}: {$text}" : $text;
        }

        return implode('; ', $lines);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function withHeader(array $options, string $name, string $value): array
    {
        $options['headers'] = [$name => $value] + (array) ($options['headers'] ?? []);

        return $options;
    }
}
