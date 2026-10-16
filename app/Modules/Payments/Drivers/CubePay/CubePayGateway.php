<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\CubePay;

use App\Modules\Payments\Contracts\OnlineGateway;
use App\Modules\Payments\Drivers\GatewayHttp;
use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\DTO\OnlineInvoice;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\DTO\OnlineVerdict;
use App\Modules\Payments\DTO\PayLink;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Support\Money;
use GuzzleHttp\ClientInterface;

/**
 * A CubePay method's gateway, on CubePay's unified router: an order is opened in Toman (create-order.php) and CubePay
 * makes it a card payment, a crypto one or a page that offers both, as the merchant's settings there say. What came of a
 * card payment is asked of verify-payment.php by its `authority` — which says yes once, then 409: either means paid —,
 * of any other by the order's id (check-order-status.php). Every call carries the merchant's token; none follows a
 * redirect. The fee the merchant passes on to customers (a percent of the amount, rounded up, and a fixed sum — as
 * CubePay's guide for platforms has it) is added to what CubePay is asked for, never to the payment: the order is paid,
 * delivered and credited at its own price. Its details kept on the payment (`payments.invoice`): the order's id at
 * CubePay, a card payment's authority, what CubePay was asked for (`price`) and of it the fee (`fee`), the exact sum the
 * page asks (`pay_amount`: CubePay adds a few Toman to tell transfers apart) and, when CubePay said that sum, the card
 * it gave and whether the merchant asked CubePay to show it in the bot.
 */
final class CubePayGateway implements OnlineGateway
{
    public const CREATE = 'https://cubevps.ir/pay/create-order.php';
    public const VERIFY = 'https://cubevps.ir/smspay/api/verify-payment.php';
    public const STATUS = 'https://cubevps.ir/pay/check-order-status.php';

    /** A page CubePay sends a customer to: on its own host, over https. */
    private const PAGE = '~^https://cubevps\.ir/[\x21-\x7e]*$~D';

    /** A card payment's id at CubePay. */
    private const AUTHORITY = '/^[A-Za-z0-9]{8,64}$/D';

    /** What CubePay makes of an order: a card payment, a crypto one, or a page offering both. */
    private const KINDS = ['card', 'crypto', 'choice'];

    /** Minutes a page takes money when CubePay says nothing of it: its router's hour. */
    private const MINUTES = 60;

    /** The longest of CubePay's own words a log line keeps. */
    private const SAID_MAX = 200;

    /**
     * @param string $feePercent The percent of the amount the customer pays as CubePay's fee: Latin digits, up to two
     *                           decimals ("3.1"); "0" for none
     * @param int $feeFixed Toman the customer pays as CubePay's fee on every payment; 0 for none
     */
    public function __construct(
        private readonly string $token,
        private readonly ClientInterface $http,
        private readonly string $feePercent = '0',
        private readonly int $feeFixed = 0,
    ) {}

    /** The fee a customer pays on `$amount` Toman: its percent, rounded up to a whole Toman, and the fixed sum. */
    public function fee(int $amount): int
    {
        $share = bcdiv(bcmul((string) $amount, $this->feePercent, 4), '100', 4);
        $whole = (int) bcadd($share, '0', 0);

        return (bccomp($share, (string) $whole, 4) > 0 ? $whole + 1 : $whole) + $this->feeFixed;
    }

    /** The key a CubePay method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'cubepay';
    }

    /**
     * Its page and the sum it asks — and the card, when CubePay gave one to show in the bot with the exact sum it takes —,
     * as open() kept them.
     */
    public function initiate(Payment $payment): PaymentInitiation
    {
        if ($payment->link === null) {
            return PaymentInitiation::instant();
        }
        $invoice = $payment->invoice ?? [];
        $exact = is_int($invoice['pay_amount'] ?? null) && $invoice['pay_amount'] > 0 ? $invoice['pay_amount'] : null;
        $asked = match (true) {
            $exact !== null => Money::normalize($exact),
            is_int($invoice['price'] ?? null) && $invoice['price'] > 0 => Money::normalize($invoice['price']),
            default => $payment->amount,
        };
        $fee = is_int($invoice['fee'] ?? null) && $invoice['fee'] > 0 ? Money::normalize($invoice['fee']) : null;
        $card = $invoice['card'] ?? null;
        $shown = $exact !== null && ($invoice['show_card'] ?? false) === true && is_array($card) && is_string($card['number'] ?? null)
            ? new CardTransfer($card['number'], is_string($card['holder'] ?? null) && $card['holder'] !== '' ? $card['holder'] : null, '')
            : null;

        return PaymentInitiation::link(new PayLink($payment->link, $payment->link_expires_at?->toDateTimeImmutable(), $asked, $shown, $fee));
    }

