<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Models\Cashback;
use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Users\Models\User;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
use Illuminate\Database\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * Rows written outside AmoBot — imported, or added by hand in the database — may have no `created_at` or `updated_at`
 * (the columns allow none). Every page of the owner's panel and every answer of the shop's website still shows them,
 * their times null as the API description says, never a server error: each GET operation the description has is
 * called on a shop of most things, once as it is and again with every row's times taken away.
 */
final class RowsWithoutTimesTest extends HttpTestCase
{
    public function testEveryPanelPageShowsRowsWithoutTimes(): void
    {
        $this->loginAsAdmin();
        $this->shop();

        $this->assertEveryPageAnswers($this->pages(static fn(string $path): ?string => match (true) {
            str_starts_with($path, '/api/admin/') => $path,
            str_starts_with($path, '/api/{panel}/') => str_replace('{panel}', 'admin', $path),
            default => null,
        }));
    }

    public function testEveryWebsiteAnswerShowsRowsWithoutTimes(): void
    {
        $customer = $this->shop();
        $website = $this->website();
        $token = $this->customerSession($customer);
        $this->bearer($token);

        $this->assertEveryPageAnswers($this->pages(static fn(string $path): ?string => str_starts_with($path, '/api/store/v1/{store}') && !str_contains($path, '/admin/')
            ? str_replace('{store}', $website->key, $path)
            : null), $token);
    }

    /**
     * Takes every row's times away, and each page that answered with them answers without them too, as the description
     * says (a page that needs what this shop lacks, a receipt's photo from Telegram, is no page of this test).
     *
     * @param list<string> $pages
     * @param string|null $token The website customer's session, for the website's pages
     */
    private function assertEveryPageAnswers(array $pages, ?string $token = null): void
    {
        $answered = array_values(array_filter($pages, fn(string $path): bool => $this->answered($path, $token) < 300));
        self::assertNotEmpty($answered, 'the shop has pages to show');

        foreach ($this->db()->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            $times = array_values(array_intersect(['created_at', 'updated_at'], array_column($this->db()->select("PRAGMA table_info(\"{$table->name}\")"), 'name')));
            if ($times !== []) {
                // The name as the database has it, its prefix in it already.
                $this->db()->withoutTablePrefix(static fn(Connection $db) => $db->table($table->name)->update(array_fill_keys($times, null)));
            }
        }

        foreach ($answered as $path) {
            $response = $this->get($path);
            self::assertLessThan(300, $response->getStatusCode(), "GET {$path}: " . $response->getBody());
        }
    }

    /**
     * A shop of most things, for its customer (returned): their wallet, orders and payments, services, a ticket, a review,
     * an agent and their request, the catalogue, the payment methods, the rewards and the discounts.
     */
    private function shop(): User
    {
        $ali = $this->wallet($this->customer(['telegram_id' => 1001, 'first_name' => 'Ali', 'username' => 'ali_r', 'phone' => '+989120000001']), '50000.00');
        $nameless = $this->customer(['telegram_id' => 1003, 'first_name' => null]);
        $this->admin();
        $server = $this->fakeServer();
        $this->inbound($server, '1');
        $plan = $this->plan(['category_id' => $this->category()->id], $server);
        $card = $this->cardMethod();
        $this->paymentCard($card);
        $this->walletMethod();
        $this->zarinpalMethod();
        $order = $this->purchaseOrder($ali, $plan, $server);
        $this->receipt($this->cardPayment($order, $card), 'note');
        $this->topUpOrder($ali, '10000.00');
        $this->subscription($ali, $plan, $server, 'USER_1');
        $this->claimedSubscription($nameless, $server, 'CLAIMED_1');
        $this->ticketMessages($this->ticket($ali), 2);
        $this->review($ali);
        $this->agentBot($this->agent($this->agencyLevel(), '1000.00', ['telegram_id' => 5252]));
        $this->agencyRequest($nameless);
        $this->customerGroup('VIP', [$ali]);
        $this->giftCode();
        $this->wheelPrize();
        $this->loyaltyTier();
        $this->cashbackRule();
        $this->discountCode();
        $this->sale();
        $this->referralAlert($ali);
        $this->channel(-1001234, 'کانال');
        TestAccount::query()->create(['user_id' => $ali->id, 'server_id' => $server->id, 'traffic_bytes' => 1, 'duration_hours' => 1, 'on_hold' => true, 'issued_at' => now()]);
        Cashback::query()->create(['order_id' => $order->id, 'user_id' => $ali->id, 'percent' => 10, 'amount' => '1000']);
        $draw = LotteryDraw::query()->create(['day' => '2026-09-12', 'entries' => 1, 'prize_kind' => RewardKind::Wallet, 'prize_amount' => '5000']);
        LotteryWinner::query()->create(['lottery_draw_id' => $draw->id, 'user_id' => $ali->id, 'outcome' => 'جایزه']);

        return $ali;
    }

    /**
     * The path of every GET operation `$panel` keeps (null: not one of its), but one that needs a query parameter (a
     * batch's unused codes): each {id} one of the first two rows of the table its segment names (`/payment-methods/{id}`:
     * payment_methods), or 1 where there is no such table.
     *
     * @param \Closure(string): ?string $panel
     * @return list<string>
     */
    private function pages(\Closure $panel): array
    {
        $pages = [];
        foreach (self::apiDescription()->paths as $template => $item) {
            assert($item instanceof PathItem);
            $path = $panel((string) $template);
            if ($path !== null && $item->get instanceof Operation && !self::asksQuery($item, $item->get)) {
                array_push($pages, ...$this->filled($path));
            }
        }

        return $pages;
    }

    /** Whether the operation needs a query parameter, which this test does not know how to fill. */
    private static function asksQuery(PathItem $item, Operation $operation): bool
    {
        foreach ([...$item->parameters, ...$operation->parameters] as $parameter) {
            if ($parameter instanceof Parameter && $parameter->in === 'query' && $parameter->required) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function filled(string $path): array
    {
        if (preg_match('/\{[^}]+\}/', $path, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [$path];
        }
        [$parameter, $at] = $match[0];
        $before = rtrim(substr($path, 0, $at), '/');
        $table = str_replace('-', '_', substr($before, strrpos($before, '/') + 1));
        $ids = $this->db()->getSchemaBuilder()->hasTable($table) ? $this->db()->table($table)->orderBy('id')->limit(2)->pluck('id')->all() : [];

        $paths = [];
        foreach ($ids === [] ? [1] : $ids as $id) {
            array_push($paths, ...$this->filled(substr($path, 0, $at) . $id . substr($path, $at + strlen($parameter))));
        }

        return $paths;
    }

    /** The status `$path` answers, unchecked (a page this test cannot show may answer what its description does not say). */
    private function answered(string $path, ?string $token): int
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path)->withHeader('Accept', 'application/json');
        if ($token !== null) {
            $request = $request->withHeader('Authorization', "Bearer {$token}");
        }

        return $this->app()->http()->handle($request)->getStatusCode();
    }
}
