<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentDirectory;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\Receipts;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The payments screen and everything its modal can do: list, the receipt itself, approve (a receipt or by hand),
 * reject, cancel, remind, retry the delivery, refund — each PaymentActions', which tells the customer; the answer is
 * the payment's row as it stands after it.
 */
final class PaymentsController extends ApiController
{
    public function __construct(
        private readonly PaymentDirectory $payments,
        private readonly PaymentActions $actions,
        private readonly Receipts $receipts,
    ) {}

    /** GET /payments?search=&status=&user=&from=&to=&sort=&dir=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->payments->search(PageRequest::fromQuery($request->getQueryParams()))->toArray('payments'));
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
     * GET /payments/{id}/receipt — the receipt's bytes: a picture to show, anything else (a HEIC photo) a file to save;
     * a 404 when there is none (none sent, or Telegram no longer has it), a 502 while Telegram is out of reach.
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
     * POST /payments/{id}/remind — nudge the customer in the bot; the answer says whether they were told (`delivery`)
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
     * POST /payments/{id}/retry — deliver again what a paid payment bought (its order's retry)
     *
     * @param array<string, string> $args
     */
    public function retry(Request $request, Response $response, array $args): Response
    {
        return $this->row($response, $this->actions->retry($this->find($args)));
    }

    /**
     * POST /payments/{id}/refund — {note?}: the wallet line the refund writes carries it, so it is held to that line's room
     * (PaymentService::REFUND_NOTE_MAX)
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

    /** Support's word to the customer that came with the decision — refused past `$max` before anything changes. */
    private function note(Request $request, int $max = Input::NOTE_MAX): ?string
    {
        return Input::note($this->input($request), 'note', $max);
    }

    /** The payment's row as it stands now — its order re-read, as an operation left it. */
    private function row(Response $response, Payment $payment): Response
    {
        return $this->json($response, ['payment' => $this->payments->present($payment->load(PaymentDirectory::RELATIONS))]);
    }
}
