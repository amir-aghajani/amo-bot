<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\Urls;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Payments\Deposits\Sources\Rikhtam;
use App\Modules\Payments\Drivers\Rikhtam\RikhtamApi;
use App\Modules\Payments\Enums\DepositStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Deposit;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Support\Money;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Rikhtam's side of «تایید خودکار واریز» (rikhtam.com, «ریختم»), each shop its own: the shop connects one Rikhtam gateway
 * by its «کلید امنیتی» — registering its own address with it (RikhtamApi::hook()), the key kept only once Rikhtam took
 * the address —, registers the address again after a move, and forgets it. For a card payment it lists the deposits no
 * invoice took (bg_pending) and attaches one to it (bg_attach): Rikhtam then sends that deposit to the shop's address
 * again, named for the payment, and Deposits::attach() decides it there — in the middle of the call, so the caller holds
 * no lock, transaction or session while it runs.
 */
final class RikhtamDeposits
{
    public const NOT_HTTPS = 'آدرس سایت فروشگاه باید با https شروع شود: ریختم واریزها را فقط به آدرس https می‌فرستد. «آدرس سایت» را در «تنظیمات پنل» به آدرس https سایت تغییر دهید و دوباره امتحان کنید.';
    public const NO_SECRET = 'کلید امنیتی درگاه ریختم را وارد کنید.';
    public const BAD_KEY = 'این کلید امنیتی هیچ درگاهی در ریختم نیست؛ آن را دوباره از پنل ریختم، از درگاهی که برای این فروشگاه ساخته‌اید، کپی کنید.';
    public const BAD_URL = 'ریختم آدرس فروشگاه را نپذیرفت: آدرس سایت باید آدرس https عمومی باشد که از اینترنت باز شود، نه آدرس داخلی یا محلی.';
    public const OUT_OF_REACH = 'ریختم الان جواب نمی‌دهد و چیزی تغییر نکرد؛ کمی بعد دوباره امتحان کنید.';
    public const NOT_CONNECTED = 'ریختم به این فروشگاه وصل نیست؛ اول کلید امنیتی درگاه ریختم را در «تایید خودکار واریز» وارد کنید.';
    public const NO_UID = 'واریزی از لیست ریختم انتخاب کنید.';
    public const ATTACH_FAILED = 'ریختم این واریز را به این پرداخت پیوست نکرد: واریز پیدا نشد، پیش‌تر تایید شده بود، یا مبلغش کمتر از مبلغ سفارش است.';
    public const TAKEN_UNHEARD = 'پرداخت با این واریز تایید شد، اما ریختم پیوست را تایید نکرد یا جوابش نرسید؛ ممکن است ریختم این واریز را هنوز وصل‌نشده نشان دهد.';
    public const KEY_ELSEWHERE = 'این کلید امنیتی را فروشگاه دیگری از همین سایت وصل کرده است، و ریختم واریزهای هر درگاه را فقط به یک آدرس می‌فرستد. برای این فروشگاه درگاه جدایی در پنل ریختم بسازید و کلید آن را وارد کنید.';

    /** The most of a key kept: as much as a Rikhtam crypto method takes (Drivers\Rikhtam\RikhtamDriver), the same key. */
    private const SECRET_MAX = 255;

