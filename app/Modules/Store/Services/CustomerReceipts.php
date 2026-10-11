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
 * A card transfer's receipt uploaded from the website, taken as the bot takes one: for the customer's own payment while
 * it awaits a receipt, judged as a picture by its bytes, kept among the shop's uploads, then the bot's compare-and-swap
 * and report (PaymentService::submitUpload()). Unlike the bot's, an uploaded receipt never auto-approves: it always waits
 * for support. Uploads share the customer's one picture budget with their tickets (CustomerPictures::budget()).
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
     * Take `$file` (with the customer's `note`) as the receipt of their payment `$paymentId`; answers its order, the payment
     * now awaiting review. A picture that loses the compare-and-swap — another receipt or a decision came first — is
     * discarded, as is one whose save failed.
     *
     * @param array<string, mixed> $input The form's other fields
     * @throws ModelNotFoundException 404: no payment of theirs
     * @throws ValidationException 422 on `status` (it awaits no receipt), `note` or `file`
     * @throws TooManyAttemptsException 429: the customer's picture budget is spent
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

        // The note and the picture are refused together, before anything is kept.
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
        // Counted once it passed its checks: the customer's one budget of pictures.
        $this->limiter->enforce([CustomerPictures::budget($customer)], CustomerPictures::TOO_MANY);

        $path = $this->receipts->keep($payment, $picture['bytes'], $picture['extension']);
        try {
            $submitted = $this->payments->submitUpload($payment, $path, $note, $file?->getClientFilename());
        } catch (\Throwable $e) {
            // The save failed (its report refused by the database, say): no receipt, so no file either.
            $this->receipts->discard($path);

            throw $e;
        }
        if (!$submitted) {
            // Another receipt (an album's other picture) or a decision came first.
            $this->receipts->discard($path);

            throw ValidationException::on('status', $this->actions->refusal($payment->refresh(), 'receipt'));
        }

        return $this->orders->find($customer, $payment->order_id);
    }
}
