<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\Tickets;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Tests\HttpTestCase;

/**
 * A screen that lists or counts asks the same few queries however many rows there are: each row's relations come with
 * its page (eager loading — a relation read row by row fails every test, see TestCase), a tally comes from one grouped
 * query, never one a row. Every such screen of the owner's panel — and every read of a shop's website, its catalogue
 * and a signed-in customer's own — is read with a few rows of everything, then with many more, and must not ask more.
 */
final class ListQueriesTest extends HttpTestCase
{
    private int $made = 0;

    private ?User $referrer = null;

    private ?PaymentMethod $card = null;

    /** The first customer's first ticket, whose conversation grows with the rows. */
    private ?Ticket $conversation = null;

    public function testTheScreensAskNoMoreForMoreRows(): void
    {
        $this->telegram();
        $this->loginAsAdmin();
        $this->referralProgram();
        // The shop sells extras: what each of a customer's services may buy is judged with its page.
        $this->extrasOnSale();
        $website = $this->website(['reviews_enabled' => true]);
        $this->rows(2);
        $referrer = $this->referrer ?? self::fail('No customer was made.');
        // The website's reads are the first customer's, signed in: their services, orders and wallet lines grow with the rows.
        $this->bearer($this->customerSession($referrer));
        $plan = Plan::active()->value('id');
        $service = Subscription::query()->where('user_id', $referrer->id)->value('id');
        $order = Order::query()->where('user_id', $referrer->id)->value('id');
        $ticket = ($this->conversation ?? self::fail('No ticket was made.'))->id;
        $server = Server::query()->oldest('id')->value('id');

        $screens = [
            '/api/admin/users',
            "/api/admin/users/{$referrer->id}",
            "/api/admin/users/{$referrer->id}/wallet",
            '/api/admin/customer-groups',
            '/api/admin/orders',
            '/api/admin/payments',
            '/api/admin/subscriptions',
            '/api/admin/plans',
            '/api/admin/plans/options',
            '/api/admin/servers',
            "/api/admin/servers/{$server}/grants",
            '/api/admin/mass-grants',
            '/api/admin/payment-methods',
            '/api/admin/referrals',
            '/api/admin/referrals/referrers',
            '/api/admin/referrals/invitees',
            '/api/admin/referrals/commissions',
            '/api/admin/referrals/alerts',
            '/api/admin/agency',
            '/api/admin/agency/requests',
            '/api/admin/agency/agents',
            '/api/admin/dashboard',
            '/api/admin/queues',
            '/api/admin/tickets',
            "/api/admin/tickets/{$ticket}",
            '/api/admin/reviews',
            '/api/admin/gift-codes',
            '/api/admin/gift-codes/batches',
            '/api/admin/wheel/prizes',
            '/api/admin/wheel/spins',
            '/api/admin/loyalty/tiers',
            '/api/admin/cashback/rules',
            '/api/admin/lottery/draws',
            '/api/admin/discount-codes',
            '/api/admin/sales',
            $this->storeApi($website, '/plans'),
            $this->storeApi($website, "/plans/{$plan}"),
            $this->storeApi($website, '/status'),
            $this->storeApi($website, '/subscriptions'),
            $this->storeApi($website, "/subscriptions/{$service}"),
            $this->storeApi($website, "/subscriptions/{$service}/renewal"),
            $this->storeApi($website, "/subscriptions/{$service}/extras"),
            $this->storeApi($website, '/payment-methods?for=purchase'),
            $this->storeApi($website, '/payment-methods?for=extra_traffic'),
            $this->storeApi($website, '/orders'),
            $this->storeApi($website, "/orders/{$order}"),
            $this->storeApi($website, '/wallet'),
            $this->storeApi($website, '/wallet/transactions'),
            $this->storeApi($website, '/referral'),
            $this->storeApi($website, '/notifications'),
            $this->storeApi($website, '/me'),
            $this->storeApi($website, '/tickets'),
            $this->storeApi($website, "/tickets/{$ticket}"),
            $this->storeApi($website, '/reviews'),
        ];
        $few = [];
        foreach ($screens as $path) {
            $few[$path] = $this->queriesOf($path);
        }

        $this->rows(6);

        foreach ($few as $path => $queries) {
            $more = $this->queriesOf($path);
            self::assertLessThanOrEqual(count($queries), count($more), "{$path} asks more for more rows:\n" . implode("\n", $more));
        }
    }

