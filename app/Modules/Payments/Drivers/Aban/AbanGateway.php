<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Aban;

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
 * An AbanGateway method's gateway, on its API (v1, the merchant's key as a Bearer token): an invoice is opened in Rial for
 * 30 minutes, and the customer pays its page — a sum of its own, a few Rial more than asked (`payable_rial`), by which
 * the bank's text is matched to it; the order is paid and credited at its own price. Aban calls the shop back at the
 * invoice's `callback_url` and sends the customer's browser to its `return_url` — the shop's callback address, both,
 * when it is https: only a reason to ask, so its webhook's signature is not needed. What came of it is asked of the
 * invoice (paid, pending — or paid in part, money in: a sum above a card's daily ceiling being split —, expired,
 * cancelled), and a paid one is claimed by verify, which says yes once (409 after: paid all the same) — held for support
 * when its order or amount is not the payment's. Its details kept on the payment (`payments.invoice`): the invoice's id,
 * the shop's reference for it, the Rial asked and to pay, whether it is Aban's test mode (an invoice the other mode's key
 * cannot see), and the card to show in the bot when the merchant wants it and the sum is one transfer's. The invoice's
 * id is the payment's reference.
 */
final class AbanGateway implements OnlineGateway
{
    use OnlinePage;

    public const INVOICES = 'https://api.abangateway.ir/api/v1/invoices';

    /** A test key's beginning: its invoices move no money, and are paid only by Aban's simulation. */
    public const TEST_PREFIX = 'ag_test_';

    /** Minutes an invoice takes money: Aban's own default is the merchant's setting there, maybe a day's. */
    public const PAGE_MINUTES = 30;

    /**
     * The most Toman the card is shown in the bot for: the shop's own bound, well below a card's daily ceiling (about 15
     * million Toman, the bank's), above which Aban splits a sum into parts on its page.
     */
    public const CARD_MOST = 10_000_000;

    /** A page Aban sends a customer to: on its own host, over https. */
    private const PAGE = '~^https://([a-z0-9-]+\.)*abangateway\.ir/[\x21-\x7e]*$~D';

    /** An invoice's id at Aban ("inv_…"). */
    private const INVOICE = '/^[A-Za-z0-9_]{4,32}$/D';

    public function __construct(
        private readonly string $apiKey,
        private readonly bool $showCard,
        private readonly ClientInterface $http,
    ) {}

