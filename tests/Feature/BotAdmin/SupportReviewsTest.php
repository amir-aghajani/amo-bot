<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Reviews\Models\Review;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «⭐️ نظرات» in the bot's management (Sections\Support\Review*): the moderation queue by the panel's tabs, a review
 * approved green or rejected red at a tap — the next one waiting a tap away —, a decided one changed again, a delete
 * asked first in red, and an admin's own review left to another admin.
 */
final class SupportReviewsTest extends BotTestCase
{
    private const STAFF = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
    }

    public function testTheQueueIsWorkedOneReviewAfterAnother(): void
    {
        $older = $this->review(name: 'Sara', rating: 4, body: 'خوب بود.');
        $newer = $this->review(name: 'Ali R.', body: 'سرعت عالی.');
        $this->review(name: 'Reza', rating: 2, body: 'کند بود.', overrides: ['status' => 'approved']);

        $this->send($this->tap(Nav::to('rv'), chat: self::STAFF));
        self::assertStringContainsString('۲ نظر · 🟡 در انتظار', $this->lastSaid(), 'the queue first, counted');
        self::assertSame([Nav::to('rvv', 'show', $newer->id), Nav::to('rvv', 'show', $older->id)], $this->rows());
        self::assertContains('🟡 ⭐۵ · Ali R. · سرعت عالی.', $this->labels(), 'a row: its chip, rating, signature and words');

        $this->send($this->tap(Nav::to('rvv', 'show', $newer->id), chat: self::STAFF));
        self::assertStringContainsString('سرعت عالی.', $this->lastSaid());
        self::assertStringContainsString('وضعیت: <b>🟡 در انتظار بررسی</b>', $this->lastSaid());
        self::assertSame('success', $this->button(Nav::to('rvv', 'ok', $newer->id))['style'] ?? null, 'approve green');
        self::assertSame('danger', $this->button(Nav::to('rvv', 'no', $newer->id))['style'] ?? null, 'reject red');
        self::assertContains(Nav::to('rvv', 'show', $older->id), $this->buttons(), 'the next one waiting a tap away');

        $this->send($this->tap(Nav::to('rvv', 'ok', $newer->id), chat: self::STAFF));
        self::assertSame('approved', $newer->refresh()->status->value, 'at one tap, through the panel\'s route');
        self::assertMatchesRegularExpression('/تصمیم: <b>[^<]+ · توسط [^<]+<\/b>/u', $this->lastSaid(), 'when, and who decided');
        self::assertSame('✅ تایید شد و روی سایت دیده می‌شود', $this->popup());
        self::assertNotContains(Nav::to('rvv', 'ok', $newer->id), $this->buttons());
        self::assertContains(Nav::to('rvv', 'no', $newer->id), $this->buttons(), 'an approved one may still be taken off');

        $this->send($this->tap(Nav::to('rvv', 'show', $older->id), chat: self::STAFF));
        $this->send($this->tap(Nav::to('rvv', 'no', $older->id), chat: self::STAFF));
        self::assertSame('rejected', $older->refresh()->status->value);
        self::assertNotContains(true, array_map(static fn(string $data): bool => str_starts_with($data, 'a:rvv:show:'), $this->buttons()), 'none left waiting: no «next»');
        self::assertContains(Nav::to('rvv', 'ok', $older->id), $this->buttons(), 'a rejected one may still be approved');
    }

    public function testTheOwnersDecisionKeepsTheirLoginWhichOnlyTheyRead(): void
    {
        // The owner's decisions keep the panel's login (AdminSeats, Reviewers) — the test shop has none until one is set,
        // and a decision kept under none names nobody.
        $this->config(['admin.username' => 'root']);
        $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);
        $review = $this->review();

        $this->send($this->tap(Nav::to('rv'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('rvv', 'ok', $review->id), chat: self::OWNER));
        self::assertSame(['approved', 'root'], [$review->refresh()->status->value, $review->reviewer]);
        self::assertMatchesRegularExpression('/تصمیم: <b>[^<]+ · توسط \x{2068}root\x{2069}<\/b>/u', $this->lastSaid(), 'the owner reads their own login');

        // Anyone else reads «پشتیبانی» for it — a shop's admin on the owner's seat too: the owner's login is never
        // shown to them.
        $this->send($this->tap(Nav::to('rv', 'f', 'status', 'approved'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('rvv', 'show', $review->id), chat: self::STAFF));
        self::assertMatchesRegularExpression('/تصمیم: <b>[^<]+ · توسط پشتیبانی<\/b>/u', $this->lastSaid());
        self::assertStringNotContainsString('root', $this->lastSaid());
    }

    public function testADeleteIsAskedFirstInRed(): void
    {
        $review = $this->review();

        $this->send($this->tap(Nav::to('rv'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('rvv', 'del', $review->id), chat: self::STAFF));
        self::assertStringContainsString('برای همیشه حذف شود؟', $this->lastSaid());
        self::assertSame('danger', $this->button(Nav::to('rvv', 'gone', $review->id))['style'] ?? null);
        self::assertNotNull(Review::query()->find($review->id), 'asked, nothing done yet');

        $this->send($this->tap(Nav::to('rvv', 'gone', $review->id), chat: self::STAFF));
        self::assertNull(Review::query()->find($review->id));
        self::assertSame('🗑 حذف شد', $this->popup());

        // Its card from an old window: gone from the list's page, the list instead, saying so.
        $this->send($this->tap(Nav::to('rvv', 'show', $review->id), chat: self::STAFF));
        self::assertSame('این ردیف در صفحه فعلی فهرست نیست؛ فهرست تازه را ببینید.', $this->popup());
        self::assertStringContainsString('⭐️ نظرات', $this->lastSaid());
    }

    public function testAnotherTabSaysHowManyWait(): void
    {
        $this->review();
        $this->review(name: 'Reza', overrides: ['status' => 'approved']);

        $this->send($this->tap(Nav::to('rv', 'f', 'status', 'approved'), chat: self::STAFF));
        self::assertStringContainsString('🟡 ۱ نظر در انتظار بررسی است.', $this->lastSaid());
        self::assertStringContainsString('۱ نظر · ✅ تاییدشده', $this->lastSaid());
    }

    public function testAnAdminsOwnReviewIsAnotherAdminsToDecideAndAGuestsIsTheirs(): void
    {
        $boss = User::query()->where('telegram_id', self::STAFF)->sole();
        $this->customer(['telegram_id' => 7200, 'first_name' => 'Reza', 'role' => UserRole::Admin]);
        $guest = $this->review(name: 'Sara', body: 'خوب بود.');
        $own = $this->review($boss, name: 'Boss', body: 'عالی است.');
        $decisions = [Nav::to('rvv', 'ok', $own->id), Nav::to('rvv', 'no', $own->id), Nav::to('rvv', 'del', $own->id)];

        $this->send($this->tap(Nav::to('rv'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('rvv', 'show', $own->id), chat: self::STAFF));
        self::assertStringContainsString('عالی است.', $this->lastSaid(), 'the review\'s own card');
        foreach ($decisions as $decision) {
            self::assertNotContains($decision, $this->buttons(), $decision);
        }
        self::assertContains(Nav::to('rvv', 'show', $guest->id), $this->buttons(), 'the next one waiting stays');
        self::assertStringContainsString('این نظر خود شماست؛ تایید، رد یا حذفش با مدیر دیگری است.', $this->lastSaid());

        // A guest's review (no customer behind it) is theirs to decide.
        $this->send($this->tap(Nav::to('rvv', 'show', $guest->id), chat: self::STAFF));
        foreach ([Nav::to('rvv', 'ok', $guest->id), Nav::to('rvv', 'no', $guest->id), Nav::to('rvv', 'del', $guest->id)] as $decision) {
            self::assertContains($decision, $this->buttons(), $decision);
        }
        self::assertStringNotContainsString('خود شماست', $this->lastSaid());

        $this->send($this->tap(Nav::to('rv'), chat: 7200));
        $this->send($this->tap(Nav::to('rvv', 'show', $own->id), chat: 7200));
        foreach ($decisions as $decision) {
            self::assertContains($decision, $this->buttons(), "{$decision}: another admin's");
        }
        self::assertStringNotContainsString('خود شماست', $this->lastSaid());
    }

    /**
     * The window's rows: its buttons to a review.
     *
     * @return list<string>
     */
    private function rows(): array
    {
        return array_values(array_filter($this->buttons(), static fn(string $data): bool => str_starts_with($data, 'a:rvv:show:')));
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
}
