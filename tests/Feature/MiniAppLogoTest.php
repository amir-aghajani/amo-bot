<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Store\Services\MiniApps;
use Tests\HttpTestCase;

/**
 * The mini app's logo (Store\Services\MiniApps): uploaded in either panel — a picture by its bytes, within its size,
 * kept no larger than the app draws it, in place of the one before —, removed, shown to the panel, and answered by the
 * Store API through the mini app's own key alone. Each shop's is its own.
 */
final class MiniAppLogoTest extends HttpTestCase
{
    private const LOGO = '/api/admin/mini-app/logo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testALogoIsUploadedShownAndRemoved(): void
    {
        self::assertFalse($this->decode($this->get('/api/admin/mini-app'))['mini_app']['logo'], 'none at first');
        $none = $this->get(self::LOGO);
        self::assertSame([404, 'این مینی‌اپ لوگویی ندارد.'], [$none->getStatusCode(), $this->decode($none)['message']]);

        $uploaded = $this->upload(self::LOGO, 'file', 'anything.jpg', self::png(200, 100));

        self::assertSame(200, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        self::assertSame([true, null], [$this->decode($uploaded)['mini_app']['logo'], $this->decode($uploaded)['menu_button_error']]);
        self::assertSame($this->logoName('png'), $this->kept()->logo_path, 'named by its content, a png, not the .jpg it was sent as');
        self::assertSame([], $this->telegram()->calls(), 'the menu button is not set again for a logo');

        $shown = $this->get(self::LOGO);
        self::assertSame(['image/png', 'private, no-cache'], [$shown->getHeaderLine('Content-Type'), $shown->getHeaderLine('Cache-Control')]);
        self::assertSame([200, 100, 'image/png'], self::imageOf((string) $shown->getBody()), 'the panel previews what the app shows');

        $removed = $this->deleteJson(self::LOGO);
        self::assertSame(200, $removed->getStatusCode());
        self::assertFalse($this->decode($removed)['mini_app']['logo']);
        self::assertNull($this->kept()->logo_path);
        self::assertFileDoesNotExist($this->logos() . '/' . $this->logoName('png'));
        self::assertSame(404, $this->get(self::LOGO)->getStatusCode());
    }

    public function testWhatIsNoPictureOrTooLargeIsRefusedAndTheLogoStaysAsItWas(): void
    {
        $this->upload(self::LOGO, 'file', 'logo.png', self::png(64, 64));

        $text = $this->upload(self::LOGO, 'file', 'logo.png', 'hello, not a picture');
        self::assertSame(422, $text->getStatusCode());
        self::assertStringContainsString('PNG', $this->decode($text)['errors']['file'][0]);

        $heavy = $this->upload(self::LOGO, 'file', 'logo.png', str_repeat('a', MiniApps::LOGO_MAX_BYTES + 1));
        self::assertSame(['حجم لوگو حداکثر ۲ مگابایت می‌تواند باشد.'], $this->decode($heavy)['errors']['file'] ?? null);

        $none = $this->unchecked()->postJson(self::LOGO, []);
        self::assertSame(['تصویری انتخاب نشده است.'], $this->decode($none)['errors']['file'] ?? null);

        self::assertSame([64, 64, 'image/png'], self::imageOf((string) $this->get(self::LOGO)->getBody()), 'the logo before stays');
    }

    public function testAPictureTooLargeToDecodeIsRefusedBeforeItIsDecoded(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD is not installed.');
        }
        // A PNG header saying 30000 × 30000 — the size is read from it, nothing is decoded.
        $ihdr = pack('NNCCCCC', 30000, 30000, 8, 2, 0, 0, 0);
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        $huge = $this->upload(self::LOGO, 'file', 'huge.png', "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IEND', ''));

        self::assertSame(422, $huge->getStatusCode());
        self::assertStringContainsString('بیش از حد بزرگ', $this->decode($huge)['errors']['file'][0]);
        self::assertNull($this->kept()->logo_path);
    }

    public function testALargePictureIsKeptAtTheSizeTheAppDrawsItItsProportionsKept(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is not installed.');
        }

        $this->upload(self::LOGO, 'file', 'wide.png', self::png(1024, 600));

        self::assertSame([MiniApps::LOGO_SIDE, 300, 'image/png'], self::imageOf((string) $this->get(self::LOGO)->getBody()));
    }