    /**
     * `$count` more of everything: a customer — brought by the first one — in a group of their own, who bought a plan on a
     * server of its own by card (the order, the payment, the service, the commission), asked to become an agent, opened
     * a ticket about their service and wrote a review (approved); an agent; a guest's review waiting on support; and the
     * first customer's own purchase of that plan — the notice of it —, a line of their wallet, a ticket of theirs and
     * support's answer in their first.
     */
    private function rows(int $count): void
    {
        $this->card ??= $this->cardMethod();
        foreach (range(1, $count) as $ignored) {
            $n = ++$this->made;
            $customer = $this->customer(['telegram_id' => 50_000 + $n, 'username' => "customer{$n}", 'referred_by' => $this->referrer?->id]);
            $this->referrer ??= $customer;
            $this->customerGroup("گروه {$n}", [$customer]);
            $server = $this->sellingServer("سرور {$n}");
            $plan = $this->plan(['name' => "پلن {$n}"], $server);
            $this->paidByCard($this->purchaseOrder($customer, $plan, $server), $this->card);
            $this->wallet($customer, (string) (10_000 * $n));
            $this->agencyRequest($customer);
            // An alert on them as a referrer: the referrers list marks them, the alerts list names them.
            $this->referralAlert($customer);
            $this->agent(overrides: ['telegram_id' => 90_000 + $n, 'username' => "agent{$n}"]);
            $this->service(CustomerNotifier::class)->paymentSettled($this->paidByCard($this->purchaseOrder($this->referrer, $plan, $server), $this->card));
            $this->wallet($this->referrer, (string) (20_000 * $n));
            $this->ticket($customer, "تیکت {$n}", overrides: ['subscription_id' => Subscription::query()->where('user_id', $customer->id)->value('id')]);
            $this->review($customer, "مشتری {$n}", overrides: ['status' => ReviewStatus::Approved, 'reviewer' => 'root', 'decided_at' => now()]);
            $this->review(null, "مهمان {$n}");
            // A gift code of a batch of its own their plan's service came of, a wheel slice of the plan, their spin of it.
            $bought = Order::query()->where('user_id', $customer->id)->latest('id')->firstOrFail();
            $this->giftCode("LIST{$n}CODEAB", ['batch' => "سری {$n}", 'kind' => GiftCodeKind::Plan, 'amount' => null, 'plan_id' => $plan->id, 'redeemed_by' => $customer->id, 'redeemed_at' => now(), 'order_id' => $bought->id]);
            $this->wheelPrize("جایزه {$n}", ['kind' => RewardKind::Plan, 'amount' => null, 'plan_id' => $plan->id]);
            $this->wheelSpin($customer, ['kind' => RewardKind::Plan, 'label' => "جایزه {$n}", 'amount' => null, 'order_id' => $bought->id, 'subscription_id' => $bought->subscription_id]);
            // A loyalty tier, a cashback rule for their group and the card, and a night's draw of a plan they won.
            $this->loyaltyTier("سطح {$n}", (string) (1_000 * $n));
            $this->cashbackRule(5, ['customer_group_id' => CustomerGroup::query()->latest('id')->value('id'), 'payment_method_id' => $this->card->id]);
            $draw = LotteryDraw::query()->create(['day' => sprintf('2026-01-%02d', $n), 'entries' => $n, 'prize_kind' => RewardKind::Plan, 'prize_plan_id' => $plan->id]);
            LotteryWinner::query()->create(['lottery_draw_id' => $draw->id, 'user_id' => $customer->id, 'outcome' => 'سرویس', 'order_id' => $bought->id, 'subscription_id' => $bought->subscription_id]);
            // A discount code for their plan and server, a purchase of the plan made with it, and a sale on the plan.
            $code = $this->discountCode("LIST{$n}", 10, ['plan_ids' => [$plan->id], 'server_ids' => [$server->id], 'max_uses' => 10]);
            $this->purchaseOrder($customer, $plan, $server, ['discount_code_id' => $code->id, 'discount_amount' => '12000.00', 'discounts' => [['source' => 'code', 'label' => "کد LIST{$n}", 'amount' => '12000.00', 'percent' => 10, 'id' => $code->id, 'code' => "LIST{$n}"]]]);
            $this->sale("حراج {$n}", 5, ['plan_ids' => [$plan->id]]);
            $this->conversation ??= $this->ticket($this->referrer, 'سوال اول');
            $this->ticket($this->referrer, "سوال {$n}");
            $this->service(Tickets::class)->answer($this->conversation->refresh(), $this->panelActor(), ['body' => "پاسخ {$n}"], null);
        }
    }

    /** @return list<string> The statements GET `$path` ran */
    private function queriesOf(string $path): array
    {
        // The change feed counts what the setup wrote before the next statement: that one is not the screen's.
        $this->db()->select('select 1');
        $queries = [];
        $response = $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        }, fn() => $this->get($path));
        self::assertSame(200, $response->getStatusCode(), $path);

        return $queries;
    }
}
