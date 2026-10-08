<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Qr\QrBackground;
use App\Modules\Telegram\Qr\QrCard;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\DatabaseTestCase;

/**
 * The QR card: the link drawn on the background as a code a phone reads — or nothing (the text goes alone) when the
 * background is no picture GD reads, or one too large to decode.
 */
#[RequiresPhpExtension('gd')]
final class QrCardTest extends DatabaseTestCase
{
    public function testTheLinkIsDrawnOnTheShippedBackgroundAsAJpegOfTheSameSize(): void
    {
        $bytes = $this->service(QrCard::class)->render('https://example.com/sub/abcdef');

        self::assertNotNull($bytes);
        $size = getimagesizefromstring($bytes);
        self::assertNotFalse($size);
        self::assertSame(['image/jpeg', 1024, 1024], [$size['mime'], $size[0], $size[1]]);

        // A white plate with dark modules in the middle: the centre pixel of a finder pattern's ring is dark,
        // the plate just inside its edge is white.
        $image = imagecreatefromstring($bytes);
        self::assertNotFalse($image);
        $plate = (int) round(1024 * 0.57);
        $left = intdiv(1024 - $plate, 2);
        $edge = imagecolorsforindex($image, imagecolorat($image, $left + 6, 512));
        self::assertGreaterThan(240, min($edge['red'], $edge['green'], $edge['blue']), 'the plate is white');
        $dark = 0;
        for ($x = $left; $x < $left + $plate; $x += 3) {
            $c = imagecolorsforindex($image, imagecolorat($image, $x, 512));
            $dark += $c['red'] < 60 ? 1 : 0;
        }
        self::assertGreaterThan(20, $dark, 'and carries the code');
    }

    public function testABackgroundGdCannotReadGivesNoCardAndAWarning(): void
    {
        $this->background('not a picture');
        $logs = $this->logs();

        self::assertNull($this->service(QrCard::class)->render('https://example.com/sub/abcdef'), 'the text goes alone rather than no link at all');
        self::assertTrue($logs->hasWarningThatContains('could not be read'));
    }

    public function testABackgroundOverThePixelLimitIsNeverDecoded(): void
    {
        // A picture put on disk by hand (an upload is held to the limit): its header says one pixel more than the limit.
        $this->background(self::pngHeader(intdiv(QrBackground::MAX_PIXELS, 4000) + 1, 4000));
        $logs = $this->logs();

        self::assertNull($this->service(QrCard::class)->render('https://example.com/sub/abcdef'), 'the text goes alone');
        self::assertTrue($logs->hasWarningThatContains('too large'), 'judged by its header, before GD is asked to read it');
        self::assertFalse($logs->hasWarningThatContains('could not be read'));
    }

    /** The bot's own background, as the uploads folder keeps one: these bytes. */
    private function background(string $bytes): void
    {
        $dir = (string) $this->app()->container()->get('qr.backgrounds');
        mkdir($dir);
        file_put_contents("{$dir}/background.png", $bytes);
        $this->service(Settings::class)->set(QrBackground::KEY, 'background.png');
    }

    /** The start of a PNG that says it is `$width` × `$height` — all a header-reader needs, nothing a decoder can draw. */
    private static function pngHeader(int $width, int $height): string
    {
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NN', $width, $height) . "\x08\x02\x00\x00\x00") . $chunk('IEND', '');
    }
}