    /** The key an AbanGateway method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'abangateway';
    }

    /** Its page and the exact sum it asks — and the card, when it is to be shown —, as open() kept them. */
    public function initiate(Payment $payment): PaymentInitiation
    {
        $invoice = $payment->invoice ?? [];
        $payable = $invoice['payable'] ?? null;
        $card = is_array($invoice['card'] ?? null) && is_string($invoice['card']['number'] ?? null)
            ? new CardTransfer($invoice['card']['number'], self::words($invoice['card']['holder'] ?? null), '', self::words($invoice['card']['bank'] ?? null))
            : null;

        return $this->page($payment, is_int($payable) && $payable > 0 ? self::toman($payable) : null, $card);
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        $rial = $request->amount * 10;
        $body = ['amount_rial' => $rial, 'order_id' => $request->orderId, 'description' => $request->description, 'expiry_minutes' => self::PAGE_MINUTES];
        if (str_starts_with($request->callbackUrl, 'https://')) {
            // Aban takes only an https address the internet reaches: without one, the shop asks (OnlinePaymentsTask).
            $body['callback_url'] = $request->callbackUrl;
            $body['return_url'] = $request->callbackUrl;
        }

        [$status, $answer] = $this->call('POST', self::INVOICES, $body);
        if ($status !== 201 && $status !== 200) {
            throw new GatewayUnavailableException(self::refusal('would not open an invoice', $status, $answer));
        }
        $id = $answer['invoice_id'] ?? null;
        $url = $answer['payment_url'] ?? null;
        $payable = $answer['payable_rial'] ?? null;
        // The same order id twice is the first invoice again, as it stands: one for another sum, or no longer pending (paid,
        // expired, cancelled), is no page to send the customer to.
        if (!is_string($id) || preg_match(self::INVOICE, $id) !== 1 || !is_string($url) || preg_match(self::PAGE, $url) !== 1
            || ($answer['amount_rial'] ?? null) !== $rial || !is_int($payable) || $payable < $rial || ($answer['status'] ?? null) !== 'pending') {
            throw new GatewayUnavailableException(self::refusal('opened an invoice it did not describe', $status, $answer));
        }

        return new OnlineInvoice($url, OnlineInvoice::until($answer['expires_at'] ?? null, self::PAGE_MINUTES), $id, [
            'invoice_id' => $id,
            'ref' => $request->orderId,
            'amount' => $rial,
            // The exact sum the customer transfers: a few Rial more, all Aban's to match the bank's text by.
            'payable' => $payable,
            'card' => $this->card($answer, $payable),
            'test' => ($answer['is_test'] ?? false) === true,
        ]);
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $id = $payment->invoice['invoice_id'] ?? null;
        if (!is_string($id) || preg_match(self::INVOICE, $id) !== 1) {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no AbanGateway invoice id.");
        }

        [$status, $answer] = $this->call('GET', self::INVOICES . '/' . $id);
        if ($status !== 200) {
            $test = $payment->invoice['test'] ?? null;
            // A key of the other mode sees none of this one's invoices: the log says so, not a guess.
            $mode = $test === true ? 'test' : 'live';
            $meaning = $status === 404 && is_bool($test) && $test !== $this->isTest()
                ? sprintf(' — it was opened with a %s key and the method\'s key is now a %s one: only a %s key sees it', $mode, $test ? 'live' : 'test', $mode)
                : null;

            throw new GatewayUnavailableException(self::refusal("did not say what came of invoice {$id}", $status, $answer, $meaning));
        }

        return match ($answer['status'] ?? null) {
            'paid' => $this->claim($payment, $id, $answer),
            'pending' => OnlineVerdict::waiting(),
            // Paid in part: a sum above a card's ceiling, in parts, whose time Aban lengthens for the rest.
            'partially_paid' => OnlineVerdict::waiting(moneyIn: true),
            'expired', 'cancelled' => OnlineVerdict::closed(),
            default => throw new GatewayUnavailableException(self::refusal("said invoice {$id} is in a state the shop does not know", $status, $answer)),
        };
    }

    /**
     * A paid invoice claimed by verify, once its order and amount are the payment's: yes now (200), or claimed before
     * (409 already_verified) — both paid. Not paid after all (402) waits; expired (410) is closed.
     *
     * @param array<string, mixed> $invoice What Aban said of it
     */
    private function claim(Payment $payment, string $id, array $invoice): OnlineVerdict
    {
        $this->holdIfForAnother($payment, $id, $invoice, 'said invoice %s was paid');

        [$status, $answer] = $this->call('POST', self::INVOICES . "/{$id}/verify");
        if ($status === 200 && ($answer['verified'] ?? null) === true) {
            $this->holdIfForAnother($payment, $id, $answer, 'verified invoice %s');

            return OnlineVerdict::paid($id);
        }

        return match (true) {
            $status === 409 && self::errorCode($answer) === 'already_verified' => OnlineVerdict::paid($id),
            $status === 402 && self::errorCode($answer) === 'not_yet_paid' => OnlineVerdict::waiting(),
            $status === 410 => OnlineVerdict::closed(),
            default => throw new GatewayUnavailableException(self::refusal("would not verify invoice {$id} it said was paid", $status, $answer)),
        };
    }

    /**
     * Holds the payment for support when what Aban said of its invoice is for another order or amount than it asked.
     *
     * @param array<string, mixed> $answer
     * @throws GatewayUnavailableException held, for another order or amount
     */
    private function holdIfForAnother(Payment $payment, string $id, array $answer, string $what): void
    {
        $kept = $payment->invoice ?? [];
        $order = $answer['order_id'] ?? null;
        $rial = $answer['amount_rial'] ?? null;
        if (($order !== null && $order !== ($kept['ref'] ?? null)) || $rial !== ($kept['amount'] ?? null)) {
            throw new GatewayUnavailableException(self::refusal(sprintf($what, $id) . " for another order or amount than payment #{$payment->id}'s", 200, $answer), held: true);
        }
    }

