<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Bluepal;

use App\Modules\Payments\Contracts\OnlineGateway;
use App\Modules\Payments\Drivers\GatewayHttp;
use App\Modules\Payments\Drivers\OnlinePage;
use App\Modules\Payments\DTO\OnlineInvoice;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\DTO\OnlineVerdict;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use GuzzleHttp\ClientInterface;

/**
 * A Bluepal method's gateway, on its API v1 (the merchant's key as a Bearer token): a payment is requested in Toman for
 * the gateway's merchant id (payments/request) and the customer sent to its page, where the card is entered on the
 * bank's, then back to the shop's callback address in their browser — only a reason to ask, whatever its query says
 * (Bluepal documents none of it). What came of it is verify's word, asked by the track id kept on the payment: result 100
 * with status `success` is paid now, 201 verified before (both paid, the amount it names held to the order's); 202 —
 * failed, nothing taken, the customer may try again — or a status other than success is not paid yet while its page
 * takes money, closed after. Bluepal is young and writes little down: its result is read as a number or as text, and a
 * track id as either too. Its details kept on the payment (`payments.invoice`): the track id and the Toman it was
 * requested for. The track id is the payment's reference; its page is shown at the order's sum.
 */
final class BluepalGateway implements OnlineGateway
{
    use OnlinePage;

    public const API = 'https://bluepal.ir/api/v1';
    public const REQUEST = '/payments/request';
    public const VERIFY = '/payments/verify/';

    /** A merchant id at Bluepal, as the method's form takes it: Latin letters, digits, `-` and `_`. */
    public const MERCHANT = '/^[A-Za-z0-9_-]{2,64}$/D';

    /**
     * Minutes the shop keeps a page: Bluepal's counts down 10 from when the customer opens it — seldom the moment it was
     * requested —, and one who paid in its last seconds may still be on the bank's page, or on the way back: five more
     * before a payment not paid yet is taken as closed (one paid later still settles it).
     */
    public const PAGE_MINUTES = 15;

    /** Its own host, which a page it sends a customer to is on (GatewayHttp::page()). */
    private const HOST = 'bluepal.ir';

    /** A payment's track id at Bluepal ("TRK-8841203991", or the digits alone). */
    private const TRACK = '/^[A-Za-z0-9_-]{4,64}$/D';

    /** The most of the order's line a request carries. */
    private const DESCRIPTION_MAX = 255;

    /** Bluepal's results: done (a payment requested, verified now), verified before, failed. */
    private const DONE = '100';
    private const VERIFIED_BEFORE = '201';
    private const FAILED = '202';

    /** verify's status of a payment paid. */
    private const SUCCESS = 'success';

    public function __construct(
        private readonly string $merchantId,
        private readonly string $apiKey,
        private readonly ClientInterface $http,
    ) {}

