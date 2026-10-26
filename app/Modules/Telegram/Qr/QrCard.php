<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Qr;

use App\Core\Support\Picture;
use App\Modules\Telegram\Api\Limits;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QRCodeException;
use chillerlan\QRCode\QROptions;
use Psr\Log\LoggerInterface;

/**
 * The QR card a delivered service comes with: the subscription link as a QR code on a white plate centred on the
 * background — a background too small to hold the code readably is drawn larger first. Needs GD; without it (or with a
 * background GD cannot read, data no code can hold, or a card Telegram would not take as a photo) render() gives null
 * and the text goes alone.
 */
final class QrCard
{
    /** The white plate — the code plus its quiet zone — as a share of the picture's shorter side. */
    private const PLATE = 0.57;

    /** Modules of white around the code on each side (the QR standard asks for four). */
    private const QUIET = 4;

    /** The fewest pixels a module is drawn with: smaller, and the code blurs away in the JPEG Telegram makes of the picture. */
    private const MIN_MODULE = 4;

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

        try {
            $matrix = (new QRCode(new QROptions(['eccLevel' => EccLevel::M, 'addQuietzone' => false])))->addByteSegment($data)->getQRMatrix();
        } catch (QRCodeException $e) {
            // An empty link, or one longer than a code holds: no code, never no delivery.
            $this->logger->warning('QR code skipped: the link cannot be drawn as a code ({message}).', ['message' => $e->getMessage()]);

            return null;
        }
        $modules = $matrix->getMatrix(true);
        // The plate's side, in modules: the code and its quiet zone.
        $side = $matrix->getSize() + 2 * self::QUIET;

        $image = $this->canvas($side);
        if ($image === null) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $module = intdiv((int) round(min($width, $height) * self::PLATE), $side);
        $plate = $module * $side;
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
        $jpeg = (string) ob_get_clean();
        if (strlen($jpeg) > Limits::PHOTO_BYTES) {
            $this->logger->warning('QR code skipped: the card is {bytes} bytes, more than Telegram takes as a photo.', ['bytes' => strlen($jpeg)]);

            return null;
        }

        return $jpeg;
    }

    /**
     * The background as a truecolor GD image the plate's `$side` modules fit on at MIN_MODULE pixels each (a smaller
     * picture drawn larger), or null when GD cannot read it — or must not.
     */
    private function canvas(int $side): ?\GdImage
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

        return $this->roomFor($image, $side);
    }

    /**
     * The picture as it is when its shorter side holds the plate's `$side` modules at MIN_MODULE pixels, else drawn larger
     * until it does; null when what it would be is no photo Telegram takes (Limits::isPhoto(): a sliver of a picture, a
     * banner) or past QrBackground::MAX_PIXELS.
     */
    private function roomFor(\GdImage $image, int $side): ?\GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = max(1, ceil($side * self::MIN_MODULE / self::PLATE) / min($width, $height));
        [$cardWidth, $cardHeight] = [(int) ceil($width * $scale), (int) ceil($height * $scale)];
        if (!Limits::isPhoto($cardWidth, $cardHeight) || $cardWidth * $cardHeight > QrBackground::MAX_PIXELS) {
            $this->logger->warning('QR code skipped: the background ({width}x{height}) cannot make a card Telegram takes as a photo.', ['width' => $width, 'height' => $height]);

            return null;
        }

        // Drawn larger as Picture draws every picture anew, never by imagescale() (libgd's scalers, Picture::fitted()).
        return $scale > 1 ? Picture::resized($image, $cardWidth, $cardHeight) : $image;
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
