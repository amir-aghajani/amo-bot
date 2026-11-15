<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
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
    private const OWNER = 7001;

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
        self::assertStringContainsString('درخواست‌های در انتظار: ۱', $this->said()[0]);

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
        self::assertSame([Nav::to('agrv', 'nw', $request->id), Nav::to('agrv', 'nn', $request->id), Nav::to('agrv', 'show', $request->id)], $this->callbacks(0), 'asked first: with a note, without, or not');
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
        self::assertSame([Nav::to('agt', 'nw'), Nav::to('agt', 'nn'), Nav::to('agt', 'quit')], $this->callbacks(0));
        $this->send($this->tap(Nav::to('agt', 'nw'), chat: self::OWNER));
        $this->send($this->message('هدیه شروع کار', chat: self::OWNER));
        self::assertSame(Traffic::bytesOfGb(30), $bot->refresh()->trafficBalance(), 'added through the panel\'s route, once');
        self::assertStringContainsString('هدیه شروع کار', implode("\n", $this->said()), 'on the ledger');

        $this->send($this->tap(Nav::to('agh', 'show', $agent->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('agh', 'srv', $server->id, 1), chat: self::OWNER));
        self::assertStringContainsString('🙈 آلمان', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '');
        self::assertContains(Nav::to('agh', 'srv', $server->id, 0), $this->callbacks($this->buttonsCall()), 'shown again by its own button');

        $this->send($this->tap(Nav::to('agv', 'sus', $agent->id), chat: self::OWNER));
        self::assertSame([Nav::to('agv', 'susw', $agent->id), Nav::to('agv', 'susn', $agent->id), Nav::to('agv', 'show', $agent->id)], $this->callbacks(0), 'asked first: with a note, without, or not');
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
        self::assertSame([Nav::to('agv', 'revw', $agent->id), Nav::to('agv', 'revn', $agent->id), Nav::to('agv', 'show', $agent->id)], $this->callbacks(0), 'asked first, a note offered');
        $this->send($this->tap(Nav::to('agv', 'revw', $agent->id), chat: self::OWNER));
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
        self::assertNotContains(Nav::to('kb'), $this->callbacks(0));

        $layouts->save('start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید', 'style' => null]]]]);
        $this->send($this->tap(Nav::to('ag'), chat: self::OWNER));
        self::assertStringContainsString('⚠️ دکمه «نمایندگی» در منوی ربات نیست', $this->said()[0]);
        self::assertContains(Nav::to('kb'), $this->callbacks(0), '«⌨️ افزودن دکمه در کیبوردها»');
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
        $this->send($this->tap(Nav::to('ags', 'e', 0), chat: self::OWNER));
        self::assertFalse($this->service(AgencySettings::class)->enabled(), 'switched off through PUT /agency/settings');
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

    private function owner(): User
    {
        $owner = User::query()->where('telegram_id', self::OWNER)->first() ?? $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);

        return $owner;
    }
}
