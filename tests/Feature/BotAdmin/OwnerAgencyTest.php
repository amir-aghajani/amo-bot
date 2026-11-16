<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Users\Enums\UserRole;
use App\Support\Traffic;
use Tests\BotTestCase;

/**
 * «🤝 نمایندگان» in the bot (Telegram\Admin\Sections\Owner\Agency): the program's numbers, a request approved on a level
 * and terms or rejected with a note, an agent's page — their terms changed, traffic added, servers hidden, selling
 * stopped and let go, the agency ended —, the levels added, moved and deleted, and the program's rules — every write
 * through the panel's own routes; the owner's alone.
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

    public function testAShopsAdminHasNoAgency(): void
    {
        $request = $this->agencyRequest($this->customer(['telegram_id' => 8100]));
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->message('/admin', chat: 7100));
        self::assertNotContains(Nav::to('ag'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('agrv', 'nn', $request->id), chat: 7100));
        self::assertSame('pending', AgencyRequest::query()->findOrFail($request->id)->status->value);
    }

    public function testTheAgentsAreSortedAsThePanelsColumnsSortThem(): void
    {
        $level = $this->agencyLevel();
        $rich = $this->wallet($this->agent($level, overrides: ['telegram_id' => 8301, 'first_name' => 'Rich']), '100000');
        $poor = $this->agent($level, overrides: ['telegram_id' => 8302, 'first_name' => 'Poor']);
        $order = fn(): array => array_values(array_filter($this->callbacks($this->buttonsCall()), static fn(string $data): bool => str_starts_with($data, Nav::to('agv', 'show') . ':')));

        $this->send($this->tap(Nav::to('agl'), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'show', $poor->id), Nav::to('agv', 'show', $rich->id)], $order(), 'the newest first, as the panel');
        self::assertContains(Nav::to('agl', 'sort'), $this->callbacks(0), '«↕️» beside the search');

        $this->send($this->tap(Nav::to('agl', 'sort'), chat: self::OWNER));
        self::assertContains(Nav::to('agl', 'so', 'balance', 'asc'), $this->callbacks(0), 'each order both ways');
        $this->send($this->tap(Nav::to('agl', 'so', 'balance', 'desc'), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'show', $rich->id), Nav::to('agv', 'show', $poor->id)], $order(), 'GET /agency/agents?sort=balance&dir=desc');
        self::assertStringContainsString('۱۰۰٬۰۰۰ تومان', implode(' ', $this->buttonLabels($this->buttonsCall())), 'the row says the figure it is sorted by');
        $this->send($this->tap(Nav::to('agl', 'so', 'balance', 'asc'), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'show', $poor->id), Nav::to('agv', 'show', $rich->id)], $order());
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
}