    public function __construct(
        private readonly DepositSettings $settings,
        private readonly PaymentActions $actions,
        private readonly Urls $urls,
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {}

    /** Where Rikhtam tells the current shop of its deposits and crypto payments: the address registered with it. */
    public function callbackUrl(): string
    {
        return $this->urls->rikhtamWebhook(CurrentBot::id());
    }

    /**
     * Connects the Rikhtam gateway whose key `$secret` is: the shop's address registered with it, then the key kept and
     * «تایید خودکار واریز» switched on. Nothing is kept on a refusal.
     *
     * @throws ValidationException 422 on `secret`: none; the key another shop of the site holds (a gateway sends to one
     *                             address: registering this one would take that shop's deposits) or the shop's address
     *                             not https — both asked before Rikhtam is —; or Rikhtam's refusal in the owner's words,
     *                             out of reach included
     */
    public function connect(string $secret): void
    {
        $secret = trim($secret);
        if ($secret === '' || mb_strlen($secret) > self::SECRET_MAX) {
            throw ValidationException::on('secret', self::NO_SECRET);
        }
        if ($this->settings->rikhtamKeyElsewhere($secret)) {
            throw ValidationException::on('secret', self::KEY_ELSEWHERE);
        }
        [$url, $answer] = $this->register($secret);
        $this->settings->connectRikhtam($secret, $url, $answer);
        if (!$this->settings->enabled()) {
            $this->settings->enable(true);
        }
    }

    /**
     * Registers the shop's address with its Rikhtam gateway again (its address moved), what Rikhtam said kept.
     *
     * @throws ValidationException 422 on `secret`: not connected, or as connect()
     */
    public function hookAgain(): void
    {
        $key = $this->settings->rikhtamKey() ?? throw ValidationException::on('secret', self::NOT_CONNECTED);
        [$url, $answer] = $this->register($key);
        $this->settings->connectRikhtam($key, $url, $answer);
    }

    /** Forgets the shop's Rikhtam key: a deposit signed with it is refused from now on. */
    public function disconnect(): void
    {
        $this->settings->forgetRikhtam();
    }

    /**
     * The settings' `rikhtam` part: whether it is connected, what its registration said, and the address to register.
     *
     * @return array{connected: bool, domain: string|null, project_id: int|null, callback_url: string, connected_at: string|null}
     */
    public function present(): array
    {
        $connected = $this->settings->rikhtamConnected();
        $hook = $connected ? $this->settings->rikhtamHook() : null;

        return [
            'connected' => $connected,
            'domain' => $hook['domain'] ?? null,
            'project_id' => $hook['project_id'] ?? null,
            'callback_url' => $this->callbackUrl(),
            'connected_at' => $hook['at'] ?? null,
        ];
    }

    /**
     * The deposits of the last 20 days no invoice took, as Rikhtam lists them (at most 40), for attaching one to the card
     * payment: those whose sum is closest to its own first, the newer first among equals. None while not connected.
     *
     * @return array{connected: bool, deposits: list<array{uid: string, amount: string, received_at: string|null, account: string|null}>}
     * @throws ActorRefusedException|ValidationException as attach() would refuse the payment; or 422 on `status`:
     *                                                   Rikhtam refusing the shop's key, in its own words
     * @throws GatewayUnavailableException out of reach, or an answer that is no list — `detail` the log's
     */
    public function pending(Payment $payment, Actor $actor): array
    {
        $this->mayAttach($payment, $actor);
        $key = $this->settings->rikhtamKey();
        if ($key === null) {
            return ['connected' => false, 'deposits' => []];
        }
        [$status, $answer] = (new RikhtamApi($this->http, $key))->pending();
        if ($status === 403) {
            $this->logger->warning('Rikhtam refused the shop\'s key listing its deposits: {code}', ['code' => RikhtamApi::code($answer) ?? '-']);

            throw ValidationException::on('status', RikhtamApi::message($answer, self::BAD_KEY));
        }
        if ($status !== 200 || ($answer['ok'] ?? null) !== true || !is_array($answer['deposits'] ?? null)) {
            throw new GatewayUnavailableException(sprintf('Rikhtam answered bg_pending with HTTP %d %s %s', $status, RikhtamApi::code($answer) ?? '', RikhtamApi::message($answer)));
        }

        $deposits = [];
        foreach ($answer['deposits'] as $row) {
            $deposit = is_array($row) ? self::listed($row) : null;
            if ($deposit !== null) {
                $deposits[] = $deposit;
            }
        }
        $price = (float) $payment->amount;
        usort($deposits, static fn(array $a, array $b): int => [abs((float) $a['amount'] - $price), $b['received_at'] ?? ''] <=> [abs((float) $b['amount'] - $price), $a['received_at'] ?? '']);
        // Whether attaching it pays the payment: it must cover its order's own price (Deposits::attach()).
        $order = $payment->order->amount;
        $deposits = array_map(static fn(array $deposit): array => $deposit + ['covers' => Money::compare($deposit['amount'], $order) >= 0], $deposits);

        return ['connected' => true, 'deposits' => $deposits];
    }

    /**
     * Attaches the deposit `$uid` to the card payment (bg_attach): Rikhtam sends it to the shop's address again during
     * the call, and the payment is approved there (Deposits::attach()). Rikhtam's word on it — its message, to show, and
     * the fee it took, in Toman (null for none said). The caller holds no lock, transaction or session meanwhile. When
     * Rikhtam's answer is lost, or a refusal — the shop's own answer reached it too late —, the shop's record of the
     * deposit decides: it approved the payment, and that is said (TAKEN_UNHEARD); it is being decided this moment, and
     * nothing is known yet (a GatewayUnavailableException).
     *
     * @return array{message: string, fee: string|null}
     * @throws ActorRefusedException 403 their own payment; on the website, no receipt in review — as an approval
     * @throws ValidationException 422 on `status` (not a payment to approve) or on `uid` (none, not connected, or
     *                             Rikhtam's refusal in its own words)
     * @throws GatewayUnavailableException out of reach, or the deposit still being decided by the shop
     */
    public function attach(Payment $payment, mixed $uid, Actor $actor): array
    {
        $this->mayAttach($payment, $actor);
        if (!is_string($uid) || trim($uid) === '' || mb_strlen($uid) > Rikhtam::UID_MAX) {
            throw ValidationException::on('uid', self::NO_UID);
        }
        $uid = trim($uid);
        $key = $this->settings->rikhtamKey() ?? throw ValidationException::on('uid', self::NOT_CONNECTED);
        try {
            [$status, $answer] = (new RikhtamApi($this->http, $key))->attach($uid, (string) $payment->id);
        } catch (GatewayUnavailableException $e) {
            return $this->unheard($payment, $uid, $e->detail) ?? throw $e;
        }
        if ($status !== 200 || ($answer['ok'] ?? null) !== true) {
            $code = RikhtamApi::code($answer);
            $this->logger->warning('Rikhtam would not attach a deposit to payment {id}: HTTP {status} {code}', ['id' => $payment->id, 'status' => $status, 'code' => $code ?? '-']);
            $taken = $this->unheard($payment, $uid, "HTTP {$status} " . ($code ?? '-'));
            if ($taken !== null) {
                return $taken;
            }
            if ($status >= 500) {
                throw new GatewayUnavailableException("Rikhtam answered bg_attach with HTTP {$status}");
            }

            throw ValidationException::on('uid', RikhtamApi::message($answer, match ($code) {
                'bad_project' => self::BAD_KEY,
                'disabled' => 'درگاه ریختم این فروشگاه غیرفعال است؛ آن را در پنل ریختم فعال کنید.',
                'billing', 'too_many' => 'ریختم فعلا پیوست تازه نمی‌پذیرد؛ کمی بعد دوباره امتحان کنید یا حساب ریختم را بررسی کنید.',
                default => self::ATTACH_FAILED,
            }));
        }
        $fee = $answer['fee'] ?? null;

        return [
            'message' => RikhtamApi::message($answer, 'واریز به این پرداخت پیوست شد.'),
            'fee' => is_int($fee) || is_float($fee) || (is_string($fee) && is_numeric($fee)) ? Money::normalize($fee) : null,
        ];
    }

    /**
     * What attaching came to by the shop's own record of the deposit `$uid`, when Rikhtam's answer said nothing of it
     * (`$why`, for the log): it approved the payment — Rikhtam's word only lost or late —, and that is the answer; null
     * for anything else.
     *
     * @return array{message: string, fee: null}|null
     * @throws GatewayUnavailableException the shop is deciding that deposit this moment: nothing is known yet
     */
    private function unheard(Payment $payment, string $uid, string $why): ?array
    {
        $deposit = Deposit::query()->where('source', Rikhtam::KEY)->where('external_id', $uid)->first();
        if ($deposit?->status === DepositStatus::Received) {
            throw new GatewayUnavailableException("Rikhtam's answer attaching deposit {$uid} said nothing ({$why}) while the shop was still deciding it");
        }
        if ($deposit?->status !== DepositStatus::Matched || $deposit->payment_id !== $payment->id) {
            return null;
        }
        $this->logger->warning('Rikhtam\'s answer attaching a deposit to payment {id} said nothing of it ({why}), but the deposit approved it', ['id' => $payment->id, 'why' => $why]);

        return ['message' => self::TAKEN_UNHEARD, 'fee' => null];
    }

    /**
     * Registers the shop's address with the Rikhtam gateway of `$key`: the address and Rikhtam's answer.
     *
     * @return array{string, array<string, mixed>}
     * @throws ValidationException 422 on `secret`
     */
    private function register(string $key): array
    {
        $url = $this->callbackUrl();
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw ValidationException::on('secret', self::NOT_HTTPS);
        }
        try {
            [$status, $answer] = (new RikhtamApi($this->http, $key))->hook($url);
        } catch (GatewayUnavailableException $e) {
            $this->logger->warning('Rikhtam could not be reached to register the shop\'s address: {detail}', ['detail' => $e->detail]);

            throw ValidationException::on('secret', self::OUT_OF_REACH);
        }
        if ($status === 200 && ($answer['ok'] ?? null) === true) {
            return [$url, $answer];
        }
        $code = RikhtamApi::code($answer);
        $this->logger->warning('Rikhtam refused the shop\'s address {url}: HTTP {status} {code}', ['url' => $url, 'status' => $status, 'code' => $code ?? '-']);
        $host = (string) parse_url($url, PHP_URL_HOST);

        throw ValidationException::on('secret', match (true) {
            $code === 'bad_key' || $status === 403 => self::BAD_KEY,
            $code === 'wrong_domain' => sprintf("آدرس فروشگاه (\u{200E}%s) روی دامنه‌ای نیست که درگاه ریختم برای آن ساخته شده است؛ درگاه را با همین دامنه بسازید، یا این آدرس را در «آدرس کال‌بک» درگاه در پنل ریختم بگذارید: \u{200E}%s", $host, $url),
            $code === 'bad_url' => self::BAD_URL,
            $status >= 500 || $status === 0 => self::OUT_OF_REACH,
            default => RikhtamApi::message($answer, self::OUT_OF_REACH),
        });
    }

