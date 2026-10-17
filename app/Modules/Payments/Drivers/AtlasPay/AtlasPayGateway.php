<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\AtlasPay;

use App\Core\Security\Encrypter;
use App\Modules\Payments\Contracts\OnlineGateway;
use App\Modules\Payments\Drivers\GatewayHttp;
use App\Modules\Payments\Drivers\OnlinePage;
use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\DTO\OnlineInvoice;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\DTO\OnlineVerdict;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Support\Money;
use GuzzleHttp\ClientInterface;

/**
 * An AtlasPay method's gateway, on its API (v1, the merchant's key in `X-API-Key`): an order is opened in Toman and the
 * customer sent to AtlasPay's mini app in Telegram, which asks a sum of its own — the order's, a last digit to tell
 * transfers apart, and any markup of AtlasPay's (`totalAmountToman`, the sum shown; the order is paid and credited at its
 * own price) — for 20 minutes; a total below the order's is refused. What came of it is asked of its verify call: paid
 * (`paid`) — for the shop's own order and sum, which an answer must say —, under way (waiting for the transfer; or money
 * in: a receipt or an underpayment in review, the rest of one awaited), or closed (rejected, expired, cancelled). A
 * payment AtlasPay took for less than asked, the customer having accepted paying less (`requiresManualDelivery`), is
 * never delivered by itself: it is held for support. AtlasPay calls the shop's callback address when an order is
 * confirmed or rejected — only a reason to ask, so its signature is not needed —, when that address is https. Its
 * details kept on the payment (`payments.invoice`): the order's id at AtlasPay, the shop's reference for it, its
 * tracking code (what a customer is told, never the id), the sum asked and of it what AtlasPay added (`fee`), and — when
 * AtlasPay shows the merchant the card ("direct payment info") — that card, encrypted, shown only while the page takes
 * money. Its tracking code, when it gave one, is the payment's reference.
 */
final class AtlasPayGateway implements OnlineGateway
{
    use OnlinePage;

    public const ORDERS = 'https://api.atlaspay.space/api/v1/orders';

    /** A page AtlasPay sends a customer to: its mini app, in Telegram. */
    private const PAGE = '~^https://t\.me/[\x21-\x7e]+$~D';

    /** An order's tracking code at AtlasPay: what it tells the customer. */
    private const TRACKING = '/^[A-Za-z0-9]{4,64}$/D';

    /**
     * Its states: paid — confirmed, then settled to the merchant —; still under way: awaiting the transfer, or with money
     * in (a receipt in review, an underpayment in review, the rest of one awaited); or over unpaid.
     */
    private const PAID = ['confirmed', 'settled'];
    private const AWAITING = 'awaiting_payment';
    private const MONEY_IN = ['admin_review', 'underpaid_review', 'underpaid_awaiting_remainder'];
    private const OVER = ['rejected', 'expired', 'cancelled'];

    /** Minutes a page takes money when AtlasPay says nothing of it: its own 20. */
    private const MINUTES = 20;

    public function __construct(
        private readonly string $apiKey,
        private readonly ClientInterface $http,
        private readonly Encrypter $encrypter,
    ) {}

