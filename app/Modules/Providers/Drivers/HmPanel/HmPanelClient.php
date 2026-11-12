<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\HmPanel;

use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use App\Modules\Providers\Support\Raw;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP transport for HMPanel's key API (NestJS, under /api at the panel's root; its routes under /api/v1): JSON in, JSON
 * out, an `hmp_…` API key as the bearer on every call. The key acts as one HMPanel admin — a reseller's reaches only its
 * own clients — and the panel answers each key a limited number of calls a minute (60 unless it was made with more).
 *
 * Failures are typed: ConnectionException (nothing answered), AuthenticationException (a key refused, or one without a
 * scope a call needs — HMPanel's 403 «Missing scope: …» — named in its hint), NotFoundException (only HMPanel's own
 * "Client not found"), UnexpectedResponseException (a route the panel does not have, a redirect, a web page: a wrong
 * address or a panel before 2.5) and HmPanelApiException (the panel said no, in its `message`; a key past its calls per
 * minute too). The key travels in a header only and reaches no message; a lookup's value travels in the query, which no
 * message repeats either.
 */
final class HmPanelClient
{
    /** The scopes the shop's calls need (HmPanelProvider): clients read, made and changed, their usage reset, inbounds read. */
    public const SCOPES = ['clients.read', 'clients.write', 'clients.renew', 'inbounds.read'];

    /** The scope of a key made with every right. */
    public const EVERY_SCOPE = '*';

    /** Where the panel serves its API (Nest's global prefix). */
    private const API = '/api';

    /** HMPanel's 404 for a client it does not have, or not within the key's admin's. */
    private const CLIENT_NOT_FOUND = 'Client not found';

    /** How Nest words a 404 for a route it does not have: "Cannot GET /api/v1/…". */
    private const UNKNOWN_ROUTE = '/^Cannot [A-Z]+ \//';

    /** How HMPanel words a key without the scope a route needs. */
    private const MISSING_SCOPE = '/^Missing scope: (\S+)$/';

    /** What to do about a refused key, in HMPanel's own terms — appended to the generic explanation. */
    private const HINT_KEY = 'کلید API پیدا نشد یا لغو شده است، یا ادمینی که کلید به نام اوست در HMPanel غیرفعال یا منقضی شده؛ در «تنظیمات ← API» پنل کلید تازه‌ای بسازید یا آن ادمین را فعال کنید.';
    private const HINT_ADDRESS = 'آدرس پنل همان آدرس صفحه ورود HMPanel است، بدون مسیرهایی مثل /api یا /dashboard (مثل https://panel.example.com)، و نسخه پنل باید 2.5 یا بالاتر باشد.';
    private const HINT_REDIRECT = 'معمولا https به‌جای http، یا آدرس دیگری برای پنل.';
    private const HINT_NOT_API = 'احتمالا آدرس پنل اشتباه است، مثلا آدرس یکی از صفحه‌های پنل یا سایتی دیگر.';

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
     * What to do about a key without `$missing` scopes, in HMPanel's own terms: a key's scopes are chosen as it is made,
     * so another key is made.
     *
     * @param list<string> $missing
     */
    public static function scopeHint(array $missing): string
    {
        return 'کلید دسترسی ' . implode('، ', $missing) . ' را ندارد؛ در «تنظیمات ← API» پنل کلید تازه‌ای با دسترسی * بسازید، یا با clients.read، clients.write، clients.renew و inbounds.read.';
    }

    /**
     * @param array<string, mixed> $options Guzzle request options
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function request(string $method, string $path, array $options): array
    {
        if (!$this->credentials->usesToken()) {
            throw AuthenticationException::missingCredentials();
        }

        $response = $this->http->send($this->connection, $method, self::API . $path, PanelHttp::withHeaders($options, [
            'Authorization' => 'Bearer ' . (string) $this->credentials->token,
            'Accept' => 'application/json',
        ]));

        return $this->decode($method, $path, $response);
    }

    /**
     * The answer's JSON body, or the typed failure it stands for. `$method` and `$path` name the call in the log. A
     * success that is no object (a bare `true`) is an empty one.
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

        // Every answer of the API is JSON, its refusals included; anything else came from something else.
        $raw = trim((string) $response->getBody());
        if ($raw === '' && $status < 300) {
            return [];
        }
        if ($raw === '' || !json_validate($raw)) {
            throw PanelHttp::notTheApi($response, self::HINT_ADDRESS, self::HINT_NOT_API);
        }
        $body = json_decode($raw, true);
        if ($status < 300) {
            return is_array($body) ? $body : [];
        }

        $said = self::said(is_array($body) ? $body : []);

        throw match (true) {
            $status === 401 => new AuthenticationException("The panel refused the API key (HTTP 401): {$said}", AuthenticationFailure::Token, $said, self::HINT_KEY),
            $status === 403 && preg_match(self::MISSING_SCOPE, $said, $scope) === 1 => AuthenticationException::insufficientScope($said, self::scopeHint([$scope[1]])),
            $status === 404 && $said === self::CLIENT_NOT_FOUND => new NotFoundException("HMPanel {$method} {$path}: {$said}"),
            // Nest's words name the route asked for: the panel's address, which no message repeats.
            $status === 404 && preg_match(self::UNKNOWN_ROUTE, $said) === 1 => new UnexpectedResponseException(404, hint: self::HINT_ADDRESS),
            $status === 429 => HmPanelApiException::rateLimited($method, $path, $said),
            default => new HmPanelApiException($method, $path, $status, $said),
        };
    }

    /**
     * The panel's `message` as one line: Nest's sentence, or its validation's list.
     *
     * @param array<mixed> $body
     */
    private static function said(array $body): string
    {
        $message = $body['message'] ?? $body['error'] ?? null;

        return match (true) {
            is_array($message) => implode('; ', Raw::stringsOf($message)),
            is_scalar($message) => (string) $message,
            default => '',
        };
    }
}
