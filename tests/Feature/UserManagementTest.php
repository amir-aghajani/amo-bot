<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Reports\UserManagement;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use Tests\BotTestCase;

/**
 * A customer's card in the report group: «⚙️ مدیریت کاربر» under a newcomer's report posts one under it, `/manage`
 * with a Telegram id, an @username or a number in the shop answers with one; its buttons give or take the admin role
 * and ban or lift the ban — each asked again first —, and switch the customer's groups one by one (each switch naming
 * the state it asks for, so it stands however often it is pressed), every press rewriting the card as the account now
 * stands; the front lists as many groups as fit. Only in the shop's report group, only for its bot admins; never the
 * admin's own account nor an agent's, never an admin banned nor put in a «همکار» group, never an admin made of someone
 * with their own discount or in a «همکار» group.
 */
final class UserManagementTest extends BotTestCase
{
    /** The shop's bot, as a group addresses its commands. */
    private const BOT = 'amo_shop_bot';

    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    /** @var array<string, int> */
    private array $threads;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => self::BOT]);
        $this->threads = $this->reportGroup();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->ali = $this->customer(['telegram_id' => 7070, 'username' => 'ali']);
    }

    public function testANewcomersReportCarriesTheButtonThatPostsTheirCardUnderIt(): void
    {
        $this->send($this->message('/start', ['from' => ['id' => 8080, 'first_name' => 'Sara']], 8080));
        $sara = User::query()->where('telegram_id', 8080)->sole();
        $report = ReportMessage::query()->where('topic', Topic::Users->value)->sole();
        self::assertSame(UserManagement::buttons($sara->id), (array) $report->keyboard);
        self::assertSame([["um:n:{$sara->id}"]], array_map(static fn(array $row): array => array_column($row, 'callback_data'), (array) $report->keyboard));

        $this->send($this->groupTap("um:n:{$sara->id}", 6161, $this->threads['users']));

        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        $card = $this->params(0);
        self::assertSame('HTML', $card['parse_mode']);
        self::assertSame((string) $this->threads['users'], $card['message_thread_id'], 'in the newcomers\' topic');
        self::assertSame(6161, json_decode($card['reply_parameters'], true)['message_id'], 'under the report');
        self::assertStringStartsWith(implode("\n", [
            "⚙️ <b>مدیریت کاربر</b> · \u{200E}#{$sara->id}",
            '👤 <a href="tg://user?id=8080">Sara</a> · بدون نام کاربری · <code>8080</code>',
        ]), $card['text']);
        self::assertStringEndsWith(implode("\n", [
            '👛 موجودی: ۰ تومان',
            '🛍 سرویس‌ها: ۰ فعال از ۰ · سفارش پرداخت‌شده: ۰',
            '🏷 تخفیف اختصاصی: ندارد',
            '🎖 نقش: مشتری',
            '🚦 وضعیت: فعال',
            '👥 گروه‌ها: ندارد',
        ]), $card['text']);
        self::assertSame([
            "uw:o:{$sara->id}", "us:o:{$sara->id}",
            "ud:o:{$sara->id}", "uo:o:{$sara->id}",
            "uh:o:{$sara->id}", "um:g:{$sara->id}",
            "ua:o:{$sara->id}", "um:r:{$sara->id}",
            "um:o:{$sara->id}", "um:b:{$sara->id}",
        ], $this->callbacks(0), 'the sections, the groups and the role, then refresh and the ban — each row\'s first on the right');
    }

    public function testManageFindsTheCustomerByTelegramIdUsernameOrNumberInAnyDigits(): void
    {
        foreach (['/manage 7070', '/manage @ALI', '/manage ali', "/manage #{$this->ali->id}", '/manage ۷۰۷۰', '/manage@' . self::BOT . ' 7070'] as $command) {
            $this->send($this->groupText($command));

            self::assertSame(['sendMessage'], $this->calls(), $command);
            self::assertStringStartsWith("⚙️ <b>مدیریت کاربر</b> · \u{200E}#{$this->ali->id}", $this->params(0)['text'], $command);
            self::assertSame(9, json_decode($this->params(0)['reply_parameters'], true)['message_id'], "{$command}: under the command");
        }
    }

    public function testManageSaysHowWhenNothingIsNamedAndWhenNobodyAnswers(): void
    {
        $this->send($this->groupText('/manage'));
        self::assertStringStartsWith('برای مدیریت یک کاربر بنویسید /manage', $this->params(0)['text']);
        self::assertArrayNotHasKey('parse_mode', $this->params(0), 'plain words');

        $this->send($this->groupText('/manage @nobody_here'));
        self::assertSame('کاربری با «@nobody_here» در این فروشگاه پیدا نشد.', $this->params(0)['text']);
    }

    public function testOnlyTheReportGroupsBotAdminsManageAndOnlyThere(): void
    {
        $this->send($this->groupText('/manage 7070', chatId: -1009999));
        self::assertSame([], $this->calls(), 'another group sees no customer');
        $this->send($this->groupText('/manage@another_bot 7070'));
        self::assertSame([], $this->calls(), 'another bot\'s command');

        $this->send($this->groupTap("um:r:{$this->ali->id}", self::CARD, $this->threads['users'], from: 9090));
        self::assertSame(GroupButtons::onlyAdmins('کاربرها را مدیریت کنند'), $this->popup());
        $this->send($this->groupTap("um:rs:{$this->ali->id}:admin", self::CARD, $this->threads['users'], from: 9090));
        self::assertSame(UserRole::Customer, $this->ali->refresh()->role, 'a press is no decision of anyone but a bot admin');

        User::query()->where('telegram_id', self::GROUP_ADMIN)->update(['role' => UserRole::Customer->value]);
        $this->send($this->groupText('/manage 7070'));
        self::assertSame(GroupButtons::onlyAdmins('کاربرها را مدیریت کنند'), $this->params(0)['text'], 'told who may');
    }

    public function testTheRoleIsAskedThenGivenAndTakenBackTheCardFollowing(): void
    {
        $this->send($this->press("um:r:{$this->ali->id}"));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertStringEndsWith('❓ <b>مدیر ربات شود؟</b> مدیرها دکمه‌های این گروه را دارند — رسیدها، تیکت‌ها، نمایندگی و همین کارت — و دستورهای مدیریتی ربات را.', $this->params(0)['text']);
        self::assertSame(["um:o:{$this->ali->id}", "um:rs:{$this->ali->id}:admin"], $this->callbacks(0), '«انصراف», then «بله» on the right');
        self::assertSame(UserRole::Customer, $this->ali->refresh()->role, 'asked, not made');

        $this->send($this->press("um:rs:{$this->ali->id}:admin"));
        self::assertSame(UserRole::Admin, $this->ali->refresh()->role);
        self::assertStringContainsString("\n🎖 نقش: مدیر ربات\n", $this->params(0)['text']);
        self::assertSame([['🔄 تازه‌سازی']], array_map(static fn(array $row): array => array_column($row, 'text'), array_slice($this->inlineKeyboard(0), -1)), 'an admin is no one to ban');
        self::assertSame('🎖 برداشتن نقش مدیر', $this->inlineKeyboard(0)[3][1]['text']);
        self::assertSame('🎖 مدیر ربات شد.', $this->popup());

        // A card older than the change asks to make him admin again: made what it names, nothing flipped.
        $this->send($this->press("um:rs:{$this->ali->id}:admin"));
        self::assertSame(UserRole::Admin, $this->ali->refresh()->role);

        $this->send($this->press("um:rs:{$this->ali->id}:customer"));
        self::assertSame(UserRole::Customer, $this->ali->refresh()->role);
        self::assertSame('🎖 نقش مدیر برداشته شد.', $this->popup());
    }

    public function testTheBanIsAskedThenMadeAndLiftedAndNoAdminIsBanned(): void
    {
        $this->send($this->press("um:b:{$this->ali->id}"));
        self::assertStringEndsWith('❓ <b>مسدود شود؟</b> ربات و وب‌سایت دیگر جوابش را نمی‌دهند.', $this->params(0)['text']);

        $this->send($this->press("um:bs:{$this->ali->id}:banned"));
        self::assertSame(UserStatus::Banned, $this->ali->refresh()->status);
        self::assertStringContainsString("\n🚦 وضعیت: ⛔️ مسدود\n", $this->params(0)['text']);
        self::assertSame('✅ رفع مسدودی', $this->inlineKeyboard(0)[4][1]['text']);
        self::assertSame('⛔️ مسدود شد.', $this->popup());

        $this->send($this->press("um:bs:{$this->ali->id}:active"));
        self::assertSame(UserStatus::Active, $this->ali->refresh()->status);

        $other = $this->admin(['telegram_id' => 9191, 'username' => 'sara']);
        $this->send($this->press("um:bs:{$other->id}:banned"));
        self::assertSame(ActorRefusedException::ADMIN_BANNED, $this->popup());
        self::assertSame('true', $this->params(1)['show_alert']);
        self::assertSame(UserStatus::Active, $other->refresh()->status);
    }

    public function testGroupsAreSwitchedOneByOneAndNoAdminIsMadeAColleague(): void
    {
        $this->send($this->press("um:g:{$this->ali->id}"));
        self::assertSame('هنوز گروهی ساخته نشده است؛ در پنل، صفحه «کاربران»، بخش «گروه‌ها» یکی بسازید.', $this->popup());

        $vip = $this->customerGroup('VIP');
        $colleagues = $this->customerGroup('همکاران', [], ['agent_pricing' => true]);
        $this->send($this->press("um:g:{$this->ali->id}"));
        self::assertSame([['▫️ همکاران · همکار', '▫️ VIP'], ['⬅️ بازگشت']], array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard(0)), 'a switch a group, the first on the right');

        self::assertSame(["um:gs:{$this->ali->id}:{$colleagues->id}:1", "um:gs:{$this->ali->id}:{$vip->id}:1", "um:o:{$this->ali->id}"], $this->callbacks(0), 'each switch names the state it asks for');

        $this->send($this->press("um:gs:{$this->ali->id}:{$vip->id}:1"));
        self::assertSame([$vip->id], $this->ali->groups()->get()->modelKeys());
        self::assertSame('✅ به «VIP» اضافه شد.', $this->popup());
        self::assertSame('✅ VIP', $this->inlineKeyboard(0)[0][1]['text'], 'the switches stay, for the next');
        self::assertSame("um:gs:{$this->ali->id}:{$vip->id}:0", $this->callbacks(0)[1]);
        self::assertStringContainsString("\n👥 گروه‌ها: VIP", $this->params(0)['text']);

        $this->send($this->press("um:gs:{$this->ali->id}:{$colleagues->id}:1"));
        $this->send($this->press("um:gs:{$this->ali->id}:{$vip->id}:0"));
        self::assertSame([$colleagues->id], $this->ali->groups()->get()->modelKeys());
        self::assertSame('▫️ از «VIP» برداشته شد.', $this->popup());

        $other = $this->admin(['telegram_id' => 9191, 'username' => 'sara']);
        $this->send($this->press("um:gs:{$other->id}:{$colleagues->id}:1"));
        self::assertSame(ActorRefusedException::ADMIN_TO_AGENT_GROUP, $this->popup());
        self::assertSame([], $other->groups()->get()->modelKeys());
        $this->send($this->press("um:gs:{$other->id}:{$vip->id}:1"));
        self::assertSame([$vip->id], $other->groups()->get()->modelKeys(), 'any other group is fine');
    }

    public function testAGroupSwitchStandsHoweverOftenItIsPressed(): void
    {
        $vip = $this->customerGroup('VIP');
        $gold = $this->customerGroup('Gold');
        $this->admin(['telegram_id' => 9191, 'username' => 'sara']);
        $id = $this->ali->id;

        // A double tap, or two admins on the same card: in the group, not in and out again.
        $this->send($this->press("um:gs:{$id}:{$vip->id}:1"));
        $this->send($this->press("um:gs:{$id}:{$vip->id}:1", 9191));
        self::assertSame([$vip->id], $this->ali->groups()->get()->modelKeys());
        self::assertSame('✅ به «VIP» اضافه شد.', $this->popup());

        // Two admins switching two groups on cards drawn before either pressed: each switch stands.
        $this->send($this->press("um:gs:{$id}:{$gold->id}:1", 9191));
        $this->send($this->press("um:gs:{$id}:{$vip->id}:0"));
        $this->send($this->press("um:gs:{$id}:{$vip->id}:0"));
        self::assertSame([$gold->id], $this->ali->groups()->get()->modelKeys());

        foreach (["um:gs:{$id}:{$gold->id}", "um:gs:{$id}:{$gold->id}:2", "um:gs:{$id}:{$gold->id}:0:1"] as $forged) {
            $this->send($this->press($forged));
            self::assertSame(['answerCallbackQuery'], $this->calls(), $forged);
        }
        self::assertSame([$gold->id], $this->ali->groups()->get()->modelKeys());
    }

    public function testTheAdminsRulesHoldWhenTheirRoleIsTakenAndGivenBack(): void
    {
        $sara = $this->admin(['telegram_id' => 9191, 'username' => 'sara']);
        $partners = $this->customerGroup('Partners', [], ['agent_pricing' => true]);

        $this->send($this->press("ud:s:{$sara->id}:50"));
        self::assertSame(ActorRefusedException::ADMIN_DISCOUNT, $this->popup());

        // Taken off, given a discount and the agents' prices as a customer — then not made an admin again here.
        $this->send($this->press("um:rs:{$sara->id}:customer"));
        $this->send($this->press("ud:s:{$sara->id}:50"));
        $this->send($this->press("um:gs:{$sara->id}:{$partners->id}:1"));
        self::assertSame([50, [$partners->id]], [$sara->refresh()->discount_percent, $sara->groups()->get()->modelKeys()]);

        $this->send($this->press("um:rs:{$sara->id}:admin"));
        self::assertSame(ActorRefusedException::ADMIN_WITH_OWN_PRICES, $this->popup());
        self::assertSame('true', $this->params(1)['show_alert']);
        self::assertSame(UserRole::Customer, $sara->refresh()->role);
        self::assertStringContainsString("\n🎖 نقش: مشتری\n", $this->params(0)['text'], 'the card as the account stands');
    }

    public function testTheGroupsLineKeepsTheFrontWithinAMessage(): void
    {
        for ($i = 1; $i <= 150; ++$i) {
            $this->customerGroup(sprintf('گروه شماره %03d با نامی بلند', $i), [$this->ali]);
        }

        $this->send($this->groupText("/manage #{$this->ali->id}"));

        $text = $this->params(0)['text'];
        self::assertSame(1, preg_match('/\n👥 گروه‌ها: (گروه شماره 001 با نامی بلند، گروه شماره 002 [^\n]*) و ([۰-۹]+) گروه دیگر$/u', $text, $line), 'a few, then how many more');
        self::assertLessThanOrEqual(300, mb_strlen($line[1]));
        self::assertSame(150, substr_count($line[1], 'گروه شماره') + (int) Persian::latinDigits($line[2]), 'every group counted');
        self::assertLessThan(1000, mb_strlen($text));
    }

    public function testTheirOwnAccountAndAnAgentsAreShownNotChanged(): void
    {
        $boss = User::query()->where('telegram_id', self::GROUP_ADMIN)->sole();
        $this->send($this->groupText('/manage @boss'));
        self::assertStringEndsWith("\n\n🔒 حساب خودتان است؛ با مدیر دیگری یا از پنل تغییر می‌کند.", $this->params(0)['text']);
        self::assertSame(["uw:o:{$boss->id}", "us:o:{$boss->id}", "ua:o:{$boss->id}", "uh:o:{$boss->id}", "um:o:{$boss->id}"], $this->callbacks(0), 'only what reads, and «تازه‌سازی»');

        $this->send($this->press("um:rs:{$boss->id}:customer"));
        self::assertSame(ActorRefusedException::OWN_ACCOUNT, $this->popup());
        self::assertSame(UserRole::Admin, $boss->refresh()->role);

        $agent = $this->agent(overrides: ['telegram_id' => 9292, 'username' => 'agent_reza']);
        $this->send($this->groupText('/manage @agent_reza'));
        self::assertStringContainsString("\n🎖 نقش: مشتری · نماینده\n", $this->params(0)['text']);
        self::assertStringEndsWith('🔒 حساب نماینده است؛ فقط از پنل تغییر می‌کند.', $this->params(0)['text']);

        $this->send($this->press("um:bs:{$agent->id}:banned"));
        self::assertSame(ActorRefusedException::AGENT_ACCOUNT, $this->popup());
        self::assertSame(UserStatus::Active, $agent->refresh()->status);
    }

    public function testACustomerGoneMeanwhileTakesTheButtonsAway(): void
    {
        $id = $this->ali->id;
        $this->ali->delete();

        $this->send($this->press("um:o:{$id}"));
        self::assertSame(['editMessageReplyMarkup', 'answerCallbackQuery'], $this->calls());
        self::assertSame('این کاربر دیگر پیدا نشد.', $this->popup());
    }

    /** A press on the card's buttons, by the report group's bot admin. */
    private function press(string $data, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap($data, self::CARD, $this->threads['users'], $from);
    }
}
