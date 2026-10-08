<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Config\Repository as Config;
use App\Core\Http\Urls;
use PHPUnit\Framework\TestCase;

/**
 * Every address the shop hands out is APP_URL — the address it is reached at, its sub-folder included — and the path:
 * never the request prefix PHP sees (APP_BASE_PATH), so a generated address carries the sub-folder once. The machine
 * addresses are spelled once, the secret each ends with a single path segment, and a screen never shows that secret.
 */
final class UrlsTest extends TestCase
{
    public function testAnAddressIsAppUrlAndThePathWithTheSubFolderOnce(): void
    {
        $urls = self::urls('https://shop.example/shop/');

        self::assertSame('https://shop.example/shop', $urls->base());
        self::assertSame('https://shop.example/shop/webhooks/telegram/s3cret', $urls->telegramWebhook('s3cret'));
        self::assertSame('https://shop.example/shop/webhooks/telegram/bot/7/a1b2', $urls->agentWebhook(7, 'a1b2'));
        self::assertSame('https://shop.example/shop/cron/t0ken', $urls->cron('t0ken'));
        self::assertSame('https://shop.example/shop/agent/login', $urls->panel('agent', '/login'));
        self::assertSame('https://shop.example/shop/admin/', $urls->panel('admin'));
    }

    public function testTheRequestPrefixPhpSeesIsNeverAdded(): void
    {
        // APP_BASE_PATH is how a request arrives (a web server that does not say it); APP_URL is where the shop is reached.
        $urls = new Urls(new Config(['app' => ['url' => 'https://shop.example', 'base_path' => '/public']]));

        self::assertSame('https://shop.example/cron/t0ken', $urls->cron('t0ken'));
    }

    public function testAWebhookMayBeRegisteredUnderAnotherBase(): void
    {
        // bot:webhook:set --url: a tunnel in front of a developer's machine.
        $urls = self::urls('http://localhost');

        self::assertSame('https://tunnel.example/webhooks/telegram/s3cret', $urls->telegramWebhook('s3cret', 'https://tunnel.example/'));
        self::assertSame('https://tunnel.example/webhooks/telegram/bot/7/a1b2', $urls->agentWebhook(7, 'a1b2', 'https://tunnel.example'));
    }

    public function testASecretIsOneSegmentAndAScreenNeverShowsIt(): void
    {
        $urls = self::urls('https://shop.example');

        self::assertSame('https://shop.example/cron/a%2Fb%3Fc', $urls->cron('a/b?c'), 'a secret cannot reach another route');
        self::assertSame('https://shop.example/cron/…', $urls->masked(Urls::CRON));
        self::assertSame('https://shop.example/webhooks/telegram/bot/…/…', $urls->masked(Urls::AGENT_WEBHOOK));
    }

    public function testTheAddressesWhoseLastSegmentIsASecretAreTheMachinesOwn(): void
    {
        self::assertSame([Urls::TELEGRAM_WEBHOOK, Urls::AGENT_WEBHOOK, Urls::CRON], Urls::SECRET_PATHS, 'what Redact masks in a log line');
        foreach (Urls::SECRET_PATHS as $path) {
            self::assertMatchesRegularExpression('~/\{\w+\}$~', $path, "{$path} ends with its secret");
        }
    }

    private static function urls(string $appUrl): Urls
    {
        return new Urls(new Config(['app' => ['url' => $appUrl]]));
    }
}
