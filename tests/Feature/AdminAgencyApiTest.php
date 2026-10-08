<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Ledger;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencyLevels;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * «نمایندگان» on the owner's panel: the program's numbers and rules — the shop's, in the main bot's settings, whatever
 * shop the panel shows; the levels — a unique name and a price per GB, in order, kept while agents are on one; the
 * requests, approved on a level with a credit (the agent's shop opens, or the one they had runs again) or rejected with
 * a note, once — of two verdicts at once one stands —, each reported under the request in the report group; and the
 * agents — their bot, its traffic and what it sold, what they owe, read in the same few queries however many there
 * are —, their level and credit changed, their traffic set right, their agency ended (their bot goes off and its
 * webhook down), only while they are agents. The customer hears of every decision, once.
 */
final class AdminAgencyApiTest extends HttpTestCase
{
    private AgencyLevel $gold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        $this->telegram();
        $this->agencyProgram(credit: '100000');
        $this->gold = $this->agencyLevel();
    }

    public function testTheSummaryHasTheNumbers(): void
    {
        $this->agent($this->gold);
        $this->agentBot();
        $this->agencyRequest($this->customer(['telegram_id' => 900_001]));

        $summary = $this->decode($this->get('/api/admin/agency'))['summary'];

        self::assertSame(['levels' => 1, 'agents' => 2, 'bots' => 1, 'pending' => 1], $summary, 'one of the two handed their bot over; the rules are /agency/settings alone');
    }

    public function testLevelsAreAddedEditedOrderedAndKeptWhileAgentsAreOnThem(): void
    {
        $refused = $this->postJson('/api/admin/agency/levels', ['name' => 'طلایی', 'price_per_gb' => '0']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['name', 'price_per_gb'], array_keys($this->decode($refused)['errors']), 'a name taken, no price');
        foreach (['', str_repeat('ب', AgencyLevels::NAME_MAX + 1)] as $name) {
            self::assertSame(['name'], array_keys($this->decode($this->postJson('/api/admin/agency/levels', ['name' => $name, 'price_per_gb' => '2000']))['errors'] ?? []), 'none, or too long');
        }
        self::assertSame(['price_per_gb'], array_keys($this->decode($this->postJson('/api/admin/agency/levels', ['name' => 'برنزی', 'price_per_gb' => '2500.5']))['errors'] ?? []), 'whole Toman');

        $bronze = $this->decode($this->postJson('/api/admin/agency/levels', ['name' => 'برنزی', 'price_per_gb' => '۴٬۰۰۰']))['level'];
        self::assertSame(['برنزی', '4000.00', ['agents' => 0]], [$bronze['name'], $bronze['price_per_gb'], $bronze['counts']]);

        $edited = $this->decode($this->putJson("/api/admin/agency/levels/{$bronze['id']}", ['name' => 'برنزی', 'price_per_gb' => 3500]))['level'];
        self::assertSame('3500.00', $edited['price_per_gb']);

        $ordered = $this->decode($this->postJson('/api/admin/agency/levels/reorder', ['ids' => [$bronze['id'], $this->gold->id]]))['levels'];
        self::assertSame(['برنزی', 'طلایی'], array_column($ordered, 'name'));

        $this->agent($this->gold);
        self::assertSame([['برنزی', 0], ['طلایی', 1]], array_map(static fn(array $level): array => [$level['name'], $level['counts']['agents']], $this->decode($this->get('/api/admin/agency/levels'))['levels']), 'in order, each with its agents');
        $inUse = $this->deleteJson("/api/admin/agency/levels/{$this->gold->id}");
        self::assertSame(409, $inUse->getStatusCode());
        self::assertNotNull(AgencyLevel::query()->find($this->gold->id));
        self::assertSame(204, $this->deleteJson("/api/admin/agency/levels/{$bronze['id']}")->getStatusCode());
    }

    public function testALevelNameTakenInTheSameMomentIsRefusedLikeAnyTakenName(): void
    {
        $taken = $this->decode($this->postJson('/api/admin/agency/levels', ['name' => 'طلایی', 'price_per_gb' => '2000']))['errors']['name'];

        // Another save takes the name between this one's check and its write: the table's unique index decides.
        $response = $this->whileListening(
            'eloquent.creating: ' . AgencyLevel::class,
            static function (AgencyLevel $level): void {
                AgencyLevel::query()->insert(['name' => $level->name, 'price_per_gb' => '2500', 'sort' => 9, 'created_at' => now(), 'updated_at' => now()]);
            },
            fn(): ResponseInterface => $this->postJson('/api/admin/agency/levels', ['name' => 'نقره‌ای', 'price_per_gb' => '2000']),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($taken, $this->decode($response)['errors']['name'], 'the same words as a name taken before');
        self::assertSame(['2500.00'], AgencyLevel::query()->where('name', 'نقره‌ای')->pluck('price_per_gb')->all(), "the other save's level alone");
    }

    public function testARequestIsApprovedOnALevelWithACreditOnceTheShopOpensAndTheCustomerIsTold(): void
    {
        $this->reportGroup();
        $customer = $this->customer(['username' => 'ali']);
        $request = $this->agencyRequest($customer);
        $this->agencyRequest($this->customer(['telegram_id' => 900_001, 'username' => 'reza']));

        $listed = $this->decode($this->get('/api/admin/agency/requests?status=pending&search=@ali'))['requests'];
        self::assertSame([[$request->id, 'pending', 'کانال فروش دارم', ['approve' => true, 'reject' => true]]], array_map(static fn(array $row): array => [$row['id'], $row['status'], $row['note'], $row['actions']], $listed), 'searched by the customer');

        $invalid = $this->postJson("/api/admin/agency/requests/{$request->id}/approve", ['level_id' => 999, 'credit_limit' => '-5']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(['level_id', 'credit_limit'], array_keys($this->decode($invalid)['errors']));

        $approved = $this->postJson("/api/admin/agency/requests/{$request->id}/approve", ['level_id' => $this->gold->id, 'credit_limit' => '۲۵۰٬۰۰۰']);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $row = $this->decode($approved)['request'];
        self::assertSame(['approved', ['id' => $this->gold->id, 'name' => 'طلایی', 'price_per_gb' => '3000.00'], self::ADMIN_USERNAME, true], [$row['status'], $row['level'], $row['reviewer'], $row['agent']]);
        self::assertSame([$this->gold->id, '250000.00'], [$customer->refresh()->agency_level_id, $customer->credit_limit]);
        self::assertSame([self::text(BotText::AgencyApproved, ['level' => 'طلایی', 'price' => '۳٬۰۰۰ تومان', 'credit' => '۲۵۰٬۰۰۰ تومان'])], $this->telegram()->sentTo(self::TELEGRAM_ID));
        self::assertSame(1, ReportMessage::query()->where('reply_ref', "agency:{$request->id}")->where('clears_buttons', true)->count(), 'the verdict reported under the request, taking its buttons off');

        // Their shop is open: their bot's row, waiting for its token, with a wallet of its own.
        $bot = Bot::query()->where('user_id', $customer->id)->sole();
        self::assertSame([BotStatus::Active, null, 0], [$bot->status(), $bot->token, $bot->trafficBalance()]);
        self::assertSame(['wallet'], CurrentBot::run($bot, static fn(): array => PaymentMethod::query()->pluck('driver')->all()));

        $again = $this->postJson("/api/admin/agency/requests/{$request->id}/reject", []);
        self::assertSame(422, $again->getStatusCode());
        self::assertSame(['این درخواست تایید شده است.'], $this->decode($again)['errors']['status'], 'what it came to');
    }

    public function testOfTwoVerdictsInTheSameMomentTheOtherStandsAndThisOneIsRefused(): void
    {
        $customer = $this->customer();
        $request = $this->agencyRequest($customer);
        // The report group's «رد» lands the moment after the page loaded the request.
        $response = $this->whileListening(
            'eloquent.retrieved: ' . AgencyRequest::class,
            static function (AgencyRequest $loaded): void {
                AgencyRequest::query()->whereKey($loaded->id)->where('status', AgencyRequestStatus::Pending->value)->update(['status' => AgencyRequestStatus::Rejected->value, 'reviewer' => '@boss', 'decided_at' => now()]);
            },
            fn(): ResponseInterface => $this->postJson("/api/admin/agency/requests/{$request->id}/approve", ['level_id' => $this->gold->id, 'credit_limit' => '0']),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['این درخواست رد شده است.'], $this->decode($response)['errors']['status'], 'the verdict that stood');
        self::assertSame([AgencyRequestStatus::Rejected, '@boss'], [$request->refresh()->status, $request->reviewer]);
        self::assertFalse($customer->refresh()->isAgent());
        self::assertFalse(Bot::query()->where('user_id', $customer->id)->exists(), 'no shop opened by the verdict that lost');
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the customer hears the verdict that stood, from where it was given');
    }

    public function testARequestIsRejectedWithANoteTheCustomerReads(): void
    {
        $customer = $this->customer();
        $request = $this->agencyRequest($customer);

        $rejected = $this->decode($this->postJson("/api/admin/agency/requests/{$request->id}/reject", ['note' => 'فعلا نماینده نمی‌گیریم']))['request'];

        self::assertSame(['rejected', 'فعلا نماینده نمی‌گیریم'], [$rejected['status'], $rejected['reason']]);
        self::assertSame(AgencyRequestStatus::Rejected, $request->refresh()->status);
        self::assertFalse($customer->refresh()->isAgent());
        self::assertFalse(Bot::query()->where('user_id', $customer->id)->exists(), 'no shop for them');
        self::assertSame([self::text(BotText::AgencyRejected, ['note' => self::text(BotText::AdminNote, ['comment' => 'فعلا نماینده نمی‌گیریم'])])], $this->telegram()->sentTo(self::TELEGRAM_ID));
        self::assertSame([], $this->decode($this->get('/api/admin/agency/requests?status=pending'))['requests']);
    }

    public function testAnAgentsCreditIsHeldToTheDefaultCreditsOwnRule(): void
    {
        $request = $this->agencyRequest($this->customer());
        $rules = ['enabled' => true, 'traffic_presets' => [50], 'traffic_min' => 10];

        foreach (['-1', (string) (AgencySettings::CREDIT_MAX + 1)] as $credit) {
            self::assertArrayHasKey('default_credit', $this->decode($this->putJson('/api/admin/agency/settings', $rules + ['default_credit' => $credit]))['errors'] ?? [], "{$credit} as the default credit");
            self::assertArrayHasKey('credit_limit', $this->decode($this->postJson("/api/admin/agency/requests/{$request->id}/approve", ['level_id' => $this->gold->id, 'credit_limit' => $credit]))['errors'] ?? [], "{$credit} as an agent's");
        }

        $most = (string) AgencySettings::CREDIT_MAX;
        self::assertSame(200, $this->putJson('/api/admin/agency/settings', $rules + ['default_credit' => $most])->getStatusCode());
        self::assertSame(200, $this->postJson("/api/admin/agency/requests/{$request->id}/approve", ['level_id' => $this->gold->id, 'credit_limit' => $most])->getStatusCode());
    }

    public function testTheAgentsComeWithTheirBotItsTrafficAndWhatItSold(): void
    {
        $this->fakePanel();
        $server = $this->fakeServer();
        $this->inbound($server, '1');
        $agent = $this->wallet($this->agent($this->gold, '300000', ['username' => 'ali']), '-20000.00');
        $bot = $this->agentBot($agent, traffic: 40);
        CurrentBot::run($bot, function () use ($server): void {
            $this->walletMethod();
            $plan = $this->plan(['traffic_gb' => 30], $server);
            $this->buy($this->customer(['telegram_id' => 31, 'username' => 'reza']), $plan, $server);
            $this->customer(['telegram_id' => 32, 'username' => 'sara']);
        });
        $this->customer(['telegram_id' => 900_001]);

        $rows = $this->decode($this->get('/api/admin/agency/agents'))['agents'];

        self::assertSame([[
            'id' => $agent->id,
            'user' => ['id' => $agent->id, 'name' => 'Ali', 'username' => 'ali', 'telegram_id' => self::TELEGRAM_ID, 'email' => null],
            'status' => 'active',
            'level' => ['id' => $this->gold->id, 'name' => 'طلایی', 'price_per_gb' => '3000.00'],
            'credit_limit' => '300000.00',
            'balance' => '-20000.00',
            'bot' => [
                'id' => $bot->id,
                'username' => 'agent_shop_bot',
                'title' => 'فروشگاه نماینده',
                'status' => 'active',
                'connected' => true,
                'problem' => null,
                'traffic_balance' => Traffic::bytesOfGb(10),
                'connected_at' => $bot->connected_at?->toIso8601String(),
            ],
            'counts' => ['customers' => 2, 'sold' => 1, 'active' => 1],
        ]], $rows, 'only agents; the sale drew its 30 GB from the 40');
        self::assertSame([], $this->decode($this->get('/api/admin/agency/agents?search=nobody'))['agents']);
        self::assertSame([$agent->id], array_column($this->decode($this->get("/api/admin/agency/agents?level={$this->gold->id}"))['agents'], 'id'));
        self::assertSame([], $this->decode($this->get('/api/admin/agency/agents?level=' . $this->agencyLevel(['name' => 'برنزی'])->id))['agents'], 'a level nobody is on');
    }

    public function testTheAgentsAreReadInTheSameQueriesHoweverManyThereAre(): void
    {
        $this->agentBot($this->agent($this->gold), traffic: 40);
        $queries = function (): int {
            $this->db()->flushQueryLog();
            $this->get('/api/admin/agency/agents');

            return count($this->db()->getQueryLog());
        };
        $this->db()->enableQueryLog();
        try {
            $queries(); // whatever a first request reads once
            $one = $queries();
            foreach ([601, 602] as $telegramId) {
                $this->traffic($this->agent($this->gold, overrides: ['telegram_id' => $telegramId])->ownBot ?? self::fail('no shop'), 10);
            }
            $three = $queries();
        } finally {
            $this->db()->disableQueryLog();
            $this->db()->flushQueryLog();
        }

        self::assertCount(3, $this->decode($this->get('/api/admin/agency/agents'))['agents']);
        self::assertSame($one, $three, 'their bots, traffic, sales and wallets read with the rows, not a few queries an agent');
    }

    public function testTheShopSetsAnAgentsTrafficRightAndTheLedgerSaysWhoAndWhy(): void
    {
        $agent = $this->agent($this->gold);
        $bot = $this->agentBot($agent, traffic: 10);

        $added = $this->postJson("/api/admin/agency/agents/{$agent->id}/traffic", ['gb' => '۵۰', 'note' => 'هدیه شروع کار']);
        self::assertSame(200, $added->getStatusCode(), (string) $added->getBody());
        self::assertSame(Traffic::bytesOfGb(60), $this->decode($added)['agent']['bot']['traffic_balance']);

        $tooMuch = $this->postJson("/api/admin/agency/agents/{$agent->id}/traffic", ['gb' => -100]);
        self::assertSame(422, $tooMuch->getStatusCode());
        self::assertSame(['gb'], array_keys($this->decode($tooMuch)['errors']), 'never below zero');
        self::assertSame(422, $this->postJson("/api/admin/agency/agents/{$agent->id}/traffic", ['gb' => 'x'])->getStatusCode());
        $long = $this->postJson("/api/admin/agency/agents/{$agent->id}/traffic", ['gb' => 1, 'note' => str_repeat('ن', Ledger::NOTE_MAX + 1)]);
        self::assertSame(['note'], array_keys($this->decode($long)['errors']), "a ledger line's note, as a wallet's: within its column");
        $customer = $this->customer(['telegram_id' => 900_001]);
        self::assertSame([AgencyActions::NOT_AGENT], $this->decode($this->postJson("/api/admin/agency/agents/{$customer->id}/traffic", ['gb' => 5]))['errors']['status'] ?? null, 'a customer who is no agent has no traffic to set right');

        $this->postJson("/api/admin/agency/agents/{$agent->id}/traffic", ['gb' => -5]);
        $lines = $this->decode($this->get("/api/admin/agency/agents/{$agent->id}/traffic"))['lines'];
        self::assertSame(
            [
                ['adjust', -Traffic::bytesOfGb(5), Traffic::bytesOfGb(55), TrafficPool::LINE_ADJUSTED, self::ADMIN_USERNAME],
                ['adjust', Traffic::bytesOfGb(50), Traffic::bytesOfGb(60), 'هدیه شروع کار', self::ADMIN_USERNAME],
                ['purchase', Traffic::bytesOfGb(10), Traffic::bytesOfGb(10), null, null],
            ],
            array_map(static fn(array $line): array => [$line['type'], $line['bytes'], $line['balance_after'], $line['description'], $line['reviewer']], $lines),
            'newest first',
        );
        self::assertSame(Traffic::bytesOfGb(55), $bot->trafficBalance(), "the ledger's last line");
    }

    public function testAnAgentsLevelAndCreditChangeAndTheirAgencyEndsWithTheirBotOff(): void
    {
        $silver = $this->agencyLevel(['name' => 'نقره‌ای', 'price_per_gb' => '4000']);
        $agent = $this->wallet($this->agent($this->gold), '-5000.00');
        $bot = $this->agentBot($agent, traffic: 40);

        $saved = $this->putJson("/api/admin/agency/agents/{$agent->id}", ['level_id' => $silver->id, 'credit_limit' => '50000']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(['id' => $silver->id, 'name' => 'نقره‌ای', 'price_per_gb' => '4000.00'], $this->decode($saved)['agent']['level']);
        self::assertSame('told', $this->decode($saved)['delivery'], 'the answer says whether the agent was told');
        self::assertSame([self::text(BotText::AgencyChanged, ['level' => 'نقره‌ای', 'price' => '۴٬۰۰۰ تومان', 'credit' => '۵۰٬۰۰۰ تومان'])], $this->telegram()->sentTo(self::TELEGRAM_ID));
        $this->telegram()->fail(502, 'Bad Gateway');
        self::assertSame('unreachable', $this->decode($this->putJson("/api/admin/agency/agents/{$agent->id}", ['level_id' => $silver->id, 'credit_limit' => '60000']))['delivery'], 'saved all the same');
        self::assertSame('60000.00', $agent->refresh()->credit_limit);

        // Saved as they are: nothing changed, nobody told.
        $this->telegram()->reset();
        $same = $this->putJson("/api/admin/agency/agents/{$agent->id}", ['level_id' => $silver->id, 'credit_limit' => '۶۰٬۰۰۰']);
        self::assertSame([200, null], [$same->getStatusCode(), $this->decode($same)['delivery']]);
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID));

        $this->telegram()->reset();
        self::assertSame(204, $this->postJson("/api/admin/agency/agents/{$agent->id}/revoke", ['note' => 'همکاری تمام شد'])->getStatusCode());
        $agent->refresh();
        self::assertSame([null, '0.00', '-5000.00'], [$agent->agency_level_id, $agent->credit_limit, $agent->balance()], 'what they owe stays owed');
        $bot->refresh();
        self::assertSame([BotStatus::Disabled, 1], [$bot->status(), $bot->panel_epoch], 'their bot off, their panel sessions over');
        self::assertSame([[FakeTelegram::AGENT_TOKEN, 'deleteWebhook']], self::calledAs($this->telegram(), FakeTelegram::AGENT_TOKEN), 'its webhook taken down');
        self::assertSame([FakeTelegram::AGENT_TOKEN, Traffic::bytesOfGb(40)], [$bot->token, $bot->trafficBalance()], 'the shop and its traffic kept for when the agency is given back');
        self::assertSame([self::text(BotText::AgencyRevoked, ['note' => self::text(BotText::AdminNote, ['comment' => 'همکاری تمام شد'])])], $this->telegram()->sentTo(self::TELEGRAM_ID));

        self::assertSame(422, $this->postJson("/api/admin/agency/agents/{$agent->id}/revoke", [])->getStatusCode(), 'no agency to end');
        self::assertSame([AgencyActions::AGENCY_ENDED], $this->decode($this->putJson("/api/admin/agency/agents/{$agent->id}", ['level_id' => $silver->id, 'credit_limit' => '0']))['errors']['status'], 'not an agent any more: nothing given back by it');
        self::assertNull($agent->refresh()->agency_level_id);
        self::assertSame(404, $this->postJson('/api/admin/agency/agents/999/revoke', [])->getStatusCode());
        self::assertNull(User::query()->find(999));
    }

    public function testAnAgencyEndsEvenWhenTelegramWillNotTakeTheBotsWebhookDown(): void
    {
        $agent = $this->agent($this->gold);
        $bot = $this->agentBot($agent);
        // The agent revoked its token in @BotFather already.
        $this->telegram()->on('deleteWebhook', static fn() => FakeTelegram::error(401, 'Unauthorized'));
        $logs = $this->logs();

        self::assertSame(204, $this->postJson("/api/admin/agency/agents/{$agent->id}/revoke", [])->getStatusCode());

        self::assertSame(BotStatus::Disabled, $bot->refresh()->status(), 'off whatever Telegram says: its webhook answers nothing while it is');
        self::assertTrue($logs->hasWarningThatContains("The webhook of bot #{$bot->id} could not be taken down"));
    }

    public function testAnAgencyGivenBackRunsTheShopTheyHadAgain(): void
    {
        $agent = $this->agent($this->gold);
        $bot = $this->agentBot($agent, traffic: 40);
        CurrentBot::run($bot, fn() => $this->plan(['name' => 'پلن رضا']));
        $this->postJson("/api/admin/agency/agents/{$agent->id}/revoke", []);
        // While it was off, Telegram turned its token down.
        $bot->refresh()->forceFill(['problem' => 'تلگرام توکن را نپذیرفت'])->save();
        $this->telegram()->reset();

        $approved = $this->postJson("/api/admin/agency/requests/{$this->agencyRequest($agent)->id}/approve", ['level_id' => $this->gold->id, 'credit_limit' => '0']);

        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame([$bot->id], Bot::query()->where('user_id', $agent->id)->pluck('id')->all(), 'their bot, not a new one');
        $bot->refresh();
        self::assertSame([BotStatus::Active, null, Traffic::bytesOfGb(40)], [$bot->status(), $bot->problem, $bot->trafficBalance()], 'on again, what kept it from running forgotten, its traffic kept');
        self::assertSame(['پلن رضا'], CurrentBot::run($bot, static fn(): array => Plan::query()->pluck('name')->all()), 'its shop as they left it');
        self::assertSame(['wallet'], CurrentBot::run($bot, static fn(): array => PaymentMethod::query()->pluck('driver')->all()), 'one wallet still');
        self::assertSame([[FakeTelegram::AGENT_TOKEN, 'deleteWebhook']], self::calledAs($this->telegram(), FakeTelegram::AGENT_TOKEN), "back in the shop's mode (polling: off any webhook)");
    }

    public function testAnAgencyEndedInTheSameMomentIsNotGivenBackByAChange(): void
    {
        $silver = $this->agencyLevel(['name' => 'نقره‌ای', 'price_per_gb' => '4000']);
        $agent = $this->agent($this->gold);

        // Another tab ends the agency the moment after this one loaded the agent.
        $response = $this->whenLoaded(
            $agent,
            fn() => $this->service(AgencyActions::class)->revoke(User::query()->findOrFail($agent->id), null),
            fn(): ResponseInterface => $this->putJson("/api/admin/agency/agents/{$agent->id}", ['level_id' => $silver->id, 'credit_limit' => '50000']),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([AgencyActions::AGENCY_ENDED], $this->decode($response)['errors']['status'], 'what came of it');
        self::assertSame([null, '0.00'], [$agent->refresh()->agency_level_id, $agent->credit_limit]);
        self::assertSame([self::text(BotText::AgencyRevoked, ['note' => ''])], $this->telegram()->sentTo(self::TELEGRAM_ID), 'told of the end alone');
    }

    public function testOfTwoEndingsInTheSameMomentOneEndsTheAgency(): void
    {
        $agent = $this->agent($this->gold);
        $bot = $this->agentBot($agent);

        $response = $this->whenLoaded(
            $agent,
            fn() => $this->service(AgencyActions::class)->revoke(User::query()->findOrFail($agent->id), 'از تب دیگر'),
            fn(): ResponseInterface => $this->postJson("/api/admin/agency/agents/{$agent->id}/revoke", ['note' => 'همکاری تمام شد']),
        );

        self::assertSame([AgencyActions::AGENCY_ENDED], $this->decode($response)['errors']['status'] ?? null);
        self::assertSame(1, $bot->refresh()->panel_epoch, 'raised once');
        self::assertSame([self::text(BotText::AgencyRevoked, ['note' => self::text(BotText::AdminNote, ['comment' => 'از تب دیگر'])])], $this->telegram()->sentTo(self::TELEGRAM_ID), 'told once, by the one that ended it');
    }

    public function testAFreshShopsProgramIsOffWithoutCreditOfferingTheUsualTraffic(): void
    {
        foreach (['agency.enabled', 'agency.default_credit', 'agency.traffic_presets', 'agency.traffic_min'] as $key) {
            $this->service(Settings::class)->forget($key);
        }

        self::assertSame(['enabled' => false, 'default_credit' => '0.00', 'traffic_presets' => [50, 100, 200, 500], 'traffic_min' => 10], $this->decode($this->get('/api/admin/agency/settings'))['settings']);
    }

    public function testTheRulesAreTheSwitchTheStartingCreditAndTheTrafficOffered(): void
    {
        self::assertSame(['enabled' => true, 'default_credit' => '100000.00', 'traffic_presets' => [50, 100], 'traffic_min' => 10], $this->decode($this->get('/api/admin/agency/settings'))['settings']);

        $saved = $this->putJson('/api/admin/agency/settings', ['enabled' => false, 'default_credit' => '۵۰۰٬۰۰۰', 'traffic_presets' => '۲۰۰، 50, 100', 'traffic_min' => '20']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(['enabled' => false, 'default_credit' => '500000.00', 'traffic_presets' => [50, 100, 200], 'traffic_min' => 20], $this->decode($saved)['settings']);

        $refused = $this->unchecked()->putJson('/api/admin/agency/settings', ['enabled' => 'maybe', 'default_credit' => '-1', 'traffic_presets' => '5, 50', 'traffic_min' => 10]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['enabled', 'default_credit', 'traffic_presets'], array_keys($this->decode($refused)['errors']), 'a preset below the least one may type');
        self::assertSame(20, $this->decode($this->get('/api/admin/agency/settings'))['settings']['traffic_min'], 'nothing stored from a refused save');
    }

    public function testTheRulesAreTheShopsWhicheverShopTheOwnerOpened(): void
    {
        $this->openShop($this->agentBot());

        self::assertSame(200, $this->putJson('/api/admin/agency/settings', ['enabled' => true, 'default_credit' => '0', 'traffic_presets' => [100], 'traffic_min' => 50])->getStatusCode());

        self::assertSame(50, $this->service(AgencySettings::class)->trafficMin());
        self::assertSame(1, $this->decode($this->get('/api/admin/agency'))['summary']['agents'], "worked in the main bot's shop, whose customers the agents are");
    }

    /**
     * `$request` made while `$then` runs once, the moment the agent's row is first read from the database — another
     * process's move landing between the panel loading the agent and acting on them.
     *
     * @param \Closure(): ResponseInterface $request
     */
    private function whenLoaded(User $agent, \Closure $then, \Closure $request): ResponseInterface
    {
        $done = false;

        return $this->whileListening('eloquent.retrieved: ' . User::class, static function (User $loaded) use ($agent, $then, &$done): void {
            if (!$done && $loaded->id === $agent->id) {
                $done = true;
                $then();
            }
        }, $request);
    }

    /** @return list<array{string, string}> The calls made with that bot's token, as [token, method]. */
    private static function calledAs(FakeTelegram $telegram, string $token): array
    {
        $calls = [];
        foreach (array_keys($telegram->history) as $i) {
            if ($telegram->tokenOf($i) === $token) {
                $calls[] = [$token, $telegram->calls()[$i]];
            }
        }

        return $calls;
    }
}
