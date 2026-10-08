<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\AgencyReview;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Tests\BotTestCase;

/**
 * A request to become an agent in the report group: its report with «تایید» / «رد» for the bot's admins only, «تایید»
 * asking which level — a button each, named with its price per GB (the program's default credit comes with it) —, the
 * customer told as from the agents page, and the verdict reported under the request, whose buttons go — here at once,
 * or when it was decided elsewhere. (Presses in an agent's group: Security\GroupButtonsAcrossShopsTest.)
 */
final class AgencyReviewTest extends BotTestCase
{
    /** The request's report in the group, as the buttons' message. */
    private const REQUEST_POST = 3131;

    /** @var array<string, int> */
    private array $threads;

    private User $customer;
    private AgencyLevel $level;

    protected function setUp(): void
    {
        parent::setUp();

        $this->threads = $this->reportGroup();
        $this->agencyProgram(credit: '200000');
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->customer = $this->customer(['username' => 'ali']);
        $this->level = $this->agencyLevel();
    }

    public function testARequestIsReportedWithItsButtons(): void
    {
        $this->send($this->tap('agency:apply'));
        $this->send($this->message('کانال فروش دارم'));

        $request = AgencyRequest::query()->sole();
        $report = ReportMessage::query()->where('topic', 'agency')->sole();
        self::assertSame("agency:{$request->id}", $report->ref);
        self::assertStringContainsString("🤝 <b>درخواست نمایندگی</b> · #{$request->id}", $report->text);
        self::assertStringContainsString('<code>' . self::CHAT . '</code>', $report->text, 'who asked');
        self::assertStringContainsString('📝 توضیح مشتری: کانال فروش دارم', $report->text);
        self::assertSame(AgencyReview::buttons($request->id), (array) $report->keyboard);
        self::assertSame([["ag:no:{$request->id}", "ag:ok:{$request->id}"]], array_map(static fn(array $row): array => array_column($row, 'callback_data'), (array) $report->keyboard), 'the wire format: «رد» and «تایید»');
    }

    public function testABotAdminApprovesOnALevelAndTheCustomerIsTold(): void
    {
        $other = $this->agencyLevel(['name' => 'برنزی', 'price_per_gb' => '4000']);
        $request = $this->agencyRequest($this->customer);

        $this->send($this->press("ag:ok:{$request->id}"));
        self::assertSame(['editMessageReplyMarkup', 'answerCallbackQuery'], $this->calls());
        self::assertSame(["ag:lv:{$request->id}:{$this->level->id}", "ag:lv:{$request->id}:{$other->id}", "ag:bk:{$request->id}"], $this->callbacks(0), 'a level a button, in their order, then back');
        $labels = array_column(array_merge(...$this->inlineKeyboard(0)), 'text');
        foreach ([[$this->level, $labels[0]], [$other, $labels[1]]] as [$level, $label]) {
            self::assertStringContainsString($level->name, $label);
            self::assertStringContainsString(Money::format($level->price_per_gb), $label, 'named with its price per GB');
        }
        self::assertSame(AgencyRequestStatus::Pending, $request->refresh()->status, 'a level is still to pick');

        $this->send($this->press("ag:lv:{$request->id}:{$other->id}"));
        $request->refresh();
        self::assertSame([AgencyRequestStatus::Approved, $other->id, '@boss'], [$request->status, $request->level_id, $request->reviewer]);
        $agent = $this->customer->refresh();
        self::assertSame([$other->id, '200000.00'], [$agent->agency_level_id, $agent->credit_limit], "the program's default credit");

        self::assertSame([self::text(BotText::AgencyApproved, ['level' => 'برنزی', 'price' => '۴٬۰۰۰ تومان', 'credit' => '۲۰۰٬۰۰۰ تومان'])], $this->telegram()->sentTo(self::CHAT));
        self::assertSame(['sendMessage', 'editMessageReplyMarkup', 'answerCallbackQuery'], $this->calls());
        self::assertSame(['inline_keyboard' => []], $this->markup(1), 'nothing left to press');
        self::assertSame(1, ReportMessage::query()->where('reply_ref', "agency:{$request->id}")->where('clears_buttons', true)->count(), 'the verdict goes under the request');
    }

