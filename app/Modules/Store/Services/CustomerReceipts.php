<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\Receipts;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Users\Exceptions\StorageFullException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerPictures;
use App\Support\Input;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The receipt of a card transfer, uploaded from the shop's website — taken as the bot takes one (ReceiptHandler): for the
 * customer's own payment while it awaits its receipt (PaymentService::awaitsReceipt()), a picture by its bytes
 * (Receipts::judge()), kept among the shop's uploads (Receipts::keep()), then the same compare-and-swap and the same
 * report to the group (PaymentService::submitUpload()) — but no review window: an uploaded receipt always waits for
 * support (AutoApproveReceiptsTask). A picture that lost the compare-and-swap — another receipt, or a decision, came
 * first — is not kept. A customer uploads CustomerPictures::UPLOADS pictures in its window at most — receipts and their
 * tickets' pictures alike, each a picture kept on the host: one budget (CustomerPictures::budget()). None is taken while
 * the shop takes no orders — its bot switched off (CustomerCheckout::takingOrders()).
 */
final class CustomerReceipts
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentActions $actions,
        private readonly Receipts $receipts,
        private readonly CustomerOrders $orders,
        private readonly CustomerCheckout $checkout,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * The picture `$file`, with the customer's words (`note`, the receipt's length rule), as the receipt of their payment
     * `$paymentId` — and its order as it stands now: the payment awaiting review.
     *
     * @param array<string, mixed> $input The form's other fields
     * @throws ModelNotFoundException 404: no payment of theirs
     * @throws ValidationException 422 on `status` (it awaits no receipt — in the words support reads the state in), `note`
     *                             or `file`
     * @throws TooManyAttemptsException 429: they uploaded CustomerPictures::UPLOADS pictures in its window
     * @throws StorageFullException 503: the host's disk has no room for it
     * @throws CheckoutRefusedException 503: the shop takes no orders while its bot is switched off
     */
    public function upload(User $customer, int $paymentId, ?UploadedFileInterface $file, array $input): Order
    {
        $this->checkout->takingOrders();
        $payment = Payment::query()->whereRelation('order', 'user_id', $customer->id)->findOrFail($paymentId);
        if (!$this->payments->awaitsReceipt($payment)) {
            throw ValidationException::on('status', $this->actions->refusal($payment, 'receipt'));
        }

        // The note and the picture refused at once, before anything is kept.
        $noteRefused = null;
        try {
            $note = Input::note($input, 'note', PaymentService::RECEIPT_NOTE_MAX);
        } catch (ValidationException $e) {
            [$note, $noteRefused] = [null, $e];
        }
        try {
            $picture = $this->receipts->judge($file);
        } catch (ValidationException $e) {
            throw $noteRefused === null ? $e : new ValidationException($noteRefused->errors() + $e->errors());
        }
        if ($noteRefused !== null) {
            throw $noteRefused;
        }
        // Counted once it passed its checks, as it is kept: the customer's one budget of pictures.
        $wait = $this->limiter->attempt([CustomerPictures::budget($customer)]);
        if ($wait > 0) {
            throw TooManyAttemptsException::wait(CustomerPictures::TOO_MANY, $wait);
        }

        $path = $this->receipts->keep($payment, $picture['bytes'], $picture['extension']);
        try {
            $submitted = $this->payments->submitUpload($payment, $path, $note, $file?->getClientFilename());
        } catch (\Throwable $e) {
            // Taken back with what refused it — the receipt's report the database turned down, say —: no receipt either.
            $this->receipts->discard($path);

            throw $e;
        }
        if (!$submitted) {
            // Another receipt — an album's other picture — or a decision came first: this picture is no receipt.
            $this->receipts->discard($path);

            throw ValidationException::on('status', $this->actions->refusal($payment->refresh(), 'receipt'));
        }

        return $this->orders->find($customer, $payment->order_id);
    }
}
