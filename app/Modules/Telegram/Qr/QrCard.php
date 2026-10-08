<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Qr;

use App\Core\Support\Picture;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Psr\Log\LoggerInterface;

/**
 * The picture a delivered service comes with: the subscription link as a QR code, drawn on the
 * background (QrBackground) — a white plate with the code and its quiet zone, centred, sized to the
 * picture, so any background works and the shipped one's own white square is covered exactly.
 * Needs GD; without it (or with a background GD cannot read) `render()` gives null and the customer
 * gets the text alone — a missing picture must never cost them the link.
 */
final class QrCard
{
    /** The white plate — the code plus its quiet zone — as a share of the picture's shorter side. */
    private const PLATE = 0.57;

    /** Modules of white around the code on each side (the QR standard asks for four). */
    private const QUIET = 4;

    /** Corner radius of the plate as a share of its side. */
    private const CORNER = 0.075;

    private const JPEG_QUALITY = 90;

    public function __construct(
        private readonly QrBackground $background,
        private readonly LoggerInterface $logger,
    ) {}

    /** JPEG bytes of the code on the background, or null when it cannot be drawn. */
    public function render(string $data): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->logger->warning('QR code skipped: the GD extension is not available.');

            return null;
        }

        $image = $this->canvas();
        if ($image === null) {
            return null;
        }

        try {
            $matrix = (new QRCode(new QROptions(['eccLevel' => EccLevel::M, 'addQuietzone' => false])))->addByteSegment($data)->getQRMatrix();
            $modules = $matrix->getMatrix(true);
            $count = $matrix->getSize();

            $width = imagesx($image);
            $height = imagesy($image);
            $module = intdiv((int) round(min($width, $height) * self::PLATE), $count + 2 * self::QUIET);
            $plate = $module * ($count + 2 * self::QUIET);
            $left = intdiv($width - $plate, 2);
            $top = intdiv($height - $plate, 2);

            $this->roundedSquare($image, $left, $top, $plate, (int) round($plate * self::CORNER), (int) imagecolorallocate($image, 255, 255, 255));

            $dark = (int) imagecolorallocate($image, 0, 0, 0);
            $origin = self::QUIET * $module;
            foreach ($modules as $y => $row) {
                foreach ($row as $x => $isDark) {
                    if ($isDark) {
                        $px = $left + $origin + $x * $module;
                        $py = $top + $origin + $y * $module;
                        imagefilledrectangle($image, $px, $py, $px + $module - 1, $py + $module - 1, $dark);
                    }
                }
            }

            ob_start();
            imagejpeg($image, null, self::JPEG_QUALITY);

            return (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }
    }

    /** The background as a truecolor GD image, or null when GD cannot read it — or must not. */
    private function canvas(): ?\GdImage
    {
        $background = $this->background->image();
        // Judged by its header before anything is decoded: a picture over the limit (one put on disk by hand — an upload
        // is held to it) costs the card, never the memory of the process that delivers services.
        if ($background['width'] * $background['height'] > QrBackground::MAX_PIXELS) {
            $this->logger->warning('QR code skipped: the background {path} is {width}x{height}, too large to draw on.', ['path' => $background['path'], 'width' => $background['width'], 'height' => $background['height']]);

            return null;
        }

        $bytes = file_get_contents($background['path']);
        $image = $bytes === false ? null : Picture::decode($bytes);
        if ($image === null) {
            $this->logger->warning('QR code skipped: the background {path} ({mime}) could not be read.', ['path' => $background['path'], 'mime' => $background['mime']]);

            return null;
        }

        // A palette PNG with all 256 colours in use has no room for the plate's white and the code's
        // black (imagecolorallocate gives false); truecolor always has.
        imagepalettetotruecolor($image);

        return $image;
    }

    private function roundedSquare(\GdImage $image, int $left, int $top, int $side, int $radius, int $color): void
    {
        $right = $left + $side - 1;
        $bottom = $top + $side - 1;

        imagefilledrectangle($image, $left + $radius, $top, $right - $radius, $bottom, $color);
        imagefilledrectangle($image, $left, $top + $radius, $right, $bottom - $radius, $color);
        foreach ([[$left + $radius, $top + $radius], [$right - $radius, $top + $radius], [$left + $radius, $bottom - $radius], [$right - $radius, $bottom - $radius]] as [$cx, $cy]) {
            imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $color);
        }
    }
}
