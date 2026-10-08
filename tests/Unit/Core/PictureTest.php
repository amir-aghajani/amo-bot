<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\Picture;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * A picture read and written one way: its type by its bytes, its size off its header, decoded, made no larger than a
 * side in proportion, written again in its type — and a damaged one an answer, never a warning (the suite fails on any:
 * getimagesize() and GD complain about a damaged picture before they give up).
 */
#[RequiresPhpExtension('gd')]
final class PictureTest extends TestCase
{
    /**
     * A JPEG's first bytes — its start and the head of its JFIF segment — and nothing after: every libmagic calls it a
     * JPEG (a bare start is text to some), and getimagesize() and GD give up on it, GD with a warning.
     */
    private const DAMAGED_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00";

    public function testAPictureIsWhatItsBytesSayAndItsSizeIsReadOffItsHeader(): void
    {
        $png = self::png(30, 20);

        self::assertSame('image/png', Picture::typeOf($png));
        self::assertStringStartsNotWith('image/', Picture::typeOf('not a picture'), 'whatever its name says');
        self::assertSame([30, 20], Picture::size($png));
        self::assertNull(Picture::size(''));
        self::assertNull(Picture::size('not a picture'));

        $file = $this->scratchDir() . '/background.png';
        file_put_contents($file, $png);
        self::assertSame([30, 20, 'image/png'], Picture::sizeOfFile($file));
        self::assertNull(Picture::sizeOfFile($this->scratchDir() . '/gone.png'));
    }

    public function testADamagedPictureIsAnAnswerNeverAWarning(): void
    {
        self::assertSame('image/jpeg', Picture::typeOf(self::DAMAGED_JPEG), 'a JPEG by its first bytes');
        self::assertNull(Picture::size(self::DAMAGED_JPEG));
        self::assertNull(Picture::decode(self::DAMAGED_JPEG));
        self::assertNull(Picture::decode(substr(self::png(30, 20), 0, 40)), 'a PNG cut short');

        $file = $this->scratchDir() . '/background.jpg';
        file_put_contents($file, self::DAMAGED_JPEG);
        self::assertNull(Picture::sizeOfFile($file));
    }

    public function testAPictureIsMadeNoLargerThanASideInProportionAndWrittenAgainInItsType(): void
    {
        $image = Picture::decode(self::png(3000, 30)) ?? self::fail('a picture GD reads');
        $fitted = Picture::fitted($image, 2560) ?? self::fail('made smaller');
        self::assertSame([2560, 26], [imagesx($fitted), imagesy($fitted)], '30 × 2560 / 3000, to the nearest');

        $small = Picture::decode(self::png(40, 20)) ?? self::fail('a picture GD reads');
        self::assertSame($small, Picture::fitted($small, 2560), 'one that fits is kept as it is');

        $written = Picture::encoded($fitted, 'image/png', 85) ?? self::fail('written');
        self::assertSame(['image/png', [2560, 26]], [Picture::typeOf($written), Picture::size($written)]);
        self::assertSame('image/jpeg', Picture::typeOf((string) Picture::encoded($small, 'image/jpeg', 85)));
    }

    public function testAThinPictureAndATransparentOneAreMadeSmallerWhole(): void
    {
        // Two pixels tall: libgd 2.3.3's bicubic imagescale() ends the process on it (Linux's PHP 8.2 and 8.3 link it).
        $strip = Picture::fitted(Picture::decode(self::png(2050, 2)) ?? self::fail('a picture GD reads'), 2048) ?? self::fail('made smaller');
        self::assertSame([2048, 2], [imagesx($strip), imagesy($strip)]);

        $line = Picture::fitted(Picture::decode(self::png(1, 5000)) ?? self::fail('a picture GD reads'), 2560) ?? self::fail('made smaller');
        self::assertSame([1, 2560], [imagesx($line), imagesy($line)], 'one pixel wide stays one pixel wide');

        $clear = imagecreatetruecolor(100, 100);
        imagealphablending($clear, false);
        imagefill($clear, 0, 0, imagecolorallocatealpha($clear, 0, 0, 0, 127) ?: self::fail('a transparent colour'));
        $fitted = Picture::fitted($clear, 50) ?? self::fail('made smaller');
        self::assertSame(127, ((int) imagecolorat($fitted, 25, 25)) >> 24, 'its transparency kept, never drawn onto black');
    }

    public function testAPictureTooLargeForWhatMemoryIsLeftIsNotDecoded(): void
    {
        self::assertTrue(Picture::decodable(40, 20, 2560, 1000));
        self::assertFalse(Picture::decodable(200_000, 200_000, 2560, 1000), 'forty gigapixels: no host has the memory');
    }

    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
