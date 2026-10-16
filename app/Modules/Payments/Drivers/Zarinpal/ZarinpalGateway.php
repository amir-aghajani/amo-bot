<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Zarinpal;

use App\Modules\Payments\Contracts\OnlineGateway;
use App\Modules\Payments\Drivers\GatewayHttp;
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
 * A Zarinpal method's gateway, on its v4 API: a payment is requested in Rial (request.json) and the customer sent to its
 * StartPay page, then back to the shop's callback address (in their browser, `Authority` and `Status` in the query —
 * only a reason to ask). What came of it is asked of inquiry.json — PAID or VERIFIED is money in, IN_BANK still being
 * paid, FAILED or REVERSED none — and money in is confirmed by verify.json (100 now, 101 before: both paid; the amount
 * held to what was asked by Zarinpal itself). Requested with `auto_verify`, so Zarinpal confirms it with the bank even
 * when the shop cannot be reached — never handed back to the customer for want of the shop's verify. The sandbox
 * (sandbox.zarinpal.com) takes any code and moves no money; a payment is always asked of the host it was opened on,
 * whatever the method's switch says now. Its details kept on the payment (`payments.invoice`): the authority, the amount
 * asked in Rial, Zarinpal's fee when its merchant has the customer pay it (`fee`, Rial), and whether it was opened in
 * the sandbox.
 */
final class ZarinpalGateway implements OnlineGateway
{
    public const LIVE = 'https://payment.zarinpal.com';
    public const SANDBOX = 'https://sandbox.zarinpal.com';
    public const REQUEST = '/pg/v4/payment/request.json';
    public const VERIFY = '/pg/v4/payment/verify.json';
    public const INQUIRY = '/pg/v4/payment/inquiry.json';
    public const START = '/pg/StartPay/';

    /** A payment's authority: its id at Zarinpal ("A…" live, "S…" in the sandbox). */
    private const AUTHORITY = '/^[A-Za-z0-9]{10,64}$/D';

    /**
     * Minutes a StartPay page is taken to take money. Zarinpal says nothing of it, and its request's `expire_in` is
     * refused when it does not like it: the shop keeps a page this long, and asks about it long after (OnlinePaymentsTask).
     */
    private const PAGE_MINUTES = 15;

    /** Zarinpal's answer: it was done (a request made, a payment verified now). */
    private const DONE = 100;

    /** verify.json: verified before. */
    private const VERIFIED_BEFORE = 101;

    /** verify.json: the amount is not the one paid. */
    private const OTHER_AMOUNT = -50;

    /** inquiry.json: an authority Zarinpal does not know (-54), or a payment request it cannot find (-55) — nothing comes of it. */
    private const UNKNOWN = [-54, -55];

    /** The longest of Zarinpal's own words a log line keeps. */
    private const SAID_MAX = 200;

    public function __construct(
        private readonly string $merchantId,
        private readonly bool $sandbox,
        private readonly ClientInterface $http,
    ) {}

    /** The key a Zarinpal method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'zarinpal';
    }

    /** Its StartPay page, and the sum it asks: the payment's, and Zarinpal's fee when the customer pays it. */
    public function initiate(Payment $payment): PaymentInitiation
    {
        if ($payment->link === null) {
            return PaymentInitiation::instant();
        }
        $rial = $payment->invoice['fee'] ?? null;
        $fee = is_int($rial) && $rial > 0 ? self::toman($rial) : null;

        return PaymentInitiation::link(new PayLink(
            $payment->link,
            $payment->link_expires_at?->toDateTimeImmutable(),
            $fee === null ? $payment->amount : Money::add($payment->amount, $fee),
            fee: $fee,
        ));
    }

    /** Zarinpal said it was paid before this runs (Services\OnlinePayments): its bank reference is the reference. */
    public function settle(Payment $payment): PaymentResult
    {
        return PaymentResult::success($payment->reference);
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        $rial = $request->amount * 10;
        [$status, $answer] = $this->call($this->sandbox, self::REQUEST, [
            'amount' => $rial,
            'callback_url' => $request->callbackUrl,
            'description' => $request->description,
            // Zarinpal confirms it with the bank by itself: a shop out of reach never loses the customer's money.
            'metadata' => ['order_id' => $request->orderId, 'auto_verify' => true],
        ]);
        $data = $answer['data'] ?? null;
        $authority = is_array($data) ? ($data['authority'] ?? null) : null;
        if (!is_array($data) || ($data['code'] ?? null) !== self::DONE || !is_string($authority) || preg_match(self::AUTHORITY, $authority) !== 1) {
            throw new GatewayUnavailableException(self::refusal('would not open a payment', $status, $answer));
        }
        $fee = ($data['fee_type'] ?? null) === 'Payer' && is_int($data['fee'] ?? null) && $data['fee'] > 0 ? $data['fee'] : null;

        return new OnlineInvoice(
            self::base($this->sandbox) . self::START . $authority,
            OnlineInvoice::until(null, self::PAGE_MINUTES),
            $authority,
            ['authority' => $authority, 'amount' => $rial, 'fee' => $fee, 'sandbox' => $this->sandbox],
        );
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $authority = $invoice['authority'] ?? null;
        $rial = $invoice['amount'] ?? null;
        if (!is_string($authority) || !is_int($rial)) {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no Zarinpal authority and amount.");
        }
        // The host it was opened on: the other knows nothing of its authority.
        $sandbox = is_bool($invoice['sandbox'] ?? null) ? $invoice['sandbox'] : $this->sandbox;

        [$status, $answer] = $this->call($sandbox, self::INQUIRY, ['authority' => $authority]);
        $state = $answer['data']['status'] ?? null;

        return match (true) {
            in_array($state, ['PAID', 'VERIFIED'], true) => $this->verify($payment, $sandbox, $authority, $rial),
            $state === 'IN_BANK' => OnlineVerdict::waiting(),
            in_array($state, ['FAILED', 'REVERSED'], true), in_array(self::errorCode($answer), self::UNKNOWN, true) => OnlineVerdict::closed(),
            default => throw new GatewayUnavailableException(self::refusal("did not say what came of authority {$authority}", $status, $answer)),
        };
    }

