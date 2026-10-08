<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Qr;

use App\Core\Application;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Files;
use App\Core\Support\Picture;
use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\Services\Settings;
use App\Support\Persian;
use App\Support\Validation;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The picture a QR code is drawn on. The shop ships one (resources/assets/qr-background.jpg — a dark nebula with a
 * white rounded square in the middle for the code); the admin may upload their own from the bot settings screen, which
 * lands in the uploads folder (the container's `qr.backgrounds`) and is remembered in the settings table, or go back to
 * the shipped one — each bot its own (an agent's file carries its bot's id). An upload is judged by its content, read
 * from its header before anything is decoded, and decoded once here — so the picture a delivery draws on is one GD reads.
 */
final class QrBackground
{
    /** Settings key: the custom file's name in the uploads folder; absent = the shipped default. */
    public const KEY = 'bot.qr_background';

    public const DEFAULT = 'resources/assets/qr-background.jpg';
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * The most pixels an upload may have. A picture is decoded whole to draw on (about 4 bytes a pixel), and a small
     * file can hold a huge one: past this it would not fit a shared host's memory and would end the process that
     * delivers services.
     */
    public const MAX_PIXELS = 16_000_000;

    /** The longest side kept: a QR card needs no more, and a smaller picture is drawn faster at every delivery. */
    public const MAX_SIDE = 2048;

    /** Accepted uploads, by their sniffed type — what GD can read on a shared host. */
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** The quality a background made smaller is written again with (a JPEG, a WebP). */
    private const QUALITY = 90;

    /** @param string $dir Where uploads go — the container's `qr.backgrounds` (storage/uploads/qr) */
    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
        private readonly string $dir,
    ) {}

    /**
     * The background in use — the bot's upload, or the shipped picture — as its header describes it.
     *
     * @return array{path: string, custom: bool, mime: string, width: int, height: int}
     */
    public function image(): array
    {
        $custom = $this->customPath();
        $path = $custom ?? $this->app->basePath(self::DEFAULT);
        // A file that is no picture (a custom one damaged on disk) is described as none.
        [$width, $height, $mime] = Picture::sizeOfFile($path) ?? [0, 0, Picture::UNKNOWN];

        return ['path' => $path, 'custom' => $custom !== null, 'mime' => $mime, 'width' => $width, 'height' => $height];
    }

    /**
     * The background as the screen shows it, with the largest upload taken (MAX_BYTES) for the screen to say.
     *
     * @return array{custom: bool, mime: string, width: int, height: int, size: int, updated_at: int, max_bytes: int}
     */
    public function describe(): array
    {
        $image = $this->image();
        $exists = is_file($image['path']);

        return [
            'custom' => $image['custom'],
            'mime' => $image['mime'],
            'width' => $image['width'],
            'height' => $image['height'],
            'size' => $exists ? (int) filesize($image['path']) : 0,
            'updated_at' => $exists ? (int) filemtime($image['path']) : 0,
            'max_bytes' => self::MAX_BYTES,
        ];
    }

    /**
     * Take the admin's upload as the background: an image (by content, not by name) of a sane size, made no larger
     * than a card needs.
     *
     * @throws ValidationException
     */
    public function store(UploadedFileInterface $file): void
    {
        $failure = Validation::uploadFailure($file);
        if ($failure !== null) {
            throw ValidationException::on('file', $failure);
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            throw ValidationException::on('file', 'حجم تصویر حداکثر ' . Persian::number(intdiv(self::MAX_BYTES, 1024 * 1024)) . ' مگابایت می‌تواند باشد.');
        }

        $bytes = (string) $file->getStream();
        $mime = Picture::typeOf($bytes);
        $extension = self::TYPES[$mime] ?? null;
        $size = Picture::size($bytes);
        if ($extension === null || $size === null) {
            throw ValidationException::on('file', 'فقط تصویر PNG، JPG یا WebP پذیرفته می‌شود.');
        }
        // Read from the header, before anything is decoded.
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::on('file', 'ابعاد تصویر بیش از حد بزرگ است؛ حداکثر ۱۶ مگاپیکسل (مثلا ۴۰۰۰ در ۴۰۰۰ پیکسل) پذیرفته می‌شود.');
        }
        $bytes = self::fitted($bytes, $mime, $size[0], $size[1]);

        // Written whole, then remembered, then the one it replaces gone: a failure on the way leaves the background as
        // it was, and a delivery never draws on half a picture.
        $previous = $this->customPath();
        $name = (CurrentBot::isMain() ? 'background.' : 'background-' . CurrentBot::id() . '.') . $extension;
        Files::writeAtomically($this->dir . '/' . $name, $bytes);
        $this->settings->set(self::KEY, $name);
        if ($previous !== null && $previous !== $this->dir . '/' . $name) {
            Files::delete($previous);
        }
    }

    /** Back to the shipped background. */
    public function reset(): void
    {
        $previous = $this->customPath();
        $this->settings->forget(self::KEY);
        if ($previous !== null) {
            Files::delete($previous);
        }
    }

    /**
     * The upload as it is kept: decoded once — one GD cannot read is refused here, never met at a delivery — and, larger
     * than MAX_SIDE, made smaller in its own type rather than decoded at full size at every delivery. Without GD (no QR
     * card is drawn then) it stays as it came.
     *
     * @throws ValidationException
     */
    private static function fitted(string $bytes, string $mime, int $width, int $height): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $image = Picture::decode($bytes) ?? throw ValidationException::on('file', 'این تصویر خوانده نشد؛ تصویر دیگری بفرستید.');
        if (max($width, $height) <= self::MAX_SIDE) {
            imagedestroy($image);

            return $bytes;
        }

        $scaled = Picture::fitted($image, self::MAX_SIDE);
        if ($scaled === null) {
            return $bytes;
        }
        $written = Picture::encoded($scaled, $mime, self::QUALITY);
        imagedestroy($scaled);

        return $written ?? $bytes;
    }

    private function customPath(): ?string
    {
        $name = $this->settings->get(self::KEY);
        if (!is_string($name) || $name === '') {
            return null;
        }

        $path = $this->dir . '/' . basename($name);

        return is_file($path) ? $path : null;
    }
}
