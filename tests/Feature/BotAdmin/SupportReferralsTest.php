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
        $said = $this->lastSaid();
        self::assertStringContainsString('معرف‌ها: <b>۱</b>', $said);
        self::assertStringContainsString('زیرمجموعه‌ها: <b>۱</b>', $said);
        self::assertStringContainsString('پورسانت‌های پرداخت‌شده: <b>۱</b>', $said);
        self::assertStringContainsString('جمع پورسانت‌ها: <b>۵٬۰۰۰ تومان</b>', $said);
        self::assertStringContainsString('پورسانت: <b>۱۰٪ از هر پرداخت؛ تمدید ۵٪</b>', $said);
        self::assertStringNotContainsString('خاموش است', $said);

        $this->send($this->tap(Nav::to('rfr'), chat: self::STAFF));
        self::assertContains(Nav::to('rfrv', 'show', $this->reza->id), $this->buttons());
        $this->send($this->tap(Nav::to('rfrv', 'show', $this->reza->id), chat: self::STAFF));
        self::assertStringContainsString('۱ نفر', $this->lastSaid());
        self::assertStringContainsString('۵٬۰۰۰ تومان', $this->lastSaid());
        self::assertContains(Nav::to('rfi', 'ref', $this->reza->id), $this->buttons(), 'the ones they brought');

        $this->send($this->tap(Nav::to('rfi', 'ref', $this->reza->id), chat: self::STAFF));
        self::assertStringContainsString('فقط زیرمجموعه‌های معرف', $this->lastSaid());
        self::assertContains(Nav::to('rfiv', 'show', $this->sara->id), $this->buttons());
        $this->send($this->tap(Nav::to('rfiv', 'show', $this->sara->id), chat: self::STAFF));
        self::assertStringContainsString('معرف: Reza', $this->lastSaid());

        $this->send($this->tap(Nav::to('rfc'), chat: self::STAFF));
        self::assertContains(Nav::to('rfcv', 'show', $commission->id), $this->buttons());
        $this->send($this->tap(Nav::to('rfcv', 'show', $commission->id), chat: self::STAFF));
        self::assertStringContainsString('۱۰٪', $this->lastSaid());
        self::assertStringContainsString('پرداخت: <b>#' . Persian::digits($payment->id), $this->lastSaid());

        $this->send($this->tap(Nav::to('rfc', 'f', 'sort', 'commission'), chat: self::STAFF));
        self::assertStringContainsString('۱ پورسانت · مبلغ پورسانت', $this->lastSaid(), 'the panel\'s sortable header as a chip, said over the list');
    }

    public function testAnAlertIsClearedAfterAsking(): void
    {
        $alert = $this->referralAlert($this->reza);

        $this->send($this->tap(Nav::to('rf'), chat: self::STAFF));
        self::assertContains('⚠️ هشدارها (۱)', $this->labels(), 'a queue: its count on its button');

        $this->send($this->tap(Nav::to('rfr'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('rfrv', 'show', $this->reza->id), chat: self::STAFF));
        self::assertContains(Nav::to('rfa', 'who', $this->reza->id), $this->buttons(), 'the referrer\'s alerts');

        $this->send($this->tap(Nav::to('rfa', 'who', $this->reza->id), chat: self::STAFF));
        self::assertContains(Nav::to('rfav', 'show', $alert->id), $this->buttons());
        $this->send($this->tap(Nav::to('rfav', 'show', $alert->id), chat: self::STAFF));
        self::assertStringContainsString('عضویت پشت سر هم با یک لینک', $this->lastSaid());

        $this->send($this->tap(Nav::to('rfav', 'clr', $alert->id), chat: self::STAFF));
        self::assertStringContainsString('آنچه تا حالا نگه داشته شد داده نمی‌شود', $this->lastSaid());
        self::assertSame(1, ReferralAlert::query()->count(), 'asked, nothing done yet');

        $this->send($this->tap(Nav::to('rfav', 'clrd', $alert->id), chat: self::STAFF));
        self::assertSame(0, ReferralAlert::query()->count());
        self::assertSame('✅ هشدار رفع شد', $this->popup());
        self::assertStringContainsString('⚠️ هشدارها', $this->lastSaid(), 'the list again');
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

    public function testAnAdminsOwnAlertHasNoClearButtonAndAnotherAdminsHasIt(): void
    {
        $staff = User::query()->where('telegram_id', self::STAFF)->firstOrFail();
        $this->customer(['telegram_id' => 7200, 'first_name' => 'Nima', 'role' => UserRole::Admin]);
        $own = $this->referralAlert($staff);
        $rezas = $this->referralAlert($this->reza);

        $this->send($this->tap(Nav::to('rfa'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('rfav', 'show', $own->id), chat: self::STAFF));
        self::assertStringContainsString('عضویت پشت سر هم با یک لینک', $this->lastSaid(), 'the alert\'s own card');
        self::assertNotContains(Nav::to('rfav', 'clr', $own->id), $this->buttons(), '«✅ رفع هشدار» another admin\'s');
        self::assertContains(Nav::to('rfa'), $this->buttons(), 'back to the alerts');
        self::assertStringContainsString('این هشدار روی حساب خود شماست؛ رفعش با مدیر دیگری است.', $this->lastSaid());

        $this->send($this->tap(Nav::to('rfav', 'show', $rezas->id), chat: self::STAFF));
        self::assertContains(Nav::to('rfav', 'clr', $rezas->id), $this->buttons(), 'another\'s alert is theirs to clear');
        self::assertStringNotContainsString('خود شماست', $this->lastSaid());

        $this->send($this->tap(Nav::to('rfa'), chat: 7200));
        $this->send($this->tap(Nav::to('rfav', 'show', $own->id), chat: 7200));
        self::assertContains(Nav::to('rfav', 'clr', $own->id), $this->buttons(), 'another admin clears it');
        self::assertStringNotContainsString('خود شماست', $this->lastSaid());
    }

    public function testTheProgramOffAndAMenuWithoutItsButtonAreSaid(): void
    {
        $this->send($this->tap(Nav::to('rf'), chat: self::STAFF));
        self::assertStringContainsString('زیرمجموعه‌گیری خاموش است', $this->lastSaid());

        $this->referralProgram();
        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'reply', 'rows' => [[['action' => 'buy', 'label' => 'خرید', 'style' => null]]]]);
        $this->send($this->tap(Nav::to('rf'), chat: self::STAFF));
        self::assertStringContainsString('دکمه «زیرمجموعه‌گیری» در منوی ربات نیست', $this->lastSaid(), 'a shop\'s admin reads the keyboards as the owner does');
        self::assertContains(Nav::to('bsf'), $this->buttons(), 'the rules');
        self::assertContains(Nav::to('kbl', 'show', 'start'), $this->buttons(), 'and the menu that lacks the button');

        $this->owner();
        $this->send($this->tap(Nav::to('rf'), chat: 7001));
        self::assertStringContainsString('دکمه «زیرمجموعه‌گیری» در منوی ربات نیست', $this->lastSaid());
        self::assertContains(Nav::to('bsf'), $this->buttons(), 'the rules, which live here, for the owner');
        self::assertNotContains(Nav::to('bs'), $this->buttons(), 'never the bot settings\' menu');
        self::assertContains(Nav::to('kbl', 'show', 'start'), $this->buttons(), 'straight to the menu that lacks the button');

        $this->referralProgram(enabled: false);
        $this->send($this->tap(Nav::to('rf'), chat: 7001));
        self::assertStringContainsString('زیرمجموعه‌گیری خاموش است', $this->lastSaid());
        $rules = $this->button(Nav::to('bsf'));
        self::assertSame('🔗 قوانین زیرمجموعه‌گیری', $rules['text'] ?? null, 'off: the way to turn it on is its rules card');
        self::assertSame('primary', $rules['style'] ?? null, 'the screen\'s one main action');
    }

    public function testEachListIsOrderedEitherWay(): void
    {
        $this->referralProgram(rate: 10);
        $nima = $this->customer(['telegram_id' => 900_003, 'first_name' => 'Nima', 'username' => 'nima']);
        $mina = $this->customer(['telegram_id' => 900_004, 'first_name' => 'Mina', 'referred_by' => $nima->id]);
        $lily = $this->customer(['telegram_id' => 900_005, 'first_name' => 'Lily', 'referred_by' => $nima->id]);
        $this->paidByCard($this->topUpOrder($this->sara, '50000'));
        $this->paidByCard($this->topUpOrder($mina, '20000'));
        [$first, $second] = ReferralCommission::query()->orderBy('id')->pluck('id')->all();
        $rows = fn(string $screen): array => array_values(array_filter($this->buttons(), static fn(string $data): bool => str_starts_with($data, "a:{$screen}:show:")));

        // The referrers: the most referrals first (the panel's), then the fewest.
        $this->send($this->tap(Nav::to('rfr'), chat: self::STAFF));
        self::assertSame([Nav::to('rfrv', 'show', $nima->id), Nav::to('rfrv', 'show', $this->reza->id)], $rows('rfrv'));
        self::assertContains('● بیشترین اول', $this->labels(), 'the panel\'s way, marked');
        self::assertStringNotContainsString('بیشترین اول', $this->lastSaid(), 'the panel\'s own way is not said over the list');
        $this->send($this->tap(Nav::to('rfr', 'f', 'dir', 'asc'), chat: self::STAFF));
        self::assertSame([Nav::to('rfrv', 'show', $this->reza->id), Nav::to('rfrv', 'show', $nima->id)], $rows('rfrv'));
        self::assertStringContainsString('۲ معرف · تعداد زیرمجموعه · کمترین اول', $this->lastSaid());
        $this->send($this->tap(Nav::to('rfr', 'f', 'sort', 'last_referral'), chat: self::STAFF));
        self::assertContains('● قدیمی‌ترین اول', $this->labels(), 'a moment\'s way in its own words');

        // The invitees: the newest first, then the oldest.
        $this->send($this->tap(Nav::to('rfi'), chat: self::STAFF));
        self::assertSame([Nav::to('rfiv', 'show', $lily->id), Nav::to('rfiv', 'show', $mina->id), Nav::to('rfiv', 'show', $this->sara->id)], $rows('rfiv'));
        $this->send($this->tap(Nav::to('rfi', 'f', 'dir', 'asc'), chat: self::STAFF));
        self::assertSame([Nav::to('rfiv', 'show', $this->sara->id), Nav::to('rfiv', 'show', $mina->id), Nav::to('rfiv', 'show', $lily->id)], $rows('rfiv'));
        self::assertStringContainsString('قدیمی‌ترین اول', $this->lastSaid());

        // The commissions: the newest first, then the oldest; by amount, the smallest, then the largest.
        $this->send($this->tap(Nav::to('rfc'), chat: self::STAFF));
        self::assertSame([Nav::to('rfcv', 'show', $second), Nav::to('rfcv', 'show', $first)], $rows('rfcv'));
        $this->send($this->tap(Nav::to('rfc', 'f', 'dir', 'asc'), chat: self::STAFF));
        self::assertSame([Nav::to('rfcv', 'show', $first), Nav::to('rfcv', 'show', $second)], $rows('rfcv'));
        $this->send($this->tap(Nav::to('rfc', 'f', 'sort', 'commission'), chat: self::STAFF));
        self::assertSame([Nav::to('rfcv', 'show', $second), Nav::to('rfcv', 'show', $first)], $rows('rfcv'), 'the smallest first');
        $this->send($this->tap(Nav::to('rfc', 'f', 'dir', '_'), chat: self::STAFF));
        self::assertSame([Nav::to('rfcv', 'show', $first), Nav::to('rfcv', 'show', $second)], $rows('rfcv'), 'the largest first');
    }

    public function testALongNameLeavesARowsFiguresWhole(): void
    {
        $this->referralProgram(rate: 10);
        $this->reza->forceFill(['first_name' => str_repeat('رضا ', 15)])->save();
        $this->paidByCard($this->topUpOrder($this->sara, '50000'));

        $this->send($this->tap(Nav::to('rfr'), chat: self::STAFF));
        $row = array_values(array_filter($this->labels(), static fn(string $label): bool => str_contains($label, 'نفر')));
        self::assertCount(1, $row);
        self::assertStringEndsWith('۱ نفر · ۵٬۰۰۰ تومان', $row[0], 'the name cut, the count and the commission whole');
        self::assertStringContainsString('…', $row[0]);
    }

    public function testASearchNarrowsTheReferrers(): void
    {
        $this->customer(['telegram_id' => 900_003, 'first_name' => 'Nima', 'username' => 'nima']);
        $this->customer(['telegram_id' => 900_004, 'first_name' => 'Mina', 'referred_by' => User::query()->where('username', 'nima')->value('id')]);

        $this->send($this->tap(Nav::to('rfr', 'search'), chat: self::STAFF));
        $this->send($this->message('reza', chat: self::STAFF));

        self::assertStringContainsString('🔎 «reza»', $this->lastSaid());
        self::assertSame([Nav::to('rfrv', 'show', $this->reza->id)], array_values(array_filter($this->buttons(), static fn(string $data): bool => str_starts_with($data, 'a:rfrv:'))));
    }

    /**
     * The labels of the window's buttons now.
     *
     * @return list<string>
     */
    private function labels(): array
    {
        return $this->buttonLabels($this->buttonsCall());
    }

    /**
     * The window's button to `$data` now, as Telegram takes it; [] for none.
     *
     * @return array<string, mixed>
     */
    private function button(string $data): array
    {
        foreach (array_merge(...$this->inlineKeyboard($this->buttonsCall())) as $button) {
            if (($button['callback_data'] ?? null) === $data) {
                return $button;
            }
        }

        return [];
    }

    /**
     * The window's buttons now: the data of the last inline buttons the bot set.
     *
     * @return list<string>
     */
    private function buttons(): array
    {
        $call = $this->buttonsCall();

        return $this->inlineKeyboard($call) === [] ? [] : $this->callbacks($call);
    }
}