    /** The key a Bluepal method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'bluepal';
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        if ($this->merchantId === '') {
            throw new GatewayUnavailableException('The Bluepal method has no merchant id: enter it in the payment method');
        }
        [$status, $answer] = $this->call('POST', self::REQUEST, ['json' => [
            'merchant_id' => $this->merchantId,
            'amount' => $request->amount,
            'callback_url' => $request->callbackUrl,
            'description' => mb_substr($request->description, 0, self::DESCRIPTION_MAX),
        ]]);
        if (($status !== 200 && $status !== 201) || self::code($answer) !== self::DONE) {
            throw new GatewayUnavailableException(self::refusal('would not open a payment', $status, $answer));
        }
        $track = self::track($answer['track_id'] ?? null);
        $url = GatewayHttp::page($answer['payment_url'] ?? null, self::HOST);
        if ($track === null || $url === null) {
            throw new GatewayUnavailableException(self::refusal('opened a payment it did not describe', $status, $answer));
        }

        return new OnlineInvoice($url, OnlineInvoice::until(null, self::PAGE_MINUTES), $track, [
            'track_id' => $track,
            'amount' => $request->amount,
        ]);
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $track = self::track($invoice['track_id'] ?? null);
        $amount = $invoice['amount'] ?? null;
        if ($track === null || !is_int($amount)) {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no Bluepal track id and amount.");
        }

        [$status, $answer] = $this->call('GET', self::VERIFY . rawurlencode($track));
        $code = self::code($answer);
        $state = is_string($answer['status'] ?? null) ? strtolower(trim($answer['status'])) : null;
        $paid = ($code === self::DONE && $state === self::SUCCESS && $status >= 200 && $status < 300) || ($code === self::VERIFIED_BEFORE && $status < 500);
        if ($paid) {
            if (($answer['amount'] ?? null) !== null && GatewayHttp::whole($answer['amount']) !== $amount) {
                // Paid, but not what was asked: never the shop's to take as this payment.
                throw new GatewayUnavailableException(self::refusal(sprintf('verified track %s for %s Toman, not payment #%d\'s %d', $track, json_encode($answer['amount']), $payment->id, $amount), $status, $answer), held: true);
            }

            return OnlineVerdict::paid($track);
        }
        // Not paid: maybe not yet — the customer may still be on the bank's page, or trying again. A `success` under an
        // error status says neither.
        if ($code === self::FAILED || ($code === self::DONE && $state !== null && $state !== self::SUCCESS)) {
            return self::whilePageOpen($payment);
        }

        throw new GatewayUnavailableException(self::refusal("did not say what came of track {$track}", $status, $answer));
    }

    /**
     * One call to Bluepal with the merchant's key (Drivers\GatewayHttp): its HTTP status and its JSON object.
     *
     * @param array<string, mixed> $options
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no key to call with
     */
    private function call(string $method, string $path, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new GatewayUnavailableException('The Bluepal method has no API key (or it no longer decrypts): enter it again in the payment method');
        }

        return GatewayHttp::call($this->http, 'Bluepal', $method, self::API . $path, $options, ['Authorization' => 'Bearer ' . $this->apiKey]);
    }

    /**
     * Its answer's result (100, 202…) as text — written as a number in its samples, the same read from text —; null for
     * none.
     *
     * @param array<string, mixed> $answer
     */
    private static function code(array $answer): ?string
    {
        $result = $answer['result'] ?? null;

        return is_int($result) || (is_string($result) && preg_match('/^\s*-?\d{1,6}\s*$/D', $result) === 1) ? trim((string) $result) : null;
    }

    /** A track id as Bluepal writes one — text, or the digits alone as a number —; null for anything else. */
    private static function track(mixed $track): ?string
    {
        $track = is_int($track) && $track > 0 ? (string) $track : $track;

        return is_string($track) && preg_match(self::TRACK, $track) === 1 ? $track : null;
    }

    /**
     * A log line of what Bluepal said: the HTTP status, its result and message cut short, and what the result — or, with
     * none, the status — means for the merchant.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer): string
    {
        $code = self::code($answer);
        $said = GatewayHttp::said($answer['message'] ?? $answer['error'] ?? null);
        $meaning = match ($code) {
            '102' => ' — a field Bluepal requires is missing: is the merchant id set in the payment method?',
            '105' => ' — the merchant is not active at Bluepal: its account is not verified yet, or it is blocked',
            '202' => ' — the payment failed: nothing was taken from the customer, who may try again',
            '404' => ' — Bluepal knows no such transaction: the track id is wrong, or another merchant\'s (was the method\'s key changed?)',
            '429' => ' — too many requests just now',
            default => match ($status) {
                401 => ' — the API key is wrong or revoked: set the one Bluepal\'s dashboard shows in the payment method',
                403 => ' — Bluepal refused the request: with its allowed domains or IP whitelist set, the shop\'s domain and this server\'s outgoing IP must be on them',
                429 => ' — too many requests just now',
                default => '',
            },
        };

        return "Bluepal {$what} (HTTP {$status}" . ($code === null ? '' : ", result {$code}") . ')' . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
