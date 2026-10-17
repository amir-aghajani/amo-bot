<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Plisio;

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
 * A Plisio method's gateway, on its API (v1, the merchant's secret key in each call's query): an invoice is opened in US
 * dollars — the order's Toman at the owner's rate, a cent rounded up — for an hour, and the customer pays it in a coin
 * they pick on Plisio's page; Plisio calls the shop back and sends the customer's browser back to it (both the callback
 * address, on an https shop: only a reason to ask, so its signature is not needed). What came of it is asked of the
 * invoice (operations/<id>): completed — or overpaid ("mismatch") — is paid; money seen on the chain and awaiting its
 * confirmations is money in, waited for; new waits; cancelled is closed; expired is closed unless money came on it, then
 * held for support (paid short). A customer who switched coins made Plisio open another invoice of the same order and
 * cancel the first as a "duplicate" once it is paid: that one is found by the order's number. An answer naming another
 * order, another currency or fewer dollars is held. Its details kept on the payment (`payments.invoice`): the invoice's
 * id, the shop's reference for it, the dollars asked and the rate they were asked at. The paid invoice's id is the
 * payment's reference; its page is shown at the order's own sum, the coin's amount Plisio's to show.
 */
final class PlisioGateway implements OnlineGateway
{
    use OnlinePage;

    public const API = 'https://api.plisio.net/api/v1';

    /** Minutes an invoice takes money. */
    public const PAGE_MINUTES = 60;

    /** The fiat currency invoices are asked in. */
    public const CURRENCY = 'USD';

    /** A page Plisio sends a customer to: on its own host, over https. */
    private const PAGE = '~^https://([a-z0-9-]+\.)*plisio\.net/[\x21-\x7e]*$~D';

    /** An operation's id at Plisio (24 hexadecimal digits in its examples). */
    private const ID = '/^[A-Za-z0-9]{6,64}$/D';

    /** Its invoices' states: paid (in full, or overpaid), money seen and awaiting its confirmations. */
    private const PAID = ['completed', 'mismatch'];
    private const UNDER_WAY = ['pending', 'pending internal'];

    public function __construct(
        private readonly string $apiKey,
        private readonly int $usdRate,
        private readonly string $currencies,
        private readonly ClientInterface $http,
    ) {}

