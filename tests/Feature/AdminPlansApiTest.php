<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\PlanService;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Services\ExtrasSettings;
use App\Modules\Users\Models\User;
use App\Support\Validation;
use Illuminate\Database\Events\QueryExecuted;
use Tests\HttpTestCase;

/**
 * The plans screen: field validation (Persian digits taken), server entries (whole server or pinned inbounds, unsellable
 * ones flagged with why), a plan's own extras prices, ordering, the switch, duplicating, and deletion refused once a plan
 * has history.
 */
final class AdminPlansApiTest extends HttpTestCase
{
    private User $customer;
    private Server $berlin;
    private Server $paris;
    private ServerInbound $reality;
    private ServerInbound $trojan;
    private ServerInbound $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->customer();
        $this->loginAsAdmin();

        $this->berlin = $this->panelServer('Berlin', ['serves_subscriptions' => true]);
        $this->paris = $this->panelServer('Paris', ['serves_subscriptions' => true]);
        $this->reality = $this->inbound($this->berlin, '1', ['port' => 443, 'remark' => 'Reality']);
        $this->trojan = $this->inbound($this->berlin, '2', ['protocol' => 'trojan', 'port' => 8443, 'remark' => 'Trojan', 'is_selectable' => false]);
        $this->hidden = $this->inbound($this->berlin, '3', ['protocol' => 'vmess', 'port' => 2083, 'remark' => 'Old', 'enabled' => false, 'is_selectable' => false]);
    }

    /**
     * @return array<string, mixed>
     *
     * @param array<string, mixed> $overrides
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'یک‌ماهه ۳۰ گیگ', 'description' => 'مناسب یک نفر', 'price' => '120000', 'duration_days' => '30', 'traffic_gb' => '30', 'ip_limit' => '2', 'is_active' => true,
            'servers' => [['server_id' => $this->berlin->id, 'all_inbounds' => true]],
        ];
    }

    public function testCreateValidatesEveryField(): void
    {
        $response = $this->postJson('/api/admin/plans', ['name' => '', 'price' => 'abc', 'duration_days' => '-1', 'traffic_gb' => 'x', 'ip_limit' => '5000', 'servers' => []]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['name', 'price', 'duration_days', 'traffic_gb', 'ip_limit', 'servers'], array_keys($this->decode($response)['errors']));
    }

    public function testThePriceIsWholeTomanAndAThousandsSeparatorStandsOnlyBetweenThousands(): void
    {
        foreach (['price' => '99999.50', 'traffic_gb' => '2,5'] as $field => $typed) {
            $response = $this->postJson('/api/admin/plans', $this->input([$field => $typed]));
            self::assertSame([$field], array_keys($this->decode($response)['errors'] ?? []), "{$typed}: no fraction of a Toman, no 25 GB from a misplaced comma");
        }
        self::assertSame(0, Plan::query()->count());

        $plan = $this->decode($this->postJson('/api/admin/plans', $this->input(['price' => '۱۲۰٬۰۰۰ تومان', 'traffic_gb' => '۲٫۵'])))['plan'];
        self::assertSame(['120000.00', 2.5], [$plan['price'], $plan['traffic_gb']]);
    }

    public function testTooLongWordsAndAServerThatIsNotThereAreRefused(): void
    {
        $max = static fn(string $limit): int => (int) (new \ReflectionClassConstant(PlanService::class, $limit))->getValue();

        $response = $this->postJson('/api/admin/plans', $this->input([
            'name' => str_repeat('ن', $max('NAME_MAX') + 1),
            'description' => str_repeat('ت', $max('DESCRIPTION_MAX') + 1),
            'servers' => [['server_id' => 999, 'all_inbounds' => true]],
        ]));

        self::assertSame(422, $response->getStatusCode());
        $errors = $this->decode($response)['errors'];
        self::assertSame([Validation::tooLong('نام پلن', $max('NAME_MAX'))], $errors['name']);
        self::assertSame([Validation::tooLong('توضیحات', $max('DESCRIPTION_MAX'))], $errors['description']);
        self::assertStringContainsString('وجود ندارد', $errors['servers'][0]);
        self::assertSame(0, Plan::query()->count());
    }

    public function testAnEntryCustomersAreNotOfferedSaysWhy(): void
    {
        $plan = $this->decode($this->postJson('/api/admin/plans', $this->input(['servers' => [
            ['server_id' => $this->berlin->id, 'all_inbounds' => true],
            ['server_id' => $this->paris->id, 'all_inbounds' => true],
        ]])))['plan'];

        self::assertNull($plan['servers'][0]['unsellable_reason'], 'Berlin sells its Reality inbound');
        self::assertStringContainsString('اینباند قابل فروشی ندارد', (string) $plan['servers'][1]['unsellable_reason'], 'Paris has nothing marked for sale: kept, and flagged');

        $this->paris->forceFill(['is_active' => false])->save();
        $options = array_column($this->decode($this->get('/api/admin/plans/options'))['servers'], 'unsellable_reason', 'name');
        self::assertNull($options['Berlin']);
        self::assertStringContainsString('غیرفعال', (string) $options['Paris'], 'the form says it before the plan is saved');
    }

    public function testAPlanTheBotDoesNotShowSaysWhyByTheBotsOwnJudgement(): void
    {
        $this->plan(['name' => 'sells'], $this->berlin);
        $this->plan(['name' => 'bare']);
        $this->plan(['name' => 'stuck'], $this->paris);
        $this->plan(['name' => 'off', 'is_active' => false]);

        $reasons = array_column($this->decode($this->get('/api/admin/plans'))['plans'], 'unsellable_reason', 'name');

        self::assertNull($reasons['sells']);
        self::assertStringContainsString('روی هیچ سروری نیست', (string) $reasons['bare'], 'its servers all gone');
        self::assertStringContainsString('هیچ‌کدام از سرورهای این پلن', (string) $reasons['stuck'], 'Paris has nothing marked for sale');
        self::assertStringContainsString('روی هیچ سروری نیست', (string) $reasons['off'], 'the switch is the row’s own: the reason says what else keeps it hidden');
        $offered = array_keys($this->service(ServerSelector::class)->offers(Plan::active()->get()));
        self::assertSame(['sells'], Plan::query()->whereKey($offered)->pluck('name')->all(), 'the bot’s shop offers exactly the plans with no reason');
    }

    public function testAnAgentsPlanItsTrafficCannotCoverIsFlagged(): void
    {
        $bot = $this->agentBot(traffic: 20);
        CurrentBot::run($bot, function (): void {
            $this->plan(['name' => 'small', 'traffic_gb' => 10], $this->berlin);
            $this->plan(['name' => 'big', 'traffic_gb' => 50], $this->berlin);
        });
        $this->loginAsAgent($bot);

        $reasons = array_column($this->decode($this->get('/api/agent/plans'))['plans'], 'unsellable_reason', 'name');

        self::assertNull($reasons['small']);
        self::assertStringContainsString('حجم باقی‌مانده نمایندگی', (string) $reasons['big']);
    }

    public function testCreateAcceptsPersianDigitsAndPresentsThePlan(): void
    {
        $response = $this->postJson('/api/admin/plans', $this->input(['price' => '۱۲۰٬۰۰۰', 'traffic_gb' => '۳۰٫۵', 'duration_days' => '۳۰']));

        self::assertSame(201, $response->getStatusCode());
        $plan = $this->decode($response)['plan'];
        self::assertSame('120000.00', $plan['price']);
        self::assertSame(30.5, $plan['traffic_gb']);
        self::assertSame(30, $plan['duration_days']);
        self::assertArrayNotHasKey('price_formatted', $plan, 'the screen formats money itself');
        self::assertSame(['active_subscriptions' => 0, 'sales' => 0, 'orders' => 0, 'subscriptions' => 0], $plan['counts']);
        self::assertSame(1, $plan['sort']);
    }

    public function testAPlanMayPriceTheExtraTrafficAndTimeItsServicesBuyItsOwnWay(): void
    {
        $response = $this->postJson('/api/admin/plans', $this->input(['extras' => [
            'traffic_price' => '۵٬۰۰۰', 'traffic_options' => [['quantity' => '20', 'price' => '90000']], 'traffic_min' => 1, 'traffic_max' => '50',
            'time_price' => '', 'time_options' => [], 'time_min' => 1, 'time_max' => 30,
            'devices_price' => '۲۰٬۰۰۰', 'devices_options' => [], 'devices_min' => 1, 'devices_max' => '3',
        ]]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $plan = $this->decode($response)['plan'];
        self::assertSame([
            'traffic_price' => '5000.00', 'traffic_options' => [['quantity' => 20, 'price' => '90000.00']], 'traffic_min' => 1, 'traffic_max' => 50,
            'time_price' => null, 'time_options' => [], 'time_min' => 1, 'time_max' => 30,
            'devices_price' => '20000.00', 'devices_options' => [], 'devices_min' => 1, 'devices_max' => 3,
        ], $plan['extras']);
        $prices = ExtrasSettings::planPrices(Plan::query()->findOrFail($plan['id'])) ?? self::fail('no prices of its own');
        self::assertSame(['90000.00', '150000.00', false, '40000.00'], [$prices->traffic->priceOf(20), $prices->traffic->priceOf(30), $prices->time->sells(), $prices->devices->priceOf(2)]);

        $kept = $this->decode($this->putJson("/api/admin/plans/{$plan['id']}", $this->input(['name' => 'یک‌ماهه ویژه'])))['plan'];
        self::assertSame($plan['extras'], $kept['extras'], 'changed without them — a form made before them —: its own stay');
        self::assertNull($this->decode($this->putJson("/api/admin/plans/{$plan['id']}", $this->input(['extras' => null])))['plan']['extras'], "null: the shop's");
        self::assertNull($this->decode($this->postJson('/api/admin/plans', $this->input(['name' => 'دوماهه'])))['plan']['extras'], "a new plan without them sells at the shop's");

        $refused = $this->postJson('/api/admin/plans', $this->input(['name' => '', 'extras' => [
            'traffic_price' => '0', 'traffic_min' => 10, 'traffic_max' => 5,
            'time_options' => [['quantity' => 7, 'price' => '']], 'time_min' => 1, 'time_max' => 30,
            'devices_options' => [['quantity' => 30, 'price' => '1000']], 'devices_min' => 1, 'devices_max' => 5,
        ]]));
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['name', 'extras.traffic_price', 'extras.devices_options', 'extras.traffic_max', 'extras.time_options'], array_keys($this->decode($refused)['errors']), 'with the rest of the form, each under its field: what each field reads first, then what fields say of each other');
        self::assertSame(2, Plan::query()->count());
    }

    public function testAPlansOwnPricesKeptBeforeDevicesWereSoldLoadAndSaveAsTheyAre(): void
    {
        $id = $this->decode($this->postJson('/api/admin/plans', $this->input()))['plan']['id'];
        // As 0.3.0 kept a plan's own list: traffic and time alone.
        $kept = ['traffic_price' => '5000.00', 'traffic_options' => [['quantity' => 20, 'price' => '90000.00']], 'traffic_min' => 1, 'traffic_max' => 50, 'time_price' => null, 'time_options' => [], 'time_min' => 1, 'time_max' => 30];
        Plan::query()->findOrFail($id)->forceFill(['extras' => $kept])->save();

        $shown = $this->decode($this->get('/api/admin/plans'))['plans'][0]['extras'];
        self::assertSame($kept + ['devices_price' => null, 'devices_options' => [], 'devices_min' => 1, 'devices_max' => 5], $shown, 'its devices shown unsold, at the default bounds');
        self::assertFalse((ExtrasSettings::planPrices(Plan::query()->findOrFail($id)) ?? self::fail('no prices of its own'))->devices->sells(), "its services buy devices once the plan prices them, not at the shop's");

        $saved = $this->putJson("/api/admin/plans/{$id}", $this->input(['extras' => $shown]));
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame($shown, $this->decode($saved)['plan']['extras'], 'saved as the form shows it');
    }

    public function testAPlansOwnPricesSentAsBeforeDevicesWereSoldSaveAndSellNoDevices(): void
    {
        // As a 0.3.0 form or website sends a plan's own list: traffic and time alone.
        $sent = ['traffic_price' => '5000', 'traffic_options' => [], 'traffic_min' => 1, 'traffic_max' => 50, 'time_price' => '', 'time_options' => [], 'time_min' => 1, 'time_max' => 30];

        $created = $this->postJson('/api/admin/plans', $this->input(['extras' => $sent]));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $plan = $this->decode($created)['plan'];
        self::assertSame(['devices_price' => null, 'devices_options' => [], 'devices_min' => 1, 'devices_max' => 5], array_intersect_key($plan['extras'], array_flip(['devices_price', 'devices_options', 'devices_min', 'devices_max'])), 'no devices, at the default bounds');
        self::assertFalse((ExtrasSettings::planPrices(Plan::query()->findOrFail($plan['id'])) ?? self::fail('no prices of its own'))->devices->sells());

        $updated = $this->putJson("/api/admin/plans/{$plan['id']}", $this->input(['extras' => ['traffic_price' => '7000'] + $sent]));
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame('7000.00', $this->decode($updated)['plan']['extras']['traffic_price']);

        $refused = $this->putJson("/api/admin/plans/{$plan['id']}", $this->input(['extras' => ['devices_min' => 4, 'devices_max' => 2] + $sent]));
        self::assertSame(['extras.devices_max'], array_keys($this->decode($refused)['errors'] ?? []), 'bounds sent are still checked');
    }

    public function testAFirstPurchasePriceIsWholeTomanBelowThePriceOrNone(): void
    {
        $plan = $this->decode($this->postJson('/api/admin/plans', $this->input(['first_purchase_price' => '۵۰٬۰۰۰'])))['plan'];
        self::assertSame('50000.00', $plan['first_purchase_price']);

        $put = fn(array $overrides): mixed => $this->decode($this->putJson("/api/admin/plans/{$plan['id']}", $this->input($overrides)))['plan']['first_purchase_price'];
        self::assertSame('50000.00', $put(['name' => 'یک‌ماهه ویژه']), 'changed without it — a form made before it —: its own stays');
        self::assertSame('0.00', $put(['first_purchase_price' => '0']), 'a free first purchase');
        self::assertNull($put(['first_purchase_price' => '']), 'blank: none');
        self::assertSame('20000.00', $put(['first_purchase_price' => '20000']));
        self::assertNull($put(['first_purchase_price' => null]), 'null: none');
        self::assertNull($this->decode($this->postJson('/api/admin/plans', $this->input(['name' => 'دوماهه'])))['plan']['first_purchase_price'], 'a new plan without it has none');

        $below = 'قیمت خرید اول باید کمتر از قیمت پلن باشد.';
        $amount = 'قیمت خرید اول را به تومان و بدون اعشار وارد کنید؛ برای خرید اول رایگان 0 بگذارید، و برای هیچ، خالی.';
        foreach ([['120000', $below, 'the price itself'], ['150000', $below, 'above it'], ['1.5', $amount, 'a fraction'], ['abc', $amount, 'no amount']] as [$first, $why, $case]) {
            $errors = $this->decode($this->postJson('/api/admin/plans', $this->input(['name' => '', 'first_purchase_price' => $first])))['errors'] ?? [];

            self::assertSame(['name', 'first_purchase_price'], array_keys($errors), "{$case}: with the rest of the form");
            self::assertSame([$why], $errors['first_purchase_price'], $case);
        }
        $refused = $this->postJson('/api/admin/plans', $this->input(['price' => 'abc', 'first_purchase_price' => '50000']));
        self::assertSame(['price'], array_keys($this->decode($refused)['errors'] ?? []), 'a price refused itself: nothing said of the first-purchase price against it');
        self::assertSame(2, Plan::query()->count());
    }

    public function testAnAgentPriceIsWholeTomanBelowThePriceOrNone(): void
    {
        $plan = $this->decode($this->postJson('/api/admin/plans', $this->input(['agent_price' => '۱۰۰٬۰۰۰'])))['plan'];
        self::assertSame('100000.00', $plan['agent_price']);

        $put = fn(array $overrides): mixed => $this->decode($this->putJson("/api/admin/plans/{$plan['id']}", $this->input($overrides)))['plan']['agent_price'];
        self::assertSame('100000.00', $put(['name' => 'یک‌ماهه ویژه']), 'changed without it: its own stays');
        self::assertNull($put(['agent_price' => '']), 'blank: none');
        self::assertSame('0.00', $put(['agent_price' => '0']), 'free for them');
        self::assertNull($put(['agent_price' => null]));

        foreach ([['120000', 'قیمت نماینده باید کمتر از قیمت پلن باشد.'], ['1.5', 'قیمت نماینده را به تومان و بدون اعشار وارد کنید؛ برای نماینده رایگان 0 بگذارید، و برای هیچ، خالی.']] as [$agent, $why]) {
            $errors = $this->decode($this->postJson('/api/admin/plans', $this->input(['name' => '', 'agent_price' => $agent])))['errors'] ?? [];

            self::assertSame(['name' => $errors['name'] ?? null, 'agent_price' => [$why]], ['name' => $errors['name'] ?? null, 'agent_price' => $errors['agent_price'] ?? null]);
            self::assertSame(['name', 'agent_price'], array_keys($errors), 'with the rest of the form');
        }
    }

    public function testItsCheckoutNoteIsKeptAsWrittenOrNone(): void
    {
        $plan = $this->decode($this->postJson('/api/admin/plans', $this->input(['checkout_note' => " روی Happ تست شده است.\r\nبرای iOS هم. "])))['plan'];
        self::assertSame("روی Happ تست شده است.\nبرای iOS هم.", $plan['checkout_note'], 'its lines kept, trimmed');

        $put = fn(array $overrides): mixed => $this->decode($this->putJson("/api/admin/plans/{$plan['id']}", $this->input($overrides)))['plan']['checkout_note'];
        self::assertSame("روی Happ تست شده است.\nبرای iOS هم.", $put([]), 'changed without it: its own stays');
        self::assertNull($put(['checkout_note' => '  ']), 'blank: none');
        self::assertNull($this->decode($this->postJson('/api/admin/plans', $this->input(['name' => 'دوماهه'])))['plan']['checkout_note'], 'a new plan without it has none');

        $long = $this->postJson('/api/admin/plans', $this->input(['checkout_note' => str_repeat('ا', 501)]));
        self::assertSame(['checkout_note' => ['یادداشت پیش‌فاکتور حداکثر 500 کاراکتر است.']], $this->decode($long)['errors'] ?? null);
    }

    public function testItsButtonInTheBotHasAColourAndARowWidth(): void
    {
        $plan = $this->decode($this->postJson('/api/admin/plans', $this->input()))['plan'];
        self::assertSame([null, 1], [$plan['button_style'], $plan['button_row']], 'a new plan: the client\'s look, a row of its own');

        $put = fn(array $overrides): array => array_intersect_key($this->decode($this->putJson("/api/admin/plans/{$plan['id']}", $this->input($overrides)))['plan'], ['button_style' => 0, 'button_row' => 0]);
        self::assertSame(['button_style' => 'success', 'button_row' => 2], $put(['button_style' => 'success', 'button_row' => '۲']));
        self::assertSame(['button_style' => 'success', 'button_row' => 2], $put([]), 'changed without them: its own stay');
        self::assertSame(['button_style' => null, 'button_row' => 3], $put(['button_style' => '', 'button_row' => 3]));

        $refused = $this->unchecked()->postJson('/api/admin/plans', $this->input(['button_style' => 'purple', 'button_row' => 4]));
        self::assertSame([
            'button_style' => ['رنگ دکمه را از میان آبی، سبز و قرمز انتخاب کنید، یا پیش‌فرض تلگرام.'],
            'button_row' => ['جای دکمه یک ردیف کامل، دو یا سه دکمه در ردیف است.'],
        ], $this->decode($refused)['errors'] ?? null);
    }

    public function testWholeServerEntrySellsWhateverIsSelectableRightNow(): void
    {
        $plan = $this->decode($this->postJson('/api/admin/plans', $this->input()))['plan'];

        self::assertCount(1, $plan['servers']);
        self::assertSame('Berlin', $plan['servers'][0]['server']['name']);
        self::assertTrue($plan['servers'][0]['all_inbounds']);
        self::assertSame(['Reality'], array_column($plan['servers'][0]['inbounds'], 'remark'), 'only the selectable, enabled inbound');

        $this->trojan->forceFill(['is_selectable' => true])->save();
        $plan = $this->decode($this->get('/api/admin/plans'))['plans'][0];
        self::assertSame(['Reality', 'Trojan'], array_column($plan['servers'][0]['inbounds'], 'remark'), 'marking another inbound selectable adds it to the offer');
    }

    public function testPinnedInboundsMustBelongToTheirServerAndBeEnabled(): void
    {
        $response = $this->postJson('/api/admin/plans', $this->input(['servers' => [['server_id' => $this->paris->id, 'inbound_ids' => [$this->reality->id]]]]));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('متعلق به «Paris» نیست', $this->decode($response)['errors']['servers'][0]);

        $response = $this->postJson('/api/admin/plans', $this->input(['servers' => [['server_id' => $this->berlin->id, 'inbound_ids' => [$this->hidden->id]]]]));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('غیرفعال', $this->decode($response)['errors']['servers'][0]);

        $response = $this->postJson('/api/admin/plans', $this->input(['servers' => [['server_id' => $this->berlin->id, 'inbound_ids' => []]]]));
        self::assertSame(422, $response->getStatusCode());

        $response = $this->postJson('/api/admin/plans', $this->input(['servers' => [['server_id' => $this->berlin->id, 'all_inbounds' => true], ['server_id' => $this->berlin->id, 'inbound_ids' => [$this->reality->id]]]]));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('بیش از یک بار', $this->decode($response)['errors']['servers'][0]);
    }

    public function testAPinnedInboundThatIsNoIdIsRefusedNotReadAsOne(): void
    {
        foreach (["{$this->reality->id}abc", true, 1.5, ['id' => $this->reality->id]] as $sent) {
            $response = $this->unchecked()->postJson('/api/admin/plans', $this->input(['servers' => [['server_id' => $this->berlin->id, 'inbound_ids' => [$this->reality->id, $sent]]]]));

            self::assertSame(422, $response->getStatusCode(), (string) json_encode($sent));
            self::assertSame(['«Berlin»: شناسه اینباند انتخاب‌شده درست نیست.'], $this->decode($response)['errors']['servers'] ?? null);
        }
        self::assertSame(0, Plan::query()->count(), 'never sold on inbound 1 or the one its digits begin');
    }

    public function testAServerWithoutSubscriptionLinksMayBeAttachedButIsFlagged(): void
    {
        $this->paris->forceFill(['serves_subscriptions' => false])->save();

        $response = $this->postJson('/api/admin/plans', $this->input(['servers' => [['server_id' => $this->paris->id, 'all_inbounds' => true]]]));

        self::assertSame(201, $response->getStatusCode(), 'the admin may prepare the plan; the customer just will not see that server');
        self::assertFalse($this->decode($response)['plan']['servers'][0]['server']['serves_subscriptions']);

        $options = $this->decode($this->get('/api/admin/plans/options'));
        self::assertSame([true, false], array_column($options['servers'], 'serves_subscriptions'), 'and the form says so up front');
    }

    public function testSeveralServersWithMixedEntriesAndEditingThem(): void
    {
        $servers = [
            ['server_id' => $this->paris->id, 'all_inbounds' => true],
            ['server_id' => $this->berlin->id, 'inbound_ids' => [$this->trojan->id, $this->reality->id]],
        ];
        $plan = $this->decode($this->postJson('/api/admin/plans', $this->input(['servers' => $servers])))['plan'];

        self::assertSame(['Paris', 'Berlin'], array_column(array_column($plan['servers'], 'server'), 'name'), 'in the order given');
        self::assertSame([], $plan['servers'][0]['inbounds'], 'Paris has no inbounds yet, the entry still stands');
        self::assertSame(['Reality', 'Trojan'], array_column($plan['servers'][1]['inbounds'], 'remark'), 'pinned inbounds, panel order; a non-selectable inbound can be pinned explicitly');

        $response = $this->putJson("/api/admin/plans/{$plan['id']}", $this->input(['servers' => [['server_id' => $this->berlin->id, 'inbound_ids' => [$this->reality->id]]]]));
        self::assertSame(200, $response->getStatusCode());
        $plan = $this->decode($response)['plan'];
        self::assertCount(1, $plan['servers']);
        self::assertSame(['Reality'], array_column($plan['servers'][0]['inbounds'], 'remark'));
        self::assertSame(1, Plan::query()->find($plan['id'])?->servers()->count(), 'entries are replaced, not accumulated');
    }

    public function testListReorderToggleAndDuplicateKeepServers(): void
    {
        $a = $this->decode($this->postJson('/api/admin/plans', $this->input(['name' => 'A'])))['plan'];
        $b = $this->decode($this->postJson('/api/admin/plans', $this->input(['name' => 'B'])))['plan'];
        $c = $this->decode($this->postJson('/api/admin/plans', $this->input(['name' => 'C'])))['plan'];

        self::assertSame(['A', 'B', 'C'], array_column($this->decode($this->get('/api/admin/plans'))['plans'], 'name'));

        $data = $this->decode($this->postJson('/api/admin/plans/reorder', ['ids' => [$c['id'], $a['id']]]));
        self::assertSame(['C', 'A', 'B'], array_column($data['plans'], 'name'), 'unlisted plans follow the listed ones');

        $refused = $this->unchecked()->postJson('/api/admin/plans/reorder', ['ids' => []]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['message' => 'لیست شناسه‌ها خالی است.', 'errors' => ['ids' => ['لیست شناسه‌ها خالی است.']]], array_diff_key($this->decode($refused), ['request_id' => true, 'debug' => true]), 'an empty order is refused on its field, in its own words');

        $data = $this->decode($this->patchJson("/api/admin/plans/{$b['id']}", ['is_active' => false]));
        self::assertFalse($data['plan']['is_active']);

        $copy = $this->decode($this->postJson("/api/admin/plans/{$a['id']}/duplicate"))['plan'];
        self::assertSame('A (کپی)', $copy['name']);
        self::assertFalse($copy['is_active']);
        self::assertSame('Berlin', $copy['servers'][0]['server']['name'], 'the copy sells on the same servers');
        self::assertSame(['C', 'A', 'A (کپی)', 'B'], array_column($this->decode($this->get('/api/admin/plans'))['plans'], 'name'), 'the copy sits right after the original');
    }

    public function testTheSwitchTakesOnOrOffAndNothingElse(): void
    {
        $plan = $this->plan(['name' => 'یک‌ماهه'], $this->berlin);

        foreach ([['is_active' => 'maybe'], ['is_active' => 2], []] as $body) {
            $response = $this->unchecked()->patchJson("/api/admin/plans/{$plan->id}", $body);
            self::assertSame(422, $response->getStatusCode(), (string) json_encode($body));
            self::assertSame([Validation::NOT_A_SWITCH], $this->decode($response)['errors']['is_active']);
        }
        self::assertTrue($plan->refresh()->is_active, 'a refused switch changes nothing');

        self::assertFalse($this->decode($this->unchecked()->patchJson("/api/admin/plans/{$plan->id}", ['is_active' => '0']))['plan']['is_active'], 'a form-style 0 is off too');
    }

    public function testTheSalesCountIsTheOrdersWhoseMoneyIsIn(): void
    {
        $plan = $this->plan(['name' => 'یک‌ماهه'], $this->berlin);
        foreach ([OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Fulfilled, OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::Refunded] as $status) {
            $this->purchaseOrder($this->customer, $plan, $this->berlin, ['status' => $status]);
        }

        self::assertSame(3, $this->decode($this->get('/api/admin/plans'))['plans'][0]['counts']['sales'], 'paid, processing, fulfilled');
        $one = $this->patchJson("/api/admin/plans/{$plan->id}", ['is_active' => true]);
        self::assertSame(3, $this->decode($one)['plan']['counts']['sales'], 'the same figure when one plan is presented on its own');
    }

    public function testDeleteIsRefusedOncePlanHasHistory(): void
    {
        $plan = $this->plan([], $this->berlin);
        $this->purchaseOrder($this->customer, $plan, $this->berlin, ['status' => OrderStatus::Paid]);

        $response = $this->deleteJson("/api/admin/plans/{$plan->id}");
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('غیرفعال', $this->decode($response)['message']);

        // What the list says of it is the rule the delete keeps: any order, any service — none sold, none running.
        $abandoned = $this->plan(['name' => 'رها شده'], $this->berlin);
        $this->purchaseOrder($this->customer, $abandoned, $this->berlin, ['status' => OrderStatus::Cancelled]);
        $ended = $this->plan(['name' => 'تمام شده'], $this->berlin);
        $this->subscription($this->customer, $ended, $this->berlin, 'ended_1', ['status' => SubscriptionStatus::Expired]);
        $counts = array_column($this->decode($this->get('/api/admin/plans'))['plans'], 'counts', 'name');
        self::assertSame(['active_subscriptions' => 0, 'sales' => 0, 'orders' => 1, 'subscriptions' => 0], $counts['رها شده']);
        self::assertSame(['active_subscriptions' => 0, 'sales' => 0, 'orders' => 0, 'subscriptions' => 1], $counts['تمام شده']);
        self::assertSame(409, $this->deleteJson("/api/admin/plans/{$abandoned->id}")->getStatusCode());
        self::assertSame(409, $this->deleteJson("/api/admin/plans/{$ended->id}")->getStatusCode());

        $fresh = $this->decode($this->postJson('/api/admin/plans', $this->input(['name' => 'Fresh'])))['plan'];
        self::assertSame(204, $this->deleteJson("/api/admin/plans/{$fresh['id']}")->getStatusCode());
        self::assertNull(Plan::query()->find($fresh['id']));
        self::assertSame(0, $this->db()->table('plan_servers')->where('plan_id', $fresh['id'])->count(), 'entries go with the plan');
    }

    public function testAnOrderOpenedAsThePlanIsDeletedKeepsIt(): void
    {
        $plan = $this->plan([], $this->berlin);
        $order = null;
        $statements = [];
        $delete = function () use ($plan, &$statements): void {
            $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
                $statements[] = $query->sql;
            }, fn() => self::assertSame(409, $this->deleteJson("/api/admin/plans/{$plan->id}")->getStatusCode()));
        };
        $this->whileListening('eloquent.retrieved: ' . Plan::class, function () use ($plan, &$order): void {
            // A customer orders it the moment the delete has read it.
            $order ??= $this->purchaseOrder($this->customer, $plan, $this->berlin);
        }, $delete);

        self::assertNotNull(Plan::query()->find($plan->id));
        self::assertSame($plan->id, $order?->refresh()->plan_id, 'the order keeps its plan');
        $deleting = array_key_first(array_filter($statements, static fn(string $sql): bool => preg_match('/^delete from\W+' . self::TABLE_PREFIX . 'plans\W/i', $sql) === 1));
        self::assertNotNull($deleting);
        self::assertSame([], array_filter(array_slice($statements, 0, (int) $deleting), static fn(string $sql): bool => preg_match('/^select\W.*\W' . self::TABLE_PREFIX . '(orders|subscriptions)\W/is', $sql) === 1), 'nothing asked of its orders before the statement that decides');
    }

    public function testOptionsListServersWithTheirInbounds(): void
    {
        $data = $this->decode($this->get('/api/admin/plans/options'));

        self::assertSame(['Berlin', 'Paris'], array_column($data['servers'], 'name'));
        self::assertSame('3X-UI', $data['servers'][0]['driver_label']);
        self::assertSame([443, 8443, 2083], array_column($data['servers'][0]['inbounds'], 'port'));
        self::assertFalse($data['servers'][0]['inbounds'][2]['enabled']);
    }
}
