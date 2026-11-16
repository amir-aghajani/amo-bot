<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Store\Models\MiniApp;
use App\Modules\Store\Services\MiniApps;
use App\Modules\Telegram\Admin\Ui\Nav;
use GuzzleHttp\Psr7\Response;
use Tests\BotTestCase;

/**
 * «📱 مینی‌اپ تلگرام» in the bot (Telegram\Admin\Sections\Panel\MiniApp): its connection and look saved a field at a time
 * through PATCH /mini-app (http said to be no address Telegram opens), its logo replaced by a picture sent here and
 * removed on a yes, and its preview opened as one of the shop's customers — a link with the preview's token.
 */
final class PanelMiniAppTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
    }

    public function testTheConnectionAndTheLookAreSavedAField(): void
    {
        $this->tapAs(Nav::to('ma'));
        self::assertStringContainsString('مینی‌اپ تلگرام', $this->said()[0]);
        foreach (['mac', 'mal', 'mag', 'map'] as $section) {
            self::assertContains(Nav::to($section), $this->callbacks(0));
        }

        $this->tapAs(Nav::to('mac'));
        self::assertStringContainsString('فقط از آدرس https باز می‌کند', $this->said()[0], 'the shop\'s address is no https one: said, as the panel does');
        $before = $this->theApp()->menu_button;
        $this->tapAs(Nav::to('mac', 'e', 1));
        self::assertSame(!$before, $this->theApp()->menu_button, 'the menu button switched through the panel\'s route');

        $this->tapAs(Nav::to('mal', 'e', 0));
        self::assertStringContainsString('نام مینی‌اپ', $this->said()[0]);
        $this->send($this->message('فروشگاه آمو', chat: self::OWNER));
        self::assertSame('فروشگاه آمو', $this->theApp()->title);

        $this->tapAs(Nav::to('mal', 'e', 1));
        $this->send($this->message('#2481CC', chat: self::OWNER));
        self::assertSame('#2481cc', $this->theApp()->accent_color, 'kept in small letters, as the panel sends it');

        $this->tapAs(Nav::to('mal', 'x', 0));
        self::assertNull($this->theApp()->title, 'emptied: the shop\'s name again');
    }

    public function testTheLogoIsReplacedByAPictureSentAndRemovedOnAYes(): void
    {
        $this->tapAs(Nav::to('mag'));
        self::assertStringContainsString('بدون لوگو', $this->said()[0]);

        $this->tapAs(Nav::to('mag', 'up'));
        self::assertStringContainsString('تصویر لوگو', $this->said()[0], 'the picture asked for');
        $this->telegram()->reply(['file_path' => 'photos/logo.png']);
        $this->telegram()->raw(new Response(200, [], self::png()));
        $this->send($this->message(null, ['photo' => [['file_id' => 'logo-1']]], self::OWNER));
        self::assertTrue($this->theApp()->logo_path !== null, 'uploaded through POST /mini-app/logo');
        self::assertContains('sendPhoto', $this->calls(), 'the screen is the logo itself');
        self::assertContains(Nav::to('mag', 'del'), $this->callbacks(array_search('sendPhoto', $this->calls(), true)));

        $this->tapAs(Nav::to('mag', 'del'));
        self::assertContains(Nav::to('mag', 'gone'), $this->callbacks(0), 'asked first');
        self::assertNotNull($this->theApp()->logo_path);
        $this->tapAs(Nav::to('mag', 'gone'));
        self::assertNull($this->theApp()->logo_path);
    }

    public function testThePreviewOpensAsACustomerSeesIt(): void
    {
        $sara = $this->customer(['telegram_id' => 501, 'first_name' => 'Sara']);

        $this->tapAs(Nav::to('map'));
        self::assertStringContainsString('یک مشتری انتخاب کنید', $this->said()[0]);
        self::assertContains(Nav::to('map', 'pick', $sara->id), $this->callbacks(0));

        $this->tapAs(Nav::to('map', 'pick', $sara->id));
        self::assertStringContainsString('پیش‌نمایش باز شد', (string) $this->popup());
        $links = $this->links();
        self::assertCount(1, $links, 'the preview, as a link');
        self::assertStringContainsString('#tgWebAppPreview=', $links[0]);
        self::assertStringContainsString('tgWebAppPlatform=android', $links[0]);
        self::assertStringContainsString('Sara', implode("\n", $this->said()));

        $this->tapAs(Nav::to('map', 'theme'));
        self::assertStringContainsString(rawurlencode('#212121'), $this->links()[0] ?? '', 'Telegram\'s dark theme');

        $this->tapAs(Nav::to('map', 'other'));
        self::assertStringContainsString('یک مشتری انتخاب کنید', $this->said()[0], 'another customer to pick');
    }

    /**
     * The links under the window since the last send().
     *
     * @return list<string>
     */
    private function links(): array
    {
        $links = [];
        foreach (array_keys($this->calls()) as $call) {
            $links = [...$links, ...array_values(array_filter(array_column(array_merge(...$this->inlineKeyboard($call)), 'url')))];
        }

        return $links;
    }

    private function theApp(): MiniApp
    {
        return $this->service(MiniApps::class)->current()->refresh();
    }

    /** A small PNG, drawn by GD; without GD, one of a pixel. */
    private static function png(): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);
        }
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