    /** The key a Plisio method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'plisio';
    }

    /** `$toman` in dollars at `$rate` Toman a dollar, rounded up to a whole cent ("12.35"). */
    public static function dollars(int $toman, int $rate): string
    {
        $cents = bcdiv(bcmul((string) $toman, '100', 0), (string) $rate, 6);
        $whole = bcadd($cents, '0', 0);

        return bcdiv(bccomp($cents, $whole, 6) > 0 ? bcadd($whole, '1', 0) : $whole, '100', 2);
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        if ($this->usdRate < PlisioDriver::RATE_MIN) {
            throw new GatewayUnavailableException('The Plisio method has no dollar rate: set how many Toman a dollar is in the payment method');
        }
        $usd = self::dollars($request->amount, $this->usdRate);
        $query = [
            'order_number' => $request->orderId,
            'order_name' => $request->description,
            'description' => $request->description,
            'source_currency' => self::CURRENCY,
            'source_amount' => $usd,
            'expire_min' => self::PAGE_MINUTES,
            // The same order number again (a retry) is its invoice again, never a refusal.
            'return_existing' => 'true',
        ];
        if ($this->currencies !== '') {
            $query['allowed_psys_cids'] = $this->currencies;
        }
        if (str_starts_with($request->callbackUrl, 'https://')) {
            // Plisio's call and the customer's way back, on an address the internet reaches; else the shop asks.
            $query += ['callback_url' => $request->callbackUrl, 'success_invoice_url' => $request->callbackUrl, 'fail_invoice_url' => $request->callbackUrl];
        }

        [$status, $answer] = $this->call('/invoices/new', $query);
        $data = $answer['data'] ?? null;
        if (($status !== 200 && $status !== 201) || ($answer['status'] ?? null) !== 'success' || !is_array($data)) {
            throw new GatewayUnavailableException(self::refusal('would not open an invoice', $status, $answer));
        }
        $id = $data['txn_id'] ?? null;
        $url = $data['invoice_url'] ?? null;
        if (!is_string($id) || preg_match(self::ID, $id) !== 1 || !is_string($url) || preg_match(self::PAGE, $url) !== 1) {
            throw new GatewayUnavailableException(self::refusal('opened an invoice it did not describe', $status, $answer));
        }
        $expires = $data['expire_utc'] ?? null;

        return new OnlineInvoice($url, OnlineInvoice::until(is_int($expires) && $expires > 0 ? '@' . $expires : null, self::PAGE_MINUTES), $id, [
            'txn_id' => $id,
            'ref' => $request->orderId,
            // What Plisio was asked for, and at what rate: the rate may change before it is paid.
            'usd' => $usd,
            'rate' => $this->usdRate,
        ]);
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $id = $payment->invoice['txn_id'] ?? null;
        if (!is_string($id) || preg_match(self::ID, $id) !== 1) {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no Plisio invoice id.");
        }

        [$status, $answer] = $this->call('/operations/' . $id, []);
        $operation = $answer['data'] ?? null;
        if ($status !== 200 || ($answer['status'] ?? null) !== 'success' || !is_array($operation)) {
            throw new GatewayUnavailableException(self::refusal("did not say what came of invoice {$id}", $status, $answer));
        }
        $this->holdIfForAnother($payment, $operation, $id);

        return match ($operation['status'] ?? null) {
            'completed', 'mismatch' => OnlineVerdict::paid($id),
            'pending', 'pending internal' => OnlineVerdict::waiting(moneyIn: true),
            'new' => OnlineVerdict::waiting(),
            'cancelled duplicate' => $this->switched($payment, $id),
            'expired' => self::moneyCame($operation)
                ? throw new GatewayUnavailableException("Plisio's invoice {$id} expired with money on it — paid short, or late: support decides on payment #{$payment->id}", held: true)
                : OnlineVerdict::closed(),
            'cancelled' => OnlineVerdict::closed(),
            'error' => self::moneyCame($operation)
                ? throw new GatewayUnavailableException("Plisio reports an error on invoice {$id}, money on it: support decides on payment #{$payment->id}", held: true)
                : throw new GatewayUnavailableException(self::refusal("reports an error on invoice {$id}", $status, $answer)),
            default => throw new GatewayUnavailableException(self::refusal("said invoice {$id} is in a state the shop does not know", $status, $answer)),
        };
    }

    /**
     * The invoice the customer switched to — another coin, another invoice of the same order, found by the order's number
     * —: paid, under way, or still waiting as that one stands. Plisio cancels the first as a duplicate only once another
     * is paid, so none found paid is support's.
     */
    private function switched(Payment $payment, string $first): OnlineVerdict
    {
        $reference = (string) ($payment->invoice['ref'] ?? '');
        [$status, $answer] = $this->call('/operations', ['type' => 'invoice', 'search' => $reference]);
        $found = $answer['data']['operations'] ?? null;
        if ($status !== 200 || ($answer['status'] ?? null) !== 'success' || !is_array($found)) {
            throw new GatewayUnavailableException(self::refusal("did not list the invoices of order {$reference}", $status, $answer));
        }
        $others = array_values(array_filter($found, static fn(mixed $operation): bool => is_array($operation)
            && ($operation['type'] ?? 'invoice') === 'invoice'
            && is_string($operation['id'] ?? null) && $operation['id'] !== $first
            // The search is by text: an invoice that names its order must name this one.
            && (!is_array($operation['params'] ?? null) || !isset($operation['params']['order_number']) || $operation['params']['order_number'] === $reference)));
        $states = array_column($others, 'status', 'id');

        foreach ($others as $other) {
            if (in_array($other['status'] ?? null, self::PAID, true) && preg_match(self::ID, $other['id']) === 1) {
                $this->holdIfForAnother($payment, $other, $other['id']);

                return OnlineVerdict::paid($other['id']);
            }
        }

        return match (true) {
            array_intersect($states, self::UNDER_WAY) !== [] => OnlineVerdict::waiting(moneyIn: true),
            in_array('new', $states, true) => OnlineVerdict::waiting(),
            default => throw new GatewayUnavailableException("Plisio cancelled invoice {$first} as a duplicate — paid in another coin — and no invoice of order {$reference} is found paid: support decides on payment #{$payment->id}", held: true),
        };
    }

