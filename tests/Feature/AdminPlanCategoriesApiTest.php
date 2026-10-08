<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Support\Validation;
use Tests\HttpTestCase;

/**
 * Plan categories: their own screen under فروشگاه, and the category a plan is filed under.
 */
final class AdminPlanCategoriesApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testCategoriesAreCreatedValidatedListedAndOrdered(): void
    {
        $response = $this->postJson('/api/admin/plans/categories', ['name' => ' ماهانه ']);
        self::assertSame(201, $response->getStatusCode());
        $monthly = $this->decode($response)['category'];
        self::assertSame('ماهانه', $monthly['name']);
        self::assertTrue($monthly['is_active']);
        self::assertSame(1, $monthly['sort']);

        $response = $this->postJson('/api/admin/plans/categories', ['name' => 'ماهانه']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('وجود دارد', $this->decode($response)['errors']['name'][0]);
        self::assertSame(422, $this->postJson('/api/admin/plans/categories', ['name' => ''])->getStatusCode());

        $yearly = $this->decode($this->postJson('/api/admin/plans/categories', ['name' => 'سالانه', 'is_active' => false]))['category'];
        self::assertFalse($yearly['is_active']);

        $list = $this->decode($this->get('/api/admin/plans/categories'))['categories'];
        self::assertSame(['ماهانه', 'سالانه'], array_column($list, 'name'));

        $response = $this->postJson('/api/admin/plans/categories/reorder', ['ids' => [$yearly['id'], $monthly['id']]]);
        self::assertSame(['سالانه', 'ماهانه'], array_column($this->decode($response)['categories'], 'name'));

        $response = $this->putJson("/api/admin/plans/categories/{$monthly['id']}", ['name' => 'یک‌ماهه']);
        self::assertSame('یک‌ماهه', $this->decode($response)['category']['name']);
        $response = $this->putJson("/api/admin/plans/categories/{$yearly['id']}", ['name' => 'یک‌ماهه']);
        self::assertSame(422, $response->getStatusCode(), 'a rename onto a taken name too');
        $theirs = CurrentBot::run($this->agentBot($this->agent()), fn(): PlanCategory => $this->service(PlanCategoryService::class)->create(['name' => 'یک‌ماهه']));
        self::assertSame('یک‌ماهه', $theirs->name, 'a name is unique in its shop, not across shops');

        $response = $this->patchJson("/api/admin/plans/categories/{$monthly['id']}", ['is_active' => false]);
        self::assertFalse($this->decode($response)['category']['is_active']);
    }

    public function testTheSwitchTakesOnOrOffAndNothingElse(): void
    {
        $category = $this->category();

        foreach ([['is_active' => 'maybe'], ['is_active' => null], []] as $body) {
            $response = $this->unchecked()->patchJson("/api/admin/plans/categories/{$category->id}", $body);
            self::assertSame(422, $response->getStatusCode(), (string) json_encode($body));
            self::assertSame([Validation::NOT_A_SWITCH], $this->decode($response)['errors']['is_active']);
        }
        self::assertTrue($category->refresh()->is_active, 'a refused switch changes nothing');

        self::assertFalse($this->decode($this->unchecked()->patchJson("/api/admin/plans/categories/{$category->id}", ['is_active' => 'false']))['category']['is_active'], 'a form-style "false" is off too');
    }

    public function testAPlanIsFiledUnderACategoryAndSurvivesItsDeletion(): void
    {
        $category = $this->category();
        $server = $this->panelServer('Berlin', ['serves_subscriptions' => true]);

        $payload = ['name' => 'یک‌ماهه ۱۰ گیگ', 'price' => '50000', 'duration_days' => 30, 'traffic_gb' => 10, 'ip_limit' => 1, 'servers' => [['server_id' => $server->id, 'all_inbounds' => true, 'inbound_ids' => []]]];

        $response = $this->postJson('/api/admin/plans', $payload + ['category_id' => 999]);
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('category_id', $this->decode($response)['errors']);

        $response = $this->postJson('/api/admin/plans', $payload + ['category_id' => $category->id]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $plan = $this->decode($response)['plan'];
        self::assertSame(['id' => $category->id, 'name' => 'اقتصادی', 'is_active' => true], $plan['category']);

        $options = $this->decode($this->get('/api/admin/plans/options'));
        self::assertSame(['اقتصادی'], array_column($options['categories'], 'name'));

        self::assertSame(1, $this->decode($this->get('/api/admin/plans/categories'))['categories'][0]['counts']['plans']);

        $response = $this->putJson("/api/admin/plans/{$plan['id']}", $payload + ['category_id' => '']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->decode($response)['plan']['category'], 'blank = no category');

        Plan::query()->whereKey($plan['id'])->update(['category_id' => $category->id]);
        $response = $this->deleteJson("/api/admin/plans/categories/{$category->id}");
        self::assertSame(204, $response->getStatusCode());
        self::assertNull(Plan::query()->findOrFail($plan['id'])->category_id, 'the plan stays, uncategorised');
    }
}
