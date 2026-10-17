<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\AqayePardakht;

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
 * An AqayePardakht method's gateway, on its API v2 (the gateway's pin in each call's body): a transaction is created in
 * Toman (create) and the customer sent to the bank's page (startpay/<transid>) — through a page on the shop's own
 * address, since Shaparak opens it only for a referrer of the gateway's verified domain (OnlineRequest::viaShop()) —,
 * then back to the shop's callback address in their browser (only a reason to ask). What came of it is verify's word —
 * the one call its pin may make about a transaction: 1 paid now, 2 verified before (both paid), 0 not paid — yet, while
 * its page takes money; closed after —, -10 for another amount (held). Its sandbox — the pin `sandbox` — moves no money.
 * Its details kept on the payment (`payments.invoice`): the transaction's id, the Toman it was created for, the bank's
 * page and whether it is the sandbox's. The transaction's id is the payment's reference; its page — the shop's, leading
 * on to the bank's — is shown at the order's sum.
 */
final class AqayePardakhtGateway implements OnlineGateway
{
    use OnlinePage;

    public const API = 'https://panel.aqayepardakht.ir/api/v2';
    public const START = 'https://panel.aqayepardakht.ir/startpay/';
    public const SANDBOX_START = 'https://panel.aqayepardakht.ir/startpay/sandbox/';

    /** The pin of its test service. */
    public const SANDBOX_PIN = 'sandbox';

    /** Minutes the shop keeps a page: AqayePardakht says nothing of its own limit. */
    public const PAGE_MINUTES = 20;

    /** A transaction's id at AqayePardakht (short letters in its sandbox, digits in its examples). */
    private const TRANSACTION = '/^[A-Za-z0-9]{3,64}$/D';

    /** verify's words: paid now, verified before, not paid. */
    private const PAID = ['1', '2'];
    private const NOT_PAID = '0';

    /** verify's: the amount is not the transaction's. */
    private const OTHER_AMOUNT = '-10';

    public function __construct(
        private readonly string $pin,
        private readonly ClientInterface $http,
    ) {}

    /** The key an AqayePardakht method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'aqayepardakht';
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        [$status, $answer] = $this->call('/create', [
            'amount' => $request->amount,
            'callback' => $request->callbackUrl,
            // In the address's query: a bank's page comes back to a browser — Telegram's — that may drop a form's body.
            'callback_method' => 'GET',
            'invoice_id' => $request->orderId,
            'description' => mb_substr($request->description, 0, 255),
        ]);
        $id = $answer['transid'] ?? null;
        if ($status !== 200 || ($answer['status'] ?? null) !== 'success' || !is_string($id) || preg_match(self::TRANSACTION, $id) !== 1) {
            throw new GatewayUnavailableException(self::refusal('would not create a transaction', $status, $answer));
        }
        $sandbox = $this->pin === self::SANDBOX_PIN;

        return new OnlineInvoice($request->viaShop(), OnlineInvoice::until(null, self::PAGE_MINUTES), $id, [
            'transid' => $id,
            'amount' => $request->amount,
            // The bank's own page, which the shop's page leads on to.
            'page' => ($sandbox ? self::SANDBOX_START : self::START) . $id,
            'sandbox' => $sandbox,
        ]);
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $id = $invoice['transid'] ?? null;
        $amount = $invoice['amount'] ?? null;
        if (!is_string($id) || preg_match(self::TRANSACTION, $id) !== 1 || !is_int($amount)) {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no AqayePardakht transaction and amount.");
        }

        [$status, $answer] = $this->call('/verify', ['amount' => $amount, 'transid' => $id]);
        $code = self::code($answer);

        return match (true) {
            in_array($code, self::PAID, true) => OnlineVerdict::paid($id),
            // Not paid: maybe not yet — the customer may still be on the bank's page.
            $code === self::NOT_PAID => $payment->link_expires_at !== null && $payment->link_expires_at->isFuture() ? OnlineVerdict::waiting() : OnlineVerdict::closed(),
            $code === self::OTHER_AMOUNT => throw new GatewayUnavailableException(self::refusal("would not verify transaction {$id} for payment #{$payment->id}'s amount", $status, $answer), held: true),
            default => throw new GatewayUnavailableException(self::refusal("did not say what came of transaction {$id}", $status, $answer)),
        };
    }

    /**
     * One call to AqayePardakht with the gateway's pin in its body: its HTTP status and its JSON object (empty for
     * anything else).
     *
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no pin to call with
     */
    private function call(string $path, array $body): array
    {
        if ($this->pin === '') {
            throw new GatewayUnavailableException('The AqayePardakht method has no pin (or it no longer decrypts): enter it again in the payment method');
        }

        return GatewayHttp::call($this->http, 'AqayePardakht', 'POST', self::API . $path, ['json' => ['pin' => $this->pin] + $body]);
    }

    /**
     * Its answer's code ("1", "-6"…) as a string — it writes them as strings, a number read the same —; null for none.
     *
     * @param array<string, mixed> $answer
     */
    private static function code(array $answer): ?string
    {
        $code = $answer['code'] ?? null;

        return is_string($code) || is_int($code) ? (string) $code : null;
    }

    /**
     * A log line of what AqayePardakht said: the HTTP status, its code, and what the code means for the merchant.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer): string
    {
        $code = self::code($answer);
        $meaning = match ($code) {
            '-5' => ' — an amount out of its bounds (1,000 to 400,000,000 Toman)',
            '-6' => ' — the pin is wrong: set the one AqayePardakht\'s panel shows in the payment method',
            '-8' => ' — AqayePardakht knows no such transaction',
            '-9' => ' — the pin is not the transaction\'s gateway\'s: was it changed to another gateway\'s?',
            '-10' => ' — another amount than the transaction was created for',
            '-11' => ' — the gateway is not approved yet, or switched off, at AqayePardakht',
            '-12' => ' — AqayePardakht takes no request for this merchant just now',
            '-14' => ' — the gateway is in use on another site',
            '-15' => ' — the shop\'s address (APP_URL) is not on the domain the gateway is verified for at AqayePardakht',
            '-16' => ' — no referrer, or another one than the gateway\'s domain',
            default => '',
        };

        return "AqayePardakht {$what} (HTTP {$status}" . ($code === null ? '' : ", code {$code}") . ')' . $meaning;
    }
}