    /**
     * Money in: confirmed by verify.json for the amount asked — paid now (100) or before (101), with its bank reference.
     * Paid for another amount (-50) is held; anything else (-51: not paid after all) is asked again later.
     */
    private function verify(Payment $payment, bool $sandbox, string $authority, int $rial): OnlineVerdict
    {
        [$status, $answer] = $this->call($sandbox, self::VERIFY, ['amount' => $rial, 'authority' => $authority]);
        $data = $answer['data'] ?? null;
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        if (in_array($code, [self::DONE, self::VERIFIED_BEFORE], true)) {
            $reference = $data['ref_id'] ?? null;

            return OnlineVerdict::paid(is_int($reference) || is_string($reference) ? (string) $reference : $authority);
        }
        if (self::errorCode($answer) === self::OTHER_AMOUNT) {
            // Paid, but not what was asked: never the shop's to take as this payment.
            throw new GatewayUnavailableException(self::refusal("verified authority {$authority} for another amount than payment #{$payment->id}'s", $status, $answer), held: true);
        }

        throw new GatewayUnavailableException(self::refusal("would not verify authority {$authority} it said was paid", $status, $answer));
    }

    /**
     * One call to Zarinpal, live or its sandbox, with the merchant's code (Drivers\GatewayHttp): its HTTP status and its
     * JSON object.
     *
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no code to call with
     */
    private function call(bool $sandbox, string $path, array $body): array
    {
        if ($this->merchantId === '') {
            throw new GatewayUnavailableException('The Zarinpal method has no merchant code (or it no longer decrypts): enter it again in the payment method');
        }

        return GatewayHttp::call($this->http, 'Zarinpal', 'POST', self::base($sandbox) . $path, ['json' => ['merchant_id' => $this->merchantId] + $body]);
    }

    private static function base(bool $sandbox): string
    {
        return $sandbox ? self::SANDBOX : self::LIVE;
    }

    /**
     * Zarinpal's error code in an answer: `errors.code`, or — as its validation errors say it — a code among a field's
     * messages (`errors.authority: ["Invalid authority.", "-54"]`); null for none.
     *
     * @param array<string, mixed> $answer
     */
    private static function errorCode(array $answer): ?int
    {
        $errors = $answer['errors'] ?? null;
        if (!is_array($errors)) {
            return null;
        }
        if (is_int($errors['code'] ?? null)) {
            return $errors['code'];
        }
        foreach ($errors as $messages) {
            foreach (is_array($messages) ? $messages : [] as $message) {
                if (is_string($message) && preg_match('/^-\d{1,3}$/D', $message) === 1) {
                    return (int) $message;
                }
            }
        }

        return null;
    }

    /** Rial as Toman, a part of a Toman rounded up. */
    private static function toman(int $rial): string
    {
        return Money::normalize(intdiv($rial + 9, 10));
    }

    /**
     * A log line of what Zarinpal said: the HTTP status, its code and message cut short, and what a code means for the
     * merchant.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer): string
    {
        $errors = is_array($answer['errors'] ?? null) ? $answer['errors'] : [];
        $said = is_string($errors['message'] ?? null) ? mb_substr($errors['message'], 0, self::SAID_MAX) : '';
        $code = self::errorCode($answer);
        $meaning = match ($code) {
            -9 => ' — an amount out of Zarinpal\'s bounds (100 to 100,000,000 Toman), or a request it would not take',
            -10 => ' — the merchant code is wrong, or the gateway is held to server IPs at Zarinpal that are not this one',
            -11, -15, -16, -17, -19 => ' — the gateway is not active at Zarinpal: see its panel or its support',
            -12 => ' — too many requests just now',
            -13 => ' — the gateway\'s limit at Zarinpal is reached: complete its documents there',
            -14 => ' — the shop\'s address (APP_URL) is not the domain registered for the gateway at Zarinpal',
            -41 => ' — more than Zarinpal takes in one payment (100,000,000 Toman)',
            default => '',
        };

        return "Zarinpal {$what} (HTTP {$status}" . ($code === null ? '' : ", code {$code}") . ')' . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
