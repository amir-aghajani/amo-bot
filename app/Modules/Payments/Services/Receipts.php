<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Payments\Exceptions\ReceiptUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Enums\PictureFolder;
use App\Modules\Users\Services\CustomerPictures;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A payment's receipt, under the shop's one rule for a picture it is sent (Users\Services\CustomerPictures): the picture
 * the customer sent the bot — its Telegram file id (`receipt_file_id`): Telegram keeps it, nothing is copied to disk — or
 * the one they uploaded from the shop's website, kept in the receipts' folder (PictureFolder::Receipts, the container's
 * `receipts.path`, storage/uploads/receipts; `receipt_path` its name there). Either is fetched on demand for the payments
 * screen (fetch()). An upload is deleted once what made it is undone or over (discard()): a receipt that lost its
 * compare-and-swap, an order that expired.
 */
final class Receipts
{
    /** What a refusal of a receipt's picture calls it. */
    private const WHAT = 'تصویر رسید';

    private const NO_FILE = 'تصویر رسید را بفرستید.';

    public function __construct(private readonly CustomerPictures $pictures) {}

    /**
     * A picture the customer uploaded from the website as their receipt, judged by the shop's rule (CustomerPictures::
     * judge()): its bytes, and the extension it is kept under (keep()).
     *
     * @return array{bytes: string, extension: string}
     * @throws ValidationException 422 on `file`
     */
    public function judge(?UploadedFileInterface $file): array
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::on('file', self::NO_FILE);
        }

        return $this->pictures->judge($file, self::WHAT);
    }

    /**
     * An uploaded receipt of the payment kept — under a name of the shop's own: the payment's bot, the payment and a
     * random part, never the customer's file name —: its name, what the payment records (`receipt_path`).
     *
     * @throws \RuntimeException when the uploads folder cannot be written
     */
    public function keep(Payment $payment, string $bytes, string $extension): string
    {
        return $this->pictures->keep(PictureFolder::Receipts, $payment->bot_id . '-' . $payment->id, $bytes, $extension);
    }

    /** Uploaded receipts nobody needs any more, by their names (CustomerPictures::discard()). */
    public function discard(string ...$names): void
    {
        $this->pictures->discard(PictureFolder::Receipts, ...$names);
    }

    /**
     * The receipt's bytes, their type as the bytes say, and a name to save them under — the customer's file name, or
     * one made up.
     *
     * @return array{body: string, mime: string, name: string}
     * @throws ReceiptUnavailableException 404: none was sent, or its file is no more — Telegram no longer hands it over,
     *                                     an upload deleted
     * @throws TelegramUnreachableException 502: Telegram could not be asked — the receipt is still there
     */
    public function fetch(Payment $payment): array
    {
        $body = match (true) {
            $payment->receipt_path !== null => $this->pictures->read(PictureFolder::Receipts, $payment->receipt_path) ?? throw ReceiptUnavailableException::removed(),
            $payment->receipt_file_id !== null => $this->pictures->fromTelegram($payment->receipt_file_id) ?? throw ReceiptUnavailableException::gone(),
            default => throw ReceiptUnavailableException::none(),
        };

        return CustomerPictures::served($body, $payment->receipt_name, 'receipt-' . $payment->id);
    }
}
