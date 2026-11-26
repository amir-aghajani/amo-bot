<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;

/**
 * «🛠 سرویس دلخواه» in the bot's management (the main shop alone): the page with its switch, each quantity's tiers added,
 * typed anew and taken off, the settings card with its servers ticked, and the price calculator.
 */
final class CatalogCustomServicesTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnerSwitchesItOnAndSetsItsTiers(): void
    {
        $this->owner();

        $this->tapAs(Nav::to('hs'));
        self::assertContains(Nav::to('cs'), $this->callbacks(0));
        $this->tapAs(Nav::to('cs'));
        self::assertStringContainsString('بدون پله قیمت', $this->said()[0]);

        self::assertContains(Nav::to('cs', 'tog', 1), $this->callbacks(0), 'the switch carries what it sets');
        $this->tapAs(Nav::to('cs', 'tog', 1));
        self::assertTrue($this->setting('custom_enabled'));
        $this->tapAs(Nav::to('cs', 'tog', 1));
        self::assertTrue($this->setting('custom_enabled'), 'a second tap sets it the same');

        $this->tapAs(Nav::to('cst', 'add', 'traffic'));
        $this->sayAs('۱ ۳۰۰۰');
        $this->tapAs(Nav::to('cst', 'add', 'traffic'));
        $this->sayAs('از ۵۰: ۲٬۵۰۰ تومان');
        self::assertSame([['from' => 1, 'price' => '3000.00'], ['from' => 50, 'price' => '2500.00']], $this->setting('custom_traffic_tiers'));
        self::assertContains(Nav::to('cst', 'del', 'traffic', 50), $this->offered());

        $this->tapAs(Nav::to('cst', 'all', 'days'));
        $this->sayAs("1 1000\n30 800");
        self::assertCount(2, (array) $this->setting('custom_days_tiers'));

        $this->tapAs(Nav::to('cst', 'del', 'traffic', 50));
        self::assertStringContainsString('این پله حذف شود؟', $this->said()[0], 'asked first');
        self::assertCount(2, (array) $this->setting('custom_traffic_tiers'));
        $this->tapAs(Nav::to('cst', 'gone', 'traffic', 50));
        self::assertSame([['from' => 1, 'price' => '3000.00']], $this->setting('custom_traffic_tiers'));

        $this->tapAs(Nav::to('cst', 'add', 'traffic'));
        $this->sayAs('۵۰');
        self::assertStringContainsString('هر پله دو عدد است', $this->said()[0], 'asked again');
    }

    public function testTheCardTicksItsServersAndTheCalculatorPricesABuild(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $this->botSettings('custom_services', ['custom_traffic_tiers' => [['from' => 1, 'price' => '3000'], ['from' => 50, 'price' => '2500']], 'custom_days_tiers' => [['from' => 1, 'price' => '1000']]]);

        $this->tapAs(Nav::to('css'));
        self::assertStringContainsString('سرورها', $this->said()[0]);
        $this->tapAs(Nav::to('css', 'e', 8));
        self::assertContains(Nav::to('css', 'm', 8, 0, 1), $this->callbacks(0));
        $this->tapAs(Nav::to('css', 'm', 8, 0, 1));
        self::assertSame([$server->id], $this->setting('custom_servers'));

        $this->tapAs(Nav::to('csc'));
        self::assertStringContainsString('۱۵۵٬۰۰۰ تومان', $this->said()[0], '50 × 2,500 + 30 × 1,000');
        $this->tapAs(Nav::to('csc', 'gb'));
        $this->sayAs('۶۰۰ گیگ');
        self::assertStringContainsString('مشتری بین ۱ تا ۵۰۰ گیگابایت می‌سازد', $this->said()[0], 'past what may be built: why there is no price');
    }

    public function testAShopsAdminSwitchesThemAsTheOwnerDoes(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('cs', 'tog', 1), chat: 7100));
        self::assertTrue($this->setting('custom_enabled'), 'switched on through the panel\'s route');
    }

    private function setting(string $name): mixed
    {
        return $this->service(BotSettingsScreen::class)->present()[$name] ?? null;
    }
}
