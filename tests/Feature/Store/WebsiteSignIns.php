<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface;

/**
 * Website sign-in steps several Store tests share, on the test's `$website`: a nonce for an id_token, Telegram's redirect
 * begun, and an answer read as its status and message.
 */
trait WebsiteSignIns
{
    /** The PKCE verifier the site keeps for a redirect it begins; only its challenge goes to the shop. */
    private const VERIFIER = 'the-sites-own-pkce-verifier.kept~in_this-browser-0123456789';

    /** A nonce for an id_token sign-in (POST /auth/nonce). */
    private function nonce(): string
    {
        return $this->decode($this->postJson($this->storeApi($this->website, '/auth/nonce')))['nonce'];
    }

    /**
     * Telegram's redirect begun at `$path` — a sign-in's (/auth/telegram/authorize) or the signed-in customer's own
     * (/me/telegram/authorize) — for VERIFIER's challenge: the state and the nonce its address carries.
     *
     * @return array{state: string, nonce: string}
     */
    private function authorize(string $path): array
    {
        $address = $this->decode($this->postJson($this->storeApi($this->website, $path), ['redirect_uri' => 'https://shop.example/account', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', self::VERIFIER, true))]))['url'];
        parse_str((string) parse_url($address, PHP_URL_QUERY), $query);

        return ['state' => (string) $query['state'], 'nonce' => (string) $query['nonce']];
    }

    /** @return array{int, mixed} The answer's status and its message */
    private function message(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['message'] ?? null];
    }

    /** @return array{int, mixed} The answer's status and its refusals by field */
    private function refused(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['errors'] ?? null];
    }
}
