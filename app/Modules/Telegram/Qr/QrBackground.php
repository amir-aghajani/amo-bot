<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Qr;

use App\Core\Application;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Files;
use App\Core\Support\Picture;
use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Api\Limits;
use App\Support\Persian;
use App\Support\Validation;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The picture a QR card is drawn on: the shipped one (resources/assets/qr-background.jpg), or the admin's upload, per
 * bot. An upload is judged by its header before anything is decoded, then decoded once here, so every delivery draws
 * on a picture GD can read.
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
     * Take the admin's upload as the background: a picture by its content, within the size limits, scaled down to what a
     * card needs — and of a shape a card can be sent in (Limits::isPhotoShape()), else no delivery would ever draw on it.
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
            throw ValidationException::on('file', 'تصویر باید ' . Picture::FORMATS . ' باشد.');
        }
        // Read from the header, before anything is decoded.
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::on('file', 'ابعاد تصویر بیش از حد بزرگ است؛ حداکثر ۱۶ مگاپیکسل (مثلا ۴۰۰۰ در ۴۰۰۰ پیکسل) پذیرفته می‌شود.');
        }
        $bytes = self::fitted($bytes, $mime, $size[0], $size[1]);
        // As kept: a card keeps the picture's proportions, and Telegram takes no photo of a banner's.
        [$width, $height] = Picture::size($bytes) ?? $size;
        if (!Limits::isPhotoShape($width, $height)) {
            throw ValidationException::on('file', 'این تصویر برای کارت QR بیش از حد کشیده است؛ ضلع بلند آن حداکثر ' . Persian::number(Limits::PHOTO_RATIO) . ' برابر ضلع کوتاهش می‌تواند باشد.');
        }

        // Written whole, then remembered, then the old one deleted: a failure on the way leaves the background as it was.
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
     * The upload as kept: decoded once (one GD cannot read is refused here, not at a delivery) and scaled down past
     * MAX_SIDE. Without GD (no card is drawn then) it stays as it came.
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
