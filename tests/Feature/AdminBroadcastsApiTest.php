<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Telegram\Broadcasts\BroadcastMode;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Broadcasts\BroadcastStatus;
use App\Modules\Telegram\Models\Broadcast;
use App\Modules\Telegram\Models\BroadcastPin;
use App\Modules\Telegram\Tasks\SendBroadcastsTask;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerGroups;
use Tests\HttpTestCase;

/**
 * «پیام همگانی» in the panel: every run the bot's admins sent, newest first, with its audience, numbers and what its
 * state allows — a page read in the same few queries however many runs it shows; pause, resume and cancel from here —
 * the bot's progress message follows — and «لغو پین» for a finished pinned one, worked through by the scheduler.
 */
final class AdminBroadcastsApiTest extends HttpTestCase
{
    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        $this->telegram();
        $this->boss = $this->admin(['username' => 'boss']);
        $this->customer(['telegram_id' => 1001, 'first_name' => 'Ali']);
        $this->customer(['telegram_id' => 1002, 'first_name' => 'Sara']);
    }

    public function testTheListShowsEveryRunWithItsNumbersAndWhatItAllows(): void
    {
        $group = $this->customerGroup('VIP', [User::query()->where('telegram_id', 1001)->sole()]);
        $done = $this->start(['pin' => true, 'audience' => 'group', 'audience_id' => $group->id, 'content' => 'photo', 'excerpt' => 'تخفیف پاییزی']);
        $this->service(BroadcastService::class)->process($done, 50);
        $sending = $this->start(['mode' => BroadcastMode::Forward]);

        $response = $this->get('/api/admin/broadcasts');

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->decode($response)['broadcasts'];
        self::assertSame([$sending->id, $done->id], array_column($rows, 'id'), 'the newest first');
        [$second, $first] = $rows;
        self::assertSame(['key' => 'group', 'id' => $group->id, 'label' => 'گروه «VIP»'], $first['audience']);
        self::assertSame(['done', 1, 1, 0, 0, true, 1], [$first['status'], $first['total'], $first['sent'], $first['blocked'], $first['failed'], $first['pin'], $first['pinned']]);
        self::assertSame(['photo', 'تخفیف پاییزی', 'boss'], [$first['content'], $first['excerpt'], $first['admin']['username']]);
        self::assertSame(['pause' => false, 'resume' => false, 'cancel' => false, 'unpin' => true], $first['actions']);
        self::assertSame(['forward', Audience::LABELS[Audience::ALL], 'sending'], [$second['mode'], $second['audience']['label'], $second['status']]);
        self::assertSame(['pause' => true, 'resume' => false, 'cancel' => true, 'unpin' => false], $second['actions']);

        $this->service(CustomerGroups::class)->delete($group);
        $rows = $this->decode($this->get('/api/admin/broadcasts'))['broadcasts'];
        self::assertSame(['key' => 'group', 'id' => $group->id, 'label' => 'گروهی که حذف شده'], $rows[1]['audience'], 'a run keeps saying whom it was for');
    }

    public function testAPageIsReadInTheSameQueriesHoweverManyRunsItShows(): void
    {
        $group = $this->customerGroup('VIP', [User::query()->where('telegram_id', 1001)->sole()]);
        $this->pinnedAndUnpinning(['audience' => Audience::GROUP, 'audience_id' => $group->id]);
        $few = $this->queriesFor('/api/admin/broadcasts');
        self::assertGreaterThan(0, $few, 'the page\'s queries are counted');

        foreach (range(1, 3) as $i) {
            $this->pinnedAndUnpinning(['audience' => Audience::GROUP, 'audience_id' => $group->id]);
            $this->start([]);
        }

        self::assertSame($few, $this->queriesFor('/api/admin/broadcasts'), 'no query a row: pins, unpin runs, sources, admins and names come with the page');
    }

    public function testPauseResumeAndCancelFromThePanelAndTheBotsMessageFollows(): void
    {
        $broadcast = $this->start([]);

        $paused = $this->postJson("/api/admin/broadcasts/{$broadcast->id}/pause");
        self::assertSame(200, $paused->getStatusCode());
        self::assertSame('paused', $this->decode($paused)['broadcast']['status']);
        self::assertSame(['editMessageText'], $this->telegram()->calls(), "the admin's progress message in the bot follows");
        self::assertSame(['77', (string) self::TELEGRAM_ID], [$this->telegram()->params(0)['message_id'], $this->telegram()->params(0)['chat_id']]);
        self::assertStringContainsString(BroadcastStatus::Paused->label(), $this->telegram()->params(0)['text']);

        $again = $this->postJson("/api/admin/broadcasts/{$broadcast->id}/pause");
        self::assertSame(422, $again->getStatusCode());
        self::assertArrayHasKey('status', $this->decode($again)['errors']);

        self::assertSame('sending', $this->decode($this->postJson("/api/admin/broadcasts/{$broadcast->id}/resume"))['broadcast']['status']);
        self::assertSame('cancelled', $this->decode($this->postJson("/api/admin/broadcasts/{$broadcast->id}/cancel"))['broadcast']['status']);
        self::assertSame(422, $this->postJson("/api/admin/broadcasts/{$broadcast->id}/resume")->getStatusCode());
        self::assertSame(404, $this->postJson('/api/admin/broadcasts/999/cancel')->getStatusCode());
    }

    public function testThePinsOfAFinishedRunComeOffFromThePanel(): void
    {
        $unpinned = $this->start([]);
        $this->service(BroadcastService::class)->process($unpinned, 50);
        self::assertSame(422, $this->postJson("/api/admin/broadcasts/{$unpinned->id}/unpin")->getStatusCode(), 'it pinned nothing');

        $pinned = $this->start(['pin' => true]);
        self::assertSame(422, $this->postJson("/api/admin/broadcasts/{$pinned->id}/unpin")->getStatusCode(), 'not over yet');
        $this->service(BroadcastService::class)->process($pinned, 50);
        self::assertSame(3, BroadcastPin::query()->where('broadcast_id', $pinned->id)->count());

        $response = $this->postJson("/api/admin/broadcasts/{$pinned->id}/unpin");
        self::assertSame(201, $response->getStatusCode());
        $run = $this->decode($response)['broadcast'];
        self::assertSame(['unpin', $pinned->id, 3, 'sending', self::ADMIN_USERNAME, null], [$run['kind'], $run['source_id'], $run['total'], $run['status'], $run['reviewer'], $run['admin']]);
        self::assertSame(['copy', 'all'], [$run['mode'], $run['audience']['key']], "its source's");
        $row = Broadcast::query()->findOrFail($run['id']);
        self::assertSame([null, null, null], [$row->message_id, $row->mode, $row->audience], 'none of its own');
        self::assertSame(422, $this->postJson("/api/admin/broadcasts/{$pinned->id}/unpin")->getStatusCode(), 'one is under way');

        $this->telegram()->reset();
        $this->service(SendBroadcastsTask::class)->run();
        self::assertSame(['unpinChatMessage', 'unpinChatMessage', 'unpinChatMessage'], $this->telegram()->calls(), 'no progress message for a run from the panel, nor a summary');
        self::assertSame(0, BroadcastPin::query()->count());
        self::assertSame(BroadcastStatus::Done, Broadcast::query()->findOrFail($run['id'])->status);
    }

    /**
     * A copy of message 5 from the boss's chat, its progress in message 77 there.
     *
     * @param array<string, mixed> $draft
     */
    private function start(array $draft): Broadcast
    {
        /** @var array{message_id: int, mode: BroadcastMode, audience: string, audience_id?: int, pin: bool} $draft */
        $draft += ['message_id' => 5, 'mode' => BroadcastMode::Copy, 'audience' => Audience::ALL, 'pin' => false];

        return $this->service(BroadcastService::class)->start($this->boss, $draft, 77);
    }

    /**
     * A pinned run sent to everyone it reaches, its pins being taken off from the panel.
     *
     * @param array<string, mixed> $draft
     */
    private function pinnedAndUnpinning(array $draft): void
    {
        $broadcasts = $this->service(BroadcastService::class);
        $run = $this->start(['pin' => true] + $draft);
        $broadcasts->process($run, 50);
        $broadcasts->startUnpin($run, null, self::ADMIN_USERNAME);
    }

    /** How many queries the database answered for one GET of the panel's. */
    private function queriesFor(string $path): int
    {
        $this->db()->flushQueryLog();
        $this->db()->enableQueryLog();
        try {
            self::assertSame(200, $this->get($path)->getStatusCode());
        } finally {
            $this->db()->disableQueryLog();
        }

        return count($this->db()->getQueryLog());
    }
}