    public function testABotAdminRejectsAndBackLeavesItOpen(): void
    {
        $request = $this->agencyRequest($this->customer);

        $this->send($this->press("ag:ok:{$request->id}"));
        $this->send($this->press("ag:bk:{$request->id}"));
        self::assertSame(["ag:no:{$request->id}", "ag:ok:{$request->id}"], $this->callbacks(0));
        self::assertSame(AgencyRequestStatus::Pending, $request->refresh()->status);

        $this->send($this->press("ag:no:{$request->id}"));
        self::assertSame([AgencyRequestStatus::Rejected, null, '@boss'], [$request->refresh()->status, $request->reason, $request->reviewer]);
        self::assertSame([self::text(BotText::AgencyRejected, ['note' => ''])], $this->telegram()->sentTo(self::CHAT), 'with no note');
        self::assertFalse($this->customer->refresh()->isAgent());
    }

    /** A bot admin is the shop's customer too: their own request is another admin's to approve — its buttons stay for them. */
    public function testAnAdminsOwnRequestIsAnotherAdminsToApprove(): void
    {
        $boss = User::query()->where('telegram_id', self::GROUP_ADMIN)->sole();
        $request = $this->agencyRequest($boss);

        $this->send($this->press("ag:lv:{$request->id}:{$this->level->id}"));

        self::assertSame(['answerCallbackQuery'], $this->calls(), 'its buttons stay');
        self::assertSame(ActorRefusedException::OWN_REQUEST, $this->popup());
        self::assertSame(AgencyRequestStatus::Pending, $request->refresh()->status);
        self::assertFalse($boss->refresh()->isAgent());

        $this->admin(['telegram_id' => 7070, 'username' => 'reza']);
        $this->send($this->press("ag:lv:{$request->id}:{$this->level->id}", 7070));
        self::assertSame([AgencyRequestStatus::Approved, '@reza'], [$request->refresh()->status, $request->reviewer]);
    }

    public function testOnlyTheBotsAdminsMayDecide(): void
    {
        $request = $this->agencyRequest($this->customer);

        foreach ([self::CHAT, self::GROUP_MEMBER] as $who) {
            $this->send($this->press("ag:lv:{$request->id}:{$this->level->id}", $who));
            self::assertSame(['answerCallbackQuery'], $this->calls());
            self::assertSame('true', $this->params(0)['show_alert']);
        }
        self::assertSame(AgencyRequestStatus::Pending, $request->refresh()->status);
    }

    public function testARequestDecidedElsewhereLosesItsButtons(): void
    {
        $request = $this->agencyRequest($this->customer);
        $this->service(AgencyActions::class)->reject($request, $this->panelActor(), null);

        $this->send($this->press("ag:lv:{$request->id}:{$this->level->id}"));

        self::assertSame(['editMessageReplyMarkup', 'answerCallbackQuery'], $this->calls());
        self::assertSame('این درخواست رد شده است.', $this->popup(), 'what it came to');
        self::assertSame(AgencyRequestStatus::Rejected, $request->refresh()->status);
        self::assertFalse($this->customer->refresh()->isAgent());
    }

    public function testWithoutLevelsThereIsNothingToApproveOn(): void
    {
        $request = $this->agencyRequest($this->customer);
        $this->level->delete();

        $this->send($this->press("ag:ok:{$request->id}"));

        self::assertSame(['answerCallbackQuery'], $this->calls(), 'the buttons stay');
        self::assertStringContainsString('هنوز سطح نمایندگی تعریف نشده است', (string) $this->popup());
        self::assertSame(AgencyRequestStatus::Pending, $request->refresh()->status);
    }

    /** A press on a button under the request's report in the agency topic — by a bot admin unless told otherwise. */
    private function press(string $data, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap($data, self::REQUEST_POST, $this->threads['agency'], $from);
    }
}
