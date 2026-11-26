<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * A sale of some plan categories on the website: the catalogue offers each plan filed under one of them at the sale
 * (`offer_price`, `discount`) — one in such a category switched off too, listed with the rest — and every other plan at
 * its own price, to a customer and a guest alike; a service's extras are offered, and an amount not sold refused, at the
 * sale when its plan is filed there — as their orders charge them (Pricing).
 */
final class StoreCategorySalesTest extends HttpTestCase
{
    private const SALE = ['source' => 'sale', 'label' => 'فروش ماهانه ۲۰٪', 'percent' => 20, 'ends_at' => '2026-10-10T00:00:00+00:00'];

    private Website $website;
    private User $ali;
    private Server $server;
    private PlanCategory $monthly;
    private Plan $plan;
    private Plan $weeklyPlan;
    private Plan $loose;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '500000');
        $this->server = $this->sellingServer('Berlin');
        $this->monthly = $this->category(['name' => 'ماهانه']);
        $weekly = $this->category(['name' => 'هفتگی']);
        $this->plan = $this->plan(['name' => 'یک‌ماهه', 'price' => '100000.00', 'category_id' => $this->monthly->id], $this->server);
        $this->weeklyPlan = $this->plan(['name' => 'هفتگی', 'price' => '100000.00', 'category_id' => $weekly->id], $this->server);
        $this->loose = $this->plan(['name' => 'بی‌دسته', 'price' => '100000.00'], $this->server);
        $this->sale('فروش ماهانه', 20, ['category_ids' => [$this->monthly->id], 'ends_at' => '2026-10-10 00:00:00']);
        $this->bearer($this->customerSession($this->ali));
    }

    public function testTheCatalogueOffersACategorysPlansAtItsSaleAndEveryOtherAtItsPrice(): void
    {
        $retired = $this->category(['name' => 'قدیمی']);
        $filedAway = $this->plan(['name' => 'دسته خاموش', 'price' => '100000.00', 'category_id' => $retired->id], $this->server);
        $sale = Sale::query()->sole();
        $sale->forceFill(['category_ids' => [$this->monthly->id, $retired->id]])->save();
        $retired->forceFill(['is_active' => false])->save();

        $expected = [
            'یک‌ماهه' => [$this->monthly->id, '80000.00', self::SALE],
            'هفتگی' => [$this->weeklyPlan->category_id, '100000.00', null],
            'بی‌دسته' => [null, '100000.00', null],
            'دسته خاموش' => [null, '80000.00', self::SALE],
        ];
        foreach (['a customer' => true, 'a guest' => false] as $reader => $signedIn) {
            $this->bearer($signedIn ? $this->customerSession($this->ali) : null);

            self::assertSame($expected, $this->offered(), "{$reader}: by the category each is filed under now — one switched off listed with the rest, at its sale still");
            $plan = $this->decode($this->get($this->storeApi($this->website, "/plans/{$this->plan->id}")))['plan'];
            self::assertSame(['100000.00', '80000.00', self::SALE], [$plan['price'], $plan['offer_price'], $plan['discount']], "{$reader}: the plan read alone");
            $other = $this->decode($this->get($this->storeApi($this->website, "/plans/{$this->weeklyPlan->id}")))['plan'];
            self::assertSame(['100000.00', null], [$other['offer_price'], $other['discount']], "{$reader}: another category's plan read alone");
        }
        self::assertSame('80000.00', $this->service(Pricing::class)->purchase($this->ali, $filedAway, $this->server)->amount(), 'what its order charges');
    }

    public function testAServicesExtrasAreOfferedAtTheSaleOfItsPlansCategory(): void
    {
        $this->extrasOnSale();
        $monthly = $this->serviceOn($this->plan, 'ali_1');
        $weekly = $this->serviceOn($this->weeklyPlan, 'ali_2');
        $loose = $this->serviceOn($this->loose, 'ali_3');

        self::assertSame([
            'price' => '2000.00',
            'offer_price' => '1600.00',
            'discount' => self::SALE,
            'options' => [['quantity' => 10, 'price' => '18000.00', 'offer_price' => '14400.00'], ['quantity' => 50, 'price' => '100000.00', 'offer_price' => '80000.00']],
            'custom' => ['min' => 1, 'max' => 100],
            'days' => null,
        ], $this->extras($monthly)['traffic'], "20% off each: the service's plan is filed under the category");
        self::assertSame('14400.00', $this->service(Pricing::class)->extra($this->ali, $monthly->refresh(), ExtraKind::Traffic, '18000')->amount(), 'what its order charges');
        $time = $this->extras($monthly)['time'];
        self::assertSame(['1000.00', '800.00', self::SALE], [$time['price'], $time['offer_price'], $time['discount']], 'and its time');

        foreach (['another category' => $weekly, 'no category' => $loose] as $label => $service) {
            $traffic = $this->extras($service)['traffic'];
            self::assertSame(['2000.00', null, ['18000.00', '100000.00']], [$traffic['offer_price'], $traffic['discount'], array_column($traffic['options'], 'offer_price')], "a service on a plan of {$label}: nothing off");
        }
    }

    public function testAnAmountNotSoldIsRefusedWithWhatMayBeBoughtAtTheSale(): void
    {
        $this->extrasOnSale();
        $monthly = $this->serviceOn($this->plan, 'ali_1');
        $loose = $this->serviceOn($this->loose, 'ali_2');

        self::assertSame(
            ['این مقدار فروخته نمی‌شود؛ حجم اضافه بین ۱ تا ۱۰۰ گیگابایت بنویسید، یا یکی از این‌ها را بخرید: ۱۰ گیگابایت (۱۴٬۴۰۰ تومان)، ۵۰ گیگابایت (۸۰٬۰۰۰ تومان).'],
            $this->refused($monthly, 'extra-1'),
            "at the sale of its plan's category, as the listing's offer_price says",
        );
        self::assertSame(
            ['این مقدار فروخته نمی‌شود؛ حجم اضافه بین ۱ تا ۱۰۰ گیگابایت بنویسید، یا یکی از این‌ها را بخرید: ۱۰ گیگابایت (۱۸٬۰۰۰ تومان)، ۵۰ گیگابایت (۱۰۰٬۰۰۰ تومان).'],
            $this->refused($loose, 'extra-2'),
            'a service on a plan in no category: its own prices',
        );
    }

    /**
     * The catalogue's plans by name, as `/plans` offers them to the reader: the category each is listed under, its
     * offer price and its automatic discount.
     *
     * @return array<string, array{int|null, string, array<string, mixed>|null}>
     */
    private function offered(): array
    {
        $offered = [];
        foreach ($this->decode($this->get($this->storeApi($this->website, '/plans')))['groups'] as $group) {
            foreach ($group['plans'] as $plan) {
                $offered[$plan['name']] = [$group['category']['id'] ?? null, $plan['offer_price'], $plan['discount']];
            }
        }

        return $offered;
    }

    /** A service of Ali's on the plan, its traffic 18 GB used, as its panel has it. */
    private function serviceOn(Plan $plan, string $name): Subscription
    {
        $subscription = $this->subscription($this->ali, $plan, $this->server, $name, ['download_bytes' => Traffic::bytesOfGb(18)]);
        FakeProvider::mirror($subscription);

        return $subscription;
    }

    /** @return array<string, mixed> The service's extras as GET …/extras offers them */
    private function extras(Subscription $subscription): array
    {
        return $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/extras")))['extras'];
    }

    /** @return mixed The refusal under `gb` of 101 GB of extra traffic for the service, from the wallet */
    private function refused(Subscription $subscription, string $key): mixed
    {
        $response = $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$subscription->id}/extra-traffic"), ['gb' => 101, 'method_id' => $this->walletMethod()->id], ['Idempotency-Key' => $key]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['errors']['gb'] ?? null;
    }
}
