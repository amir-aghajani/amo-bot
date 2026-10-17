<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Variza;

use App\Modules\Payments\Contracts\SignedWebhook;
use App\Modules\Payments\Drivers\GatewayHttp;
use App\Modules\Payments\Drivers\OnlinePage;
use App\Modules\Payments\DTO\OnlineInvoice;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\DTO\OnlineVerdict;
use App\Modules\Payments\DTO\WebhookWord;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use GuzzleHttp\ClientInterface;

/**
 * A Variza method's gateway, on its API (v1, the merchant's key as a Bearer token): a payment link is made in Toman for
 * 30 minutes (pay), its page asking a unique sum a few Toman above the order's, and the customer sent back to the shop's
 * callback address in their browser (only a reason to ask). Variza answers no question about a payment: the one word it
 * gives is its webhook, payment.paid, to the shop's address for it (Controllers\GatewayWebhookController), signed with
 * the merchant's webhook secret — HMAC-SHA256 of the raw body, `sha256=<hex>` — and believed only so (hear()). The shop
 * keeps that word on the payment (`invoice.heard`) and check() reads it: paid when it names the payment's sum, held when
 * it names another; not heard yet is waiting while the page takes money, closed after. Its details kept on the payment
 * (`payments.invoice`): the link's slug and the Toman it was made for. The slug is the payment's reference; its page is
 * shown at the order's sum, the few Toman above it Variza's page's to ask.
 */
final class VarizaGateway implements SignedWebhook
{
    use OnlinePage;

    public const API = 'https://variza.ir/api/v1';

    /** The cards a payment may go to: the merchant's, the one with fewest payments today — or Variza's, settled in Tether. */
    public const RANDOM = 'random';
    public const VARIZA = 'variza';

    /** How long a link takes money, in Variza's words (30m, 1h, …), and in minutes. */
    private const EXPIRES_IN = '30m';
    private const PAGE_MINUTES = 30;

    /** A page Variza sends a customer to: on its own host, over https. */
    private const PAGE = '~^https://([a-z0-9-]+\.)*variza\.ir/[\x21-\x7e]*$~D';

    /** A link's slug at Variza. */
    private const SLUG = '/^[A-Za-z0-9_-]{4,64}$/D';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $webhookSecret,
        private readonly string $card,
        private readonly ClientInterface $http,
    ) {}

    /** The key a Variza method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'variza';
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        if (!str_starts_with($request->callbackUrl, 'https://')) {
            throw new GatewayUnavailableException('Variza sends a customer back only to an https address: APP_URL must start with https://');
        }
        [$status, $answer] = $this->call('/pay', [
            'amount' => $request->amount,
            'return_url' => $request->callbackUrl,
            'title' => $request->description,
            'expires_in' => self::EXPIRES_IN,
            'card_last_4' => $this->card,
        ]);
        $slug = $answer['slug'] ?? null;
        $url = $answer['pay_url'] ?? null;
        if (($status !== 201 && $status !== 200) || !is_string($slug) || preg_match(self::SLUG, $slug) !== 1 || !is_string($url) || preg_match(self::PAGE, $url) !== 1) {
            throw new GatewayUnavailableException(self::refusal('would not make a payment link', $status, $answer));
        }

        return new OnlineInvoice($url, OnlineInvoice::until($answer['expires_at'] ?? null, self::PAGE_MINUTES), $slug, [
            'slug' => $slug,
            'amount' => $request->amount,
            'card' => $this->card,
        ]);
    }

    /** What Variza's signed webhook said of it, as the shop kept it — no question to ask Variza. */
    public function check(Payment $payment): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $heard = $invoice['heard'] ?? null;
        if (!is_array($heard)) {
            return $payment->link_expires_at !== null && $payment->link_expires_at->isFuture() ? OnlineVerdict::waiting() : OnlineVerdict::closed();
        }
        $asked = $invoice['amount'] ?? null;
        $paid = $heard['amount'] ?? null;
        if (($heard['base_amount'] ?? null) !== $asked || !is_int($paid) || !is_int($asked) || $paid < $asked) {
            throw new GatewayUnavailableException(sprintf('Variza said link %s was paid %s Toman on a base of %s, not payment #%d\'s %s', (string) ($invoice['slug'] ?? '?'), json_encode($paid), json_encode($heard['base_amount'] ?? null), $payment->id, json_encode($asked)), held: true);
        }

        return OnlineVerdict::paid(is_string($invoice['slug'] ?? null) ? $invoice['slug'] : null);
    }

    public function hear(string $body, array $headers): ?WebhookWord
    {
        if ($this->webhookSecret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $body, $this->webhookSecret), $headers['x-webhook-signature'] ?? '')) {
            return null;
        }
        $event = json_decode($body, true);
        if (!is_array($event) || ($event['event'] ?? null) !== 'payment.paid' || ($event['status'] ?? null) !== 'paid' || !is_string($event['slug'] ?? null)) {
            // Signed, but no payment's word.
            return new WebhookWord(null, []);
        }

        return new WebhookWord($event['slug'], [
            'amount' => is_int($event['amount'] ?? null) ? $event['amount'] : null,
            'base_amount' => is_int($event['base_amount'] ?? null) ? $event['base_amount'] : null,
            'attempt_code' => is_string($event['attempt_code'] ?? null) ? mb_substr($event['attempt_code'], 0, 64) : null,
            'sent_at' => is_string($event['sent_at'] ?? null) ? mb_substr($event['sent_at'], 0, 40) : null,
        ]);
    }

    /**
     * One call to Variza with the merchant's key (Drivers\GatewayHttp): its HTTP status and its JSON object.
     *
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no key to call with
     */
    private function call(string $path, array $body): array
    {
        if ($this->apiKey === '') {
            throw new GatewayUnavailableException('The Variza method has no API key (or it no longer decrypts): enter it again in the payment method');
        }

        return GatewayHttp::call($this->http, 'Variza', 'POST', self::API . $path, ['json' => $body], ['Authorization' => 'Bearer ' . $this->apiKey]);
    }

    /**
     * A log line of what Variza said: the HTTP status, its message cut short, and what the status means for the merchant.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer): string
    {
        $said = GatewayHttp::said($answer['message'] ?? null);
        $meaning = match ($status) {
            401 => ' — the API key is wrong or cut short: set the one Variza\'s profile shows in the payment method',
            422 => ' — a field Variza would not take: no callback_url set in its profile, a destination card not registered or not active, or the subscription\'s limit on open links (or on «random» and «variza» cards)',
            429 => ' — too many requests just now',
            default => '',
        };

        return "Variza {$what} (HTTP {$status})" . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
