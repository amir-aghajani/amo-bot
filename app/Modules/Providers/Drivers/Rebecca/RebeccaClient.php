<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Rebecca;

use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP transport for the Rebecca API (under /api at the panel's root): JSON in, JSON out, an admin's personal `rk_…` API
 * key as the bearer on every call — the way Rebecca asks bots in, never an admin's password. The key acts as its admin,
 * with that admin's role, permissions and services.
 *
 * Failures are typed: ConnectionException (nothing answered), AuthenticationException (a key refused: Rebecca's 401
 * «Could not validate credentials»), NotFoundException (only Rebecca's own "User not found"), UnexpectedResponseException
 * (a route the panel does not have, a redirect, a web page: a wrong address or a Rebecca before its API keys) and
 * RebeccaApiException (the panel said no, in its `detail`: a permission, a limit, a service). The key travels in a
 * header only and reaches no message.
 */
final class RebeccaClient
{
    /** Rebecca's 404 for a user it does not have (deleted ones included). */
    private const USER_NOT_FOUND = 'User not found';

    /** Rebecca's 404 for a route it does not have, in either of its spellings. */
    private const ROUTE_NOT_FOUND = ['not found', 'Not Found'];

    private const HINT_KEY = 'کلید API پیدا نشد، لغو شده یا تاریخش گذشته است، یا ادمینی که کلید به نام اوست در Rebecca غیرفعال شده، زمانش تمام شده یا به سقف حجمش رسیده؛ در «حساب من ← کلیدهای API» پنل کلید تازه‌ای بسازید یا آن ادمین را دوباره فعال کنید.';
    private const HINT_ADDRESS = "آدرس پنل همان آدرس داشبورد Rebecca بدون \u{2068}/dashboard\u{2069} است (مثل https://panel.example.com:8000) و پنل باید کلید API داشته باشد (نسخه 1.0 به بعد).";
    private const HINT_REDIRECT = 'معمولا https به‌جای http، یا آدرس دیگری برای پنل.';
    private const HINT_NOT_API = 'احتمالا آدرس پنل اشتباه است، مثلا آدرس داشبورد یا سایتی دیگر.';

    public function __construct(
        private readonly PanelHttp $http,
        private readonly PanelConnection $connection,
        private readonly PanelCredentials $credentials,
    ) {}

    /**
     * @param array<string, scalar> $query
     * @return array<mixed>
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
     * GET one of the panel's public subscription routes, without the key: its JSON object, or null for any other answer
     * (PanelHttp::anonymous()).
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
     * @return array<mixed>
     * @throws ProviderException
     */
    private function request(string $method, string $path, array $options): array
    {
        if (!$this->credentials->usesToken()) {
            throw AuthenticationException::missingCredentials();
        }

        $response = $this->http->send($this->connection, $method, $path, PanelHttp::withHeaders($options, [
            'Authorization' => 'Bearer ' . (string) $this->credentials->token,
            'Accept' => 'application/json',
        ]));

        return $this->decode($method, $path, $response);
    }

    /**
     * The answer's JSON body, or the typed failure it stands for. `$method` and `$path` name the call in the log.
     *
     * @return array<mixed>
     * @throws ProviderException
     */
    private function decode(string $method, string $path, ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if (PanelHttp::isRedirect($response)) {
            throw PanelHttp::redirected($response, self::HINT_REDIRECT);
        }

        // Every answer of the API is JSON, its refusals included; anything else came from something else.
        $raw = trim((string) $response->getBody());
        if ($raw === '' && $status < 300) {
            return [];
        }
        $body = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($body)) {
            throw PanelHttp::notTheApi($response, self::HINT_ADDRESS, self::HINT_NOT_API);
        }
        if ($status < 300) {
            return $body;
        }

        $detail = self::detail($body);

        throw match (true) {
            $status === 401 => new AuthenticationException("The panel refused the API key (HTTP 401): {$detail}", AuthenticationFailure::Token, $detail, self::HINT_KEY),
            $status === 404 && $detail === self::USER_NOT_FOUND => new NotFoundException("Rebecca {$method} {$path}: {$detail}"),
            $status === 404 && in_array($detail, self::ROUTE_NOT_FOUND, true) => new UnexpectedResponseException(404, hint: self::HINT_ADDRESS),
            default => RebeccaApiException::refused($method, $path, $status, $detail),
        };
    }

    /**
     * The panel's `detail` as one line: Rebecca's sentence, or — from a client that sends a Marzban-shaped body — a list.
     *
     * @param array<mixed> $body
     */
    private static function detail(array $body): string
    {
        $detail = $body['detail'] ?? null;
        if (!is_array($detail)) {
            return is_scalar($detail) ? (string) $detail : '';
        }

        return implode('; ', array_map(static fn(mixed $line): string => is_scalar($line) ? (string) $line : (string) json_encode($line), $detail));
    }
}