    /**
     * Holds the payment for support when what Plisio says of an invoice names another order, another currency, or fewer
     * dollars than asked (the fields a white-label shop is answered; none of them, nothing to hold to).
     *
     * @param array<string, mixed> $operation
     * @throws GatewayUnavailableException held
     */
    private function holdIfForAnother(Payment $payment, array $operation, string $id): void
    {
        $asked = $payment->invoice ?? [];
        $params = is_array($operation['params'] ?? null) ? $operation['params'] : [];
        $order = $params['order_number'] ?? null;
        $currency = $params['source_currency'] ?? $operation['source_currency'] ?? null;
        $amount = $params['source_amount'] ?? null;
        $usd = is_string($asked['usd'] ?? null) ? $asked['usd'] : null;
        if (($order !== null && $order !== ($asked['ref'] ?? null))
            || ($currency !== null && $currency !== self::CURRENCY)
            || ($amount !== null && (!is_numeric($amount) || ($usd !== null && bccomp((string) $amount, $usd, 2) < 0)))) {
            throw new GatewayUnavailableException(self::refusal("said invoice {$id} is for another order, currency or amount than payment #{$payment->id}'s", 200, ['data' => $params]), held: true);
        }
    }

    /**
     * One call to Plisio with the merchant's secret key in its query: its HTTP status and its JSON object (empty for
     * anything else). The key never reaches a message of one that did not get through.
     *
     * @param array<string, mixed> $query
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no key to call with
     */
    private function call(string $path, array $query): array
    {
        if ($this->apiKey === '') {
            throw new GatewayUnavailableException('The Plisio method has no secret key (or it no longer decrypts): enter it again in the payment method');
        }

        return GatewayHttp::call($this->http, 'Plisio', 'GET', self::API . $path, ['query' => $query + ['api_key' => $this->apiKey]], hide: $this->apiKey);
    }

    /**
     * Whether money came on an invoice: its incoming sum when Plisio says it (`actual_sum`), else a transaction on the
     * chain it links to.
     *
     * @param array<string, mixed> $operation
     */
    private static function moneyCame(array $operation): bool
    {
        $sum = $operation['actual_sum'] ?? null;
        if (is_numeric($sum)) {
            return bccomp((string) $sum, '0', 8) > 0;
        }
        foreach (['tx_url', 'tx_id'] as $field) {
            $value = $operation[$field] ?? null;
            if ((is_string($value) && $value !== '') || (is_array($value) && array_filter($value) !== [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A log line of what Plisio said: the HTTP status, its code and message cut short, and what the code means for the
     * merchant.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer): string
    {
        $data = is_array($answer['data'] ?? null) ? $answer['data'] : [];
        $code = is_int($data['code'] ?? null) ? $data['code'] : null;
        $said = GatewayHttp::said($data['message'] ?? null);
        $meaning = match (true) {
            $code === 101 || $status === 401 => ' — the secret key is wrong, the shop\'s domain is not verified at Plisio, or this server\'s IP is not in its «Request IP»',
            $code === 104 => ' — a coin of «رمزارزهای مجاز» Plisio does not take, or none switched on in its panel',
            $code === 105 => ' — less than the least Plisio takes for that coin: raise the method\'s least amount',
            $code === 111 || $status === 404 => ' — Plisio knows no such invoice under this key: was the key changed to another account\'s?',
            $status === 403 => ' — Plisio refuses this server: see its «Request IP» setting, or the account',
            $status === 429 => ' — too many requests just now',
            default => '',
        };

        return "Plisio {$what} (HTTP {$status}" . ($code === null ? '' : ", code {$code}") . ')' . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
