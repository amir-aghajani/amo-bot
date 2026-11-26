<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Sections\Panel\Search\SettingsEntry;
use App\Modules\Telegram\Admin\Sections\Panel\Search\SettingsIndex;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Session\ChatSession;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Update\ReceivedUpdates;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «🔎 جستجوی تنظیمات» and what it leans on: a word typed however the keyboard typed it — Arabic letters, Persian digits, no
 * half-space, a letter off — finds the panel's settings where the bot has them, the seat's own alone; a result opens the
 * very field (a typed value asked at once), the part of a card, or the card a switch is on, never flipping it; what the
 * panel alone sets is said with why. And a card's parts and fields by name (SettingsScreen::PART, ::FIELD): whatever is
 * done in a part comes back to it — a switch turned there too —, a part's help its own fields', the wallet's random sum
 * a switch that asks its most.
 */
final class BotAreaSettingsSearchTest extends BotTestCase
{
    private const ADMIN = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testAWordTypedAnyWayFindsTheSettingWhereTheBotHasIt(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('hc'), chat: self::OWNER));
        self::assertSame(Nav::to('ss'), $this->inlineKeyboard(0)[0][1]['callback_data'] ?? null, '«🔎 جستجوی تنظیمات» first on «⚙️ تنظیمات», on the right');

        $this->send($this->tap(Nav::to('ss'), chat: self::OWNER));
        self::assertStringContainsString('<b>✍️ جستجوی تنظیمات</b>', $this->said()[0]);
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'waiting for the word');

        // Arabic kaf and yeh, as some keyboards type them.
        $this->send($this->message('كيف پول', chat: self::OWNER));
        $found = $this->callbacks($this->windowCall());
        self::assertSame(Nav::to('bsw'), $found[0] ?? null, 'the wallet card first');
        self::assertStringContainsString('«كيف پول»', implode("\n", $this->said()));
        self::assertNotContains(Nav::to('cbs'), $found, 'a match by chance in a hint, far under the best, left out');

        self::assertSame('کیف پول 2', SettingsIndex::normalize("كيف\u{200C}پول ٢"), 'Arabic letters, a half-space and Persian digits folded');
        self::assertSame('bsw', self::first('کیفپول')?->screen, 'typed without its half-space');
        self::assertSame('bsa', self::first('تمدد خودکار')?->screen, 'a letter off, in a title');
        self::assertSame('ppb', self::first('backup')?->screen, 'the English word');
        self::assertSame('ppb', self::first('بکاپ')?->screen, 'the way owners write it');
        self::assertLessThanOrEqual(SettingsIndex::MOST, count(SettingsIndex::find('ر', static fn(): bool => true)), 'at most twenty');

        // A section whose page is one card: the card is the section itself — one result for the screen both open, the
        // card's own title still finding it.
        $opened = array_map(static fn(SettingsEntry $entry): string => (string) ($entry->button('x')->markup()['callback_data'] ?? ''), SettingsIndex::find('زیرمجموعه‌گیری', static fn(): bool => true));
        self::assertSame([Nav::to('rf'), Nav::to('bsf')], array_values(array_intersect($opened, [Nav::to('rf'), Nav::to('bsf')])), 'the referrals and their rules, each once');
        // Found as the card was found, shown as its section: the card's title typed whole first, a word of its title or of
        // what it says among the rest.
        foreach (['برنامه نمایندگی' => 'ags', 'مدیریت فروشگاه از ربات' => 'ba', 'قوانین اکانت تست' => 'tss', 'کارهای زمان‌بندی‌شده' => 'ppj'] as $title => $screen) {
            $first = self::first($title);
            self::assertSame([$screen, true], [$first?->screen, $first?->isSection()], "«{$title}»: the card's title, its section first");
        }
        self::assertContains('ba', array_map(static fn(SettingsEntry $entry): string => $entry->screen, SettingsIndex::find('مدیریت', static fn(): bool => true)), 'a word of the card\'s title');
        self::assertSame('ppj', self::first('موعدش')?->screen, 'a word of what the card says');
    }

    public function testAResultOpensTheFieldItselfAndASwitchOnlyItsCard(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('ss'), chat: self::OWNER));
        $this->send($this->message('حداقل شارژ', chat: self::OWNER));
        self::assertContains(Nav::to('bsw', 'f', 'topup_min'), $this->callbacks($this->windowCall()));

        $this->send($this->tap(Nav::to('bsw', 'f', 'topup_min'), chat: self::OWNER));
        self::assertStringContainsString('<b>✍️ حداقل مبلغ شارژ</b>', $this->said()[0], 'asked at once');
        self::assertStringContainsString(Words::LIMITS . ': از ۱٬۰۰۰ تا', $this->said()[0], 'the panel\'s own bounds');
        $this->send($this->message('20000', chat: self::OWNER));
        self::assertSame(20_000, $this->settings()['topup_min'], 'saved through the panel\'s route');

        // A switch: its card, never a flip.
        $this->send($this->tap(Nav::to('bsg', 'f', 'enabled'), chat: self::OWNER));
        self::assertTrue($this->settings()['enabled']);
        self::assertStringContainsString('<b>⚙️ عمومی</b>', $this->said()[0]);
        $this->send($this->tap(Nav::to('bsg', 'f', 'no_such_field'), chat: self::OWNER));
        self::assertStringContainsString('<b>⚙️ عمومی</b>', $this->said()[0], 'a name it has not: the card');
        self::assertNull($this->popup(), 'and nothing said of it');

        // A field another hides now: its card, saying which switch shows it — nothing switched for it.
        $this->send($this->tap(Nav::to('bsx', 'f', 'extra_devices_max'), chat: self::OWNER));
        self::assertSame('این گزینه با روشن کردن «فروش حجم، زمان و کاربر اضافه» دیده می‌شود.', $this->popup());
        self::assertStringStartsWith('<b>🧩 حجم، زمان و کاربر اضافه</b>', $this->said()[0]);
        self::assertFalse($this->settings()['extras_enabled']);
        // … and one a choice hides, by the value that shows it.
        $this->send($this->tap(Nav::to('lts', 'f', 'lottery_traffic_gb'), chat: self::OWNER));
        self::assertSame('این گزینه وقتی «جایزه هر برنده» روی «حجم» باشد دیده می‌شود.', $this->popup());
        // … with another part of its card left open from before: where the switch it names is, that switch at hand.
        $this->botSettings('test_accounts', ['test_cleanup' => false]);
        $this->send($this->tap(Nav::to('tss', 'p', 0), chat: self::OWNER));
        self::assertStringStartsWith('<b>🔢 سهمیه</b>', $this->said()[0]);
        $this->send($this->tap(Nav::to('tss', 'f', 'test_unused_days'), chat: self::OWNER));
        self::assertSame('این گزینه با روشن کردن «اکانت‌های تمام‌شده حذف شوند» دیده می‌شود.', $this->popup());
        self::assertContains(Nav::to('tss', 'e', 5, 1), $this->callbacks($this->windowCall()), 'the switch it names, on the window');
        self::assertFalse($this->service(BotSettingsScreen::class)->present()['test_cleanup'] ?? null, 'nothing switched for it');

        // A field of several with nothing to pick among yet (no server in the shop): its picker says so.
        $this->send($this->tap(Nav::to('css', 'f', 'custom_servers'), chat: self::OWNER));
        self::assertStringStartsWith('<b>سرورها</b>', $this->said()[0]);
        self::assertStringContainsString('موردی برای انتخاب نیست.', $this->said()[0]);
    }

    public function testTheSeatFindsOnlyItsOwnScreensAndThePanelsAloneSaysWhy(): void
    {
        $this->owner(UserRole::Admin);
        $this->send($this->tap(Nav::to('ss'), chat: self::OWNER));
        $this->send($this->message('منطقه زمانی', chat: self::OWNER));
        self::assertContains(Nav::to('ppa'), $this->callbacks($this->windowCall()), 'the owner\'s config.php');

        $this->send($this->tap(Nav::to('ss'), chat: self::OWNER));
        $this->send($this->message('رمز عبور جدید', chat: self::OWNER));
        $said = implode("\n", $this->said());
        self::assertStringContainsString('🧰 فقط در پنل', $said);
        self::assertSame(1, substr_count($said, 'فقط از خود پنل عوض می‌شوند'), 'why, once for the login\'s fields together');
        self::assertStringContainsString('«رمز عبور جدید»، «تکرار رمز عبور جدید»', $said, 'the settings it is for, together');
        self::assertContains(Nav::to('ppl'), $this->callbacks($this->windowCall()), 'the screen that says so');

        // An agent: their own shop's settings — no config.php.
        $bot = $this->agentBot();
        $this->sendTo($bot, $this->tap(Nav::to('ss'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('منطقه زمانی', chat: self::AGENT_TELEGRAM_ID));
        self::assertNotContains(Nav::to('ppa'), $this->callbacks($this->windowCall()));

        // A shop's admin works with the owner's seat in the main bot: the same settings to find, config.php's too, and the
        // home search offers them.
        $this->customer(['telegram_id' => self::ADMIN, 'role' => UserRole::Admin]);
        $this->send($this->tap(Nav::to('ss'), chat: self::ADMIN));
        $this->send($this->message('منطقه زمانی', chat: self::ADMIN));
        self::assertContains(Nav::to('ppa'), $this->callbacks($this->windowCall()), 'the owner\'s config.php, for an admin too');
        $this->send($this->tap(Nav::to(Screens::SEARCH), chat: self::ADMIN));
        self::assertContains(Nav::to('ss'), $this->callbacks(0));
        $this->send($this->tap(Nav::to(Screens::SEARCH), chat: self::OWNER));
        self::assertContains(Nav::to('ss'), $this->callbacks(0), 'the home search\'s other mode, for the owner');
    }

    public function testWhateverIsDoneInAPartComesBackToIt(): void
    {
        $this->owner(UserRole::Admin);
        $this->botSettings('extras', ['extras_enabled' => true]);

        $this->send($this->tap(Nav::to('bsx'), chat: self::OWNER));
        self::assertStringContainsString('<b>⏳ زمان اضافه</b>', $this->said()[0], 'each part a section of the summary');
        self::assertNotContains(Nav::to('bsx', 'e', 5), $this->callbacks(0), 'its fields\' buttons in the part');

        $this->send($this->tap(Nav::to('bsx', 'p', 1), chat: self::OWNER));
        self::assertStringStartsWith('<b>⏳ زمان اضافه</b>', $this->said()[0]);
        self::assertStringContainsString('› حجم، زمان و کاربر اضافه</i>', $this->said()[0], 'under the card');
        self::assertContains(Nav::to('bsx', 'e', 5), $this->callbacks(0));
        self::assertContains(Nav::to('bsx', 'p'), $this->callbacks(0), 'its way back: the card');

        $this->send($this->tap(Nav::to('bsx', 'e', 7), chat: self::OWNER));
        $this->send($this->tap(Nav::to('bsx', 'v'), chat: self::OWNER));
        self::assertStringStartsWith('<b>⏳ زمان اضافه</b>', $this->said()[0], 'a question left: the part again');
        $this->send($this->tap(Nav::to('bsx', 'e', 7), chat: self::OWNER));
        $this->send($this->message('3', chat: self::OWNER));
        self::assertSame(3, $this->settings()['extra_time_min']);
        self::assertStringContainsString('<b>⏳ زمان اضافه</b>', implode("\n", $this->said()), 'saved: the part again');

        $this->send($this->tap(Nav::to('bsx', 'p'), chat: self::OWNER));
        self::assertStringStartsWith('<b>🧩 حجم، زمان و کاربر اضافه</b>', $this->said()[0]);

        // A field opened by its name lands in its part once answered.
        $this->send($this->tap(Nav::to('bsx', 'f', 'extra_devices_max'), chat: self::OWNER));
        $this->send($this->message('4', chat: self::OWNER));
        self::assertSame(4, $this->settings()['extra_devices_max']);
        self::assertStringContainsString('<b>👤 کاربر اضافه</b>', implode("\n", $this->said()));
        $this->send($this->tap(Nav::to('bsx'), chat: self::OWNER));
        self::assertStringStartsWith('<b>🧩 حجم، زمان و کاربر اضافه</b>', $this->said()[0], 'opened afresh: the card itself');
    }

    public function testASwitchTurnedInAPartComesBackToItAndThePartsHelpIsItsOwn(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('tss', 'p', 0), chat: self::OWNER));
        $this->send($this->tap(Nav::to('tss', 'e', 4, 1), chat: self::OWNER));
        self::assertTrue($this->service(BotSettingsScreen::class)->present()['test_newcomers_only'] ?? null, 'saved through the panel\'s route');
        self::assertStringStartsWith('<b>🔢 سهمیه</b>', $this->said()[0], 'a switch turned in a part: the part again');
        self::assertContains('⏸ خاموش کردن «فقط مشتری‌هایی که خرید نکرده‌اند»', $this->buttonLabels($this->windowCall()));

        // A part's «❔ راهنما»: its own fields' hints, and back to the part.
        self::assertContains(Nav::to('tss', 'help'), $this->callbacks($this->windowCall()));
        $this->send($this->tap(Nav::to('tss', 'help'), chat: self::OWNER));
        self::assertStringContainsString('روزی حداکثر چند اکانت تست', $this->said()[0]);
        self::assertStringNotContainsString('<b>اکانت تست فعال باشد</b>', $this->said()[0], 'the card\'s other fields not');
        self::assertStringNotContainsString('<b>اکانت‌های تمام‌شده حذف شوند</b>', $this->said()[0], 'nor another part\'s');
        $this->send($this->tap(Nav::to('tss', 'v'), chat: self::OWNER));
        self::assertStringStartsWith('<b>🔢 سهمیه</b>', $this->said()[0]);
    }

    public function testAPickOpenedByNameAndTheResultsPaged(): void
    {
        $this->owner(UserRole::Admin);

        // A field a card picks among its own rows (BotCard::picks()), opened by its name: its picker.
        $this->send($this->tap(Nav::to('bsr', 'f', 'renew_default_method'), chat: self::OWNER));
        self::assertStringContainsString('<b>روش تمدید خودکار</b>', $this->said()[0]);
        self::assertContains(Nav::to('bsr', 'pk', 6, 'extend'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('ss'), chat: self::OWNER));
        $this->send($this->message('حجم', chat: self::OWNER));
        $first = $this->callbacks($this->windowCall());
        self::assertContains(Nav::to('ss', 'r', 2), $first, 'more than a page: the next one');
        $this->send($this->tap(Nav::to('ss', 'r', 2), chat: self::OWNER));
        self::assertStringContainsString('«حجم»', $this->said()[0], 'the same search, its second page');
        self::assertSame([], array_values(array_intersect(array_filter($first, static fn(string $data): bool => !str_starts_with($data, 'a:ss:') && !str_starts_with($data, 'a:pgi:') && !str_starts_with($data, 'a:h:') && !str_starts_with($data, 'a:hc:')), $this->callbacks(0))), 'other results');
    }

    public function testTheRandomSumIsASwitchThatAsksItsMost(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('bsw'), chat: self::OWNER));
        self::assertStringContainsString('مبلغ تصادفی افزوده به شارژ کارت به کارت: <b>' . Words::OFF . '</b>', $this->said()[0]);
        self::assertContains(Nav::to('bsw', 'e', 6), $this->callbacks(0));
        self::assertContains('▶️ روشن کردن «مبلغ تصادفی افزوده به شارژ کارت…»', $this->buttonLabels(0), 'off: a switch saying what it sets');

        $this->send($this->tap(Nav::to('bsw', 'e', 6), chat: self::OWNER));
        $this->send($this->message('5000', chat: self::OWNER));
        self::assertSame(5000, $this->settings()['topup_unique_max']);
        self::assertStringContainsString(Words::ON . ' · تا ۵٬۰۰۰ تومان', implode("\n", $this->said()));

        $this->send($this->tap(Nav::to('bsw', 'e', 6), chat: self::OWNER));
        self::assertContains(Nav::to('bsw', 'd', 6), $this->callbacks(0), 'on: turned off from its question');
        self::assertContains(Words::TURN_OFF, $this->buttonLabels(0));
        self::assertNotContains(Words::RESET, $this->buttonLabels(0), 'said as what it does, not «پیش‌فرض»');
        $this->send($this->tap(Nav::to('bsw', 'd', 6), chat: self::OWNER));
        self::assertSame(0, $this->settings()['topup_unique_max']);
    }

    public function testEveryResultASeatFindsOpensWhereItSaysAndChangesNothing(): void
    {
        $this->configFile(['APP_NAME' => 'AmoBot', 'APP_URL' => 'http://localhost', 'APP_KEY' => 'base64:x', 'DB_CONNECTION' => 'sqlite', 'TELEGRAM_BOT_TOKEN' => '', 'LOG_LEVEL' => 'info']);
        $this->logs();
        $owner = $this->owner(UserRole::Admin);
        $bot = $this->agentBot();
        $agent = User::query()->where('telegram_id', self::AGENT_TELEGRAM_ID)->firstOrFail();
        $seats = $this->service(AdminSeats::class);
        $settings = $this->settings();

        $mine = $this->sweep(fn(string $data) => $this->send($this->tap($data, chat: self::OWNER)), $seats->of($owner), 'the owner');
        self::assertGreaterThan(250, $mine['tapped'], 'the owner finds nearly every setting');

        $this->send($this->tap(Nav::to('shop', 'pick', $bot->id), chat: self::OWNER));
        $theirs = $this->sweep(fn(string $data) => $this->send($this->tap($data, chat: self::OWNER)), $seats->of($owner, $bot->id), 'the owner in an agent\'s shop');
        self::assertLessThan($mine['tapped'], $theirs['tapped'], 'config.php, the schedule and the imports are the main shop\'s');

        $seat = CurrentBot::run($bot, static fn() => $seats->of($agent));
        $agents = $this->sweep(fn(string $data) => $this->sendTo($bot, $this->tap($data, chat: self::AGENT_TELEGRAM_ID)), $seat, 'an agent', $bot);
        self::assertLessThan($theirs['tapped'], $agents['tapped'], 'the update and the webhooks are the owner\'s');

        foreach (['the owner' => $mine, 'the owner in an agent\'s shop' => $theirs, 'an agent' => $agents] as $who => $outcomes) {
            // Each way a result lands, taken by every seat: no check above passed for want of its case.
            foreach (['asked', 'switch', 'choices', 'part', 'hidden'] as $way) {
                self::assertGreaterThan(0, $outcomes[$way], "{$who}: results that land {$way}");
            }
        }

        self::assertSame($settings, $this->settings(), 'a result opened changes no setting');

        // A field the main shop's card alone has is found there alone.
        $excluded = array_values(array_filter(SettingsIndex::entries(), static fn(SettingsEntry $entry): bool => $entry->field === 'lottery_exclude_agents'));
        self::assertCount(1, $excluded);
        self::assertTrue($excluded[0]->shownTo($seats->of($owner) ?? self::fail('no seat')));
        self::assertFalse($excluded[0]->shownTo($seats->of($owner, $bot->id) ?? self::fail('no seat')), 'not in an agent\'s shop: its card has it not');
    }

    /**
     * Tap every entry `$seat` would be shown (the screens it opens) — in `$in`, an agent's bot, the session read there —:
     * each opens — no old button's home, no refusal —, a typed field asked on its own card, a switch shown on its card or
     * part (its button there), a choice offered (none to pick among, said), a part opened under its card; a field or a
     * part hidden as the values stand, the card it would be on — a field saying what shows it. How many were tapped, and
     * how many landed each way.
     *
     * @return array{tapped: int, asked: int, switch: int, choices: int, part: int, hidden: int, 'hidden part': int}
     */
    private function sweep(\Closure $tap, ?AdminSeat $seat, string $who, ?Bot $in = null): array
    {
        self::assertNotNull($seat, $who);
        $screens = $this->service(Screens::class);
        $session = fn(): ChatSession => $this->service(SessionStore::class)->load($seat->user->telegram_id ?? 0);
        $outcomes = ['tapped' => 0, 'asked' => 0, 'switch' => 0, 'choices' => 0, 'part' => 0, 'hidden' => 0, 'hidden part' => 0];
        foreach (SettingsIndex::entries() as $entry) {
            $screen = $screens->get($entry->screen);
            if ($screen === null || !$screen->allows($seat) || !$entry->shownTo($seat)) {
                continue;
            }
            $data = (string) ($entry->button('x')->markup()['callback_data'] ?? '');
            if ($outcomes['tapped']++ % (ReceivedUpdates::FLOOD_UPDATES - 2) === 0) {
                // Past the chat's pace of taps, as an owner going through them one by one would be.
                Carbon::setTestNow(now()->addSeconds(ReceivedUpdates::FLOOD_SECONDS + 1));
            }
            $tap($data);
            $where = "{$who}: «{$entry->title}» ({$data})";
            self::assertStringNotContainsString(Words::OLD_BUTTON, implode("\n", $this->said()), $where);
            $records = $this->logs()->getRecords();
            // Nothing said at the tap — a popup is a refusal —, but by a field hidden now: what shows it (checked below).
            $popup = $this->popup();
            $refused = "{$where}: refused — " . ($records === [] ? 'nothing logged' : end($records)->message);
            self::assertNotSame([], array_intersect(['sendMessage', 'editMessageText', 'sendPhoto', 'editMessageMedia'], $this->calls()), "{$where}: a window drawn");
            $callbacks = $this->callbacks($this->windowCall());
            $chat = $in === null ? $session() : CurrentBot::run($in, $session);
            $asked = $chat->state() === Admin::INPUT ? $chat->get('adm_prompt') : null;
            $own = preg_quote(Nav::PREFIX . $entry->screen, '/');
            if ($entry->part !== null) {
                if (in_array(Nav::to($entry->screen, SettingsScreen::PART), $callbacks, true)) {
                    $outcomes['part']++;
                } else {
                    // A part none of whose fields shows as the values stand (the extras off): the card, its switch there.
                    self::assertNotEmpty(preg_grep("/^{$own}:e:/", $callbacks), "{$where}: the part, or the card while it is hidden");
                    $outcomes['hidden part']++;
                }
            }
            if ($entry->field === null || !$screen instanceof SettingsScreen) {
                self::assertNull($popup, $refused);

                continue;
            }
            $fields = (new \ReflectionMethod($screen, 'fields'))->invoke($screen);
            $index = array_search($entry->field, array_map(static fn(FieldDef $each): string => $each->name, $fields), true);
            self::assertIsInt($index, $where);
            $field = $fields[$index];
            $toggle = $field->type === FieldDef::TOGGLE;
            $offered = preg_grep("/^{$own}:(c|m|pk):{$index}:/", $callbacks) !== [];
            $way = match (true) {
                is_array($asked) => 'asked',
                $offered => 'choices',
                preg_grep("/^{$own}:e:{$index}:[01]$/", $callbacks) !== [] => 'switch',
                // Nothing to pick among yet (no server, no plan in the shop): its picker, empty, its way back the card.
                in_array($field->type, [FieldDef::CHOICE, FieldDef::MULTI], true) && $field->options === [] && in_array(Nav::to($entry->screen, SettingsScreen::VIEW), $callbacks, true) => 'choices',
                // Hidden as the values stand (its `when`): the card it would be on — its buttons, no question — without it.
                default => 'hidden',
            };
            match ($way) {
                'asked' => self::assertSame([false, $entry->screen], [$toggle, $asked['screen'] ?? null], "{$where}: asked at once, on its card"),
                'switch' => self::assertTrue($toggle, "{$where}: a switch on its card"),
                'choices' => self::assertFalse($toggle, "{$where}: its choices"),
                default => self::assertSame([true, []], [preg_grep("/^{$own}:(e|p):/", $callbacks) !== [], preg_grep("/^{$own}:e:{$index}$/", $callbacks)], "{$where}: hidden now, so its card without it"),
            };
            if ($way === 'hidden') {
                self::assertMatchesRegularExpression('/^این گزینه (وقتی «[^«»]+» روی .+ باشد دیده می‌شود|با (روشن|خاموش) کردن «[^«»]+» دیده می‌شود|با تنظیمات فعلی دیده نمی‌شود)\.$/u', (string) $popup, "{$where}: what shows it, said");
            } else {
                self::assertNull($popup, $refused);
            }
            if ($way === 'choices' && !$offered) {
                self::assertStringContainsString('موردی برای انتخاب نیست.', $this->said()[0] ?? '', "{$where}: nothing to pick, said");
            }
            $outcomes[$way]++;
        }

        return $outcomes;
    }

    /** The best entry `$query` finds for the owner, if any. */
    private static function first(string $query): ?SettingsEntry
    {
        return SettingsIndex::find($query, static fn(): bool => true)[0] ?? null;
    }

    /** @return array<string, mixed> Every bot settings field as the panel reads them. */
    private function settings(): array
    {
        return $this->service(BotSettingsScreen::class)->present();
    }
}
