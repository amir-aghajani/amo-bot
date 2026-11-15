<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Rikhtam;

use App\Modules\Payments\Drivers\GatewayHttp;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use GuzzleHttp\ClientInterface;

/**
 * Rikhtam's one address (rikhtam.com/api.php), each action named by its `a` field, for one Rikhtam gateway — its
 * «کلید امنیتی», which Rikhtam makes and the merchant pastes in the shop: `hook` (a form, the key as `key`) tells Rikhtam
 * the shop's address for that gateway; every other action is JSON with the key as `secret` — crypto_create and
 * crypto_status (its crypto invoices), bg_pending and bg_attach (its deposits no invoice took, and one attached to an
 * invoice by hand). Each call gives back Rikhtam's HTTP status and its JSON object (Drivers\GatewayHttp), never
 * following a redirect; what a refusal means is its caller's to say (code(), message()).
 */
final class RikhtamApi
{
    public const URL = 'https://rikhtam.com/api.php';

    /**
     * Seconds bg_attach may take: Rikhtam calls the shop's own address in the middle of it (and gives that 20 seconds),
     * so its guide asks for at least 30.
     */
    private const ATTACH_TIMEOUT = 45;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $secret,
    ) {}

    /**
     * Tells Rikhtam the address that hears this gateway's deposits and crypto payments — https, on the domain its panel
     * has for the gateway (or one under it). Safe to send again.
     *
     * @return array{int, array<string, mixed>} 200 `{ok, project_id, url, domain, active}`; 403 bad_key; 422 wrong_domain or bad_url
     * @throws GatewayUnavailableException out of reach, or no key
     */
    public function hook(string $url): array
    {
        return $this->call(['form_params' => ['a' => 'hook', 'key' => $this->key(), 'url' => $url]]);
    }

    /**
     * A crypto invoice for an order (the same order_id again gives back its open one; a paid one is `already_paid`).
     *
     * @param array{order_id: string, amount: int|string, currency: string, coin?: string, return_url?: string, title?: string} $fields
     * @return array{int, array<string, mixed>} `{ok, invoice_id, pay_url, status}` — and, a coin chosen, `address, amount, comment, expires`
     * @throws GatewayUnavailableException out of reach, or no key
     */
    public function cryptoCreate(array $fields): array
    {
        return $this->json(['a' => 'crypto_create'] + $fields);
    }

    /**
     * What came of a crypto invoice: its `status` — new, wait, paid, expired, canceled.
     *
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no key
     */
    public function cryptoStatus(string $invoiceId): array
    {
        return $this->json(['a' => 'crypto_status', 'invoice_id' => $invoiceId]);
    }

    /**
     * The gateway's deposits no invoice took: up to 40 of the last 20 days, each `{uid, amount (Toman), time (Unix
     * seconds), account (its last four digits)}`.
     *
     * @return array{int, array<string, mixed>} `{ok, deposits: list<…>}`
     * @throws GatewayUnavailableException out of reach, or no key
     */
    public function pending(): array
    {
        return $this->json(['a' => 'bg_pending']);
    }

    /**
     * Attaches the deposit `$uid` to the invoice `$invoice`: Rikhtam sends that deposit to the shop's address again, with
     * `invoice` and `manual=1`, and it is confirmed only if the shop takes it. Holds no lock of the shop's while it runs.
     *
     * @return array{int, array<string, mixed>} 200 `{ok, message, invoice, fee, debt}` (Toman); else `{ok: false, code, message}` —
     *                                          403 bad_project or disabled, 409 attach_failed, 429 billing or too_many
     * @throws GatewayUnavailableException out of reach, or no key
     */
    public function attach(string $uid, string $invoice): array
    {
        return $this->json(['a' => 'bg_attach', 'uid' => $uid, 'invoice' => $invoice], self::ATTACH_TIMEOUT);
    }

    /**
     * Rikhtam's code for a refusal (bad_key, wrong_domain, attach_failed, already_paid…), as `code` or `error` writes it;
     * null for none.
     *
     * @param array<string, mixed> $answer
     */
    public static function code(array $answer): ?string
    {
        foreach (['code', 'error'] as $field) {
            if (is_string($answer[$field] ?? null) && preg_match('/^[a-z_]{2,40}$/D', $answer[$field]) === 1) {
                return $answer[$field];
            }
        }

        return null;
    }

    /**
     * Rikhtam's own words in an answer (Persian, made to be shown), cut short; `$otherwise` for none.
     *
     * @param array<string, mixed> $answer
     */
    public static function message(array $answer, string $otherwise = ''): string
    {
        $said = GatewayHttp::said($answer['message'] ?? null);

        return $said !== '' ? $said : $otherwise;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>}
     */
    private function json(array $body, ?int $timeout = null): array
    {
        return $this->call(['json' => $body + ['secret' => $this->key()]] + ($timeout === null ? [] : ['timeout' => $timeout]));
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, array<string, mixed>}
     */
    private function call(array $options): array
    {
        return GatewayHttp::call($this->http, 'Rikhtam', 'POST', self::URL, $options);
    }

    /** @throws GatewayUnavailableException no key to call with */
    private function key(): string
    {
        if ($this->secret === '') {
            throw new GatewayUnavailableException('No Rikhtam key (or it no longer decrypts): paste the gateway\'s «کلید امنیتی» from Rikhtam\'s panel again');
        }

        return $this->secret;
    }
}
