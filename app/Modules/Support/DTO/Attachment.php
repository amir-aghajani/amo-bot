<?php

declare(strict_types=1);

namespace App\Modules\Support\DTO;

/**
 * A ticket message's picture before it is kept: an upload judged by its bytes (kept in the tickets' folder), or a
 * Telegram file id (Telegram keeps it). Its name is the sender's device's, or one made up.
 */
final readonly class Attachment
{
    private function __construct(
        public ?string $bytes,
        public ?string $extension,
        public ?string $fileId,
        public string $name,
    ) {}

    /** An upload, judged: its bytes, the extension they are kept under, and its name (the device's, else one made up). */
    public static function upload(string $bytes, string $extension, ?string $name): self
    {
        return new self($bytes, $extension, null, $name ?? 'picture.' . $extension);
    }

    /**
     * A picture sent in Telegram, by its file id: a photo (Telegram's own JPEG), or a picture sent as a file under the
     * name its sender's device gave it.
     */
    public static function telegram(string $fileId, ?string $name = null): self
    {
        return new self(null, null, $fileId, $name ?? 'photo.jpg');
    }
}