    /** CubePay said it was paid before this runs (Services\OnlinePayments): its authority, if it has one, is the reference. */
    public function settle(Payment $payment): PaymentResult
    {
        return PaymentResult::success($payment->reference);
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        if (!str_starts_with($request->callbackUrl, 'https://')) {
            throw new GatewayUnavailableException('CubePay calls a shop back only over https: APP_URL must start with https://');
        }
        $fee = $this->fee($request->amount);
        $price = $request->amount + $fee;
        $order = [
            'order_id' => $request->orderId,
            'price_amount' => $price,
            'callback_url' => $request->callbackUrl,
            // A bot's customer is told the outcome by the bot: CubePay's page says it, and sends nobody to the shop's address.
            'redirect_after_payment' => false,
            'description' => $request->description,
        ];
        if ($request->telegramId !== null) {
            $order['customer_user_id'] = $request->telegramId;
        }
        if ($request->username !== null && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $request->username) === 1) {
            $order['customer_username'] = $request->username;
        }

        [$status, $answer] = $this->call('POST', self::CREATE, ['json' => $order]);
        if ($status !== 200 || ($answer['success'] ?? null) !== true) {
            throw new GatewayUnavailableException(self::refusal('would not open an order', $status, $answer));
        }
        $url = $answer['pay_page_url'] ?? null;
        $kind = $answer['method'] ?? null;
        $authority = $answer['authority'] ?? null;
        if (!is_string($url) || preg_match(self::PAGE, $url) !== 1 || !in_array($kind, self::KINDS, true)
            || ($kind === 'card' && (!is_string($authority) || preg_match(self::AUTHORITY, $authority) !== 1))) {
            throw new GatewayUnavailableException(self::refusal('opened an order it did not describe', $status, $answer));
        }
        $authority = $kind === 'card' ? $authority : null;
        // The sum a card payment's page asks: that, a few Toman CubePay adds, and any share of its fee the merchant passes
        // on at CubePay — CubePay's, never credited (its guidance: credit the base price). Without it the card is not
        // kept: a transfer of another sum is never matched to the payment.
        $exact = is_int($answer['pay_amount_toman'] ?? null) && $answer['pay_amount_toman'] >= $price ? $answer['pay_amount_toman'] : null;
        $minutes = $answer['expires_in_minutes'] ?? null;

        return new OnlineInvoice($url, OnlineInvoice::until($answer['expires_at'] ?? null, is_int($minutes) && $minutes > 0 ? $minutes : self::MINUTES), $authority, [
            'order_id' => $request->orderId,
            'authority' => $authority,
            // What CubePay was asked for, in Toman — the amount and the customer's fee —: what its verification's amount
            // (in Rial) is held to.
            'price' => $price,
            'fee' => $fee,
            'pay_amount' => $exact,
            'card' => $exact === null ? null : self::card($answer['card'] ?? null),
            'show_card' => ($answer['show_card_in_bot'] ?? false) === true,
        ]);
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $orderId = $invoice['order_id'] ?? null;
        if (!is_string($orderId) || $orderId === '') {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no CubePay order id.");
        }
        $authority = $invoice['authority'] ?? null;

