<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use Tests\HttpTestCase;

/** The shop's «آموزش»: the very text the bot shows under its button, in the shop's own wording; anyone may read it. */
final class StoreTutorialTest extends HttpTestCase
{
    public function testTheTutorialIsTheBotsText(): void
    {
        $website = $this->website();

        self::assertSame(['text' => self::text(BotText::Tutorial)], $this->decode($this->get($this->storeApi($website, '/tutorial'))), 'the default wording, no token needed');

        $this->service(BotTexts::class)->save(BotText::Tutorial, "📚 <b>راهنمای اتصال</b>\nبرنامه v2rayNG را نصب کنید و <a href=\"https://shop.example/help\">این صفحه</a> را ببینید.");
        self::assertSame(
            "📚 <b>راهنمای اتصال</b>\nبرنامه v2rayNG را نصب کنید و <a href=\"https://shop.example/help\">این صفحه</a> را ببینید.",
            $this->decode($this->get($this->storeApi($website, '/tutorial')))['text'],
            "the shop's wording, in Telegram's HTML as the bot sends it",
        );
    }

    public function testAnAgentsShopHasItsOwnTutorial(): void
    {
        $this->service(BotTexts::class)->save(BotText::Tutorial, 'آموزش فروشگاه اصلی');
        $bot = $this->agentBot();
        $website = CurrentBot::run($bot, function (): Website {
            $this->service(BotTexts::class)->save(BotText::Tutorial, 'آموزش نماینده');

            return $this->website();
        });

        self::assertSame('آموزش نماینده', $this->decode($this->get($this->storeApi($website, '/tutorial')))['text']);
    }
}
