<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Qr\QrBackground;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\DatabaseTestCase;

/**
 * The picture QR cards are drawn on, as the uploads folder keeps it: a new upload takes the old one's place whatever
 * its type — one file a bot, the one in use — each bot its own, and going back to the shipped picture leaves none; an
 * upload the server cut short or one too big is refused before it is read, and one is written whole before it is
 * remembered, so one that cannot be written leaves the background as it was. (What the picture itself must be — its
 * type, its pixels, its longest side — is the settings screen's upload, AdminBotSettingsApiTest.)
 */
#[RequiresPhpExtension('gd')]
final class QrBackgroundTest extends DatabaseTestCase
{
    public function testANewUploadReplacesTheOldOneWhateverItsType(): void
    {
        $backgrounds = $this->service(QrBackground::class);

        $backgrounds->store(self::upload(self::picture('png')));
        self::assertSame(['background.png'], $this->kept());

        $backgrounds->store(self::upload(self::picture('jpeg')));
        self::assertSame(['background.jpg'], $this->kept(), 'the picture in use, and nothing else');
        self::assertSame(['image/jpeg', true], [$backgrounds->image()['mime'], $backgrounds->image()['custom']]);

        $bot = $this->agentBot();
        CurrentBot::run($bot, static fn() => $backgrounds->store(self::upload(self::picture('png'))));
        self::assertSame(['background-' . $bot->id . '.png', 'background.jpg'], $this->kept(), 'each bot its own');

        $backgrounds->reset();
        self::assertSame(['background-' . $bot->id . '.png'], $this->kept());
        self::assertFalse($backgrounds->image()['custom'], 'the shipped one');
    }

    public function testAnUploadThatCannotBeWrittenLeavesTheBackgroundAsItWas(): void
    {
        $backgrounds = $this->service(QrBackground::class);
        $backgrounds->store(self::upload(self::picture('png')));
        // Something keeps the new picture from being written whole: here, a folder squatting its name.
        mkdir($this->dir() . '/background.jpg');

        try {
            $backgrounds->store(self::upload(self::picture('jpeg')));
            self::fail('The picture was taken.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(ValidationException::class, $e, 'a good picture: the disk said no');
        }

        self::assertSame('background.png', $this->service(Settings::class)->get(QrBackground::KEY), 'not remembered before it was written');
        self::assertSame(['image/png', true], [$backgrounds->image()['mime'], $backgrounds->image()['custom']], 'the one in use stays in use');
        self::assertSame(['background.jpg', 'background.png'], $this->kept(), 'nothing half-written left beside it');
    }

    public function testADamagedPictureIsRefusedInWordsNeverWithAWarning(): void
    {
        $backgrounds = $this->service(QrBackground::class);

        try {
            // A JPEG by its first bytes, nothing after them: what its header is read with complains before it gives up.
            $backgrounds->store(self::upload("\xFF\xD8\x00"));
            self::fail('The picture was taken.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('file', $e->errors());
        }

        self::assertSame([], $this->kept());
    }

    #[DataProvider('uploadsRefusedUnread')]
    public function testAnUploadTheServerCutShortOrOneTooBigIsRefusedBeforeItIsRead(int $error, int $size): void
    {
        $backgrounds = $this->service(QrBackground::class);

        try {
            $backgrounds->store(new UploadedFile(Utils::streamFor(self::picture('png')), $size, $error, 'background', null));
            self::fail('The upload was taken.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('file', $e->errors());
        }

        self::assertSame([], $this->kept());
        self::assertFalse($backgrounds->image()['custom'], 'the shipped one stays in use');
    }

    /** @return iterable<string, array{int, int}> The upload's error status and the size it says it has */
    public static function uploadsRefusedUnread(): iterable
    {
        yield "over the server's own limit" => [UPLOAD_ERR_INI_SIZE, 0];
        yield 'over the form\'s limit' => [UPLOAD_ERR_FORM_SIZE, 0];
        yield 'cut short on the way' => [UPLOAD_ERR_PARTIAL, 0];
        yield 'larger than a background may be' => [UPLOAD_ERR_OK, QrBackground::MAX_BYTES + 1];
    }

    /** The uploads folder (the container's `qr.backgrounds`, the run's own). */
    private function dir(): string
    {
        return (string) $this->app()->container()->get('qr.backgrounds');
    }

    /** @return list<string> The files in the uploads folder, by name. */
    private function kept(): array
    {
        $dir = $this->dir();
        $files = is_dir($dir) ? array_values(array_diff((array) scandir($dir), ['.', '..'])) : [];
        sort($files);

        return $files;
    }

    private static function upload(string $bytes): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, 'background', null);
    }

    /** A small picture of that type, drawn by GD. */
    private static function picture(string $type): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
