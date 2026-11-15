<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Door;

/** A file an API route answered (a receipt, a backup, an attachment): its bytes, its type and the name to send it under. */
final class ApiFile
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly string $filename,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
