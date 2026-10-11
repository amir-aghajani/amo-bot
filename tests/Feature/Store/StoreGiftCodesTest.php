<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Rewards\Services\GiftCodes;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/** Gift codes redeemed on the website: wallet credit or a plan's service, refusals on `code`, wrong tries limited. */
final class StoreGiftCodesTest extends HttpTestCase
{
    private Website $website;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->withoutQr();
        $this->telegram();
        $this->giftCodesOn();
        $this->website = $this->website();
        $this->ali = $this->customer();
        $this->bearer($this->customerSession($this->ali));
    }

    public function testTomanLandInTheWallet(): void
    {
        $this->giftCode('NOWRUZ7K3M9QXAPT');

        $response = $this->postJson($this->storeApi($this->website, '/gift-codes/redeem'), ['code' => 'nowruz-7k3m-9qxapt']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['gift' => ['kind' => 'wallet', 'words' => '۵۰٬۰۰۰ تومان شارژ کیف پول', 'balance' => '50000.00', 'order' => null, 'subscription' => null]], $this->decode($response));
        self::assertSame([], $this->telegram()->calls(), 'nothing sent in Telegram: the site shows it');
    }

    public function testAPlansServiceIsAnsweredWithItsOrder(): void
    {
        $server = $this->sellingServer();
        $plan = $this->plan(['name' => 'یک‌ماهه'], $server);
        $this->giftCode('PLANGIFT2345', ['kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $plan->id]);

        $gift = $this->decode($this->postJson($this->storeApi($this->website, '/gift-codes/redeem'), ['code' => 'PLANGIFT2345']))['gift'];

        self::assertSame(['plan', 'سرویس «یک‌ماهه»', null], [$gift['kind'], $gift['words'], $gift['balance']]);
        self::assertSame('fulfilled', $gift['order']['status']);
        self::assertSame(GiftCode::query()->sole()->order_id, $gift['order']['id']);
        self::assertSame(FakeProvider::$created[0]['spec']->name, $gift['subscription']['name'] ?? null);
    }

    public function testARefusalIsOnTheCodeAndWrongOnesAreHeld(): void
    {
        $this->giftCode('TAKENCODE234', ['redeemed_by' => $this->customer(['telegram_id' => 7002])->id, 'redeemed_at' => now()]);

        $taken = $this->postJson($this->storeApi($this->website, '/gift-codes/redeem'), ['code' => 'TAKENCODE234']);
        self::assertSame(422, $taken->getStatusCode());
        self::assertSame(['code' => ['این کد پیش‌تر استفاده شده است.']], $this->decode($taken)['errors']);

        for ($i = 0; $i < GiftCodes::WRONG_CODES; $i++) {
            self::assertSame(422, $this->postJson($this->storeApi($this->website, '/gift-codes/redeem'), ['code' => "WRONG{$i}CODE"])->getStatusCode());
        }
        $held = $this->postJson($this->storeApi($this->website, '/gift-codes/redeem'), ['code' => 'WRONGAGAIN22']);
        self::assertSame(429, $held->getStatusCode());
        self::assertNotSame('', $held->getHeaderLine('Retry-After'));
    }

    public function testSignedInOnly(): void
    {
        $this->bearer(null);

        self::assertSame(401, $this->unchecked()->postJson($this->storeApi($this->website, '/gift-codes/redeem'), ['code' => 'ANYCODE23456'])->getStatusCode());
    }
}
