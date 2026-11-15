<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Referrals\Models\ReferralAlert;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use Tests\BotTestCase;

/**
 * «🤝 زیرمجموعه‌گیری» in the bot's management (Sections\Support\Referral*, Referrers*, Invitees*, Commission*): the
 * program's numbers and rules, its off and missing-button notices, the four lists with their rows' screens and the ways
 * between them, and an alert cleared after asking — by the panel's own route, which keeps an admin off their own.
 */
final class SupportReferralsTest extends BotTestCase
{
    private const STAFF = 7100;

    private User $reza;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
        $this->reza = $this->customer(['telegram_id' => 900_001, 'first_name' => 'Reza', 'username' => 'reza']);
        $this->sara = $this->customer(['telegram_id' => 900_002, 'first_name' => 'Sara', 'referred_by' => $this->reza->id]);
    }

    public function testTheNumbersAndTheListsLeadIntoEachOther(): void
    {
        $this->referralProgram(rate: 10, overrides: ['referral_renewal_rate' => 5]);
        $payment = $this->paidByCard($this->topUpOrder($this->sara, '50000'));
        $commission = ReferralCommission::query()->firstOrFail();

        $this->send($this->tap(Nav::to('hl'), chat: self::STAFF));
        self::assertContains(Nav::to('rf'), $this->buttons(), 'in «فروش»');

        $this->send($this->tap(Nav::to('rf'), chat: self::STAFF));
        $said = $this->window();
        self::assertStringContainsString('معرف‌ها: <b>۱</b>', $said);
        self::assertStringContainsString('زیرمجموعه‌ها: <b>۱</b>', $said);
        self::assertStringContainsString('پورسانت‌های پرداخت‌شده: <b>۱</b>', $said);
        self::assertStringContainsString('جمع پورسانت‌ها: <b>۵٬۰۰۰ تومان</b> — ۱۰٪ از هر پرداخت؛ تمدید ۵٪', $said);
        self::assertStringNotContainsString('خاموش است', $said);

        $this->send($this->tap(Nav::to('rfr'), chat: self::STAFF));
        self::assertContains(Nav::to('rfrv', 'show', $this->reza->id), $this->buttons());
        $this->send($this->tap(Nav::to('rfrv', 'show', $this->reza->id), chat: self::STAFF));
        self::assertStringContainsString('۱ نفر', $this->window());
        self::assertStringContainsString('۵٬۰۰۰ تومان', $this->window());
        self::assertContains(Nav::to('rfi', 'ref', $this->reza->id), $this->buttons(), 'the ones they brought');

        $this->send($this->tap(Nav::to('rfi', 'ref', $this->reza->id), chat: self::STAFF));
        self::assertStringContainsString('فقط زیرمجموعه‌های معرف', $this->window());
        self::assertContains(Nav::to('rfiv', 'show', $this->sara->id), $this->buttons());
        $this->send($this->tap(Nav::to('rfiv', 'show', $this->sara->id), chat: self::STAFF));
        self::assertStringContainsString('معرف: Reza', $this->window());

        $this->send($this->tap(Nav::to('rfc'), chat: self::STAFF));
        self::assertContains(Nav::to('rfcv', 'show', $commission->id), $this->buttons());
        $this->send($this->tap(Nav::to('rfcv', 'show', $commission->id), chat: self::STAFF));
        self::assertStringContainsString('۱۰٪', $this->window());
        self::assertStringContainsString('پرداخت: #' . Persian::digits($payment->id), $this->window());

        $this->send($this->tap(Nav::to('rfc', 'f', 'sort', 'commission'), chat: self::STAFF));
        self::assertStringContainsString('بیشترین پورسانت', $this->window(), 'the panel\'s sortable header as a chip');
    }

    public function testAnAlertIsClearedAfterAsking(): void
    {
        $alert = $this->referralAlert($this->reza);

        $this->send($this->tap(Nav::to('rf'), chat: self::STAFF));
        self::assertStringContainsString('هشدارها: <b>۱</b>', $this->window());

        $this->send($this->tap(Nav::to('rfr'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('rfrv', 'show', $this->reza->id), chat: self::STAFF));
        self::assertContains(Nav::to('rfa', 'who', $this->reza->id), $this->buttons(), 'the referrer\'s alerts');

        $this->send($this->tap(Nav::to('rfa', 'who', $this->reza->id), chat: self::STAFF));
        self::assertContains(Nav::to('rfav', 'show', $alert->id), $this->buttons());
        $this->send($this->tap(Nav::to('rfav', 'show', $alert->id), chat: self::STAFF));
        self::assertStringContainsString('عضویت پشت سر هم با یک لینک', $this->window());

        $this->send($this->tap(Nav::to('rfav', 'clr', $alert->id), chat: self::STAFF));
        self::assertStringContainsString('آنچه تا حالا نگه داشته شد داده نمی‌شود', $this->window());
        self::assertSame(1, ReferralAlert::query()->count(), 'asked, nothing done yet');

        $this->send($this->tap(Nav::to('rfav', 'clrd', $alert->id), chat: self::STAFF));
        self::assertSame(0, ReferralAlert::query()->count());
        self::assertSame('✅ هشدار رفع شد', $this->popup());
        self::assertStringContainsString('⚠️ هشدارها', $this->window(), 'the list again');
    }

    public function testAnAdminNeverClearsAnAlertOnThemselves(): void
    {
        $staff = User::query()->where('telegram_id', self::STAFF)->firstOrFail();
        $alert = $this->referralAlert($staff);

        $this->send($this->tap(Nav::to('rfa'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('rfav', 'clrd', $alert->id), chat: self::STAFF));

        self::assertSame(1, ReferralAlert::query()->count(), 'the panel\'s route said no');
        self::assertNotNull($this->popup());
    }

    public function testTheProgramOffAndAMenuWithoutItsButtonAreSaid(): void
    {
        $this->send($this->tap(Nav::to('rf'), chat: self::STAFF));
        self::assertStringContainsString('زیرمجموعه‌گیری خاموش است', $this->window());

        $this->referralProgram();
        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید', 'style' => null]]]]);
        $this->send($this->tap(Nav::to('rf'), chat: self::STAFF));
        self::assertStringNotContainsString('در منوی ربات نیست', $this->window(), 'the keyboards are no admin\'s to read');

        self::assertSame([], array_intersect([Nav::to('bsf'), Nav::to('bs'), Nav::to('kb')], $this->buttons()), 'the rules and the keyboards are no admin\'s');

        $this->owner();
        $this->send($this->tap(Nav::to('rf'), chat: 7001));
        self::assertStringContainsString('دکمه «زیرمجموعه‌گیری» در منوی ربات نیست', $this->window());
        self::assertNotSame([], array_intersect([Nav::to('bsf'), Nav::to('bs')], $this->buttons()), '«قوانین پورسانت» for the owner');
    }

    public function testASearchNarrowsTheReferrers(): void
    {
        $this->customer(['telegram_id' => 900_003, 'first_name' => 'Nima', 'username' => 'nima']);
        $this->customer(['telegram_id' => 900_004, 'first_name' => 'Mina', 'referred_by' => User::query()->where('username', 'nima')->value('id')]);

        $this->send($this->tap(Nav::to('rfr', 'search'), chat: self::STAFF));
        $this->send($this->message('reza', chat: self::STAFF));

        self::assertStringContainsString('🔎 «reza»', $this->window());
        self::assertSame([Nav::to('rfrv', 'show', $this->reza->id)], array_values(array_filter($this->buttons(), static fn(string $data): bool => str_starts_with($data, 'a:rfrv:'))));
    }

    /** The account 7001 linked as the owner. */
    private function owner(): void
    {
        $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $this->linkOwner(7001);
    }

    /** What the management's window says now: the last text the bot wrote. */
    private function window(): string
    {
        $said = $this->said();

        return $said === [] ? '' : $said[array_key_last($said)];
    }

    /**
     * The window's buttons now: the data of the last inline buttons the bot set.
     *
     * @return list<string>
     */
    private function buttons(): array
    {
        foreach (array_reverse(array_keys($this->calls())) as $call) {
            if ($this->inlineKeyboard($call) !== []) {
                return $this->callbacks($call);
            }
        }

        return [];
    }
}
