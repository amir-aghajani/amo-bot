<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Support\Requirements;

/**
 * A picture the shop is handed — a customer's receipt, a ticket's picture, the QR card's background, a premium emoji's
 * still —, read and written one way: what it is by its own bytes (typeOf()), how big by its header before anything is
 * decoded (size(), sizeOfFile()), whether it may be decoded in the memory PHP has left (decodable()), decoded (decode()),
 * turned as its camera held it (upright()), made no larger than a side (fitted()) and written again in its type
 * (encoded()) — with GD, where the host has it. A damaged picture is an answer here (null), never a PHP warning:
 * getimagesize() and GD complain about one before they give up, and those complaints are theirs alone (quietly()).
 */
final class Picture
{
    /** What bytes are when they are no picture this knows: a file to save, never one to show. */
    public const UNKNOWN = 'application/octet-stream';

    /** The most pixels a picture is decoded with when PHP's memory has no limit; with one, what it leaves free decides. */
    private const MAX_PIXELS = 50_000_000;

    /** The type the bytes are, by their own content — whatever their sender says they are. */
    public static function typeOf(string $bytes): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: self::UNKNOWN;
    }

    /**
     * Its width and height, off its header — nothing decoded —; null for bytes that are no picture.
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
     * Whether a picture of `$width` by `$height` — `$length` bytes of it — may be decoded and made no larger than `$side`
     * (fitted()) in what PHP's memory has left: four bytes a pixel at its fullest — the picture beside GD's first pass and
     * the one made smaller, or one kept at its size beside itself turned — and a margin, beside its bytes and what is
     * written. With no limit, MAX_PIXELS.
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
     * The image no larger than `$side` on its longest side, its proportions kept, drawn again on a truecolor one with its
     * transparency; the same image when it fits. Made smaller, the one given is let go of — and null when GD could not
     * do it. By imagecopyresampled(), never imagescale(): the bicubic scaler of the libgd Linux's PHP packages link
     * (2.3.3) reads a row that is not there and ends the process — a picture 2050 by 2 is enough.
     */
    public static function fitted(\GdImage $image, int $side): ?\GdImage
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        [$toWidth, $toHeight] = self::fit($width, $height, $side);
        if ($toWidth === $width && $toHeight === $height) {
            return $image;
        }

        $scaled = imagecreatetruecolor($toWidth, $toHeight);
        // Its transparency written as it is, never blended onto the black a new image starts as.
        $drawn = $scaled !== false
            && imagealphablending($scaled, false)
            && imagesavealpha($scaled, true)
            && imagecopyresampled($scaled, $image, 0, 0, 0, 0, $toWidth, $toHeight, $width, $height);
        imagedestroy($image);

        return $drawn ? $scaled : null;
    }

    /**
     * A JPEG's image turned and flipped the way its camera says it was held (EXIF's Orientation, read from `$jpeg`'s own
     * bytes — no exif extension): the way it was seen. The one given is let go of when it is turned.
     */
    public static function upright(\GdImage $image, string $jpeg): \GdImage
    {
        $turn = static function (\GdImage $image, int $angle): \GdImage {
            $turned = imagerotate($image, $angle, 0);
            if ($turned === false) {
                return $image;
            }
            imagedestroy($image);

            return $turned;
        };
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
     * The image written as `$type` — image/png or image/webp, either keeping its transparency, else a JPEG; the two lossy
     * ones at `$quality` —; null when GD cannot write it (a host's GD without WebP).
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
     * The size a picture is made no larger than `$side` on its longest side with, its proportions kept; its own when it
     * fits.
     *
     * @return array{int, int}
     */
    private static function fit(int $width, int $height, int $side): array
    {
        $scale = $side / max($width, $height, 1);

        return $scale < 1.0 ? [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))] : [$width, $height];
    }

    /**
     * How the camera says a JPEG is held — EXIF's Orientation, 1 to 8 —, read from its first APP1 segment; 1 (as it is
     * stored) for none.
     */
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
     * What getimagesize() answered as a width and a height; null for none.
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
     * `$call` with what it complains of on the way left out: getimagesize() and GD warn about a damaged picture before
     * they give up — their false is the answer, and the warning nobody's.
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
