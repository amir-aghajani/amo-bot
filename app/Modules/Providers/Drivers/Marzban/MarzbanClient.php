<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Marzban;

use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP transport for the Marzban API (FastAPI, under /api at the panel's root): JSON in, JSON out.
 *
 * Marzban's API has no keys: the client trades an admin's username and password at POST /api/admin/token (an OAuth2
 * password form) for a bearer token it keeps — a day by default (the panel's JWT_ACCESS_TOKEN_EXPIRE_MINUTES) —, for the
 * client's life and, with a saved server's MarzbanTokens, across requests: the panel reports every sign-in to its
 * Telegram admins, so it is not signed in to on every call, nor by every PHP request. A kept token the panel refuses
 * (expired, password changed, admin removed) is dropped and renewed once.
 *
 * Failures are typed: ConnectionException (nothing answered), AuthenticationException (credentials refused),
 * NotFoundException (only Marzban's own "User not found" 404), UnexpectedResponseException (an unknown route, a
 * redirect, a web page: a wrong address) and MarzbanApiException (the panel said no, in its `detail`). No secret reaches
 * a message or a log line: the token and password travel in the header and the sign-in form only, and a sign-in answer
 * is never quoted.
 */
final class MarzbanClient
{
    /** The `detail` of Marzban's 404 for a user it does not have. */
    private const USER_NOT_FOUND = 'User not found';

    /** The `detail` of the framework's 404 for a route the panel does not have. */
    private const ROUTE_NOT_FOUND = 'Not Found';

    private const HINT_ADDRESS = "آدرس پنل همان آدرس داشبورد بدون \u{2068}/dashboard\u{2069} است (مثل https://panel.example.com:8000) و نسخه پنل باید 0.8 یا بالاتر باشد.";
    private const HINT_REDIRECT = 'معمولا https به‌جای http، یا آدرس دیگری برای پنل.';
    private const HINT_NOT_API = 'احتمالا آدرس پنل اشتباه است، مثلا آدرس داشبورد یا سایتی دیگر.';

    private const SIGN_IN = '/api/admin/token';

    /** The bearer token of the last sign-in, while the panel takes it. */
    private ?string $token = null;

    public function __construct(
        private readonly PanelHttp $http,
        private readonly PanelConnection $connection,
        private readonly PanelCredentials $credentials,
        /** Where tokens outlive the client, for the saved server `$server`; null: for its life alone. */
        private readonly ?MarzbanTokens $tokens = null,
        private readonly ?int $server = null,
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
     * GET one of the panel's public subscription routes, without the admin's token: its JSON object, or null for any
     * other answer (PanelHttp::anonymous()).
     *
     * @return array<string, mixed>|null
     * @throws ProviderException when nothing answered
     */
    public function anonymous(string $path): ?array
    {
        return $this->http->anonymous($this->connection, $path);
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
     * Send with the kept token — the client's, else the one kept across requests —, signing in for a fresh one when
     * there is none or the panel refused the kept one (dropped then). A fresh token refused too is the end of it. Only a
     * token this client sent is dropped: one another request kept meanwhile stays.
     *
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    private function authorized(string $method, string $path, array $options): ResponseInterface
    {
        $kept = $this->token ??= $this->kept();
        $sent = $kept ?? $this->signIn();
        $response = $this->send($method, $path, PanelHttp::withHeaders($options, ['Authorization' => 'Bearer ' . $sent]));
        if ($response->getStatusCode() === 401 && $kept !== null) {
            $this->http->logger->info('Marzban at {host} refused the kept token; signing in again', ['host' => parse_url($this->connection->baseUrl, PHP_URL_HOST)]);
            $this->drop($kept);
            $sent = $this->signIn();
            $response = $this->send($method, $path, PanelHttp::withHeaders($options, ['Authorization' => 'Bearer ' . $sent]));
        }
        if ($response->getStatusCode() === 401) {
            $this->drop($sent);

            throw AuthenticationException::sessionRejected(401);
        }

        return $response;
    }

    /**
     * Trade the admin's username and password for a token, and keep it — across requests too.
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

        // 401: "Incorrect username or password" — an admin of the panel's database or its env's SUDO_USERNAME alike.
        $status = $response->getStatusCode();
        $refusal = $status === 401 ? json_decode((string) $response->getBody(), true) : null;
        if (is_array($refusal) && isset($refusal['detail'])) {
            throw AuthenticationException::loginFailed(self::detail($refusal), false);
        }

        // A sign-in answer is never quoted in an error: whatever it holds may be a credential.
        $token = $this->decode('POST', self::SIGN_IN, $response)['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new UnexpectedResponseException($status, hint: self::HINT_NOT_API);
        }
        $issuedFor = $this->issuedFor();
        if ($issuedFor !== null) {
            $this->tokens?->keep($issuedFor, $token);
        }

        return $this->token = $token;
    }

    /** The token kept across requests for this server and these credentials, if any. */
    private function kept(): ?string
    {
        $issuedFor = $this->issuedFor();

        return $issuedFor !== null ? $this->tokens?->find($issuedFor) : null;
    }

    /** `$token`, which the panel refused, forgotten — by the client, and across requests while it is still the one kept there. */
    private function drop(string $token): void
    {
        $this->token = null;
        $issuedFor = $this->issuedFor();
        if ($issuedFor !== null) {
            $this->tokens?->forget($issuedFor, $token);
        }
    }

    /**
     * Everything a token is issued for, as MarzbanTokens keys it: the saved server, the panel's address, the way there
     * (the proxy, its login included, and the TLS check — as a kept password is bound to them) and the admin's username
     * and password. Null without a store, a saved server or a login: nothing is kept then.
     */
    private function issuedFor(): ?string
    {
        if ($this->tokens === null || $this->server === null || !$this->credentials->canLogin()) {
            return null;
        }

        return (string) json_encode([$this->server, $this->connection->baseUrl, $this->connection->proxy, $this->connection->verifyTls, $this->credentials->username, $this->credentials->password]);
    }

    /**
     * The answer's JSON body, or the typed failure it stands for. `$method` and `$path` name the call in the log.
     *
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function decode(string $method, string $path, ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if (PanelHttp::isRedirect($response)) {
            throw PanelHttp::redirected($response, self::HINT_REDIRECT);
        }

        // Every answer of the API is JSON, its refusals included — its own 500 is the framework's plain text, read here
        // as something else's page, which a write may have come before.
        $raw = (string) $response->getBody();
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body) || ($raw === '' && $status >= 400)) {
            throw self::notTheApi($response);
        }
        if ($status < 300) {
            return $body;
        }

        $detail = self::detail($body);

        throw match (true) {
            $status === 404 && $detail === self::USER_NOT_FOUND => new NotFoundException("Marzban {$method} {$path}: {$detail}"),
            $status === 404 && $detail === self::ROUTE_NOT_FOUND => self::notTheApi($response),
            default => MarzbanApiException::refused($method, $path, $status, $detail),
        };
    }

    /**
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    private function send(string $method, string $path, array $options): ResponseInterface
    {
        return $this->http->send($this->connection, $method, $path, PanelHttp::withHeaders($options, ['Accept' => 'application/json']));
    }

    /** An answer that is not the API, with Marzban's likely cause: the dashboard's or another site's address, or a panel too old. */
    private static function notTheApi(ResponseInterface $response): UnexpectedResponseException
    {
        return PanelHttp::notTheApi($response, self::HINT_ADDRESS, self::HINT_NOT_API);
    }

    /**
     * The panel's `detail` as one line: a sentence, Marzban's field → message map (its 422s), or the framework's list.
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
}
