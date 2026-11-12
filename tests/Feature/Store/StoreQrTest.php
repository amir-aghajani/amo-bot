<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Services\Settings;
use App\Modules\Store\Exceptions\QrCardUnavailableException;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerSubscriptions;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Telegram\Qr\QrBackground;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * A service's QR card, as the bot sends it with a delivery: the customer's own service with a link alone, never cached;
 * a 503 when the server cannot draw it, and 30 a minute at most.
 */
#[RequiresPhpExtension('gd')]
final class StoreQrTest extends HttpTestCase
{
    private Website $website;

    private User $customer;

    private Server $server;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->customer = $this->customer();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheirServiceIsItsLinkDrawnOnTheShopsCard(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');

        $response = $this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/qr"));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['image/jpeg', 'private, no-cache'], [$response->getHeaderLine('Content-Type'), $response->getHeaderLine('Cache-Control')]);
        $size = getimagesizefromstring((string) $response->getBody());
        self::assertNotFalse($size);
        self::assertSame(['image/jpeg', 1024, 1024], [$size['mime'], $size[0], $size[1]], "the shipped background's card, as the bot sends it");
        self::assertSame([], $this->telegram()->calls(), 'nothing sent in Telegram');
    }

    public function testAnothersServiceOrOneWithoutALinkIsA404(): void
    {
        $theirs = $this->subscription($this->customer(['telegram_id' => 7272]), $this->plan, $this->server, 'reza_1');
        $deleted = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1', ['status' => SubscriptionStatus::Deleted]);

        foreach ([$theirs, $deleted] as $subscription) {
            $response = $this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/qr"));
            self::assertSame([404, ErrorHandler::NOT_FOUND], [$response->getStatusCode(), $this->decode($response)['message']], $subscription->remote_name);
        }
    }

    public function testACardTheServerCannotDrawIsA503(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        $this->unreadableBackground();

        $response = $this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/qr"));

        self::assertSame([503, QrCardUnavailableException::MESSAGE], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testACustomerAsksForThirtyCardsAMinuteAtMost(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        // Each card refused at once, rather than drawn: the window counts what is asked.
        $this->unreadableBackground();
        $card = fn(): ResponseInterface => $this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/qr"));
        foreach (range(1, CustomerSubscriptions::QR_CARDS) as $n) {
            self::assertSame(503, $card()->getStatusCode(), "card {$n}");
        }

        $response = $card();

        self::assertSame([429, '60'], [$response->getStatusCode(), $response->getHeaderLine('Retry-After')]);
        Carbon::setTestNow(now()->addMinute());
        self::assertSame(503, $card()->getStatusCode(), 'the minute over');
    }

    public function testSignedInOnly(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        $this->bearer(null);

        self::assertSame(401, $this->unchecked()->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/qr"))->getStatusCode());
    }

    /** The bot's own background, as the uploads folder keeps one: bytes GD cannot read, so no card can be drawn. */
    private function unreadableBackground(): void
    {
        $dir = (string) $this->app()->container()->get('qr.backgrounds');
        mkdir($dir);
        file_put_contents("{$dir}/background.png", 'not a picture');
        $this->service(Settings::class)->set(QrBackground::KEY, 'background.png');
    }
}
