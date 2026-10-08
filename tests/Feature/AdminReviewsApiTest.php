<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The reviews screen (both panels, and the shop's website for its admins): every review of the shop, the newest first —
 * by status, searched by the name it is signed with, its words, the customer who wrote it or its number —, the pending
 * ones counted (the queue: the sidebar's and the dashboard's too); and what support decides — approve (the website shows
 * it), reject (it does not), either way again, delete —, each once, a refused state in the words of the state it found,
 * under the deciding principal (the owner's login «پشتیبانی» to anyone but the owner). Each shop its own.
 */
final class AdminReviewsApiTest extends HttpTestCase
{
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->loginAsAdmin();
        $this->ali = $this->customer(['username' => 'ali', 'first_name' => 'Ali']);
    }

    public function testTheListIsEveryReviewTheNewestFirstWithThePendingCounted(): void
    {
        $rejected = $this->review(null, 'Spam', 1, 'یک متن تبلیغاتی بی‌ربط.', ['status' => ReviewStatus::Rejected, 'reviewer' => 'root', 'decided_at' => now()]);
        $approved = $this->review($this->ali, 'Ali R.', 5, 'سرعت عالی و پشتیبانی خوب.', ['status' => ReviewStatus::Approved, 'context' => 'ایرانسل · اندروید · Happ']);
        $pending = $this->review(null, 'Sara', 3, 'گاهی کند می‌شود ولی بد نیست.');
        $list = fn(string $query = ''): array => $this->decode($this->get('/api/admin/reviews' . ($query === '' ? '' : "?{$query}")));
        $ids = static fn(array $page): array => array_column($page['reviews'], 'id');

        $all = $list();
        self::assertSame([$pending->id, $approved->id, $rejected->id], $ids($all), 'the newest first');
        self::assertSame([3, 1], [$all['meta']['total'], $all['meta']['pending']]);
        self::assertSame([
            'id' => $approved->id,
            'name' => 'Ali R.',
            'rating' => 5,
            'body' => 'سرعت عالی و پشتیبانی خوب.',
            'context' => 'ایرانسل · اندروید · Happ',
            'status' => 'approved',
            'customer' => ['id' => $this->ali->id, 'name' => 'Ali', 'username' => 'ali', 'telegram_id' => self::TELEGRAM_ID, 'email' => null],
            'reviewer' => null,
            'decided_at' => null,
            'created_at' => '2026-10-08T12:00:00+00:00',
            'actions' => ['approve' => false, 'reject' => true],
        ], $all['reviews'][1], 'the customer who wrote it, as every screen points at them');
        self::assertSame([null, ['approve' => true, 'reject' => true]], [$all['reviews'][0]['customer'], $all['reviews'][0]['actions']], "a guest's, waiting");
        self::assertSame(['root', '2026-10-08T12:00:00+00:00', ['approve' => true, 'reject' => false]], [$all['reviews'][2]['reviewer'], $all['reviews'][2]['decided_at'], $all['reviews'][2]['actions']]);

        self::assertSame([$pending->id], $ids($list('status=pending')));
        self::assertSame([$approved->id], $ids($list('status=approved')));
        self::assertSame(1, $list('status=rejected')['meta']['pending'], 'the queue whatever the tab');

        self::assertSame([$approved->id], $ids($list('search=ali')), 'by the customer who wrote it');
        self::assertSame([$pending->id], $ids($list('search=Sara')), 'by the name it is signed with');
        self::assertSame([$rejected->id], $ids($list('search=' . rawurlencode('تبلیغاتی'))), 'by its words');
        self::assertSame([$pending->id], $ids($list('search=' . rawurlencode("#{$pending->id}"))), 'the review alone');
    }

    public function testSupportApprovesRejectsAndDeletesEachOnceInTheWordsOfTheStateItFound(): void
    {
        $review = $this->review(null, 'Sara', 4, 'اتصال پایدار است و قیمت مناسب.');
        $website = $this->website(['reviews_enabled' => true]);
        $shown = fn(): array => array_column($this->decode($this->get($this->storeApi($website, '/reviews')))['reviews'], 'id');
        $logs = $this->logs();

        $approved = $this->postJson("/api/admin/reviews/{$review->id}/approve");
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame(['approved', 'root', '2026-10-08T12:00:00+00:00', ['approve' => false, 'reject' => true]], $this->fields($this->decode($approved)['review']));
        self::assertSame([$review->id], $shown(), 'the website shows it');
        self::assertTrue($logs->hasInfoThatContains("Review {$review->id} approved by root"));

        $again = $this->postJson("/api/admin/reviews/{$review->id}/approve");
        self::assertSame([422, ['status' => [Reviews::APPROVED]]], [$again->getStatusCode(), $this->decode($again)['errors']]);

        Carbon::setTestNow('2026-10-08 13:00:00');
        $rejected = $this->postJson("/api/admin/reviews/{$review->id}/reject");
        self::assertSame(['rejected', 'root', '2026-10-08T13:00:00+00:00', ['approve' => true, 'reject' => false]], $this->fields($this->decode($rejected)['review']), 'hidden again');
        self::assertSame([], $shown());
        $twice = $this->postJson("/api/admin/reviews/{$review->id}/reject");
        self::assertSame([422, ['status' => [Reviews::REJECTED]]], [$twice->getStatusCode(), $this->decode($twice)['errors']]);

        self::assertSame('approved', $this->decode($this->postJson("/api/admin/reviews/{$review->id}/approve"))['review']['status'], 'a rejected one approved after all');

        self::assertSame(204, $this->deleteJson("/api/admin/reviews/{$review->id}")->getStatusCode());
        self::assertNull(Review::query()->find($review->id));
        self::assertSame([], $shown(), 'shown no more');
        self::assertTrue($logs->hasInfoThatContains("Review {$review->id} deleted by root"));
        self::assertSame(404, $this->deleteJson("/api/admin/reviews/{$review->id}")->getStatusCode());
        self::assertSame(404, $this->postJson("/api/admin/reviews/{$review->id}/approve")->getStatusCode());
    }

    public function testADecisionTheSameMomentLostIsRefusedInTheWordsOfTheOneThatStood(): void
    {
        $review = $this->review(null, 'Sara', 4, 'اتصال پایدار است و قیمت مناسب.');
        // Read by this request, then approved elsewhere before its own write.
        $refused = $this->whileListening('eloquent.retrieved: ' . Review::class, static function (Review $read): void {
            Review::query()->whereKey($read->id)->update(['status' => ReviewStatus::Approved->value, 'reviewer' => 'tg:7001']);
        }, fn() => $this->postJson("/api/admin/reviews/{$review->id}/approve"));

        self::assertSame([422, ['status' => [Reviews::APPROVED]]], [$refused->getStatusCode(), $this->decode($refused)['errors']]);
        self::assertSame('tg:7001', $review->refresh()->reviewer, 'the decision that stood');
    }

    public function testAnAgentsShopIsItsOwnAndTheOwnersLoginReadsSupportThere(): void
    {
        $mains = $this->review(null, 'اصلی', 5, 'نظر مشتری ربات اصلی است.');
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Review => $this->review(null, 'نماینده', 4, 'نظر مشتری ربات نماینده است.'));

        $this->openShop($bot);
        self::assertSame([$theirs->id], array_column($this->decode($this->get('/api/admin/reviews'))['reviews'], 'id'), "the owner in the agent's shop");
        self::assertSame(404, $this->postJson("/api/admin/reviews/{$mains->id}/approve")->getStatusCode(), "another shop's is not there");
        self::assertSame('root', $this->decode($this->postJson("/api/admin/reviews/{$theirs->id}/approve"))['review']['reviewer'], 'the owner reads their own login');

        $_SESSION = [];
        $this->loginAsAgent($bot);
        $agentList = $this->decode($this->get('/api/agent/reviews'));
        self::assertSame([[$theirs->id, 'پشتیبانی']], array_map(static fn(array $row): array => [$row['id'], $row['reviewer']], $agentList['reviews']), "the agent reads the owner's login as support");
        self::assertSame(404, $this->postJson("/api/agent/reviews/{$mains->id}/reject")->getStatusCode());
        self::assertSame(404, $this->deleteJson("/api/agent/reviews/{$mains->id}")->getStatusCode());
        self::assertSame('@agent_shop_bot', $this->decode($this->postJson("/api/agent/reviews/{$theirs->id}/reject"))['review']['reviewer']);
        self::assertSame(ReviewStatus::Pending, $mains->refresh()->status, "the main shop's untouched");
    }

    public function testTheShopsAdminsModerateFromItsWebsite(): void
    {
        $website = $this->website();
        $sara = $this->customer(['telegram_id' => 7001, 'username' => 'sara']);
        $review = $this->review($this->ali, 'Ali R.', 5, 'سرعت عالی و پشتیبانی خوب.');
        $this->loginAsStaff($website, $sara);

        $list = $this->decode($this->get($this->storeApi($website, '/admin/reviews')));
        self::assertSame([[$review->id, 'pending']], array_map(static fn(array $row): array => [$row['id'], $row['status']], $list['reviews']));
        self::assertSame(1, $list['meta']['pending']);

        $approved = $this->decode($this->postJson($this->storeApi($website, "/admin/reviews/{$review->id}/approve")))['review'];
        self::assertSame(['approved', '@sara'], [$approved['status'], $approved['reviewer']], 'under their own name');
        $again = $this->postJson($this->storeApi($website, "/admin/reviews/{$review->id}/approve"));
        self::assertSame([422, ['status' => [Reviews::APPROVED]]], [$again->getStatusCode(), $this->decode($again)['errors']]);
        self::assertSame(204, $this->deleteJson($this->storeApi($website, "/admin/reviews/{$review->id}"))->getStatusCode());
    }

    public function testThePendingOnesAreAQueueOfTheSidebarsAndTheDashboards(): void
    {
        $this->review(null, 'Sara', 4, 'اتصال پایدار است و قیمت مناسب.');
        $this->review(null, 'Nima', 5, 'همه چیز خوب است، ممنون از شما.', ['status' => ReviewStatus::Approved]);

        self::assertSame(1, $this->decode($this->get('/api/admin/queues'))['queues']['pending_reviews']);
        self::assertSame(1, $this->decode($this->get('/api/admin/dashboard'))['attention']['pending_reviews']);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<mixed> Where a review row stands, who decided it, when, and what it allows
     */
    private function fields(array $row): array
    {
        return [$row['status'], $row['reviewer'], $row['decided_at'], $row['actions']];
    }
}
