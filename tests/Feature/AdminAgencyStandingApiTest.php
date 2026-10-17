<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\DTO\SellingTerms;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The owner's say over an agent's terms of selling (AgencyStanding) on the agents page — an end and a monthly minimum on
 * approval (the program's by default) and on a change, a suspension by hand and its lifting — and what the agent's panel
 * and their shop's website make of a suspension: the account, the dashboard and the session say why; the website takes
 * no order (what only prices one, a renewal's preview or a code checked, refuses on `status`), while a receipt for one
 * made before is still taken.
 */
final class AdminAgencyStandingApiTest extends HttpTestCase
{
    private AgencyLevel $gold;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->telegram();
        $this->agencyProgram();
        $this->gold = $this->agencyLevel();
    }

    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testAnApprovalGivesTheProgramsTermsOrTheOnesTyped(): void
    {
        $this->loginAsAdmin();
        $settings = $this->service(AgencySettings::class);
        $settings->save(['term_days' => 30, 'monthly_minimum' => '1000000'] + $settings->present());
        $ali = $this->customer();
        $reza = $this->customer(['telegram_id' => 900_001]);

        $approved = $this->postJson('/api/admin/agency/requests/' . $this->agencyRequest($ali)->id . '/approve', ['level_id' => $this->gold->id, 'credit_limit' => '0']);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $bot = $ali->refresh()->ownBot ?? self::fail('no shop');
        self::assertSame(['2026-10-31 12:00:00', '1000000.00', '1405-07'], [$bot->agency_expires_at?->format('Y-m-d H:i:s'), $bot->monthly_minimum, $bot->minimum_checked_month], "the program's: thirty days, and this month not judged");
        self::assertSame([self::text(BotText::AgencyApproved, ['level' => 'طلایی', 'price' => '۳٬۰۰۰ تومان', 'credit' => Messages::credit('0')]) . "\n\n"
            . self::text(BotText::AgencyExpiryLine, ['date' => Persian::date(Carbon::parse('2026-10-31 12:00:00'), withTime: true)]) . "\n"
            . self::text(BotText::AgencyMinimumLine, ['minimum' => Money::format('1000000')])], $this->telegram()->sentTo(self::TELEGRAM_ID), 'told their terms of selling too');

        $typed = $this->postJson('/api/admin/agency/requests/' . $this->agencyRequest($reza)->id . '/approve', ['level_id' => $this->gold->id, 'credit_limit' => '0', 'expires_at' => '2026-12-01T18:00', 'monthly_minimum' => '0']);
        self::assertSame(200, $typed->getStatusCode(), (string) $typed->getBody());
        $bot = $reza->refresh()->ownBot ?? self::fail('no shop');
        self::assertSame(['2026-12-01 18:00:00', null], [$bot->agency_expires_at?->format('Y-m-d H:i:s'), $bot->monthly_minimum], 'as typed: an end, no minimum (0)');
    }

    public function testAnApprovalWhoseProgramTermWouldEndPastTheLastDayTheShopKeepsIsRefused(): void
    {
        // The program's 30 days, 20 days before the TIMESTAMP columns' last moment (MySQL refuses a later one).
        Carbon::setTestNow(LocalTime::last()->subDays(20));
        $this->loginAsAdmin();
        $settings = $this->service(AgencySettings::class);
        $settings->save(['term_days' => 30] + $settings->present());
        $ali = $this->customer();
        $request = $this->agencyRequest($ali);
        $refusal = LocalTime::tooLateFor('تاریخ پایان نمایندگی') . '؛ مدت نمایندگی را در تنظیمات نمایندگی کوتاه‌تر کنید یا تاریخ پایان را خودتان بنویسید.';

        foreach (['nothing of the terms sent' => [], 'only the minimum sent' => ['monthly_minimum' => '0']] as $case => $terms) {
            $refused = $this->postJson("/api/admin/agency/requests/{$request->id}/approve", ['level_id' => $this->gold->id, 'credit_limit' => '0'] + $terms);
            self::assertSame([422, ['expires_at' => [$refusal]]], [$refused->getStatusCode(), $this->decode($refused)['errors']], $case);
        }
        try {
            $this->service(AgencyActions::class)->approve($request, $this->panelActor(), $this->gold, null);
            self::fail('Approved from the report group.');
        } catch (ValidationException $e) {
            self::assertSame(['expires_at' => [$refusal]], $e->errors());
        }
        self::assertNull($ali->refresh()->ownBot, 'no shop opened');

        $typed = $this->postJson("/api/admin/agency/requests/{$request->id}/approve", ['level_id' => $this->gold->id, 'credit_limit' => '0', 'expires_at' => '']);
        self::assertSame(200, $typed->getStatusCode(), 'no end typed: approved');
    }

    public function testTheProgramsEndIsKeptToTheShopsHourSoARowSavedAsItIsChangesNothing(): void
    {
        LocalTime::use('Asia/Tehran');
        Carbon::setTestNow('2026-10-01 12:34:56'); // 16:04 in Tehran
        $settings = $this->service(AgencySettings::class);
        $settings->save(['term_days' => 30] + $settings->present());
        $ali = $this->customer();

        // Approved from the report group: the program's terms as they are.
        $this->service(AgencyActions::class)->approve($this->agencyRequest($ali), $this->panelActor(), $this->gold, null);
        $bot = $ali->refresh()->ownBot ?? self::fail('no shop');
        self::assertSame('2026-10-31 12:30:00', $bot->agency_expires_at?->format('Y-m-d H:i:s'), "16:00 on the shop's clock, as the agents page keeps an end");

        // The agents page sends the end back as it shows it: nothing changed, nobody told, the warning kept.
        $this->loginAsAdmin();
        $saved = $this->decode($this->putJson("/api/admin/agency/agents/{$ali->id}", ['level_id' => $this->gold->id, 'credit_limit' => '0', 'expires_at' => '2026-10-31T16:00', 'monthly_minimum' => '0']));
        self::assertNull($saved['delivery']);

        $errors = [];
        SellingTerms::fromInput(['expires_at' => 20261031], SellingTerms::of($bot), $errors);
        self::assertSame(['expires_at'], array_keys($errors), 'no text is no moment — never "no end"');
    }

    public function testAReturningAgentStartsTheirTermsOfSellingAfresh(): void
    {
        $agent = $this->agent($this->gold, overrides: ['telegram_id' => self::AGENT_TELEGRAM_ID]);
        $bot = $this->agentBot($agent);
        $this->sellingTerms($bot, Carbon::now()->addDay(), '500000');
        $standing = $this->service(AgencyStanding::class);
        $standing->judge($bot->refresh(), Carbon::now());
        $standing->suspendByHand($bot, null);
        self::assertNotNull($bot->refresh()->expiry_warned_at);
        $this->service(AgencyActions::class)->revoke($agent, null);

        $this->service(AgencyActions::class)->approve($this->agencyRequest($agent->refresh()), $this->panelActor(), $this->gold, null);

        $bot->refresh();
        self::assertSame([null, null, null, null, null, null], [$bot->agency_expires_at, $bot->expiry_warned_at, $bot->monthly_minimum, $bot->minimum_warned_month, $bot->selling_suspended_at, $bot->suspension_reason], "the program's terms (none), no suspension, no warning of before");
        self::assertNull($bot->sellingSuspension());
    }

    public function testTheOwnerChangesAnAgentsTermsAndTheyAreTold(): void
    {
        $this->loginAsAdmin();
        $agent = $this->agent($this->gold, overrides: ['telegram_id' => self::AGENT_TELEGRAM_ID]);
        $this->agentBot($agent);
        $terms = ['level_id' => $this->gold->id, 'credit_limit' => '0'];

        $refused = $this->putJson("/api/admin/agency/agents/{$agent->id}", $terms + ['expires_at' => '2026-02-30T10:00', 'monthly_minimum' => '-5']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['expires_at', 'monthly_minimum'], array_keys($this->decode($refused)['errors']), 'no such day; below zero');
        $tooLate = $this->putJson("/api/admin/agency/agents/{$agent->id}", $terms + ['expires_at' => '2040-01-01T00:00']);
        self::assertSame(
            [422, ['expires_at' => [LocalTime::tooLateFor('تاریخ پایان نمایندگی') . '؛ برای بدون پایان، خالی بگذارید.']]],
            [$tooLate->getStatusCode(), $this->decode($tooLate)['errors']],
            'past the last day the database keeps (MySQL\'s TIMESTAMP ends in 2038): refused, never a 500',
        );
        // None is 0, as in the program's own field — whose words a blank one gets.
        $blank = $this->decode($this->putJson("/api/admin/agency/agents/{$agent->id}", $terms + ['monthly_minimum' => '']))['errors'] ?? [];
        $program = $this->decode($this->putJson('/api/admin/agency/settings', ['monthly_minimum' => ''] + $this->service(AgencySettings::class)->present()))['errors'] ?? [];
        self::assertSame(['monthly_minimum' => $program['monthly_minimum'] ?? self::fail('the program took a blank minimum')], $blank, 'one field');

        $changed = $this->putJson("/api/admin/agency/agents/{$agent->id}", $terms + ['expires_at' => '2026-11-01T00:00', 'monthly_minimum' => '۵۰۰٬۰۰۰']);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        $row = $this->decode($changed);
        self::assertSame('told', $row['delivery'], 'only their terms of selling changed: still told');
        self::assertSame(['2026-11-01T00:00:00+00:00', '500000.00', false, null], [$row['agent']['standing']['expires_at'], $row['agent']['standing']['monthly_minimum'], $row['agent']['standing']['month']['judged'], $row['agent']['standing']['suspension']]);

        $kept = $this->decode($this->putJson("/api/admin/agency/agents/{$agent->id}", $terms));
        self::assertNull($kept['delivery'], 'left out: kept, nothing changed');
        self::assertSame('500000.00', $kept['agent']['standing']['monthly_minimum']);

        $past = $this->decode($this->putJson("/api/admin/agency/agents/{$agent->id}", $terms + ['expires_at' => '2026-09-30T12:00']));
        self::assertSame('expired', $past['agent']['standing']['suspension']['reason'] ?? null, 'an end already passed suspends at once');
        self::assertContains(self::text(BotText::AgencySuspended, ['reason' => SellingSuspension::Expired->words(), 'note' => '']), $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));

        $never = $this->decode($this->putJson("/api/admin/agency/agents/{$agent->id}", $terms + ['expires_at' => null]));
        self::assertSame([null, null], [$never['agent']['standing']['expires_at'], $never['agent']['standing']['suspension']], 'no end: it sells again');
    }

    public function testTheOwnerSuspendsAndResumesAnAgentsSelling(): void
    {
        $this->loginAsAdmin();
        $agent = $this->agent($this->gold, overrides: ['telegram_id' => self::AGENT_TELEGRAM_ID]);
        $bot = $this->agentBot($agent);

        $suspended = $this->postJson("/api/admin/agency/agents/{$agent->id}/suspend", ['note' => 'تسویه نشده']);
        self::assertSame(200, $suspended->getStatusCode(), (string) $suspended->getBody());
        self::assertSame(['reason' => 'manual', 'since' => '2026-10-01T12:00:00+00:00'], $this->decode($suspended)['agent']['standing']['suspension']);
        self::assertSame([self::text(BotText::AgencySuspended, ['reason' => SellingSuspension::Manual->words(), 'note' => self::text(BotText::AdminNote, ['comment' => 'تسویه نشده'])])], $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
        self::assertSame(422, $this->postJson("/api/admin/agency/agents/{$agent->id}/suspend", [])->getStatusCode(), 'suspended by hand already');

        $resumed = $this->postJson("/api/admin/agency/agents/{$agent->id}/resume");
        self::assertSame(200, $resumed->getStatusCode(), (string) $resumed->getBody());
        self::assertNull($this->decode($resumed)['agent']['standing']['suspension']);
        self::assertSame([AgencyStanding::NOT_SUSPENDED], $this->decode($this->postJson("/api/admin/agency/agents/{$agent->id}/resume"))['errors']['status']);

        $this->sellingTerms($bot, Carbon::now()->subMinute());
        self::assertSame([AgencyStanding::EXTEND_FIRST], $this->decode($this->postJson("/api/admin/agency/agents/{$agent->id}/resume"))['errors']['status'], 'an end passed is extended instead');

        $this->service(AgencyActions::class)->revoke($agent, null);
        self::assertSame(422, $this->postJson("/api/admin/agency/agents/{$agent->id}/suspend", [])->getStatusCode(), 'no agent any more');
    }

    public function testTheAgentsPanelSaysWhyTheirShopSellsNothing(): void
    {
        $bot = $this->agentBot();
        $agent = $bot->agent ?? self::fail('no agent');
        $this->wallet($agent, '1000000');
        $this->sellingTerms($bot, Carbon::parse('2026-10-01 13:00:00'), '500000');
        $this->loginAsAgent($bot);

        $standing = $this->decode($this->get('/api/agent/account'))['account']['standing'];
        self::assertSame(['2026-10-01T13:00:00+00:00', '500000.00', 'مهر ۱۴۰۵', '0.00', null, 3], [$standing['expires_at'], $standing['monthly_minimum'], $standing['month']['name'], $standing['month']['purchased'], $standing['suspension'], $standing['warn_days']]);
        self::assertSame($standing, $this->decode($this->get('/api/agent/dashboard'))['agency_standing'], 'the dashboard warns of the same');
        self::assertNull($this->decode($this->get('/api/agent/auth/me'))['session']['shop']['selling_suspended']);

        Carbon::setTestNow('2026-10-01 13:00:00');
        self::assertSame('expired', $this->decode($this->get('/api/agent/auth/me'))['session']['shop']['selling_suspended'], 'the moment its end passes');
        self::assertSame('expired', $this->decode($this->get('/api/agent/account'))['account']['standing']['suspension']['reason']);
        $plans = $this->decode($this->get('/api/agent/plans'))['plans'];
        self::assertSame([], $plans, 'no plan yet');

        $this->loginAsAdmin();
        self::assertNull($this->decode($this->get('/api/admin/dashboard'))['agency_standing'], 'none in the main shop');
        self::assertNull($this->decode($this->get('/api/admin/auth/me'))['session']['shop']['selling_suspended']);
    }

    /** The terms are the agent's dealings with the main shop: the owner sees them in the agent's shop, its website's admins never. */
    public function testTheShopsWebsiteAdminsSeeNoneOfTheAgentsTerms(): void
    {
        $bot = $this->agentBot();
        $this->sellingTerms($bot, Carbon::parse('2026-11-01 12:00:00'), '500000');

        $this->loginAsAdmin();
        $this->openShop($bot);
        self::assertSame('500000.00', $this->decode($this->get('/api/admin/dashboard'))['agency_standing']['monthly_minimum'] ?? null, "the owner, in the agent's shop");

        $website = CurrentBot::run($bot, fn(): Website => $this->website());
        $this->loginAsStaff($website, CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7002])));
        $dashboard = $this->decode($this->get($this->storeApi($website, '/admin/dashboard')));
        self::assertArrayHasKey('kpis', $dashboard, (string) json_encode($dashboard));
        self::assertNull($dashboard['agency_standing'], "the agent's website's admins");
    }

    public function testASuspendedShopsWebsiteTakesNoOrderButStillTakesAReceipt(): void
    {
        $this->fakePanel();
        $server = $this->fakeServer();
        $this->inbound($server, '1');
        $bot = $this->agentBot();
        [$website, $token, $plan, $card, $service] = CurrentBot::run($bot, function () use ($server): array {
            $this->botSettings('general', ['enabled' => true, 'phone_required' => false, 'support_contact' => '']);
            $plan = $this->plan(['traffic_gb' => 10], $server);
            $card = $this->cardMethod();
            $customer = $this->customer(['telegram_id' => 8181]);
            $service = $this->subscription($customer, $plan, $server, 'ali_1', ['expires_at' => now()->addDay()]);

            return [$this->website(), $this->customerSession($customer), $plan, $card, $service];
        });
        $this->bearer($token);
        $order = fn(string $key) => $this->placeOrder($website, $plan, $server, $card, key: $key);
        $renewal = fn() => $this->get($this->storeApi($website, "/subscriptions/{$service->id}/renewal"));
        $code = fn() => $this->postJson($this->storeApi($website, '/discount-codes/check'), ['code' => 'NOWRUZ', 'for' => 'renewal', 'subscription_id' => $service->id]);
        $transfer = $this->decode($order('before'))['checkout']['transfer'];
        self::assertTrue($this->decode($this->get($this->storeApi($website)))['shop']['taking_orders']);
        self::assertSame(200, $renewal()->getStatusCode(), 'a renewal previewed before');
        self::assertSame(['code'], array_keys($this->decode($code())['errors']), 'a code checked before: only the code refused');

        $this->service(AgencyStanding::class)->suspendByHand($bot, null);

        self::assertFalse($this->decode($this->get($this->storeApi($website)))['shop']['taking_orders']);
        self::assertSame([], $this->decode($this->get($this->storeApi($website, '/plans')))['groups'], 'no plan offered');
        foreach ([
            'a purchase' => $order('after'),
            'a top-up' => $this->placeTopUp($website, 50000, $card, 'top-up'),
        ] as $what => $response) {
            self::assertSame([503, SellingSuspendedException::MESSAGE], [$response->getStatusCode(), $this->decode($response)['message']], $what);
        }
        // What only prices one says it under `status`.
        foreach (['a renewal previewed' => $renewal(), 'a code checked for a renewal' => $code()] as $what => $response) {
            self::assertSame([422, ['status' => [SellingSuspendedException::MESSAGE]]], [$response->getStatusCode(), $this->decode($response)['errors'] ?? null], $what);
        }
        $receipt = $this->upload($this->storeApi($website, "/payments/{$transfer['payment_id']}/receipt"), 'file', 'receipt.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        self::assertSame(200, $receipt->getStatusCode(), (string) $receipt->getBody());
        self::assertNotNull(CurrentBot::run($bot, static fn(): ?Carbon => Payment::query()->sole()->receipt_at), 'an order made before is still paid');
    }
}
