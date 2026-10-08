<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Security\RateLimiter;
use App\Core\Support\FileCache;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Users\Enums\PictureFolder;
use App\Modules\Users\Exceptions\StorageFullException;
use App\Modules\Users\Services\CustomerPictures;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * A picture the shop keeps — a receipt, a ticket's — as GD writes it again: no larger than CustomerPictures::MAX_SIDE,
 * turned the way its camera said it was held (a phone's photo kept upright), nothing of the sender's file but its pixels;
 * one GD cannot read kept as it came. A shop's uploads take no more than their day; a picture Telegram keeps is fetched
 * once for the reads that follow.
 */
#[RequiresPhpExtension('gd')]
final class CustomerPicturesTest extends TestCase
{
    public function testALargePictureIsKeptNoLargerThanItsSideInItsOwnType(): void
    {
        $kept = $this->kept(self::png(3000, 30), 'png');

        self::assertSame([CustomerPictures::MAX_SIDE, 26, 'image/png'], self::imageOf($kept));
    }

    public function testAPhonesPhotoIsKeptUprightAsItsCameraHeldIt(): void
    {
        // A JPEG stored 40 wide and 20 high, its EXIF saying the camera was turned: it is seen 20 wide and 40 high.
        foreach (['II' => 'little-endian', 'MM' => 'big-endian'] as $order => $what) {
            self::assertSame([20, 40, 'image/jpeg'], self::imageOf($this->kept(self::rotated(self::jpeg(40, 20), $order), 'jpg')), $what);
        }
        self::assertSame([40, 20, 'image/jpeg'], self::imageOf($this->kept(self::jpeg(40, 20), 'jpg')), 'one without EXIF as it is');
    }

    public function testWhatIsKeptIsGdsOwnWritingNotTheSendersFile(): void
    {
        // What a sender appended to a picture — a page, a script — goes: the picture is written again from its pixels.
        $sent = self::png(10, 10) . '<script>alert(1)</script>';

        $kept = $this->kept($sent, 'png');

        self::assertSame([10, 10, 'image/png'], self::imageOf($kept));
        self::assertStringNotContainsString('<script>', $kept);
    }

    public function testOneGdCannotReadIsKeptAsItCame(): void
    {
        $broken = substr(self::png(10, 10), 0, 40);

        self::assertSame($broken, $this->kept($broken, 'png'));
    }

    public function testAShopsDayOfUploadsIsSpentOnceItsMegabytesAreTakenAndItsOwnerHearsItOnce(): void
    {
        $logs = $this->logs();
        $limiter = $this->service(RateLimiter::class);
        $pictures = $this->pictures(limiter: $limiter);
        // The shop's uploads took all of their day but a megabyte.
        $limiter->attempt(array_fill(0, CustomerPictures::DAY_MEGABYTES - 1, ['pictures|day|' . Bot::MAIN, CustomerPictures::DAY_MEGABYTES, 86400]));

        $kept = $pictures->keep(PictureFolder::Receipts, '1-1', self::png(10, 10), 'png');
        self::assertNotNull($pictures->read(PictureFolder::Receipts, $kept), 'its last megabyte');

        foreach ([static fn() => $pictures->keep(PictureFolder::Receipts, '1-2', self::png(10, 10), 'png'), fn() => $pictures->judge($this->upload(self::png(10, 10)), 'تصویر رسید')] as $refused) {
            try {
                $refused();
                self::fail('a picture past the shop\'s day was taken');
            } catch (StorageFullException $e) {
                self::assertSame([503, 'امروز بیش از این تصویری پذیرفته نمی‌شود؛ چند ساعت دیگر دوباره بفرستید.'], [$e->status(), $e->getMessage()]);
            }
        }
        self::assertCount(1, glob($this->app()->container()->get('receipts.path') . '/*') ?: [], 'nothing more kept');
        self::assertCount(1, array_filter($logs->getRecords(), static fn(LogRecord $record): bool => str_contains($record->message, 'for the rest of the day')), 'its owner hears it once');

        Carbon::setTestNow(now()->addDay());
        self::assertNotNull($pictures->read(PictureFolder::Receipts, $pictures->keep(PictureFolder::Receipts, '1-3', self::png(10, 10), 'png')), 'a new day');
    }

    public function testAPictureFetchedFromTelegramIsKeptAWhileForTheReadsThatFollow(): void
    {
        $pictures = $this->pictures(fetched: new FileCache($this->scratchDir()));
        $jpeg = self::jpeg(4, 4);
        $this->telegram()->reply(['file_id' => 'photo-1', 'file_path' => 'photos/file_1.jpg']);
        $this->telegram()->raw(new Response(200, ['Content-Type' => 'image/jpeg'], $jpeg));

        self::assertSame($jpeg, $pictures->fromTelegram('photo-1'));
        self::assertSame($jpeg, $pictures->fromTelegram('photo-1'), 'a screen that reads it again');
        self::assertCount(2, $this->telegram()->history, 'Telegram asked once: its file, and its bytes');
    }

    /** The shop's rule for pictures as the container builds it, with what counts its day and keeps what it fetched. */
    private function pictures(?RateLimiter $limiter = null, ?FileCache $fetched = null): CustomerPictures
    {
        $container = $this->app()->container();
        $folders = [PictureFolder::Receipts->value => $container->get('receipts.path'), PictureFolder::Tickets->value => $container->get('tickets.path')];

        return new CustomerPictures($this->service(BotApi::class), $folders, $this->service(LoggerInterface::class), CustomerPictures::ROOM, $limiter ?? $this->service(RateLimiter::class), $fetched ?? $this->service(FileCache::class));
    }

    private function upload(string $bytes): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, 'receipt.png', 'image/png');
    }

    private function kept(string $bytes, string $extension): string
    {
        $pictures = $this->service(CustomerPictures::class);

        return (string) $pictures->read(PictureFolder::Tickets, $pictures->keep(PictureFolder::Tickets, '1-1', $bytes, $extension));
    }

    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height) ?: throw new \RuntimeException('GD made no picture.');
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height) ?: throw new \RuntimeException('GD made no picture.');
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** The JPEG with an EXIF segment saying its camera was turned a quarter clockwise (Orientation 6), in that byte order. */
    private static function rotated(string $jpeg, string $order): string
    {
        $little = $order === 'II';
        $short = static fn(int $value): string => pack($little ? 'v' : 'n', $value);
        $long = static fn(int $value): string => pack($little ? 'V' : 'N', $value);
        $tiff = $order . $short(42) . $long(8) . $short(1) . $short(0x0112) . $short(3) . $long(1) . $short(6) . $short(0) . $long(0);
        $segment = "Exif\0\0" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($segment) + 2) . $segment . substr($jpeg, 2);
    }
}
