<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyEnd;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «🤝 نمایندگان» in the bot (Telegram\Admin\Sections\Owner\Agency): the program's numbers, a request approved on a level
 * and terms or rejected with a note, an agent's renewal request listed apart and approved with an end (the program's,
 * one picked, none) or rejected, an agent's page — their terms changed, traffic added, servers hidden, selling
 * stopped and let go, the agency ended —, the levels added, moved and deleted, and the program's rules — every write
 * through the panel's own routes; the owner's and the main shop's admins', never an admin's own request.
 */
final class OwnerAgencyTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
    }

    public function testARequestIsApprovedOnALevelWithTheProgramsTermsChanged(): void
    {
        $bronze = $this->agencyLevel(['name' => 'برنزی', 'price_per_gb' => '4000']);
        $gold = $this->agencyLevel(['name' => 'طلایی']);
        $this->agencyProgram(credit: '50000');
        $customer = $this->customer(['telegram_id' => 8100, 'first_name' => 'Reza']);
        $request = $this->agencyRequest($customer);

        $this->send($this->tap(Nav::to('ag'), chat: self::OWNER));
        self::assertStringContainsString('درخواست‌های در انتظار: <b>۱</b>', $this->said()[0]);

        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        self::assertContains(Nav::to('agrv', 'show', $request->id), $this->callbacks(0), 'the waiting one first');
        $this->send($this->tap(Nav::to('agrv', 'show', $request->id), chat: self::OWNER));
        self::assertStringContainsString('کانال فروش دارم', $this->said()[0], 'what the customer wrote');

        $this->send($this->tap(Nav::to('agra', 'new', $request->id), chat: self::OWNER));
        self::assertStringContainsString('برنزی', $this->said()[0], 'the first level offered');
        self::assertStringContainsString('۵۰٬۰۰۰ تومان', $this->said()[0], 'the program\'s starting credit');

        $this->send($this->tap(Nav::to('agra', 'lvp', $gold->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agra', 'cr'), chat: self::OWNER));
        $this->send($this->message('۱۰۰ هزار تومان', chat: self::OWNER));
        self::assertStringContainsString('۱۰۰٬۰۰۰ تومان', $this->said()[0]);

        $this->send($this->tap(Nav::to('agra', 'save'), chat: self::OWNER));
        $customer->refresh();
        self::assertSame([$gold->id, '100000'], [$customer->agency_level_id, (string) (int) $customer->credit_limit], 'approved through the panel\'s route');
        self::assertSame('approved', $request->refresh()->status->value);
        self::assertNotContains(Nav::to('agra', 'new', $request->id), $this->callbacks($this->buttonsCall()), 'approved: no approving again');
        self::assertNotNull($bronze->id);
    }

    public function testARequestIsRejectedWithANoteTheCustomerReads(): void
    {
        $this->agencyLevel();
        $request = $this->agencyRequest($this->customer(['telegram_id' => 8100]));

        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrv', 'no', $request->id), chat: self::OWNER));
        self::assertSame([Nav::to('agrv', 'nn', $request->id), Nav::to('agrv', 'nw', $request->id), Nav::to('agrv', 'show', $request->id)], $this->callbacks(0), 'asked first: with a note (on the right), without, or not');
        $this->send($this->message('فعلا ظرفیت نداریم', chat: self::OWNER));
        self::assertSame('pending', $request->refresh()->status->value, 'a typed note alone is no yes');

        $this->send($this->tap(Nav::to('agrv', 'nw', $request->id), chat: self::OWNER));
        $this->send($this->message('فعلا ظرفیت نداریم', chat: self::OWNER));

        $request->refresh();
        self::assertSame(['rejected', 'فعلا ظرفیت نداریم'], [$request->status->value, $request->reason]);
        self::assertStringContainsString('درخواست رد شد', implode("\n", $this->said()));
    }

    public function testAnAgentsTrafficIsTypedAsEveryTrafficQuestionTakesIt(): void
    {
        $bot = $this->agentBot(traffic: 20);
        $agent = $bot->agent ?? self::fail('no agent');

        $this->send($this->tap(Nav::to('agt', 'show', $agent->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agt', 'sub'), chat: self::OWNER));
        $this->send($this->message('۵ گیگ', chat: self::OWNER));
        self::assertStringContainsString('۵ گیگابایت از حجم ربات', $this->said()[0], 'its unit typed with it');
        $this->send($this->tap(Nav::to('agt', 'nn'), chat: self::OWNER));
        self::assertSame(Traffic::bytesOfGb(15), $bot->refresh()->trafficBalance());

        $this->send($this->tap(Nav::to('agt', 'add'), chat: self::OWNER));
        $this->send($this->message('۲٫۵', chat: self::OWNER));
        $this->send($this->tap(Nav::to('agt', 'nn'), chat: self::OWNER));
        self::assertSame(Traffic::bytesOfGb('17.5'), $bot->refresh()->trafficBalance(), 'a decimal, as the panel takes it');
    }

    public function testAnAgentsPageChangesTheirTermsTrafficHiddenServersAndSelling(): void
    {
        $this->fakePanel();
        $server = $this->fakeServer('آلمان');
        $bot = $this->agentBot(traffic: 20);
        $agent = $bot->agent ?? self::fail('no agent');

        $this->send($this->tap(Nav::to('agl'), chat: self::OWNER));
        self::assertContains(Nav::to('agv', 'show', $agent->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('agv', 'show', $agent->id), chat: self::OWNER));
        self::assertStringContainsString('agent_shop_bot', $this->said()[0]);
        self::assertStringContainsString('۲۰ گیگابایت', $this->said()[0]);

        $this->send($this->tap(Nav::to('agt', 'show', $agent->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agt', 'gp', 1, 0), chat: self::OWNER));
        self::assertStringContainsString('۱۰ گیگابایت به حجم ربات', $this->said()[0], 'the change asked first');
        self::assertSame([Nav::to('agt', 'nn'), Nav::to('agt', 'nw'), Nav::to('agt', 'quit')], $this->callbacks(0), 'with a note on the right, without, or not');
        $this->send($this->tap(Nav::to('agt', 'nw'), chat: self::OWNER));
        $this->send($this->message('هدیه شروع کار', chat: self::OWNER));
        self::assertSame(Traffic::bytesOfGb(30), $bot->refresh()->trafficBalance(), 'added through the panel\'s route, once');
        self::assertStringContainsString('هدیه شروع کار', implode("\n", $this->said()), 'on the ledger');

        $this->send($this->tap(Nav::to('agh', 'show', $agent->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agh', 'srv', $server->id, 1), chat: self::OWNER));
        self::assertStringContainsString('🙈 آلمان', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '');
        self::assertContains(Nav::to('agh', 'srv', $server->id, 0), $this->callbacks($this->buttonsCall()), 'shown again by its own button');

        $this->send($this->tap(Nav::to('agv', 'sus', $agent->id), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'susn', $agent->id), Nav::to('agv', 'susw', $agent->id), Nav::to('agv', 'show', $agent->id)], $this->callbacks(0), 'asked first: with a note (on the right), without, or not');
        $this->send($this->message('👛 کیف پول', chat: self::OWNER));
        self::assertNull($bot->refresh()->sellingSuspension(), 'a typed text alone stops nothing');
        $this->send($this->tap(Nav::to('agv', 'susn', $agent->id), chat: self::OWNER));
        self::assertSame('manual', $bot->refresh()->sellingSuspension()?->value);
        self::assertContains(Nav::to('agv', 'res', $agent->id), $this->callbacks($this->buttonsCall()));
        $this->send($this->tap(Nav::to('agv', 'res', $agent->id), chat: self::OWNER));
        self::assertNull($bot->refresh()->sellingSuspension());

        $this->send($this->tap(Nav::to('age', 'edit', $agent->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('age', 'crz'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('age', 'mn'), chat: self::OWNER));
        $this->send($this->message('۲۰۰۰۰۰', chat: self::OWNER));
        $this->send($this->tap(Nav::to('age', 'save'), chat: self::OWNER));
        self::assertSame('200000', (string) (int) $bot->refresh()->monthly_minimum, 'its minimum changed, the rest kept');

        $this->send($this->tap(Nav::to('agv', 'rev', $agent->id), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'revn', $agent->id), Nav::to('agv', 'revw', $agent->id), Nav::to('agv', 'show', $agent->id)], $this->callbacks(0), 'asked first, a note offered (on the right)');
        $where = '<i>مدیریت › نمایندگان › نماینده‌ها › نماینده ';
        self::assertStringContainsString($where, $this->said()[0], 'asked from the agent\'s page, as every confirmation says');
        $this->send($this->tap(Nav::to('agv', 'revw', $agent->id), chat: self::OWNER));
        self::assertStringContainsString($where, $this->said()[0], 'the note\'s question too: the confirmation was no place of its own');
        $this->send($this->message('پایان همکاری', chat: self::OWNER));
        self::assertNull($agent->refresh()->agency_level_id, 'the agency ended, with the note');
    }

    public function testTheHubSaysWhenTheMainMenuLacksTheAgencyButton(): void
    {
        $this->agencyLevel();
        $this->agencyProgram();
        $layouts = $this->service(KeyboardLayouts::class);

        $layouts->save('start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید', 'style' => null], ['action' => 'agency', 'label' => 'نمایندگی', 'style' => null]]]]);
        $this->send($this->tap(Nav::to('ag'), chat: self::OWNER));
        self::assertStringNotContainsString('در منوی ربات نیست', $this->said()[0]);
        self::assertNotContains(Nav::to('kbl', 'show', 'start'), $this->callbacks(0));

        $layouts->save('start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید', 'style' => null]]]]);
        $this->send($this->tap(Nav::to('ag'), chat: self::OWNER));
        self::assertStringContainsString('⚠️ دکمه «نمایندگی» در منوی ربات نیست', $this->said()[0]);
        self::assertContains(Nav::to('kbl', 'show', 'start'), $this->callbacks(0), '«⌨️ افزودن دکمه در منوی شروع»: straight to the start menu');
    }

    public function testHiddenServersComeAPageAtATimeAndEachButtonSetsWhatItSays(): void
    {
        $this->fakePanel();
        $servers = [];
        for ($n = 1; $n <= 45; $n++) {
            $servers[] = $this->fakeServer('S' . $n);
        }
        $bot = $this->agentBot();
        $agent = $bot->agent ?? self::fail('no agent');
        $last = $servers[44];

        $this->send($this->tap(Nav::to('agh', 'show', $agent->id), chat: self::OWNER));
        self::assertLessThanOrEqual(100, count($this->callbacks(0)), 'within Telegram\'s keyboard');
        self::assertNotContains(Nav::to('agh', 'srv', $last->id, 1), $this->callbacks(0), 'the 45th on the next page');
        self::assertContains(Nav::to('agh', 'page', 2), $this->callbacks(0));

        $this->send($this->tap(Nav::to('agh', 'page', 2), chat: self::OWNER));
        self::assertContains(Nav::to('agh', 'srv', $last->id, 1), $this->callbacks(0));
        $this->send($this->tap(Nav::to('agh', 'srv', $last->id, 1), chat: self::OWNER));
        // A stale screen's second tap hides it again, never shows it.
        $this->send($this->tap(Nav::to('agh', 'srv', $last->id, 1), chat: self::OWNER));
        self::assertSame([$last->id], $bot->refresh()->hiddenServerIds());
        self::assertContains(Nav::to('agh', 'srv', $last->id, 0), $this->callbacks($this->buttonsCall()), 'still on its page');

        $this->send($this->tap(Nav::to('agh', 'tab', 'p'), chat: self::OWNER));
        self::assertStringContainsString('پلن‌های پنهان', $this->said()[0]);
    }

    public function testTheTermsStartFromTheAgentReadFreshInAnyShop(): void
    {
        $this->agencyLevel();
        $bot = $this->agentBot(traffic: 5);
        $agent = $bot->agent ?? self::fail('no agent');
        $this->sellingTerms($bot, null, '100000');

        // Working the agent's own shop, their list read (its rows kept), then their minimum changed in the panel.
        $this->send($this->tap(Nav::to('shop', 'pick', $bot->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agl'), chat: self::OWNER));
        $this->sellingTerms($bot, null, '300000');

        $this->send($this->tap(Nav::to('age', 'edit', $agent->id), chat: self::OWNER));
        self::assertStringContainsString('۳۰۰٬۰۰۰ تومان', $this->said()[0], 'the minimum as it is now');
        $this->send($this->tap(Nav::to('age', 'crz'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('age', 'save'), chat: self::OWNER));
        self::assertSame('300000', (string) (int) $bot->refresh()->monthly_minimum, 'the panel\'s change kept');
    }

    public function testAnAgentsPageOpensByTheirCustomerIdWithoutTheList(): void
    {
        $bot = $this->agentBot(traffic: 5);
        $agent = $bot->agent ?? self::fail('no agent');

        // The customer page's «🤝 مدیریت نماینده» opens it straight (Nav::to('agv', 'show', $userId)).
        $this->send($this->tap(Nav::to('agv', 'show', $agent->id), chat: self::OWNER));
        self::assertStringContainsString('🤝 نماینده', $this->said()[0]);
        self::assertStringContainsString('۵ گیگابایت', $this->said()[0]);
        self::assertContains(Nav::to('age', 'edit', $agent->id), $this->callbacks(0));

        $customer = $this->customer(['telegram_id' => 8200]);
        $this->send($this->tap(Nav::to('agv', 'show', $customer->id), chat: self::OWNER));
        self::assertStringContainsString('🤝 نماینده‌ها', $this->said()[0], 'no agent: the agents list');
    }

    public function testTheLevelsAreAddedMovedAndDeletedAndTheRulesSaved(): void
    {
        $first = $this->agencyLevel(['name' => 'برنزی']);
        $this->agent($first);

        $this->send($this->tap(Nav::to('agkn', 'new'), chat: self::OWNER));
        $this->send($this->message('الماس', chat: self::OWNER));
        $this->send($this->message('۲۵۰۰', chat: self::OWNER));
        self::assertStringContainsString('۲۵۰٬۰۰۰ تومان', $this->said()[0], 'what 100 GB costs on it');
        $this->send($this->tap(Nav::to('agkn', 'save'), chat: self::OWNER));
        $level = AgencyLevel::query()->where('name', 'الماس')->sole();
        self::assertSame('2500', (string) (int) $level->price_per_gb);

        $this->send($this->tap(Nav::to('agkv', 'up', $level->id), chat: self::OWNER));
        self::assertSame([$level->id, $first->id], AgencyLevel::query()->orderBy('sort')->pluck('id')->all());

        $this->send($this->tap(Nav::to('agkv', 'del', $first->id), chat: self::OWNER));
        self::assertStringContainsString('به سطح دیگری ببرید', (string) $this->popup(), 'an agent is on it');
        $this->send($this->tap(Nav::to('agkv', 'del', $level->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agkv', 'gone', $level->id), chat: self::OWNER));
        self::assertNull(AgencyLevel::query()->find($level->id));

        $this->send($this->tap(Nav::to('ags'), chat: self::OWNER));
        $rules = $this->said()[0];
        self::assertStringContainsString('مدت نمایندگی: <b>بدون پایان</b>', $rules, '0 days: none, as an agent\'s terms say it');
        self::assertStringContainsString('کف خرید ماهانه: <b>بدون کف خرید</b>', $rules);
        self::assertStringNotContainsString('<i>اعتبار اولیه، مدت نمایندگی', $rules, 'the long note folded into the help, not an italic wall');
        self::assertStringContainsString('اعتبار اولیه، مدت نمایندگی', (string) strstr($rules, '<blockquote expandable>'));
        $this->send($this->tap(Nav::to('ags', 'e', 0), chat: self::OWNER));
        self::assertFalse($this->service(AgencySettings::class)->enabled(), 'switched off through PUT /agency/settings');
    }

    public function testAnAgentsTermsHaveOneWayOutAskedFirstOnceChanged(): void
    {
        $bot = $this->agentBot();
        $agent = $bot->agent ?? self::fail('no agent');
        $this->send($this->tap(Nav::to('agv', 'show', $agent->id), chat: self::OWNER));

        $this->send($this->tap(Nav::to('age', 'edit', $agent->id), chat: self::OWNER));
        $labels = array_column(array_merge(...$this->inlineKeyboard(0)), 'text', 'callback_data');
        self::assertSame(Words::BACK, $labels[Nav::to('age', 'quit')] ?? null, 'as they started: «⬅️ بازگشت» the one way out');
        self::assertNotContains(Words::CANCEL, $labels);

        $this->send($this->tap(Nav::to('age', 'exd', 30), chat: self::OWNER));
        $buttons = array_column(array_merge(...$this->inlineKeyboard(0)), null, 'callback_data');
        self::assertSame(['🗑 کنار گذاشتن', 'danger'], [$buttons[Nav::to('age', 'quit')]['text'] ?? null, $buttons[Nav::to('age', 'quit')]['style'] ?? null], 'changed: «🗑 کنار گذاشتن», red');
        self::assertNotContains(Words::BACK, array_column($buttons, 'text'), 'no second way out');

        // The way out, an older window's «⬅️ بازگشت» the same, asks first.
        $this->send($this->tap(Nav::to('age', 'quit'), chat: self::OWNER));
        self::assertStringContainsString('تغییرها کنار گذاشته شوند؟', $this->said()[0], 'the words a form asks with');
        self::assertSame([Nav::to('age', 'show'), Nav::to('age', 'gone')], $this->callbacks(0));
        $this->send($this->tap(Nav::to('age', 'gone'), chat: self::OWNER));
        self::assertNull($bot->refresh()->agency_expires_at, 'dropped: nothing saved');
        self::assertContains(Nav::to('age', 'edit', $agent->id), $this->callbacks(0), 'back on the agent\'s page');
    }

    public function testAShopsAdminDecidesTheRequestsButNeverTheirOwn(): void
    {
        $request = $this->agencyRequest($this->customer(['telegram_id' => 8100]));
        $admin = $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->message('/admin', chat: 7100));
        self::assertContains(Nav::to('ag'), $this->callbacks(0), 'the agency, on the home');
        $this->send($this->tap(Nav::to('agrv', 'nn', $request->id), chat: 7100));
        $request->refresh();
        self::assertSame(['rejected', 'tg:7100'], [$request->status->value, $request->reviewer], 'through the panel\'s route, under their own name');

        // Their own request is another admin's to approve.
        $own = $this->agencyRequest($admin);
        $seat = $this->service(AdminSeats::class)->of($admin);
        self::assertNotNull($seat);
        try {
            $this->service(PanelBridge::class)->for($seat)->post("/agency/requests/{$own->id}/approve", ['level_id' => $this->agencyLevel()->id, 'credit_limit' => 0]);
            self::fail('an admin approved their own agency');
        } catch (ApiRefusal $refusal) {
            self::assertSame([403, ActorRefusedException::OWN_REQUEST], [$refusal->status, $refusal->getMessage()]);
        }
        self::assertSame('pending', $own->refresh()->status->value);
    }

    public function testAnAdminWhoIsAnAgentTooChangesNothingOfTheirOwnAgency(): void
    {
        $this->fakePanel();
        $server = $this->fakeServer('آلمان');
        $this->agencyLevel();
        $level = $this->agencyLevel(['name' => 'نقره‌ای']);
        $agent = $this->agent(overrides: ['telegram_id' => 7100, 'first_name' => 'Sara', 'role' => UserRole::Admin]);
        $bot = $this->agentBot($agent, traffic: 20);
        $was = $agent->agency_level_id;
        $own = $this->bridge($agent);
        $other = $this->bridge($this->customer(['telegram_id' => 7200, 'first_name' => 'Reza', 'role' => UserRole::Admin]));
        $other->post("/agency/agents/{$agent->id}/suspend", ['note' => 'تسویه نشده']);
        self::assertSame('manual', $bot->refresh()->sellingSuspension()?->value, 'another admin stops their selling');

        $refusals = [
            'their terms' => static fn() => $own->put("/agency/agents/{$agent->id}", ['level_id' => $level->id, 'credit_limit' => '50000']),
            'their traffic' => static fn() => $own->post("/agency/agents/{$agent->id}/traffic", ['gb' => 5, 'note' => 'برای خودم']),
            'their selling let go' => static fn() => $own->post("/agency/agents/{$agent->id}/resume"),
            'their selling stopped' => static fn() => $own->post("/agency/agents/{$agent->id}/suspend", ['note' => 'دوباره']),
            'their hidden servers' => static fn() => $own->put("/agency/agents/{$agent->id}/hidden", ['server_ids' => [$server->id], 'plan_ids' => []]),
            'their agency ended' => static fn() => $own->post("/agency/agents/{$agent->id}/revoke", ['note' => 'پایان']),
        ];
        foreach ($refusals as $what => $call) {
            $refused = self::refusal($call);
            self::assertSame([403, ActorRefusedException::OWN_AGENCY], [$refused->status, $refused->getMessage()], $what);
        }
        $agent->refresh();
        $bot->refresh();
        self::assertSame([$was, '0'], [$agent->agency_level_id, (string) (int) $agent->credit_limit], 'their terms as they were');
        self::assertSame([Traffic::bytesOfGb(20), 'manual', []], [$bot->trafficBalance(), $bot->sellingSuspension()?->value, $bot->fresh()?->hiddenServerIds()], 'their traffic, their selling and their servers');
        self::assertTrue($agent->isAgent(), 'still an agent');

        // From their bot's management, the same refusal.
        $this->send($this->tap(Nav::to('agv', 'res', $agent->id), chat: 7100));
        self::assertSame(ActorRefusedException::OWN_AGENCY, $this->popup());
        self::assertSame('manual', $bot->refresh()->sellingSuspension()?->value);

        // The owner, and another admin, make each of them.
        $this->send($this->tap(Nav::to('agv', 'res', $agent->id), chat: self::OWNER));
        self::assertNull($bot->refresh()->sellingSuspension(), 'the owner lets their selling go');
        $other->post("/agency/agents/{$agent->id}/traffic", ['gb' => 5]);
        $other->put("/agency/agents/{$agent->id}/hidden", ['server_ids' => [$server->id], 'plan_ids' => []]);
        $other->put("/agency/agents/{$agent->id}", ['level_id' => $level->id, 'credit_limit' => '50000']);
        self::assertSame([Traffic::bytesOfGb(25), [$server->id]], [$bot->refresh()->trafficBalance(), $bot->fresh()?->hiddenServerIds()]);
        self::assertSame([$level->id, '50000'], [$agent->refresh()->agency_level_id, (string) (int) $agent->credit_limit]);
        $other->post("/agency/agents/{$agent->id}/revoke", ['note' => 'پایان همکاری']);
        self::assertNull($agent->refresh()->agency_level_id, 'the agency ended by another admin');
    }

    public function testAnAdminWorkingTheirOwnAgentsShopChangesNothingOfTheirAgencyThereEither(): void
    {
        [$agent, $own] = $this->adminInTheirOwnAgentsShop();
        $silver = $this->agencyLevel(['name' => 'نقره‌ای']);

        $refused = self::refusal(static fn() => $own->put("/agency/agents/{$agent->id}", ['level_id' => $silver->id, 'credit_limit' => '50000']));
        self::assertSame([403, ActorRefusedException::OWN_AGENCY], [$refused->status, $refused->getMessage()], 'their agency, from whichever shop they work');
        $agent->refresh();
        self::assertNotSame($silver->id, $agent->agency_level_id);
        self::assertSame('0', (string) (int) $agent->credit_limit);
    }

    public function testAnAdminWorkingTheirOwnAgentsShopRenewsNotTheirOwnAgency(): void
    {
        [$agent, $own] = $this->adminInTheirOwnAgentsShop();
        $renewal = $this->renewalRequest($agent);

        $refused = self::refusal(static fn() => $own->post("/agency/requests/{$renewal->id}/approve", ['expires_at' => null]));
        self::assertSame([403, ActorRefusedException::OWN_REQUEST], [$refused->status, $refused->getMessage()], 'their own renewal, from whichever shop they work');
        self::assertSame('pending', $renewal->refresh()->status->value);
    }

    public function testAnAdminsOwnRequestsOfferTheirRejectionButNoApproval(): void
    {
        $this->agencyLevel();
        $boss = $this->customer(['telegram_id' => 7100, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
        $this->customer(['telegram_id' => 7200, 'first_name' => 'Reza', 'role' => UserRole::Admin]);
        $agent = $this->agent(overrides: ['telegram_id' => 7300, 'first_name' => 'Sara', 'role' => UserRole::Admin]);
        $this->agentBot($agent);
        $join = $this->agencyRequest($boss);
        $renewal = $this->renewalRequest($agent);
        $note = 'این درخواست خود شماست؛ تاییدش با مدیر دیگری است.';

        // Their request to become an agent.
        $this->send($this->tap(Nav::to('agr'), chat: 7100));
        $this->send($this->tap(Nav::to('agrv', 'show', $join->id), chat: 7100));
        self::assertStringContainsString('کانال فروش دارم', $this->said()[0], 'the request\'s own card');
        self::assertNotContains(Nav::to('agra', 'new', $join->id), $this->callbacks(0), '«✅ تایید» another admin\'s');
        self::assertContains(Nav::to('agrv', 'no', $join->id), $this->callbacks(0), '«🚫 رد» stays');
        self::assertStringContainsString($note, $this->said()[0]);

        // An agent's renewal request of their own.
        $this->send($this->tap(Nav::to('agr'), chat: 7300));
        $this->send($this->tap(Nav::to('agrv', 'show', $renewal->id), chat: 7300));
        self::assertStringContainsString('درخواست تمدید نمایندگی', $this->said()[0]);
        self::assertNotContains(Nav::to('agrn', 'new', $renewal->id), $this->callbacks(0));
        self::assertContains(Nav::to('agrv', 'no', $renewal->id), $this->callbacks(0));
        self::assertStringContainsString($note, $this->said()[0]);

        // Another admin approves either.
        $this->send($this->tap(Nav::to('agr'), chat: 7200));
        $this->send($this->tap(Nav::to('agrv', 'show', $join->id), chat: 7200));
        self::assertContains(Nav::to('agra', 'new', $join->id), $this->callbacks(0));
        self::assertStringNotContainsString('خود شماست', $this->said()[0]);
        $this->send($this->tap(Nav::to('agrv', 'show', $renewal->id), chat: 7200));
        self::assertContains(Nav::to('agrn', 'new', $renewal->id), $this->callbacks(0));
        self::assertStringNotContainsString('خود شماست', $this->said()[0]);

        // Rejecting their own is taken from them.
        $this->send($this->tap(Nav::to('agrv', 'nn', $join->id), chat: 7100));
        $join->refresh();
        self::assertSame(['rejected', 'tg:7100'], [$join->status->value, $join->reviewer]);
        $this->send($this->tap(Nav::to('agrv', 'nn', $renewal->id), chat: 7300));
        self::assertSame('rejected', $renewal->refresh()->status->value);
    }

    public function testAnAdminWhoIsAnAgentFindsTheirShopAloneOnTheirOwnAgentsPage(): void
    {
        $agent = $this->agent(overrides: ['telegram_id' => 7100, 'first_name' => 'Sara', 'role' => UserRole::Admin]);
        $bot = $this->agentBot($agent, traffic: 20);
        $this->customer(['telegram_id' => 7200, 'first_name' => 'Reza', 'role' => UserRole::Admin]);
        $note = 'این نمایندگی خود شماست؛ تغییرش با مدیر دیگری است.';
        $changes = [
            Nav::to('age', 'edit', $agent->id),
            Nav::to('agt', 'show', $agent->id),
            Nav::to('agh', 'show', $agent->id),
            Nav::to('agv', 'sus', $agent->id),
            Nav::to('agv', 'rev', $agent->id),
        ];

        $this->send($this->tap(Nav::to('agv', 'show', $agent->id), chat: 7100));
        $buttons = $this->callbacks($this->windowCall());
        self::assertContains(Nav::to('shop', 'pick', $bot->id), $buttons, '«🏪 باز کردن فروشگاه» stays');
        foreach ($changes as $change) {
            self::assertNotContains($change, $buttons, "{$change}: another admin's");
        }
        self::assertStringContainsString($note, $this->said()[0]);

        $this->send($this->tap(Nav::to('agv', 'show', $agent->id), chat: 7200));
        $buttons = $this->callbacks($this->windowCall());
        foreach ($changes as $change) {
            self::assertContains($change, $buttons, "{$change}: another admin's to make");
        }
        self::assertStringNotContainsString('خود شماست', $this->said()[0]);

        // Their selling stopped by another admin: letting it go is another admin's too.
        $this->send($this->tap(Nav::to('agv', 'susn', $agent->id), chat: 7200));
        self::assertSame('manual', $bot->refresh()->sellingSuspension()?->value);
        self::assertContains(Nav::to('agv', 'res', $agent->id), $this->callbacks($this->windowCall()));
        $this->send($this->tap(Nav::to('agv', 'show', $agent->id), chat: 7100));
        self::assertNotContains(Nav::to('agv', 'res', $agent->id), $this->callbacks($this->windowCall()));
        self::assertStringContainsString($note, $this->said()[0]);

        // Working their own agent's shop, a customer of it too (its account the seat's own there): their agency all the
        // same.
        CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7100, 'first_name' => 'Sara']));
        $this->send($this->tap(Nav::to('shop', 'pick', $bot->id), chat: 7100));
        self::assertStringContainsString('🏪 فروشگاه نماینده · 🛡 مدیر ربات', implode("\n", $this->said()), 'in the agent\'s shop');
        $this->send($this->tap(Nav::to('agv', 'show', $agent->id), chat: 7100));
        $buttons = $this->callbacks($this->windowCall());
        self::assertContains(Nav::to('shop', 'pick', $bot->id), $buttons);
        self::assertNotContains(Nav::to('agv', 'res', $agent->id), $buttons);
        self::assertNotContains(Nav::to('age', 'edit', $agent->id), $buttons);
        self::assertStringContainsString($note, $this->said()[0]);
    }

    public function testTheAgentsAreSortedAsThePanelsColumnsSortThem(): void
    {
        $level = $this->agencyLevel();
        $rich = $this->wallet($this->agent($level, overrides: ['telegram_id' => 8301, 'first_name' => 'Rich']), '100000');
        $poor = $this->wallet($this->agent($level, overrides: ['telegram_id' => 8302, 'first_name' => 'Poor']), '-50000');
        $order = fn(): array => array_values(array_filter($this->callbacks($this->buttonsCall()), static fn(string $data): bool => str_starts_with($data, Nav::to('agv', 'show') . ':')));

        $this->send($this->tap(Nav::to('agl'), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'show', $poor->id), Nav::to('agv', 'show', $rich->id)], $order(), 'the newest first, as the panel');
        self::assertContains(Nav::to('agl', 'sort'), $this->callbacks(0), '«↕️» beside the search');

        $this->send($this->tap(Nav::to('agl', 'sort'), chat: self::OWNER));
        self::assertContains(Nav::to('agl', 'so', 'balance', 'asc'), $this->callbacks(0), 'each order both ways');
        $this->send($this->tap(Nav::to('agl', 'so', 'balance', 'desc'), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'show', $rich->id), Nav::to('agv', 'show', $poor->id)], $order(), 'GET /agency/agents?sort=balance&dir=desc');
        self::assertStringContainsString('۱۰۰٬۰۰۰ تومان', implode(' ', $this->buttonLabels($this->buttonsCall())), 'the row says the figure it is sorted by');
        self::assertStringContainsString('۵۰٬۰۰۰ تومان بدهی', implode(' ', $this->buttonLabels($this->buttonsCall())), 'a debt in words, never a bare minus');
        $this->send($this->tap(Nav::to('agl', 'so', 'balance', 'asc'), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'show', $poor->id), Nav::to('agv', 'show', $rich->id)], $order());

        $this->send($this->tap(Nav::to('agv', 'show', $poor->id), chat: self::OWNER));
        self::assertStringContainsString('۵۰٬۰۰۰ تومان بدهی', $this->said()[0], 'their page too');
        self::assertStringNotContainsString('-۵۰٬۰۰۰', $this->said()[0]);
        $this->send($this->tap(Nav::to('age', 'edit', $poor->id), chat: self::OWNER));
        self::assertStringContainsString('موجودی ۵۰٬۰۰۰ تومان بدهی', implode("\n", $this->said()), 'and their terms\' form');
    }

    public function testTheRequestsComeNewestFirstOrOldest(): void
    {
        $this->agencyLevel();
        $first = $this->agencyRequest($this->customer(['telegram_id' => 8401]));
        $second = $this->agencyRequest($this->customer(['telegram_id' => 8402]));
        $order = fn(): array => array_values(array_filter($this->callbacks($this->buttonsCall()), static fn(string $data): bool => str_starts_with($data, Nav::to('agrv', 'show') . ':')));

        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        self::assertSame([Nav::to('agrv', 'show', $second->id), Nav::to('agrv', 'show', $first->id)], $order(), 'the newest first');
        self::assertContains(Nav::to('agr', 'dir', 'asc'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('agr', 'dir', 'asc'), chat: self::OWNER));
        self::assertSame([Nav::to('agrv', 'show', $first->id), Nav::to('agrv', 'show', $second->id)], $order(), 'GET /agency/requests?sort=created&dir=asc');
        self::assertContains(Nav::to('agr', 'dir', 'desc'), $this->callbacks($this->buttonsCall()), 'and back');
    }

    public function testARenewalIsListedApartAndApprovedWithTheProgramsTerm(): void
    {
        $join = $this->agencyRequest($this->customer(['telegram_id' => 8100, 'first_name' => 'Reza']));
        $renewal = $this->agentsRenewal();
        $bot = $renewal->user->ownBot ?? self::fail('no shop');
        $term = $bot->agency_expires_at?->copy()->addDays(30) ?? self::fail('no end');

        $this->send($this->tap(Nav::to('ag'), chat: self::OWNER));
        self::assertStringContainsString('درخواست‌های در انتظار: <b>۲ (۱ تمدید)</b>', $this->said()[0], 'the renewals among those waiting');

        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        $labels = array_column(array_merge(...$this->inlineKeyboard(0)), 'text', 'callback_data');
        self::assertStringContainsString('🔄', $labels[Nav::to('agrv', 'show', $renewal->id)] ?? '', 'a renewal marked');
        self::assertStringNotContainsString('تمدید', $labels[Nav::to('agrv', 'show', $renewal->id)] ?? '', 'once: its 🔄 says it');
        self::assertStringContainsString('🤝', $labels[Nav::to('agrv', 'show', $join->id)] ?? '', 'a request to become an agent marked');
        self::assertContains(Nav::to('agr', 'f', 'kind', 'join'), $this->callbacks(0), 'a chip for each kind');

        $this->send($this->tap(Nav::to('agrv', 'show', $join->id), chat: self::OWNER));
        self::assertContains(Nav::to('agra', 'new', $join->id), $this->callbacks(0), 'a request to become an agent: approved on a level');
        self::assertNotContains(Nav::to('agrn', 'new', $join->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('agrn', 'new', $join->id), chat: self::OWNER));
        self::assertStringContainsString('✅ نمایندگی Reza', $this->said()[0], 'an older window\'s renewal approval on it: the level card');
        self::assertContains(Nav::to('agra', 'lv'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('agr', 'f', 'kind', 'renewal'), chat: self::OWNER));
        self::assertContains(Nav::to('agrv', 'show', $renewal->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('agrv', 'show', $join->id), $this->callbacks(0), 'GET /agency/requests?kind=renewal');
        self::assertStringContainsString('🔄 تمدید', $this->said()[0], 'what narrows the list said');

        $this->send($this->tap(Nav::to('agrv', 'show', $renewal->id), chat: self::OWNER));
        $screen = $this->said()[0];
        self::assertStringContainsString('🔄 درخواست تمدید نمایندگی', $screen);
        self::assertStringContainsString('روز مانده)', $screen, 'the agent\'s end now');
        self::assertStringContainsString('تمدید با مدت نمایندگی: <b>' . Persian::date($term, true), $screen);
        self::assertContains(Nav::to('agrn', 'new', $renewal->id), $this->callbacks(0), 'approved with an end, never a level');
        self::assertNotContains(Nav::to('agra', 'new', $renewal->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('agrn', 'new', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString(Persian::date($term, true) . ' · با مدت نمایندگی', $this->said()[0], 'the program\'s end, a tap away');
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));

        $renewal->refresh();
        self::assertSame(['approved', $term->toDateTimeString()], [$renewal->status->value, $renewal->extended_to?->toDateTimeString()]);
        self::assertSame($term->toDateTimeString(), $bot->refresh()->agency_expires_at?->toDateTimeString(), 'the agent\'s end moved on');
        self::assertStringContainsString('تمدید شد', (string) $this->popup());
        self::assertStringContainsString('تمدید تا: <b>' . Persian::date($term, true), implode("\n", $this->said()), 'the end it gave');
        self::assertNotContains(Nav::to('agrn', 'new', $renewal->id), $this->callbacks($this->buttonsCall()), 'approved: no approving again');
    }

    public function testARenewalIsApprovedWithAnEndPickedOrWithNone(): void
    {
        $renewal = $this->agentsRenewal();
        $bot = $renewal->user->ownBot ?? self::fail('no shop');

        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'new', $renewal->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'ex'), chat: self::OWNER));
        self::assertSame([Nav::to('agrn', 'exn'), Nav::to('agrn', 'ext'), Nav::to('agrn', 'exd', 90), Nav::to('agrn', 'exd', 30)], array_slice($this->callbacks(0), 0, 4), 'the program\'s end first (on the right), none, then some days from now');
        self::assertStringContainsString('«🔁» پایانی است که مدت نمایندگی می‌دهد.', $this->said()[0], 'the program\'s end said for what it is');
        $this->send($this->message('۱۴۱۰/۰۱/۱۵', chat: self::OWNER));
        self::assertStringContainsString('پایان تازه: <b>۱۵ فروردین ۱۴۱۰', implode("\n", $this->said()), 'a Jalali day typed');
        self::assertSame('pending', $renewal->refresh()->status->value, 'a typed end alone approves nothing');
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));
        self::assertSame(['approved', '2031-04-04 23:00:00'], [$renewal->refresh()->status->value, $renewal->extended_to?->toDateTimeString()]);
        self::assertSame('2031-04-04 23:00:00', $bot->refresh()->agency_expires_at?->toDateTimeString());

        $again = $this->renewalRequest($renewal->user);
        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'new', $again->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'exn'), chat: self::OWNER));
        self::assertStringContainsString('پایان تازه: <b>بدون پایان</b>', $this->said()[0]);
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));
        $again->refresh();
        self::assertSame(['approved', null], [$again->status->value, $again->extended_to], 'expires_at null: none at all, not the program\'s term');
        self::assertNull($bot->refresh()->agency_expires_at);
        self::assertStringContainsString('بدون پایان تمدید شد', (string) $this->popup());
    }

    public function testARenewalOfAnEndPassedGivesTheTermFromNow(): void
    {
        $renewal = $this->agentsRenewal(days: -2);
        $bot = $renewal->user->ownBot ?? self::fail('no shop');

        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrv', 'show', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString('(تمام شده)</b>', $this->said()[0], 'the agent\'s end, passed');
        $this->send($this->tap(Nav::to('agrn', 'new', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString('پایان فعلی: <b>', $this->said()[0]);
        self::assertStringContainsString('(تمام شده)</b>', $this->said()[0]);
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));

        $renewal->refresh();
        $given = $renewal->extended_to ?? self::fail('no end given');
        self::assertSame('approved', $renewal->status->value);
        self::assertTrue($given->isFuture() && $given->diffInDays(Carbon::now(), true) > 29, 'the program\'s term on from now');
        self::assertSame($given->toDateTimeString(), $bot->refresh()->agency_expires_at?->toDateTimeString());
        self::assertNull($bot->sellingSuspension(), 'selling again');
    }

    public function testWithoutAProgramTermARenewalAsksItsEndFirst(): void
    {
        $renewal = $this->agentsRenewal(term: 0);
        $bot = $renewal->user->ownBot ?? self::fail('no shop');

        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'new', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString('مدتی برای نمایندگی تعیین نشده', $this->said()[0]);
        self::assertStringContainsString('پایان تازه: <b>⚠️ انتخاب نشده</b>', $this->said()[0]);
        self::assertNotContains(Nav::to('agrn', 'save'), $this->callbacks(0), 'nothing to approve with yet');
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));
        self::assertSame('pending', $renewal->refresh()->status->value, 'an older window\'s save gives no end unasked');

        $this->send($this->tap(Nav::to('agrn', 'ex'), chat: self::OWNER));
        self::assertSame([Nav::to('agrn', 'exd', 30), Nav::to('agrn', 'exn')], array_slice($this->callbacks(0), 0, 2), 'none first (on the right): no program\'s end to offer');
        self::assertNotContains(Nav::to('agrn', 'ext'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('agrn', 'exd', 90), chat: self::OWNER));
        $picked = AgencyEnd::in(90);
        self::assertContains(Nav::to('agrn', 'save'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));
        self::assertSame('approved', $renewal->refresh()->status->value);
        self::assertSame($picked, LocalTime::of($bot->refresh()->agency_expires_at ?? self::fail('no end'))->format(LocalTime::MOMENT));
    }

    public function testARenewalsRefusalIsShownAndItIsRejectedAsAJoinRequestIs(): void
    {
        $renewal = $this->agentsRenewal();
        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));

        // An older window's approval on a level, tapped on a renewal: its own card, never the levels.
        $this->send($this->tap(Nav::to('agra', 'new', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString('🔄 تمدید نمایندگی', $this->said()[0]);
        self::assertContains(Nav::to('agrn', 'ex'), $this->callbacks(0));
        self::assertNotContains(Nav::to('agra', 'lv'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('agrn', 'ex'), chat: self::OWNER));
        $this->send($this->message('۱۴۰۰/۰۱/۰۱', chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));
        self::assertStringContainsString('تاریخ پایان تازه باید بعد از همین حالا باشد', (string) $this->popup(), 'the API\'s refusal, as a popup');
        self::assertSame('pending', $renewal->refresh()->status->value);
        $this->send($this->tap(Nav::to('agrn', 'gone'), chat: self::OWNER));
        self::assertContains(Nav::to('agrv', 'no', $renewal->id), $this->callbacks(0), 'back on the request');

        $this->send($this->tap(Nav::to('agrv', 'no', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString('درخواست تمدید', $this->said()[0]);
        self::assertSame([Nav::to('agrv', 'nn', $renewal->id), Nav::to('agrv', 'nw', $renewal->id), Nav::to('agrv', 'show', $renewal->id)], $this->callbacks(0));
        $this->send($this->tap(Nav::to('agrv', 'nn', $renewal->id), chat: self::OWNER));
        self::assertSame('rejected', $renewal->refresh()->status->value);
        self::assertNull($renewal->extended_to);
    }

    public function testTheCardShowsTheTermAsItIsNowAndApprovesWithExactlyThatEnd(): void
    {
        $renewal = $this->agentsRenewal();
        $bot = $renewal->user->ownBot ?? self::fail('no shop');
        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));

        // The agent's end moved after the list was read: the card reads the request again.
        $moved = Carbon::now()->addDays(8)->startOfHour();
        $this->sellingTerms($bot, $moved);
        $term = $moved->copy()->addDays(30);
        $this->send($this->tap(Nav::to('agrn', 'new', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString('پایان تازه: <b>' . Persian::date($term, true) . ' · با مدت نمایندگی', $this->said()[0], 'the term from the end as it is now');
        self::assertStringContainsString('پایان فعلی: <b>' . Persian::date($moved, true), $this->said()[0]);

        // Moved again while the card is open: the end the card shows is the one given, not one reckoned at the save.
        $this->sellingTerms($bot->refresh(), Carbon::now()->addDays(20)->startOfHour());
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));
        self::assertSame(['approved', $term->toDateTimeString()], [$renewal->refresh()->status->value, $renewal->extended_to?->toDateTimeString()]);
        self::assertSame($term->toDateTimeString(), $bot->refresh()->agency_expires_at?->toDateTimeString());
    }

    public function testARenewalDecidedElsewhereIsShownAsItStandsNow(): void
    {
        $renewal = $this->agentsRenewal();
        $actions = $this->service(AgencyActions::class);
        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));

        // Rejected in the report group while the list showed it waiting: its card opens on the request as it stands.
        $actions->reject($renewal, $this->panelActor(), null);
        $this->send($this->tap(Nav::to('agrn', 'new', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString('🔄 درخواست تمدید نمایندگی', $this->said()[0]);
        self::assertStringContainsString('وضعیت: <b>' . Words::REJECTED, $this->said()[0]);
        self::assertNotContains(Nav::to('agrn', 'new', $renewal->id), $this->offered(), 'nothing to approve');
        self::assertNotContains(Nav::to('agrv', 'no', $renewal->id), $this->offered());

        // Decided while its card was open: why, and the request as it stands — never the list's row still offering ✅ / 🚫.
        $again = $this->renewalRequest($renewal->user);
        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'new', $again->id), chat: self::OWNER));
        self::assertContains(Nav::to('agrn', 'save'), $this->callbacks(0));
        $actions->reject($again, $this->panelActor(), null);
        $this->send($this->tap(Nav::to('agrn', 'save'), chat: self::OWNER));
        self::assertSame('این درخواست رد شده است.', $this->popup());
        self::assertSame(['rejected', null], [$again->refresh()->status->value, $again->extended_to]);
        self::assertStringContainsString('وضعیت: <b>' . Words::REJECTED, implode("\n", $this->said()));
        self::assertNotContains(Nav::to('agrn', 'new', $again->id), $this->offered());
        self::assertNotContains(Nav::to('agrv', 'no', $again->id), $this->offered());
        self::assertContains(Nav::to('agr'), $this->offered(), 'back to the list');
    }

    public function testARenewalOfSomeoneNoLongerAnAgentShowsNoEnd(): void
    {
        $renewal = $this->agentsRenewal();
        $this->service(AgencyActions::class)->revoke($renewal->user, null);

        $this->send($this->tap(Nav::to('agr', 'f', 'status', ListScreen::ANY), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrv', 'show', $renewal->id), chat: self::OWNER));
        self::assertStringContainsString('دیگر نماینده نیست', $this->said()[0]);
        self::assertStringNotContainsString('پایان نمایندگی:', $this->said()[0], 'no end to show, as the panel\'s');
    }

    public function testADayTheJalaliYearLacksIsRefused(): void
    {
        self::assertNull(AgencyEnd::typed('۱۴۰۵/۱۲/۳۰'), '1405 is no leap year: Esfand has 29 days');
        self::assertNull(AgencyEnd::typed('1405/07/31'));
        self::assertSame('2030-03-20T23:00', AgencyEnd::typed('۱۴۰۸/۱۲/۳۰'), '1408 is');

        $renewal = $this->agentsRenewal();
        $this->send($this->tap(Nav::to('agr'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'new', $renewal->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agrn', 'ex'), chat: self::OWNER));
        $this->send($this->message('۱۴۰۵/۱۲/۳۰', chat: self::OWNER));
        self::assertStringContainsString('تاریخ شمسی را مثل ۱۴۰۵/۰۷/۲۰', implode("\n", $this->said()), 'not a day');
        self::assertStringNotContainsString('فروردین ۱۴۰۶', implode("\n", $this->said()), 'never the next month\'s first');
        $this->send($this->message('۱۴۰۸/۱۲/۳۰', chat: self::OWNER));
        self::assertStringContainsString('پایان تازه: <b>۳۰ اسفند ۱۴۰۸', implode("\n", $this->said()));
    }

    /**
     * One of the main shop's admins who is an agent too (Telegram 7100), working their own agent's shop from the main bot
     * — a customer of their own bot as well, so the seat's own account there is that one (AdminSeat::in()) —, and the
     * panels' API as that seat asks it.
     *
     * @return array{User, PanelBridge}
     */
    private function adminInTheirOwnAgentsShop(): array
    {
        $agent = $this->agent(overrides: ['telegram_id' => 7100, 'first_name' => 'Sara', 'role' => UserRole::Admin]);
        $bot = $this->agentBot($agent);
        CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7100]));
        $seat = $this->service(AdminSeats::class)->of($agent, $bot->id);
        self::assertSame([$bot->id, false], [$seat?->shop()->id, $seat?->principal->isTheOwner()], 'the owner\'s seat, in their shop, not the owner');

        return [$agent, $this->service(PanelBridge::class)->for($seat ?? self::fail('no seat'))];
    }

    /** The panels' API as `$user`'s seat in the main bot asks it, the seat as the bot gives it (AdminSeats::of()). */
    private function bridge(User $user): PanelBridge
    {
        $seat = $this->service(AdminSeats::class)->of($user);
        self::assertNotNull($seat);

        return $this->service(PanelBridge::class)->for($seat);
    }

    private static function refusal(\Closure $call): ApiRefusal
    {
        try {
            $call();
        } catch (ApiRefusal $refusal) {
            return $refusal;
        }

        self::fail('the API answered');
    }

    /**
     * A renewal request of the agent's (agentBot()'s), as their «درخواست تمدید نمایندگی» files it: their agency ending
     * `$days` from now, to the hour (below 0: passed; 0: never), the program's term `$term` days (0: none).
     */
    private function agentsRenewal(int $days = 5, int $term = 30): AgencyRequest
    {
        $bot = $this->agentBot();
        $settings = $this->service(AgencySettings::class);
        $settings->save(['term_days' => $term] + $settings->present());
        $this->sellingTerms($bot, $days !== 0 ? Carbon::now()->addDays($days)->startOfHour() : null);
        $agent = $bot->agent ?? self::fail('no agent');

        return $this->renewalRequest($agent);
    }
}
