<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Enums\AgencyRequestKind;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\AgencyReview;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\JalaliMonth;
use App\Support\LocalTime;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * «درخواست تمدید نمایندگی»: an agent whose agency has an end asks for it to be moved on — from their panel here (the
 * main bot's button: AgencyBotTest; the report group's «تایید»: AgencyReviewTest) —, once at a time, and the owner
 * approves it with the program's term on from their end (from now once it passed), an end of their own or none at all —
 * the agent told the renewal before their bot sells again —, or rejects it with a note. Ending the agency closes one
 * still waiting; a renewal never stands for a request to become an agent, nor the other way round.
 */
final class AgencyRenewalTest extends HttpTestCase
{
    private const END = '2026-10-11 18:00:00';

    private AgencyLevel $gold;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->telegram();
        $this->reportGroup();
        $this->agencyProgram();
        $this->gold = $this->agencyLevel();
        $this->term(30);
    }

    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testAnAgentAsksFromTheirPanelOnceAndTheGroupHearsOfIt(): void
    {
        $bot = $this->agentWithAnEnd();
        $this->loginAsAgent($bot);
        $account = $this->decode($this->get('/api/agent/account'))['account'];
        self::assertSame([null, ['request_renewal' => true]], [$account['renewal'], $account['actions']]);

        $asked = $this->postJson('/api/agent/account/renewal');
        self::assertSame(201, $asked->getStatusCode(), (string) $asked->getBody());
        $account = $this->decode($asked)['account'];
        $request = AgencyRequest::query()->sole();
        self::assertSame([AgencyRequestKind::Renewal, $bot->user_id, AgencyRequestStatus::Pending], [$request->kind, $request->user_id, $request->status]);
        self::assertSame([$request->id, 'pending', null, null], [$account['renewal']['id'], $account['renewal']['status'], $account['renewal']['extended_to'], $account['renewal']['reason']]);
        self::assertFalse($account['actions']['request_renewal'], 'one waits');

        $report = ReportMessage::query()->where('topic', 'agency')->sole();
        self::assertSame("agency:{$request->id}", $report->ref);
        self::assertStringContainsString("🔄 <b>درخواست تمدید نمایندگی</b> · \u{200E}#{$request->id}", $report->text);
        self::assertStringContainsString('@agent_shop_bot', $report->text);
        self::assertStringContainsString('📅 پایان نمایندگی: ' . Persian::date(Carbon::parse(self::END), withTime: true), $report->text);
        self::assertSame(AgencyReview::buttons($request->id)[0], ((array) $report->keyboard)[0], '«رد» and «تایید», as under a request to become an agent');

        $again = $this->postJson('/api/agent/account/renewal');
        self::assertSame([422, ['status' => [AgencyActions::RENEWAL_WAITING]]], [$again->getStatusCode(), $this->decode($again)['errors']]);
        self::assertSame(1, AgencyRequest::query()->count(), 'never two waiting');
    }

    public function testAnAgencyWithNoEndHasNothingToRenewWhateverTheProgramsSwitch(): void
    {
        $bot = $this->agentBot();
        $this->loginAsAgent($bot);

        self::assertFalse($this->decode($this->get('/api/agent/account'))['account']['actions']['request_renewal']);
        $refused = $this->postJson('/api/agent/account/renewal');
        self::assertSame([422, ['status' => [AgencyActions::RENEWAL_NOT_NEEDED]]], [$refused->getStatusCode(), $this->decode($refused)['errors']]);
        self::assertSame(0, AgencyRequest::query()->count());

        // The program switched off closes the door to new agents, not to the ones there are.
        $this->agencyProgram(false);
        $this->sellingTerms($bot, Carbon::parse(self::END));
        self::assertTrue($this->decode($this->get('/api/agent/account'))['account']['actions']['request_renewal']);
        self::assertSame(201, $this->postJson('/api/agent/account/renewal')->getStatusCode());
    }

    public function testTheOwnerApprovesByTheProgramsTermOnFromTheEndStillAhead(): void
    {
        $this->loginAsAdmin();
        $bot = $this->agentWithAnEnd();
        $request = $this->asks($bot);

        $row = $this->decode($this->get('/api/admin/agency/requests?kind=renewal'))['requests'][0] ?? self::fail('not listed');
        self::assertSame(['renewal', 'pending', '2026-10-11T18:00:00+00:00', '2026-11-10T18:00:00+00:00', null], [$row['kind'], $row['status'], $row['expires_at'], $row['term_end'], $row['extended_to']], 'thirty days on from their end, which lies ahead');
        self::assertSame($row, $this->decode($this->get("/api/admin/agency/requests/{$request->id}"))['request'], 'one request reads as the list shows it');
        self::assertSame(404, $this->get('/api/admin/agency/requests/999')->getStatusCode());
        self::assertSame([], $this->decode($this->get('/api/admin/agency/requests?kind=join'))['requests']);
        self::assertSame(1, $this->decode($this->get('/api/admin/agency'))['summary']['pending'], 'waiting with the requests to become an agent');

        $approved = $this->postJson("/api/admin/agency/requests/{$request->id}/approve", []);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $row = $this->decode($approved)['request'];
        self::assertSame(['approved', '2026-11-10T18:00:00+00:00', null, 'root'], [$row['status'], $row['extended_to'], $row['term_end'], $row['reviewer']]);
        self::assertSame('2026-11-10 18:00:00', $bot->refresh()->agency_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame([self::text(BotText::AgencyRenewed, ['date' => Persian::date(Carbon::parse('2026-11-10 18:00:00'), withTime: true)])], $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
        self::assertSame(1, ReportMessage::query()->where('reply_ref', "agency:{$request->id}")->where('clears_buttons', true)->count(), 'the verdict goes under the request');
        self::assertSame(422, $this->postJson("/api/admin/agency/requests/{$request->id}/approve", [])->getStatusCode(), 'decided');
    }

    public function testAnEndPassedIsMovedOnFromNowAndTheAgentHearsOfItBeforeTheirBotSellsAgain(): void
    {
        LocalTime::use('Asia/Tehran');
        Carbon::setTestNow('2026-10-01 12:34:56'); // 16:04 in Tehran
        $this->loginAsAdmin();
        $bot = $this->agentBot();
        $this->sellingTerms($bot, Carbon::now()->subDay());
        self::assertSame(SellingSuspension::Expired, $bot->refresh()->sellingSuspension());
        $request = $this->asks($bot);
        $told = count($this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));

        self::assertSame(200, $this->postJson("/api/admin/agency/requests/{$request->id}/approve", [])->getStatusCode());

        $bot->refresh();
        self::assertSame('2026-10-31 12:30:00', $bot->agency_expires_at?->format('Y-m-d H:i:s'), "thirty days from now, to the hour on the shop's clock (16:00 in Tehran)");
        self::assertNull($bot->sellingSuspension(), 'it sells again');
        self::assertSame([
            self::text(BotText::AgencyRenewed, ['date' => Persian::date(Carbon::parse('2026-10-31 12:30:00'), withTime: true)]),
            self::text(BotText::AgencyResumed),
        ], array_slice($this->telegram()->sentTo(self::AGENT_TELEGRAM_ID), $told), 'the renewal first, then its bot selling again');
    }

    public function testTheOwnerGivesAnEndOfTheirOwnOrNoneAtAll(): void
    {
        $this->loginAsAdmin();
        $bot = $this->agentWithAnEnd();
        $agent = $bot->agent ?? self::fail('no agent');
        $request = $this->asks($bot);
        $url = "/api/admin/agency/requests/{$request->id}/approve";

        foreach ([
            'a moment passed' => ['2026-09-30T12:00', [AgencyActions::END_PASSED]],
            'no such day' => ['2026-02-30T10:00', null],
            'past the last day the shop keeps' => ['2040-01-01T00:00', [LocalTime::tooLateFor('تاریخ پایان نمایندگی') . '؛ برای بدون پایان، خالی بگذارید.']],
        ] as $case => [$end, $messages]) {
            $refused = $this->postJson($url, ['expires_at' => $end]);
            $errors = $this->decode($refused)['errors'] ?? [];
            self::assertSame([422, ['expires_at']], [$refused->getStatusCode(), array_keys($errors)], $case);
            if ($messages !== null) {
                self::assertSame($messages, $errors['expires_at'], $case);
            }
        }
        self::assertSame([AgencyRequestStatus::Pending, self::END], [$request->refresh()->status, $bot->refresh()->agency_expires_at?->format('Y-m-d H:i:s')], 'nothing decided');

        self::assertSame(200, $this->postJson($url, ['expires_at' => '2027-01-01T10:00'])->getStatusCode());
        self::assertSame(['2027-01-01 10:00:00', '2027-01-01 10:00:00'], [$bot->refresh()->agency_expires_at?->format('Y-m-d H:i:s'), $request->refresh()->extended_to?->format('Y-m-d H:i:s')]);

        $none = $this->asks($bot);
        self::assertSame(200, $this->postJson("/api/admin/agency/requests/{$none->id}/approve", ['expires_at' => ''])->getStatusCode());
        self::assertSame([AgencyRequestStatus::Approved, null, null], [$none->refresh()->status, $none->extended_to, $bot->refresh()->agency_expires_at], 'none at all');
        $sent = $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID);
        self::assertSame(self::text(BotText::AgencyRenewed, ['date' => 'بدون پایان']), end($sent));
        self::assertFalse(AgencyActions::mayRequestRenewal($agent->refresh()), 'nothing left to renew');

        // Approved again with no end named: said decided — never that the agency has no end to move on.
        $again = $this->postJson("/api/admin/agency/requests/{$none->id}/approve", []);
        self::assertSame([422, ['status' => ['این درخواست تایید شده است.']]], [$again->getStatusCode(), $this->decode($again)['errors']]);
    }

    public function testANewEndIsNeverEarlierThanTheEndStillAheadAndTheTermKeepsItsMinutes(): void
    {
        $this->loginAsAdmin();
        $bot = $this->sellingTerms($this->agentBot(), Carbon::parse('2026-10-11 18:30:00'));
        $request = $this->asks($bot);

        $row = $this->decode($this->get('/api/admin/agency/requests'))['requests'][0];
        self::assertSame('2026-11-10T18:30:00+00:00', $row['term_end'], 'thirty days on from the end, to the minute');
        $earlier = $this->postJson("/api/admin/agency/requests/{$request->id}/approve", ['expires_at' => '2026-10-05T10:00']);
        self::assertSame([422, ['expires_at' => [AgencyActions::END_EARLIER]]], [$earlier->getStatusCode(), $this->decode($earlier)['errors']]);
        self::assertSame(AgencyRequestStatus::Pending, $request->refresh()->status);

        self::assertSame(200, $this->postJson("/api/admin/agency/requests/{$request->id}/approve", [])->getStatusCode());
        self::assertSame('2026-11-10 18:30:00', $bot->refresh()->agency_expires_at?->format('Y-m-d H:i:s'));
    }

    public function testAShopStillStoppedForAnEndThatLiesAheadSellsAgainAtTheNextRun(): void
    {
        // An end moved whose lifting never ran (the process died after the renewal was written).
        $bot = $this->agentBot();
        $bot->forceFill(['agency_expires_at' => Carbon::now()->addDays(20), 'selling_suspended_at' => Carbon::now(), 'suspension_reason' => SellingSuspension::Expired->value])->save();

        $this->service(AgencyStanding::class)->judge($bot->refresh(), Carbon::now());

        self::assertNull($bot->refresh()->sellingSuspension());
        self::assertSame([self::text(BotText::AgencyResumed)], $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
    }

    public function testAnHourlyRunThatReadAShopStoppedForItsMinimumNeverCallsItStoppedForAnEndRenewedSince(): void
    {
        $this->loginAsAdmin();
        $bot = $this->agentBot();
        $bot->forceFill([
            'agency_expires_at' => Carbon::now()->subMinute(), 'selling_suspended_at' => Carbon::now()->subDay(), 'suspension_reason' => SellingSuspension::Minimum->value,
            'monthly_minimum' => '500000', 'minimum_checked_month' => JalaliMonth::now()->previous()->key(),
        ])->save();
        $read = Bot::query()->findOrFail($bot->id);
        self::assertSame(200, $this->postJson('/api/admin/agency/requests/' . $this->asks($bot)->id . '/approve', [])->getStatusCode());

        $this->service(AgencyStanding::class)->judge($read, Carbon::now());

        self::assertSame(SellingSuspension::Minimum, $bot->refresh()->sellingSuspension(), 'still the minimum: its end lies ahead');
    }

    public function testNoTermEndIsOfferedPastTheLastDayTheShopKeeps(): void
    {
        Carbon::setTestNow(LocalTime::last()->subDays(10));
        $this->loginAsAdmin();
        $bot = $this->sellingTerms($this->agentBot(), Carbon::now()->addDays(5));
        $request = $this->asks($bot);

        self::assertNull($this->decode($this->get("/api/admin/agency/requests/{$request->id}"))['request']['term_end'], 'thirty days on would pass it');
    }

    public function testAnHourlyRunThatReadTheShopBeforeARenewalNeitherSuspendsItNorWarnsOfTheOldEnd(): void
    {
        $this->loginAsAdmin();
        $standing = $this->service(AgencyStanding::class);

        // Its end passed and its selling stopped; the run reads the shop; a renewal is approved; the run judges what it read.
        $bot = $this->agentBot();
        $this->sellingTerms($bot, Carbon::now()->subDay());
        $read = Bot::query()->findOrFail($bot->id);
        self::assertSame(200, $this->postJson('/api/admin/agency/requests/' . $this->asks($bot)->id . '/approve', [])->getStatusCode());
        $told = count($this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
        $standing->judge($read, Carbon::now());
        self::assertNull($bot->refresh()->sellingSuspension(), 'still selling');
        self::assertCount($told, $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID), 'nobody told it stopped');

        // Its end near and not yet warned of when the run reads it; renewed before the run judges it.
        $this->sellingTerms($bot, Carbon::parse('2026-11-30 12:00:00'));
        Carbon::setTestNow('2026-11-28 12:00:00');
        $read = Bot::query()->findOrFail($bot->id);
        $actions = $this->service(AgencyActions::class);
        $request = $this->asks($bot);
        $actions->renew($request, $this->panelActor(), $actions->termEnd($request));
        $told = count($this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
        $standing->judge($read, Carbon::now());
        self::assertCount($told, $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID), 'no warning of the end it had');
        self::assertNull($bot->refresh()->expiry_warned_at);

        Carbon::setTestNow('2026-12-28 12:00:00');
        $standing->judge($bot->refresh(), Carbon::now());
        $sent = $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID);
        self::assertSame(self::text(BotText::AgencyExpiring, ['date' => Persian::date(Carbon::parse('2026-12-30 12:00:00'), withTime: true)]), end($sent), 'the new end is warned of in its time');
    }

    public function testWithoutATermOrAnEndToMoveOnTheOwnerNamesTheEnd(): void
    {
        $this->loginAsAdmin();
        $this->term(0);
        $bot = $this->agentWithAnEnd();
        $request = $this->asks($bot);
        $url = "/api/admin/agency/requests/{$request->id}/approve";

        self::assertNull($this->decode($this->get('/api/admin/agency/requests'))['requests'][0]['term_end'], 'no term in the program');
        $refused = $this->postJson($url, []);
        self::assertSame([422, ['expires_at' => [AgencyActions::RENEWAL_NO_TERM]]], [$refused->getStatusCode(), $this->decode($refused)['errors']]);

        // The owner took the agent's end away meanwhile: the term has nothing to count on from.
        $this->term(30);
        $this->sellingTerms($bot, null);
        self::assertNull($this->decode($this->get('/api/admin/agency/requests'))['requests'][0]['term_end']);
        $refused = $this->postJson($url, []);
        self::assertSame([422, ['expires_at' => [AgencyActions::RENEWAL_NO_END]]], [$refused->getStatusCode(), $this->decode($refused)['errors']]);

        self::assertSame(200, $this->postJson($url, ['expires_at' => '2026-12-01T00:00'])->getStatusCode(), 'an end of their own still goes');
        self::assertSame('2026-12-01 00:00:00', $bot->refresh()->agency_expires_at?->format('Y-m-d H:i:s'));
    }

    public function testASuspensionByHandOutlivesARenewalAndTheAgentIsNotToldTheyAreSellingAgain(): void
    {
        $this->loginAsAdmin();
        $bot = $this->agentBot();
        $this->sellingTerms($bot, Carbon::now()->subMinute());
        $this->service(AgencyStanding::class)->suspendByHand($bot->refresh(), 'تسویه نشده');
        $request = $this->asks($bot);
        $told = count($this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));

        self::assertSame(200, $this->postJson("/api/admin/agency/requests/{$request->id}/approve", [])->getStatusCode());

        self::assertSame(SellingSuspension::Manual, $bot->refresh()->sellingSuspension(), "the owner's hand still holds it");
        self::assertSame([self::text(BotText::AgencyRenewed, ['date' => Persian::date(Carbon::parse('2026-10-31 12:00:00'), withTime: true)])], array_slice($this->telegram()->sentTo(self::AGENT_TELEGRAM_ID), $told));
    }

    public function testARejectedRenewalTellsTheAgentWhyAndTheyMayAskAgain(): void
    {
        $this->loginAsAdmin();
        $bot = $this->agentWithAnEnd();
        $request = $this->asks($bot);

        $rejected = $this->postJson("/api/admin/agency/requests/{$request->id}/reject", ['note' => 'اول بدهی را صاف کنید']);
        self::assertSame(200, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame(['rejected', 'اول بدهی را صاف کنید'], [$this->decode($rejected)['request']['status'], $this->decode($rejected)['request']['reason']]);
        self::assertSame([self::text(BotText::AgencyRenewalRejected, ['note' => self::text(BotText::AdminNote, ['comment' => 'اول بدهی را صاف کنید'])])], $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
        self::assertSame(self::END, $bot->refresh()->agency_expires_at?->format('Y-m-d H:i:s'), 'its end as it was');

        $this->loginAsAgent($bot);
        $account = $this->decode($this->get('/api/agent/account'))['account'];
        self::assertSame(['rejected', 'اول بدهی را صاف کنید', true], [$account['renewal']['status'], $account['renewal']['reason'], $account['actions']['request_renewal']]);
    }

    public function testEndingTheAgencyClosesARenewalStillWaiting(): void
    {
        $bot = $this->agentWithAnEnd();
        $agent = $bot->agent ?? self::fail('no agent');
        $request = $this->asks($bot);

        $this->service(AgencyActions::class)->revoke($agent, null);

        $request->refresh();
        self::assertSame([AgencyRequestStatus::Rejected, AgencyActions::RENEWAL_CLOSED, null], [$request->status, $request->reason, $request->reviewer]);
        self::assertNotContains(self::text(BotText::AgencyRenewalRejected, ['note' => '']), $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID), "the agency's end tells them");
        $this->loginAsAdmin();
        $refused = $this->postJson("/api/admin/agency/requests/{$request->id}/approve", []);
        self::assertSame([422, ['status' => ['این درخواست رد شده است.']]], [$refused->getStatusCode(), $this->decode($refused)['errors']]);
        self::assertNull($this->service(AgencyActions::class)->requestRenewal($agent->refresh()), 'no agent, no renewal');

        // A return starts afresh: the renewal closed with the agency before is none of their account's.
        $this->service(AgencyActions::class)->approve($this->agencyRequest($agent), $this->panelActor(), $this->gold, null);
        $this->loginAsAgent($bot->refresh());
        self::assertNull($this->decode($this->get('/api/agent/account'))['account']['renewal']);
    }

    public function testARenewalAndARequestToBecomeAnAgentNeverStandForEachOther(): void
    {
        $bot = $this->agentWithAnEnd();
        $agent = $bot->agent ?? self::fail('no agent');
        $renewal = $this->asks($bot);
        $customer = $this->customer(['telegram_id' => 900_001]);
        $join = $this->agencyRequest($customer);
        $actions = $this->service(AgencyActions::class);

        self::assertSame([null, $renewal->id], [$actions->pendingRequest($agent)?->id, AgencyActions::pendingRenewal($agent)?->id]);
        self::assertSame([$join->id, null, false], [$actions->pendingRequest($customer)?->id, AgencyActions::pendingRenewal($customer)?->id, AgencyActions::mayRequestRenewal($customer)]);
        self::assertSame(AgencyRequestKind::Join, $join->refresh()->kind, 'a request to become an agent by default');

        $this->expectException(\LogicException::class);
        $actions->approve($renewal, $this->panelActor(), $this->gold, null);
    }

    /** The agent's shop (the fixtures' agent, @agent_shop_bot) with its agency ending at END. */
    private function agentWithAnEnd(): Bot
    {
        return $this->sellingTerms($this->agentBot(), Carbon::parse(self::END));
    }

    /** The shop's agent asks for a renewal, as their panel or the main bot's button does. */
    private function asks(Bot $bot): AgencyRequest
    {
        $agent = User::query()->findOrFail($bot->user_id);

        return $this->service(AgencyActions::class)->requestRenewal($agent) ?? self::fail('The agent may not ask for a renewal.');
    }

    /** The program's «مدت نمایندگی», in days; 0 for none. */
    private function term(int $days): void
    {
        $settings = $this->service(AgencySettings::class);
        $settings->save(['term_days' => $days] + $settings->present());
    }
}