    /** The key an AtlasPay method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'atlaspay';
    }

    /** Its page and the sum it asks — and the card, while the page takes money, when AtlasPay gave one —, as open() kept them. */
    public function initiate(Payment $payment): PaymentInitiation
    {
        $invoice = $payment->invoice ?? [];
        $asked = is_int($invoice['total'] ?? null) && $invoice['total'] > 0 ? Money::normalize($invoice['total']) : null;
        $fee = is_int($invoice['fee'] ?? null) && $invoice['fee'] > 0 ? Money::normalize($invoice['fee']) : null;
        $card = $payment->link_expires_at?->isFuture() === true ? $this->openCard($invoice['card'] ?? null) : null;

        return $this->page($payment, $asked, $card, $fee);
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        $order = ['merchantOrderRef' => $request->orderId, 'baseAmountToman' => $request->amount];
        if ($request->telegramId !== null && preg_match('/^[1-9]\d{0,18}$/D', $request->telegramId) === 1) {
            // The order is the customer's alone at AtlasPay.
            $order['customerTelegramId'] = (int) $request->telegramId;
        }
        if (str_starts_with($request->callbackUrl, 'https://')) {
            // AtlasPay calls only https; without it, the shop asks (OnlinePaymentsTask).
            $order['webhookUrl'] = $request->callbackUrl;
        }

        [$status, $answer] = $this->call('POST', self::ORDERS, ['json' => $order]);
        if ($status < 200 || $status > 299) {
            throw new GatewayUnavailableException(self::refusal('would not open an order', $status, $answer));
        }
        $id = $answer['orderId'] ?? null;
        $url = $answer['customerStartLink'] ?? null;
        $total = $answer['totalAmountToman'] ?? null;
        // A total below the order's would have the customer pay less than its price, and be paid all the same.
        if (!is_int($id) || $id <= 0 || !is_string($url) || preg_match(self::PAGE, $url) !== 1 || !is_int($total) || $total < $request->amount) {
            throw new GatewayUnavailableException(self::refusal('opened an order it did not describe', $status, $answer));
        }
        $tracking = is_string($answer['trackingCode'] ?? null) && preg_match(self::TRACKING, $answer['trackingCode']) === 1 ? $answer['trackingCode'] : null;

        return new OnlineInvoice($url, OnlineInvoice::until($answer['paymentDeadlineAt'] ?? null, self::MINUTES), $tracking, [
            'order_id' => $id,
            'ref' => $request->orderId,
            'tracking_code' => $tracking,
            // The sum the customer is asked, and of it what AtlasPay added: never credited (the order's price is).
            'total' => $total,
            'fee' => $total > $request->amount ? $total - $request->amount : null,
            'card' => $this->sealCard($answer),
        ]);
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $id = $invoice['order_id'] ?? null;
        if (!is_int($id) || $id <= 0) {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no AtlasPay order id.");
        }

        [$status, $answer] = $this->call('POST', self::ORDERS . "/{$id}/verify", []);
        if ($status !== 200 || ($answer['success'] ?? true) !== true) {
            throw new GatewayUnavailableException(self::refusal("did not say what came of order {$id}", $status, $answer));
        }
        $state = $answer['status'] ?? null;
        $paid = is_bool($answer['paid'] ?? null) ? $answer['paid'] : in_array($state, self::PAID, true);

        return match (true) {
            $paid => $this->paid($payment, $id, $answer),
            in_array($state, self::OVER, true) => OnlineVerdict::closed(),
            $state === self::AWAITING => OnlineVerdict::waiting(),
            in_array($state, self::MONEY_IN, true) => OnlineVerdict::waiting(moneyIn: true),
            default => throw new GatewayUnavailableException(self::refusal("said order {$id} is in a state the shop does not know", $status, $answer)),
        };
    }

    /**
     * Paid, as AtlasPay says: delivered by itself only when it was the shop's order and paid in full. One the customer paid
     * less on and AtlasPay took all the same, or for another order or a smaller sum — or an answer that does not say
     * them —, is held for support.
     *
     * @param array<string, mixed> $answer
     */
    private function paid(Payment $payment, int $id, array $answer): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $received = $answer['actualReceivedAmountToman'] ?? null;
        if (($answer['requiresManualDelivery'] ?? false) !== false) {
            throw new GatewayUnavailableException(sprintf(
                'AtlasPay took order %d for less than it asked (%s of %s Toman), the customer having accepted paying less: support decides on payment #%d',
                $id,
                is_int($received) ? (string) $received : 'an unknown sum',
                is_int($invoice['total'] ?? null) ? (string) $invoice['total'] : '?',
                $payment->id,
            ), held: true);
        }
        $reference = $answer['merchantOrderRef'] ?? null;
        $total = $answer['totalAmountToman'] ?? null;
        $asked = $invoice['total'] ?? null;
        if (!is_string($reference) || $reference !== ($invoice['ref'] ?? null) || !is_int($total) || !is_int($asked) || $total < $asked) {
            throw new GatewayUnavailableException(self::refusal("said order {$id} was paid for another order or a smaller sum than payment #{$payment->id}'s", 200, $answer), held: true);
        }
        $tracking = $invoice['tracking_code'] ?? $answer['trackingCode'] ?? null;