    /**
     * One call to Aban with the merchant's key (Drivers\GatewayHttp): its HTTP status and its JSON object.
     *
     * @param array<string, mixed>|null $body
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no key to call with
     */
    private function call(string $method, string $url, ?array $body = null): array
    {
        if ($this->apiKey === '') {
            throw new GatewayUnavailableException('The AbanGateway method has no API key (or it no longer decrypts): enter it again in the payment method');
        }

        return GatewayHttp::call($this->http, 'AbanGateway', $method, $url, $body === null ? [] : ['json' => $body], ['Authorization' => 'Bearer ' . $this->apiKey]);
    }

    /** Whether the method's key is a test one: Aban's test mode. */
    private function isTest(): bool
    {
        return str_starts_with($this->apiKey, self::TEST_PREFIX);
    }

    /**
     * The card to show in the bot and on the site — its 16 digits, holder and bank —: when the merchant wants it, and the
     * sum is one transfer's in whole Toman, no more than CARD_MOST (above it, only Aban's page, which splits a sum above a
     * card's ceiling into parts). Null for none.
     *
     * @param array<string, mixed> $answer
     * @return array{number: string, holder: string, bank: string}|null
     */
    private function card(array $answer, int $payable): ?array
    {
        $number = $answer['card_number'] ?? null;
        if (!$this->showCard || !is_string($number) || preg_match('/^\d{16}$/D', $number) !== 1 || $payable % 10 !== 0 || intdiv($payable, 10) > self::CARD_MOST) {
            return null;
        }

        return [
            'number' => $number,
            'holder' => is_string($answer['card_holder'] ?? null) ? mb_substr(trim($answer['card_holder']), 0, 100) : '',
            'bank' => is_string($answer['bank'] ?? null) ? mb_substr(trim($answer['bank']), 0, 50) : '',
        ];
    }

    /**
     * Rial as Toman, a part of a Toman kept in the sum (a screen of whole Toman rounds it: the card, whose sum the customer
     * types, is shown only for whole Toman — card()).
     */
    private static function toman(int $rial): string
    {
        return Money::normalize(bcdiv((string) $rial, '10', 1));
    }

    private static function words(mixed $text): ?string
    {
        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * Aban's error code in an answer (`error.code`); null for none.
     *
     * @param array<string, mixed> $answer
     */
    private static function errorCode(array $answer): ?string
    {
        $code = is_array($answer['error'] ?? null) ? ($answer['error']['code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    /**
     * A log line of what Aban said: the HTTP status, its code and message cut short — its errors' `error`, or the
     * `detail` of a key it would not take and of a body it could not read —, and what that means for the merchant
     * (`$meaning`, when the caller knows better).
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer, ?string $meaning = null): string
    {
        $code = self::errorCode($answer);
        $detail = $answer['detail'] ?? null;
        $said = GatewayHttp::said(match (true) {
            is_array($answer['error'] ?? null) && is_string($answer['error']['message'] ?? null) => $answer['error']['message'],
            is_array($detail) => implode('; ', array_filter(array_map(static fn(mixed $fault): mixed => is_array($fault) ? ($fault['msg'] ?? null) : null, $detail), is_string(...))),
            default => $detail,
        });
        $meaning ??= match (true) {
            $status === 401 => ' — the API key is wrong or was revoked: make another in Aban\'s panel (Settings, API keys) and set it in the payment method',
            $code === 'insufficient_fee_wallet' => ' — the fee wallet at Aban is short: top it up in its panel',
            $code === 'no_card_registered' => ' — no card is registered at Aban: add one in its panel',
            $code === 'cards_at_ceiling' => ' — every card at Aban has reached its ceiling for the period: add another card there',
            $code === 'capacity_full' => ' — no distinct sum is free on the card just now: add a second card at Aban',
            $code === 'amount_not_allowed' => ' — an amount out of Aban\'s bounds',
            $code === 'unsafe_callback_url' => ' — Aban cannot reach the shop\'s address (APP_URL) from the internet over https',
            $status === 404 => ' — Aban knows no such invoice under this key: was the key changed to another account\'s, or between a live and a test one?',
            $status === 429 => ' — too many requests just now',
            default => '',
        };

        return "AbanGateway {$what} (HTTP {$status}" . ($code === null ? '' : ", {$code}") . ')' . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
