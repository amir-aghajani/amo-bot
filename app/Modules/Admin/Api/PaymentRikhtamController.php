<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Core\Http\Json;
use App\Core\Http\LongRunning;
use App\Core\Session\Session;
use App\Modules\Auth\Principal;
use App\Modules\Payments\Deposits\Services\RikhtamDeposits;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentDirectory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * A card payment paid by a deposit Rikhtam holds (rikhtam.com: one no invoice took), from the payments screen: the
 * deposits to pick from, and the one picked attached to it (Deposits\Services\RikhtamDeposits) — refused as an approval
 * is. A 503 in the admin's words while Rikhtam cannot be asked, or what attaching came to is not known yet: the log
 * says why.
 */
final class PaymentRikhtamController extends ApiController
{
    public const DOWN = 'ریختم الان جواب نمی‌دهد؛ کمی بعد دوباره امتحان کنید.';
    public const ATTACH_DOWN = 'نتیجه پیوست هنوز معلوم نیست: ریختم جواب نداد یا این واریز همین حالا در حال بررسی است. چند لحظه بعد وضعیت پرداخت را ببینید و اگر تایید نشده بود، دوباره امتحان کنید.';

    /** Seconds attaching may take once the admin hung up: Rikhtam calls the shop back in the middle of it. */
    private const TIME_LIMIT = 120;

    public function __construct(
        private readonly RikhtamDeposits $rikhtam,
        private readonly PaymentDirectory $payments,
        private readonly Session $session,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /payments/{id}/rikhtam — the deposits Rikhtam holds that no invoice took, closest to the payment's sum first;
     * none while Rikhtam is not connected
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $payment = $this->find($args);
        try {
            return $this->json($response, $this->rikhtam->pending($payment, Principal::of($request)->actor()));
        } catch (GatewayUnavailableException $e) {
            $this->logger->warning('Rikhtam\'s deposits could not be listed for payment {id}: {detail}', ['id' => $payment->id, 'detail' => $e->detail]);

            return Json::error($response, self::DOWN, 503);
        }
    }

    /**
     * POST /payments/{id}/rikhtam — {uid}: attaches that deposit to the payment; Rikhtam sends it to the shop's address
     * again in the middle of the call, which approves the payment. The payment as it stands after, and Rikhtam's word —
     * or, Rikhtam's answer lost where the deposit did approve it, the shop's (RikhtamDeposits::TAKEN_UNHEARD). A 503
     * while that is not known yet.
     *
     * @param array<string, string> $args
     */
    public function attach(Request $request, Response $response, array $args): Response
    {
        $payment = $this->find($args);
        $uid = $this->input($request)['uid'] ?? null;
        // Rikhtam calls the shop's own address in the middle of it: no lock of the session may wait on this request.
        $this->session->release();
        LongRunning::keepGoing(self::TIME_LIMIT);
        try {
            $said = $this->rikhtam->attach($payment, $uid, Principal::of($request)->actor());
        } catch (GatewayUnavailableException $e) {
            $this->logger->warning('A deposit could not be attached to payment {id} at Rikhtam: {detail}', ['id' => $payment->id, 'detail' => $e->detail]);

            return Json::error($response, self::ATTACH_DOWN, 503);
        }

        return $this->json($response, ['payment' => $this->payments->present($payment->refresh()->load(PaymentDirectory::RELATIONS))] + $said);
    }

    /** @param array<string, string> $args */
    private function find(array $args): Payment
    {
        return $this->load(Payment::class, $args, PaymentDirectory::RELATIONS);
    }
}