        return OnlineVerdict::paid(is_string($tracking) && preg_match(self::TRACKING, $tracking) === 1 ? $tracking : null);
    }

    /**
     * One call to AtlasPay with the merchant's key (Drivers\GatewayHttp): its HTTP status and its JSON object.
     *
     * @param array<string, mixed> $options
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no key to call with
     */
    private function call(string $method, string $url, array $options): array
    {
        if ($this->apiKey === '') {
            throw new GatewayUnavailableException('The AtlasPay method has no API key (or it no longer decrypts): enter it again in the payment method');
        }

        return GatewayHttp::call($this->http, 'AtlasPay', $method, $url, $options, ['X-API-Key' => $this->apiKey]);
    }

    /**
     * The card AtlasPay gave, to show in the bot and on the site — its 16 digits, holder and bank —, encrypted: it is a
     * real card's number, kept only for its page's minutes. Null for none (the merchant has no "direct payment info").
     *
     * @param array<string, mixed> $answer
     */
    private function sealCard(array $answer): ?string
    {
        $number = is_string($answer['cardNumber'] ?? null) ? preg_replace('/[\s-]+/', '', $answer['cardNumber']) : null;
        if ($number === null || preg_match('/^\d{16}$/D', $number) !== 1 || !$this->encrypter->isReady()) {
            return null;
        }
        $words = static fn(mixed $text, int $max): string => is_string($text) ? mb_substr(trim($text), 0, $max) : '';

        return $this->encrypter->encrypt(json_encode(
            ['number' => $number, 'holder' => $words($answer['cardHolderName'] ?? null, 100), 'bank' => $words($answer['bankName'] ?? null, 50)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** The card sealCard() kept, as the checkout shows one; none when it does not decrypt (APP_KEY changed). */
    private function openCard(mixed $sealed): ?CardTransfer
    {
        if (!is_string($sealed) || $sealed === '') {
            return null;
        }
        try {
            $card = json_decode($this->encrypter->decrypt($sealed), true, 4, JSON_THROW_ON_ERROR);
        } catch (\RuntimeException | \JsonException) {
            return null;
        }
        if (!is_array($card) || !is_string($card['number'] ?? null) || preg_match('/^\d{16}$/D', $card['number']) !== 1) {
            return null;
        }
        $holder = is_string($card['holder'] ?? null) && $card['holder'] !== '' ? $card['holder'] : null;
        $bank = is_string($card['bank'] ?? null) && $card['bank'] !== '' ? $card['bank'] : null;

        return new CardTransfer($card['number'], $holder, '', $bank);
    }

    /**
     * A log line of what AtlasPay said: the HTTP status, its message cut short — one, or a request's every fault as its
     * 400s list them —, and what a status means for the merchant.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer): string
    {
        $message = $answer['message'] ?? null;
        $said = GatewayHttp::said(is_array($message) ? implode('; ', array_filter($message, is_string(...))) : $message);
        $meaning = match ($status) {
            400 => ' — AtlasPay would not take the request: an amount out of its bounds, or a field it would not take',
            401 => ' — the API key is wrong: set the one AtlasPay gives in the payment method',
            404 => ' — AtlasPay knows no such order under this key: was the key changed to another account\'s?',
            503 => ' — AtlasPay is not taking payments just now',
            default => '',
        };

        return "AtlasPay {$what} (HTTP {$status})" . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
