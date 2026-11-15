<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Rikhtam;

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
 * A Rikhtam crypto method's gateway, on Rikhtam's one address (RikhtamApi, the gateway's «کلید امنیتی» in each call): an
 * invoice is made in Toman for the payment's order number (crypto_create) — in the coin the owner set, or the one the
 * customer picks on Rikhtam's page — and the customer sent to its page, then back to the shop on an https shop. The money
 * goes on the chain straight to the merchant's own wallet, set in Rikhtam's panel. What came of it is asked of the
 * invoice (crypto_status): paid is paid; new (no coin chosen yet) and wait (a coin's address given) wait while the page
 * takes money — or the end Rikhtam set for the coin's address, picked late on its page, lies ahead —, closed after;
 * expired and canceled are closed — an underpaid or late transfer is never confirmed by Rikhtam on its own, it reports it
 * in its panel. An answer naming another order or invoice is held. Rikhtam's crypto.paid notification, to the shop's one
 * Rikhtam address (Urls::RIKHTAM_WEBHOOK), is only a reason to ask; accepts() tells whose it is. Its details kept on the
 * payment (`payments.invoice`): the invoice's id (text or a number in Rikhtam's answer, kept as text), the order number,
 * the Toman asked, the coin and — a coin chosen on making it — its address, amount and comment. The invoice's id is the
 * payment's reference; its page is shown at the order's sum, the coin's amount Rikhtam's page's to show.
 */
final class RikhtamGateway implements OnlineGateway
{
    use OnlinePage;

    /** The currency invoices are made in: the shop's own, Toman. */
    public const CURRENCY = 'IRT';

    /**
     * Minutes the shop keeps a page Rikhtam gave no end for: its documentation says none for an invoice whose coin is not
     * chosen yet, and a coin's address gets its own end only once the customer picks it on the page — an hour leaves
     * time for both.
     */
    public const PAGE_MINUTES = 60;

    /** The most hours a page's end Rikhtam gave may lie ahead: one further is a misread moment, and the minutes say it. */
    private const PAGE_MOST_HOURS = 24;

    /** The zone of a moment Rikhtam writes without one: Tehran's, as it writes its deposits' times. */
    private const ZONE = 'Asia/Tehran';

    /** The longest title an invoice is given. */
    private const TITLE_MAX = 100;

    /** Its own host, which a page it sends a customer to is on (GatewayHttp::page()). */
    private const HOST = 'rikhtam.com';

    /** An invoice's id at Rikhtam: its documentation shows none, so a number as much as a token. */
    private const ID = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** The longest coin address, amount or comment kept from its answer. */
    private const DETAIL_MAX = 128;

    /** @param string $coin The coin the owner set, by Rikhtam's name for it (RikhtamDriver::COINS); '' for the customer's pick */
    public function __construct(
        private readonly string $secret,
        private readonly string $coin,
        private readonly ClientInterface $http,
    ) {}

    /** The key a Rikhtam crypto method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'rikhtam';
    }

    /**
     * Whether `$key` — what a notification to the shop's Rikhtam address is signed with — is this gateway's own; never
     * for a method without one.
     */
    public function accepts(string $key): bool
    {
        return $this->secret !== '' && hash_equals($this->secret, $key);
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        $fields = [
            'order_id' => $request->orderId,
            'amount' => $request->amount,
            'currency' => self::CURRENCY,
            'title' => mb_substr($request->description, 0, self::TITLE_MAX),
        ];
        if ($this->coin !== '') {
            $fields['coin'] = $this->coin;
        }
        if (str_starts_with($request->callbackUrl, 'https://')) {
            // The customer's way back, to an address the internet reaches; else they come back to the bot themselves.
            $fields['return_url'] = $request->callbackUrl;
        }

        // The same order number again (a retry) is its open invoice again, never a second one.
        [$status, $answer] = $this->api()->cryptoCreate($fields);
        $id = self::id($answer['invoice_id'] ?? null);
        $url = GatewayHttp::page($answer['pay_url'] ?? null, self::HOST);
        if ($status !== 200 || ($answer['ok'] ?? null) !== true || $id === null || $url === null) {
            throw new GatewayUnavailableException(self::refusal('would not make a crypto invoice', $status, $answer));
        }

        return new OnlineInvoice($url, self::until($answer['expires'] ?? null), $id, [
            'invoice_id' => $id,
            'order_id' => $request->orderId,
            'amount' => $request->amount,
            'coin' => $this->coin === '' ? null : $this->coin,
        ] + array_filter([
            'address' => self::detail($answer['address'] ?? null),
            'coin_amount' => self::detail($answer['amount'] ?? null),
            'comment' => self::detail($answer['comment'] ?? null),
        ], static fn(?string $value): bool => $value !== null));
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $id = $invoice['invoice_id'] ?? null;
        if (!is_string($id) || preg_match(self::ID, $id) !== 1) {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no Rikhtam invoice id.");
        }

        [$status, $answer] = $this->api()->cryptoStatus($id);
        if ($status !== 200 || ($answer['ok'] ?? null) !== true) {
            throw new GatewayUnavailableException(self::refusal("did not say what came of crypto invoice {$id}", $status, $answer));
        }
        $order = $answer['order_id'] ?? null;
        $named = $answer['invoice_id'] ?? null;
        if (($order !== null && $order !== ($invoice['order_id'] ?? null)) || ($named !== null && self::id($named) !== $id)) {
            throw new GatewayUnavailableException(sprintf('Rikhtam said crypto invoice %s is order %s\'s (invoice %s), not payment #%d\'s %s', $id, json_encode($order, JSON_UNESCAPED_UNICODE), json_encode($named, JSON_UNESCAPED_UNICODE), $payment->id, json_encode($invoice['order_id'] ?? null)), held: true);
        }

        return match ($answer['status'] ?? null) {
            'paid' => OnlineVerdict::paid($id),
            // Not paid: maybe not yet — the customer may still be picking a coin on Rikhtam's page, or paying to the address
            // it gave until the end it set for it, which a coin picked late puts past the page's own.
            'new', 'wait' => self::end($answer['expires'] ?? null) !== null ? OnlineVerdict::waiting() : self::whilePageOpen($payment),
            'expired', 'canceled' => OnlineVerdict::closed(),
            default => throw new GatewayUnavailableException(self::refusal("said crypto invoice {$id} is in a state the shop does not know", $status, $answer)),
        };
    }

    /** Until when a page takes money: the end Rikhtam gave (end()), else PAGE_MINUTES from now. */
    public static function until(mixed $expires): \DateTimeImmutable
    {
        return self::end($expires) ?? OnlineInvoice::until(null, self::PAGE_MINUTES);
    }

    /**
     * The end Rikhtam gave a page (`expires`, Unix seconds or a moment it writes — Tehran's when it names no zone) while it
     * lies ahead within PAGE_MOST_HOURS; null for none, one past or too far ahead, or one not to be read.
     */
    private static function end(mixed $expires): ?\DateTimeImmutable
    {
        $at = null;
        if (is_int($expires) || (is_string($expires) && preg_match('/^[0-9]{9,11}$/D', $expires) === 1)) {
            $at = (new \DateTimeImmutable('@' . $expires))->setTimezone(new \DateTimeZone('UTC'));
        } elseif (is_string($expires) && $expires !== '') {
            try {
                $at = (new \DateTimeImmutable($expires, new \DateTimeZone(self::ZONE)))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                // Not a moment it can be read as: none.
            }
        }

        return $at !== null && $at > now()->toDateTimeImmutable() && $at <= now()->addHours(self::PAGE_MOST_HOURS)->toDateTimeImmutable() ? $at : null;
    }

    private function api(): RikhtamApi
    {
        return new RikhtamApi($this->http, $this->secret);
    }

    /** An invoice's id as an answer writes it, text or a number; null for anything else. */
    private static function id(mixed $value): ?string
    {
        $id = is_int($value) ? (string) $value : $value;

        return is_string($id) && preg_match(self::ID, $id) === 1 ? $id : null;
    }

    /** A coin's address, amount or comment from an answer, as text cut short; null for none. */
    private static function detail(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') || is_int($value) || is_float($value) ? mb_substr((string) $value, 0, self::DETAIL_MAX) : null;
    }

    /**
     * A log line of what Rikhtam said: the HTTP status, its code and its own words cut short, and what the code means for
     * the merchant.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer): string
    {
        $code = RikhtamApi::code($answer);
        $said = RikhtamApi::message($answer);
        $meaning = match (true) {
            $code === 'bad_project' || $code === 'bad_key' => ' — the gateway\'s «کلید امنیتی» is wrong: paste the one Rikhtam\'s panel shows under «درگاه‌ها» in the payment method',
            $code === 'disabled' => ' — the Rikhtam account, or this gateway, is disabled at Rikhtam',
            $code === 'billing' => ' — Rikhtam\'s fees on the account are unpaid: settle them in its panel',
            $code === 'already_paid' => ' — Rikhtam says this order was paid already: see it in Rikhtam\'s panel before the customer pays again',
            $code === 'not_found' || $status === 404 => ' — Rikhtam knows no such invoice under this key: was the key changed to another gateway\'s?',
            $code === 'too_many' || $status === 429 => ' — too many requests just now',
            $code === 'bad_request' || $status === 422 => ' — a field Rikhtam would not take: the amount, or the coin set in the payment method',
            $status === 403 => ' — Rikhtam refuses this key: see the gateway in Rikhtam\'s panel',
            default => '',
        };

        return "Rikhtam {$what} (HTTP {$status}" . ($code === null ? '' : ", {$code}") . ')' . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
