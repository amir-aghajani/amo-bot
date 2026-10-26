<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Support\Requirements;

/**
 * The one place a picture is handled (receipts, tickets' pictures, the QR background): its type by its own bytes, its
 * size off the header before anything is decoded, whether it fits in PHP's free memory, then — with GD — decoding,
 * turning upright, shrinking and writing it again. A damaged picture is an answer (null), never a PHP warning.
 */
final class Picture
{
    /** The type of bytes that are no known picture: a file to save, never to show. */
    public const UNKNOWN = 'application/octet-stream';

    /**
     * The formats a picture is taken in, as a Persian sentence names them (a refusal's): each Latin name in Unicode's
     * isolates (U+2068 … U+2069), so right to left reads them in this order with «،» after the first — bare, the
     * names and the comma between them make one left-to-right run that reads «PNG ،JPG».
     */
    public const FORMATS = "\u{2068}JPG\u{2069}، \u{2068}PNG\u{2069} یا \u{2068}WebP\u{2069}";

    /** The most pixels decoded when PHP's memory has no limit; with one, the free memory decides. */
    private const MAX_PIXELS = 50_000_000;

    /** The bytes' type by their own content, whatever their sender says. */
    public static function typeOf(string $bytes): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: self::UNKNOWN;
    }

    /**
     * Width and height off the header, nothing decoded; null for bytes that are no picture.
     *
     * @return array{int, int}|null
     */
    public static function size(string $bytes): ?array
    {
        $size = $bytes === '' ? false : self::quietly(static fn(): array|false => getimagesizefromstring($bytes));

        return $size === false ? null : self::dimensions($size);
    }

    /**
     * A picture file's width, height and type, off its header; null for a file that is not there, or is no picture.
     *
     * @return array{int, int, string}|null
     */
    public static function sizeOfFile(string $path): ?array
    {
        $size = is_file($path) ? self::quietly(static fn(): array|false => getimagesize($path)) : false;
        $dimensions = $size === false ? null : self::dimensions($size);

        return $dimensions === null || $size === false ? null : [...$dimensions, (string) $size['mime']];
    }

    /**
     * Whether a `$width` × `$height` picture (`$length` bytes) can be decoded and fitted to `$side` in PHP's free memory:
     * four bytes a pixel for every copy alive at once, plus a margin. With no memory limit, MAX_PIXELS.
     */
    public static function decodable(int $width, int $height, int $side, int $length): bool
    {
        $limit = Requirements::memoryLimit();
        if ($limit < 0) {
            return $width * $height <= self::MAX_PIXELS;
        }

        [$toWidth, $toHeight] = self::fit($width, $height, $side);
        $pixels = $toWidth < $width || $toHeight < $height ? $width * $height + $toWidth * $height + $toWidth * $toHeight : 2 * $width * $height;

        return memory_get_usage() + (int) ($pixels * 4 * 1.25) + 2 * $length + 4 * 1048576 < $limit;
    }

    /** Decoded by GD; null without GD, or for bytes it cannot read. */
    public static function decode(string $bytes): ?\GdImage
    {
        if ($bytes === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = self::quietly(static fn(): \GdImage|false => imagecreatefromstring($bytes));

        return $image === false ? null : $image;
    }

    /**
     * The image no larger than `$side` on its longest side, proportions and transparency kept; the same image when it
     * fits; null when GD failed. Uses imagecopyresampled(), never imagescale(): libgd 2.3.3's bicubic scaler (Linux PHP
     * packages) reads past the image and kills the process (2050 × 2 is enough).
     */
    public static function fitted(\GdImage $image, int $side): ?\GdImage
    {
        [$toWidth, $toHeight] = self::fit(imagesx($image), imagesy($image), $side);

        return $toWidth === imagesx($image) && $toHeight === imagesy($image) ? $image : self::resized($image, $toWidth, $toHeight);
    }

    /**
     * The image drawn anew at `$toWidth` × `$toHeight`, larger or smaller, its transparency kept; null when GD failed.
     * By imagecopyresampled(), as fitted() says.
     */
    public static function resized(\GdImage $image, int $toWidth, int $toHeight): ?\GdImage
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $scaled = imagecreatetruecolor($toWidth, $toHeight);
        // Its transparency written as it is, never blended onto the black a new image starts as.
        $drawn = $scaled !== false
            && imagealphablending($scaled, false)
            && imagesavealpha($scaled, true)
            && imagecopyresampled($scaled, $image, 0, 0, 0, 0, $toWidth, $toHeight, $width, $height);

        return $drawn ? $scaled : null;
    }

    /** A JPEG's image turned as its EXIF Orientation says (read from the bytes, no exif extension). */
    public static function upright(\GdImage $image, string $jpeg): \GdImage
    {
        $turn = static fn(\GdImage $image, int $angle): \GdImage => imagerotate($image, $angle, 0) ?: $image;
        $flip = static function (\GdImage $image, int $mode): \GdImage {
            imageflip($image, $mode);

            return $image;
        };

        return match (self::orientation($jpeg)) {
            2 => $flip($image, IMG_FLIP_HORIZONTAL),
            3 => $turn($image, 180),
            4 => $flip($image, IMG_FLIP_VERTICAL),
            5 => $flip($turn($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $turn($image, -90),
            7 => $flip($turn($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $turn($image, 90),
            default => $image,
        };
    }

    /**
     * The image encoded as `$type` (PNG and WebP keep transparency; anything else is a JPEG; lossy at `$quality`); null
     * when GD cannot (no WebP support).
     */
    public static function encoded(\GdImage $image, string $type, int $quality): ?string
    {
        if ($type === 'image/webp' && !function_exists('imagewebp')) {
            return null;
        }

        ob_start();
        $written = match ($type) {
            'image/png' => imagesavealpha($image, true) && imagepng($image),
            'image/webp' => imagesavealpha($image, true) && imagewebp($image, null, $quality),
            default => imagejpeg($image, null, $quality),
        };
        $output = (string) ob_get_clean();

        return $written && $output !== '' ? $output : null;
    }

    /**
     * The size that fits `$side` on the longest side, proportions kept; its own when it already fits.
     *
     * @return array{int, int}
     */
    private static function fit(int $width, int $height, int $side): array
    {
        $scale = $side / max($width, $height, 1);

        return $scale < 1.0 ? [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))] : [$width, $height];
    }

    /** EXIF Orientation (1–8) from the JPEG's first APP1 segment; 1 for none. */
    private static function orientation(string $jpeg): int
    {
        $at = 2;
        $length = strlen($jpeg);
        while ($at + 4 <= $length && $jpeg[$at] === "\xFF") {
            $marker = ord($jpeg[$at + 1]);
            $size = (ord($jpeg[$at + 2]) << 8) | ord($jpeg[$at + 3]);
            if ($marker === 0xDA || $size < 2) {
                break;
            }
            if ($marker === 0xE1 && substr($jpeg, $at + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($jpeg, $at + 10, $size - 8));
            }
            $at += 2 + $size;
        }

        return 1;
    }

    /** The Orientation tag (0x0112) of a TIFF header's first IFD; 1 for none. */
    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $little = substr($tiff, 0, 2) === 'II';
        $short = static fn(int $at): int => strlen($tiff) < $at + 2 ? 0 : (int) unpack($little ? 'v' : 'n', $tiff, $at)[1];
        $long = static fn(int $at): int => strlen($tiff) < $at + 4 ? 0 : (int) unpack($little ? 'V' : 'N', $tiff, $at)[1];

        $ifd = $long(4);
        $entries = $short($ifd);
        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + 12 * $i;
            if ($short($entry) === 0x0112) {
                $value = $short($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * getimagesize()'s width and height; null for none.
     *
     * @param array<int|string, mixed> $size
     * @return array{int, int}|null
     */
    private static function dimensions(array $size): ?array
    {
        [$width, $height] = [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)];

        return $width < 1 || $height < 1 ? null : [$width, $height];
    }

    /**
     * Run `$call` with its warnings silenced: getimagesize() and GD warn about a damaged picture before returning false.
     *
     * @template T
     * @param \Closure(): T $call
     * @param-immediately-invoked-callable $call
     * @return T
     */
    private static function quietly(\Closure $call): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
