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
 * Receipt pictures, under the shop's one rule for customer pictures (CustomerPictures): one sent in the bot stays
 * Telegram's (`receipt_file_id`); one uploaded from the website is kept in the receipts folder (`receipt_path`) until
 * discarded — after losing its compare-and-swap, or when its order expired.
 */
final class Receipts
{
    /** What a refusal of a receipt's picture calls it. */
    private const WHAT = 'تصویر رسید';

    private const NO_FILE = 'تصویر رسید را بفرستید.';

    public function __construct(private readonly CustomerPictures $pictures) {}

    /**
     * Judges a receipt uploaded from the website (CustomerPictures::judge()): its bytes and the extension to keep it under.
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
     * Keeps an uploaded receipt under a name the shop makes (bot, payment, random part — never the customer's file
     * name) and returns that name for `receipt_path`.
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
     * The receipt's bytes, their sniffed type and a file name to save them under.
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
