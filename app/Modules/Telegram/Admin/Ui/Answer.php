<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Update\Update;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Unit;

/**
 * What an admin typed or sent at a management screen's question (Prompt): its words, read as the panel's forms read
 * them — digits in either script, «۵۰ هزار تومان», a list of numbers —, or a picture or a file, fetched from Telegram
 * when asked for.
 */
final class Answer
{
    public function __construct(
        private readonly Update $update,
        private readonly BotApi $api,
    ) {}

    /** The words, trimmed (a picture's caption for a picture); '' for none. */
    public function text(): string
    {
        return trim((string) ($this->update->text() ?? $this->update->caption()));
    }

    /** The words with Latin digits (a code, an address typed on a Persian keyboard). */
    public function latin(): string
    {
        return Persian::latinDigits($this->text());
    }

    /** A whole number typed in either script, separators allowed; null for anything else. */
    public function integer(): ?int
    {
        return Input::wholeOf(str_replace([',', '٬', '،', ' '], '', $this->latin()));
    }

    /** An amount of `$unit` as the bot's other steps read one («۲۰ گیگ», «۱۵۰٬۰۰۰ تومان»); null for anything else. */
    public function amount(Unit $unit): ?int
    {
        return Input::amountOf($this->text(), $unit);
    }

    /** A decimal typed in either script ("12.5"); null for anything else. */
    public function decimal(): ?string
    {
        return Input::decimalOf(str_replace(['٫', '/'], '.', $this->latin()));
    }

    /**
     * The numbers typed, in order (a list: "50000, 100000"); a part that is no whole number is left out.
     *
     * @return list<int>
     */
    public function numbers(): array
    {
        return array_values(array_filter(array_map(Input::wholeOf(...), Input::numbersOf($this->text())), is_int(...)));
    }

    /**
     * The picture sent, its largest size: bytes, type and a name; null for none, or one past what Telegram hands a bot.
     *
     * @return array{bytes: string, mime: string, name: string}|null
     */
    public function picture(): ?array
    {
        $id = $this->pictureId();
        $bytes = $id === null ? null : $this->api->fileBytes($id);
        if ($bytes === null) {
            return null;
        }
        $mime = match (true) {
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => 'image/jpeg',
        };

        return ['bytes' => $bytes, 'mime' => $mime, 'name' => 'picture.' . substr($mime, 6)];
    }

    /**
     * The file sent as a document (a backup, a list): bytes, type and its name; null for none, or one past what
     * Telegram hands a bot (20 MB).
     *
     * @return array{bytes: string, mime: string, name: string}|null
     */
    public function document(): ?array
    {
        $document = ($this->update->message() ?? [])['document'] ?? null;
        $bytes = is_array($document) && is_string($document['file_id'] ?? null) ? $this->api->fileBytes($document['file_id']) : null;
        if (!is_array($document) || $bytes === null) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime' => is_string($document['mime_type'] ?? null) ? $document['mime_type'] : 'application/octet-stream',
            'name' => is_string($document['file_name'] ?? null) ? $document['file_name'] : 'file',
        ];
    }

    /**
     * The message as Telegram sent it (its entities: premium emoji in a text).
     *
     * @return array<string, mixed>
     */
    public function message(): array
    {
        return $this->update->message() ?? [];
    }

    private function pictureId(): ?string
    {
        $message = $this->update->message() ?? [];
        $photos = $message['photo'] ?? null;
        if (is_array($photos) && $photos !== []) {
            $largest = end($photos);

            return is_array($largest) && is_string($largest['file_id'] ?? null) ? $largest['file_id'] : null;
        }
        $document = $message['document'] ?? null;

        return is_array($document) && str_starts_with((string) ($document['mime_type'] ?? ''), 'image/') && is_string($document['file_id'] ?? null) ? $document['file_id'] : null;
    }
}