    /**
     * Refuses a payment a deposit may not be attached to here, as an approval would refuse it: only an open card payment
     * of an open order, never an admin's own, and on the website only a receipt in review.
     *
     * @throws ActorRefusedException|ValidationException
     */
    private function mayAttach(Payment $payment, Actor $actor): void
    {
        if (!$this->actions->allowed($payment)['approve']) {
            throw ValidationException::on('status', $this->actions->refusal($payment, 'approve'));
        }
        if ($actor->is($payment->order->user_id)) {
            throw ActorRefusedException::ownPayment();
        }
        if ($actor->isStaff() && $payment->status !== PaymentStatus::AwaitingReview) {
            throw ActorRefusedException::receiptFirst();
        }
    }

    /**
     * A deposit as bg_pending lists it, as the shop shows it; null for a row that is not one.
     *
     * @param array<array-key, mixed> $row
     * @return array{uid: string, amount: string, received_at: string|null, account: string|null}|null
     */
    private static function listed(array $row): ?array
    {
        $uid = $row['uid'] ?? null;
        $amount = $row['amount'] ?? null;
        if (!is_string($uid) || $uid === '' || mb_strlen($uid) > Rikhtam::UID_MAX || !(is_int($amount) || (is_string($amount) && is_numeric($amount)))) {
            return null;
        }
        $account = preg_replace('/\D+/', '', is_scalar($row['account'] ?? null) ? (string) $row['account'] : '');

        return [
            'uid' => $uid,
            'amount' => Money::normalize($amount),
            'received_at' => self::when($row['time'] ?? null),
            'account' => $account === null || $account === '' ? null : substr($account, -4),
        ];
    }

    /** A moment Rikhtam gave — Unix seconds, or Tehran's clock as text —, in ISO 8601; null for none. */
    private static function when(mixed $time): ?string
    {
        if (is_int($time) || (is_string($time) && ctype_digit($time))) {
            return (int) $time > 0 ? Carbon::createFromTimestamp((int) $time)->utc()->toIso8601String() : null;
        }
        if (is_string($time) && trim($time) !== '') {
            try {
                return Carbon::parse($time, 'Asia/Tehran')->utc()->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