        return is_string($authority) && $authority !== '' ? $this->verify($payment, $orderId, $authority) : $this->status($payment, $orderId);
    }

    /**
     * A card payment, by its authority: verified now (its order's id and amount held to what CubePay was asked for — an
     * answer that does not say them is not this payment's either), or verified before (409); not paid yet (402); its time
     * over or failed (410).
     */
    private function verify(Payment $payment, string $orderId, string $authority): OnlineVerdict
    {
        [$status, $answer] = $this->call('POST', self::VERIFY, ['json' => ['authority' => $authority]]);
        $state = $answer['status'] ?? null;
        if ($status === 200 && ($answer['success'] ?? null) === true && $state === 'verified') {
            $rial = $answer['amount'] ?? null;
            $asked = is_int($payment->invoice['price'] ?? null) ? $payment->invoice['price'] : (int) Money::digits($payment->amount);
            if (($answer['order_id'] ?? null) !== $orderId || !is_int($rial) || $rial < $asked * 10) {
                // CubePay says yes once: what it says from now on (409) cannot be held to the payment any more.
                throw new GatewayUnavailableException(self::refusal("verified authority {$authority} for another order or amount than payment #{$payment->id}'s", $status, $answer), held: true);
            }

            return OnlineVerdict::paid($authority);
        }

        return match (true) {
            $status === 409 && $state === 'verified' => OnlineVerdict::paid($authority),
            $status === 402 => OnlineVerdict::waiting(),
            $status === 410 => OnlineVerdict::closed(),
            default => throw new GatewayUnavailableException(self::refusal("did not say what came of authority {$authority}", $status, $answer)),
        };
    }

    /**
     * A crypto payment, or one the customer chose on CubePay's page, by the order's id: verified (a card) or finished (a
     * crypto one) is paid; expired or failed is closed; anything else is under way. An order CubePay does not know yet is
     * waiting while its page is open.
     */
    private function status(Payment $payment, string $orderId): OnlineVerdict
    {
        [$status, $answer] = $this->call('GET', self::STATUS, ['query' => ['order_id' => $orderId]]);
        if ($status === 404) {
            return $payment->link_expires_at?->isPast() === true ? OnlineVerdict::closed() : OnlineVerdict::waiting();
        }
        if ($status !== 200 || ($answer['success'] ?? null) !== true) {
            throw new GatewayUnavailableException(self::refusal("did not say what came of order {$orderId}", $status, $answer));
        }

        return match ($answer['status'] ?? null) {
            'verified', 'finished' => OnlineVerdict::paid(is_string($answer['authority'] ?? null) && preg_match(self::AUTHORITY, $answer['authority']) === 1 ? $answer['authority'] : null),
            'expired', 'failed' => OnlineVerdict::closed(),
            default => OnlineVerdict::waiting(),
        };
    }

    /**
     * One call to CubePay with the merchant's token (Drivers\GatewayHttp): its HTTP status and its JSON object.
     *
     * @param array<string, mixed> $options
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach
     */
    private function call(string $method, string $url, array $options): array
    {
        if ($this->token === '') {
            throw new GatewayUnavailableException('The CubePay method has no API token (or it no longer decrypts): enter it again in the payment method');
        }

        return GatewayHttp::call($this->http, 'CubePay', $method, $url, $options, ['Authorization' => 'Bearer ' . $this->token]);
    }

    /**
     * The card CubePay gave a card payment, to show in the bot: its 16 digits and holder (null when CubePay names none);
     * null for no card (a crypto one, a choice, a merchant with no card there).
     *
     * @return array{number: string, holder: ?string}|null
     */
    private static function card(mixed $card): ?array
    {
        $number = is_array($card) && is_string($card['number'] ?? null) ? $card['number'] : '';
        if (preg_match('/^\d{16}$/D', $number) !== 1) {
            return null;
        }
        $holder = is_string($card['holder'] ?? null) ? mb_substr(trim($card['holder']), 0, 100) : '';

        return ['number' => $number, 'holder' => $holder === '' ? null : $holder];
    }

    /**
     * A log line of what CubePay said: the HTTP status, its message cut short, and what a status means for the merchant.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer): string
    {
        $said = is_string($answer['message'] ?? null) ? mb_substr($answer['message'], 0, self::SAID_MAX) : '';
        $meaning = match ($status) {
            401 => ' — the API token is wrong: set the one @cubepy_bot gives in the payment method',
            403 => ' — the merchant account is not approved yet',
            402 => ' — the fee wallet at CubePay is short: top it up in @cubepy_bot',
            503 => ' — CubePay has no room for another payment just now',
            default => '',
        };

        return "CubePay {$what} (HTTP {$status})" . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