    public function testANewLogoReplacesTheOneBeforeItsFileGoing(): void
    {
        if (!function_exists('imagejpeg')) {
            self::markTestSkipped('GD is not installed.');
        }

        $this->upload(self::LOGO, 'file', 'logo.png', self::png(64, 64));
        $png = $this->logos() . '/' . $this->logoName('png');
        self::assertFileExists($png);

        $this->upload(self::LOGO, 'file', 'logo.jpg', self::jpeg(80, 40));

        self::assertSame($this->logoName('jpg'), $this->kept()->logo_path);
        self::assertFileDoesNotExist($png, 'the old file goes with the old logo');
        $shown = $this->get(self::LOGO);
        self::assertSame(['image/jpeg', [80, 40, 'image/jpeg']], [$shown->getHeaderLine('Content-Type'), self::imageOf((string) $shown->getBody())]);
    }

    public function testTheStoreApiAnswersItThroughTheMiniAppsKeyAlone(): void
    {
        $app = $this->miniApp();
        $address = "/api/store/v1/{$app->key}/app/logo";

        $none = $this->get($address);
        self::assertSame([404, 'این مینی‌اپ لوگویی ندارد.'], [$none->getStatusCode(), $this->decode($none)['message']]);

        $this->upload(self::LOGO, 'file', 'logo.png', self::png(48, 48));
        $logo = $this->get($address);
        self::assertSame(200, $logo->getStatusCode());
        self::assertSame(['image/png', 'public, max-age=300'], [$logo->getHeaderLine('Content-Type'), $logo->getHeaderLine('Cache-Control')]);
        self::assertSame([48, 48, 'image/png'], self::imageOf((string) $logo->getBody()));
        self::assertMatchesRegularExpression("~/api/store/v1/{$app->key}/app/logo\?v=\d+$~", (string) $this->decode($this->get("/api/store/v1/{$app->key}"))['app']['logo_url'], 'where the app finds it, versioned by its last change');

        $website = $this->website();
        self::assertSame(404, $this->get($this->storeApi($website, '/app/logo'))->getStatusCode(), 'a website has no logo of the app');

        $app->forceFill(['enabled' => false])->save();
        self::assertSame(404, $this->get($address)->getStatusCode(), 'switched off, its key opens nothing');
    }

    public function testEachShopsLogoIsItsOwn(): void
    {
        $this->upload(self::LOGO, 'file', 'logo.png', self::png(32, 32));
        $bot = $this->agentBot();

        $this->loginAsAgent($bot);
        self::assertSame(404, $this->get('/api/agent/mini-app/logo')->getStatusCode(), 'the main shop\'s is not theirs');
        $uploaded = $this->upload('/api/agent/mini-app/logo', 'file', 'logo.png', self::png(40, 40));

        self::assertTrue($this->decode($uploaded)['mini_app']['logo']);
        self::assertSame("logo-{$bot->id}.png", CurrentBot::run($bot, fn(): ?string => $this->kept()->logo_path));
        self::assertSame([40, 40, 'image/png'], self::imageOf((string) $this->get('/api/agent/mini-app/logo')->getBody()));
        self::assertSame([32, 32, 'image/png'], self::imageOf((string) $this->get(self::LOGO)->getBody()), 'the main shop keeps its own');
    }

    /** The current shop's mini app, as kept. */
    private function kept(): MiniApp
    {
        return $this->service(MiniApps::class)->current()->refresh();
    }

    /** The current shop's logo's file name, of that type. */
    private function logoName(string $extension): string
    {
        return 'logo-' . CurrentBot::id() . '.' . $extension;
    }

    private function logos(): string
    {
        return (string) $this->app()->container()->get('miniapp.logos');
    }

    /** A black PNG of that size, drawn by GD; without GD, one of a pixel. */
    private static function png(int $width, int $height): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);
        }
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A black JPEG of that size, drawn by GD. */
    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
