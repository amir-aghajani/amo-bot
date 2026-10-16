<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Core\Http\Json;
use App\Modules\Auth\Principal;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentDirectory;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\ReceiptDismissals;
use App\Modules\Payments\Services\Receipts;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * The payments screen: the list, a receipt's picture, and the modal's operations — approve, reject, cancel, remind, retry
 * the delivery, refund, and ask an online payment's gateway — each PaymentActions', which tells the customer. An
 * operation answers the payment's row as it stands after it. The list's selection dismisses receipts waiting for review
 * in bulk (ReceiptDismissals).
 */
final class PaymentsController extends ApiController
{
    /** What the admin reads while an online payment's gateway cannot be asked (its reason is the log's). */
    public const GATEWAY_DOWN = 'درگاه پرداخت الان جواب نمی‌دهد و چیزی تغییر نکرد؛ کمی بعد دوباره استعلام کنید.';

    public function __construct(
        private readonly PaymentDirectory $payments,
        private readonly PaymentActions $actions,
        private readonly Receipts $receipts,
        private readonly ReceiptDismissals $dismissals,
        private readonly LoggerInterface $logger,
    ) {}

    /** GET /payments?search=&status=&user=&from=&to=&sort=&dir=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->payments->search($this->pageRequest($request))->toArray('payments'));
    }

    /**
     * GET /payments/{id} — one payment as the list shows it: the modal keeps it fresh while it is open
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->row($response, $this->find($args));
    }

    /**
     * GET /payments/{id}/receipt — the receipt: a picture to show, anything else a file to save; a 404 when there is none
     * (none sent, or Telegram no longer has it), a 502 while Telegram is out of reach.
     *
     * @param array<string, string> $args
     */
    public function receipt(Request $request, Response $response, array $args): Response
    {
        $file = $this->receipts->fetch($this->find($args));

        return $this->bytes($response, $file['body'], $file['mime'], 'private, max-age=300', $file['name']);
    }

    /**
     * POST /payments/{id}/approve — a receipt, or by hand
     *
     * @param array<string, string> $args
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        return $this->row($response, $this->actions->approve($this->find($args), Principal::of($request)->actor()));
    }

    /**
     * POST /payments/{id}/reject — {note?}
     *
     * @param array<string, string> $args
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $payment = $this->find($args);

        return $this->row($response, $this->actions->reject($payment, Principal::of($request)->actor(), $this->note($request)));
    }

    /**
     * POST /payments/dismiss — {ids? | all? (until?), note?}: the receipts waiting for review that `ids` names, or (`all`)
     * the shop's oldest ReceiptDismissals::MOST that had come by `until`, each refused as reject() refuses one and its
     * uploaded picture deleted
     */
    public function dismiss(Request $request, Response $response): Response
    {
        $input = $this->input($request);
        $which = $this->dismissals->selection($input);
        $note = Input::note($input, 'note');

        return $this->json($response, $this->dismissals->dismiss($which, Principal::of($request)->actor(), $note));
    }

    /**
     * POST /payments/{id}/cancel — {note?}: the payment and its open order
     *
     * @param array<string, string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $payment = $this->find($args);

        return $this->row($response, $this->actions->cancel($payment, Principal::of($request)->actor(), $this->note($request)));
    }

    /**
     * POST /payments/{id}/remind — remind the customer to pay; `delivery` says whether, and how, they were told
     *
     * @param array<string, string> $args
     */
    public function remind(Request $request, Response $response, array $args): Response
    {
        $payment = $this->find($args);
        $delivery = $this->actions->remind($payment);

        return $this->json($response, ['payment' => $this->payments->present($payment->load(PaymentDirectory::RELATIONS)), 'delivery' => $delivery->value]);
    }

    /**
     * POST /payments/{id}/check — ask an online payment's gateway what came of it, acting on its word (PaymentActions::
     * check()); `state` says what it said. A 503 in the admin's words while the gateway cannot be asked: the log says why.
     * An answer that does not fit the payment holds it for support: a 422 on `status`, as every ask of it from then on.
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `status`: not to be asked, or held for support by this ask
     */
    public function check(Request $request, Response $response, array $args): Response
    {
        $payment = $this->find($args);
        try {
            $state = $this->actions->check($payment, Principal::of($request)->actor());
        } catch (GatewayUnavailableException $e) {
            if ($e->held) {
                // Held by this very ask, not "nothing changed": the gateway's answer did not fit it (OnlinePayments logs it).
                throw ValidationException::on('status', $this->actions->refusal($payment->refresh(), 'check'));
            }
            $this->logger->warning('Payment {id}\'s gateway could not be asked from a panel: {detail}', ['id' => $payment->id, 'detail' => $e->detail]);

            return Json::error($response, self::GATEWAY_DOWN, 503);
        }

        return $this->json($response, ['payment' => $this->payments->present($payment->refresh()->load(PaymentDirectory::RELATIONS)), 'state' => $state->value]);
    }

    /**
     * POST /payments/{id}/retry — deliver again what a paid payment bought (its order's retry)
     *
     * @param array<string, string> $args
     */
    public function retry(Request $request, Response $response, array $args): Response
    {
        return $this->row($response, $this->actions->retry($this->find($args)));
    }

    /**
     * POST /payments/{id}/refund — {note?}: the note becomes the refund's wallet line, so it is held to that line's
     * length (PaymentService::REFUND_NOTE_MAX)
     *
     * @param array<string, string> $args
     */
    public function refund(Request $request, Response $response, array $args): Response
    {
        $payment = $this->find($args);

        return $this->row($response, $this->actions->refund($payment, Principal::of($request)->actor(), $this->note($request, PaymentService::REFUND_NOTE_MAX)));
    }

    /** @param array<string, string> $args */
    private function find(array $args): Payment
    {
        return $this->load(Payment::class, $args, PaymentDirectory::RELATIONS);
    }

    /** Support's note to the customer with the decision, refused past `$max` before anything changes. */
    private function note(Request $request, int $max = Input::NOTE_MAX): ?string
    {
        return Input::note($this->input($request), 'note', $max);
    }

    /** The payment's row as it stands now, its relations read again. */
    private function row(Response $response, Payment $payment): Response
    {
        return $this->json($response, ['payment' => $this->payments->present($payment->load(PaymentDirectory::RELATIONS))]);
    }
}
