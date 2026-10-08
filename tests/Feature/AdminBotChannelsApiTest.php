<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Models\BotChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * The required-channels list of the bot settings screen: adding by link (the bot must be an admin
 * there), private channels by id with the invite link the bot makes, re-checking, ordering and removing —
 * and Telegram out of reach for a moment said so, with nothing changed.
 */
final class AdminBotChannelsApiTest extends HttpTestCase
{
    /** A private channel as Telegram describes it to an admin bot that has not made its invite link yet. */
    private const PRIVATE_CHAT = ['id' => -1001000000010, 'type' => 'channel', 'title' => 'Private'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->loginAsAdmin();
    }

    public function testAPublicChannelIsAddedByItsLinkOnceTheBotIsAnAdminThere(): void
    {
        $this->telegram()->reply(['id' => -1001000000001, 'type' => 'channel', 'title' => 'اخبار فروشگاه', 'username' => 'shop_news']);
        $this->telegram()->reply(['status' => 'administrator', 'user' => ['id' => FakeTelegram::BOT_ID]]);

        $response = $this->postJson('/api/admin/bot/channels', ['link' => 'https://t.me/shop_news']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $channel = $this->decode($response)['channel'];
        self::assertSame('اخبار فروشگاه', $channel['title']);
        self::assertSame('shop_news', $channel['username']);
        self::assertSame('https://t.me/shop_news', $channel['link']);
        self::assertTrue($channel['bot_is_admin']);
        self::assertNotNull($channel['checked_at']);

        self::assertSame(['getChat', 'getChatMember'], $this->telegram()->calls());
        self::assertSame('@shop_news', $this->telegram()->params(0)['chat_id'], 'looked up by handle');
        self::assertSame((string) FakeTelegram::BOT_ID, $this->telegram()->params(1)['user_id'], "the bot's own membership, from its token");

        self::assertSame(['اخبار فروشگاه'], array_column($this->decode($this->get('/api/admin/bot/channels'))['channels'], 'title'));
    }

    public function testAChannelWhereTheBotIsNotAnAdminIsRefused(): void
    {
        $this->telegram()->reply(['id' => -1001000000002, 'type' => 'channel', 'title' => 'Other', 'username' => 'other']);
        $this->telegram()->reply(['status' => 'member']);

        $response = $this->postJson('/api/admin/bot/channels', ['link' => '@other']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('ادمین نیست', $this->decode($response)['errors']['link'][0]);
        self::assertSame(0, BotChannel::query()->count());
    }

    public function testAPrivateChannelIsAddedByIdAndTheBotExportsItsInviteLink(): void
    {
        $this->telegram()->reply(['id' => -1001000000003, 'type' => 'supergroup', 'title' => 'گروه VIP']);
        $this->telegram()->reply(['status' => 'creator']);
        $this->telegram()->reply('https://t.me/+AbCdEf');

        $response = $this->postJson('/api/admin/bot/channels', ['link' => '-1001000000003']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $channel = $this->decode($response)['channel'];
        self::assertSame('supergroup', $channel['type']);
        self::assertNull($channel['username']);
        self::assertSame('https://t.me/+AbCdEf', $channel['link']);
        self::assertSame(['getChat', 'getChatMember', 'exportChatInviteLink'], $this->telegram()->calls());
        self::assertSame('-1001000000003', $this->telegram()->params(0)['chat_id']);
    }

    public function testAPrivateChannelWhoseInviteLinkTheBotMayNotMakeIsRefused(): void
    {
        $this->telegram()->reply(self::PRIVATE_CHAT, ['status' => 'administrator']);
        $this->telegram()->fail(400, 'Bad Request: not enough rights to manage chat invite links');

        $response = $this->postJson('/api/admin/bot/channels', ['link' => (string) self::PRIVATE_CHAT['id']]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('لینک عضویت', $this->decode($response)['errors']['link'][0], 'the customer would have no way in');
        self::assertSame(0, BotChannel::query()->count());
    }

    public function testALinkToAnythingButAChannelOrAGroupIsRefused(): void
    {
        $this->telegram()->reply(['id' => 5151, 'type' => 'private', 'first_name' => 'Ali', 'username' => 'ali_ahmadi']);

        $response = $this->postJson('/api/admin/bot/channels', ['link' => '@ali_ahmadi']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('کانال یا گروه اشاره نمی‌کند', $this->decode($response)['errors']['link'][0]);
        self::assertSame(['getChat'], $this->telegram()->calls());
    }

    public function testWhatCannotBeLookedUpIsRefusedBeforeAskingTelegram(): void
    {
        $invite = $this->postJson('/api/admin/bot/channels', ['link' => 'https://t.me/+AbCdEf']);
        self::assertSame(422, $invite->getStatusCode());
        self::assertStringContainsString('شناسه عددی', $this->decode($invite)['errors']['link'][0], 'a private invite link needs the id instead');

        $garbage = $this->postJson('/api/admin/bot/channels', ['link' => 'https://example.com/x']);
        self::assertSame(422, $garbage->getStatusCode());
        self::assertStringContainsString('معتبر نیست', $this->decode($garbage)['errors']['link'][0]);

        self::assertSame([], $this->telegram()->calls());
    }

    public function testAnUnknownChatAndADuplicateAreRefused(): void
    {
        $this->telegram()->fail(400, 'Bad Request: chat not found');
        $missing = $this->postJson('/api/admin/bot/channels', ['link' => '@nope']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('پیدا نشد', $this->decode($missing)['errors']['link'][0]);

        $this->channel(-1001000000004, 'Dup', ['username' => 'dup_channel']);
        $this->telegram()->reply(['id' => -1001000000004, 'type' => 'channel', 'title' => 'Dup', 'username' => 'dup_channel']);
        $duplicate = $this->postJson('/api/admin/bot/channels', ['link' => 'dup_channel']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertStringContainsString('قبلا اضافه شده', $this->decode($duplicate)['errors']['link'][0]);
    }

    public function testTheSameChannelAddedTwiceAtOnceIsKeptOnceAndTheSecondHearsSo(): void
    {
        $this->telegram()->reply(['id' => -1001000000008, 'type' => 'channel', 'title' => 'Twice', 'username' => 'twice']);
        // The other tab's request adds it while this one asks Telegram about the bot's rights.
        $this->telegram()->on('getChatMember', function (): array {
            $this->channel(-1001000000008, 'Twice', ['username' => 'twice']);

            return ['status' => 'administrator'];
        });

        $response = $this->postJson('/api/admin/bot/channels', ['link' => '@twice']);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('قبلا اضافه شده', $this->decode($response)['errors']['link'][0]);
        self::assertSame(1, BotChannel::query()->count());
    }

    public function testACheckRefreshesTheRowAndNoticesLostAdminRights(): void
    {
        $channel = $this->channel(-1001000000005, 'Old title', ['username' => 'ch5']);
        $this->telegram()->reply(['id' => -1001000000005, 'type' => 'channel', 'title' => 'New title', 'username' => 'ch5_new']);
        $this->telegram()->reply(['status' => 'left']);

        $response = $this->postJson("/api/admin/bot/channels/{$channel->id}/check");

        self::assertSame(200, $response->getStatusCode());
        $row = $this->decode($response)['channel'];
        self::assertSame('New title', $row['title']);
        self::assertSame('ch5_new', $row['username']);
        self::assertFalse($row['bot_is_admin']);
        self::assertSame('https://t.me/ch5_new', $row['link'], "a public one's link is its handle's: they never disagree");
    }

    public function testAPrivateChannelsWayInIsItsInviteLinkUntilItHasAHandle(): void
    {
        $channel = $this->channel(-1001000000006, 'Private', ['username' => null, 'invite_link' => 'https://t.me/+old']);
        $this->telegram()->reply(['id' => -1001000000006, 'type' => 'channel', 'title' => 'Private', 'invite_link' => 'https://t.me/+new']);
        $this->telegram()->reply(['status' => 'administrator']);
        self::assertSame('https://t.me/+new', $this->decode($this->postJson("/api/admin/bot/channels/{$channel->id}/check"))['channel']['link']);

        $this->telegram()->reply(['id' => -1001000000006, 'type' => 'channel', 'title' => 'Public now', 'username' => 'pub6']);
        $this->telegram()->reply(['status' => 'administrator']);
        self::assertSame('https://t.me/pub6', $this->decode($this->postJson("/api/admin/bot/channels/{$channel->id}/check"))['channel']['link']);
        self::assertNull($channel->refresh()->invite_link, 'its handle is the way in now');
    }

    public function testAPrivateChannelKeepsItsLastInviteLinkWhileTheBotCannotMakeOne(): void
    {
        $channel = $this->channel(self::PRIVATE_CHAT['id'], 'Private', ['username' => null, 'invite_link' => 'https://t.me/+kept']);
        $check = fn(): array => $this->decode($this->postJson("/api/admin/bot/channels/{$channel->id}/check"))['channel'];

        // No longer an admin there: no link to make.
        $this->telegram()->reply(self::PRIVATE_CHAT, ['status' => 'member']);
        $row = $check();
        self::assertSame(['https://t.me/+kept', false], [$row['link'], $row['bot_is_admin']]);

        // An admin without the right to make one.
        $this->telegram()->reply(self::PRIVATE_CHAT, ['status' => 'administrator']);
        $this->telegram()->fail(400, 'Bad Request: not enough rights to manage chat invite links');
        $row = $check();
        self::assertSame(['https://t.me/+kept', true], [$row['link'], $row['bot_is_admin']]);
    }

    public function testAChannelTelegramNoLongerShowsTheBotIsFlagged(): void
    {
        $channel = $this->channel(-1001000000011, 'Gone', ['username' => 'gone_channel']);
        $this->telegram()->fail(400, 'Bad Request: chat not found');

        $response = $this->postJson("/api/admin/bot/channels/{$channel->id}/check");

        self::assertSame(200, $response->getStatusCode());
        $row = $this->decode($response)['channel'];
        self::assertSame([false, 'Gone', 'https://t.me/gone_channel'], [$row['bot_is_admin'], $row['title'], $row['link']], 'flagged, the rest as it was known');
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> What Telegram answered before it went out of reach */
    public static function outOfReach(): iterable
    {
        yield 'looking the chat up' => [[]];
        yield "asking the bot's place there" => [[self::PRIVATE_CHAT]];
        yield 'making its invite link' => [[self::PRIVATE_CHAT, ['status' => 'administrator']]];
    }

    /** @param list<array<string, mixed>> $answered */
    #[DataProvider('outOfReach')]
    public function testTelegramOutOfReachWhileAddingIsSaidSoAndAddsNothing(array $answered): void
    {
        $this->telegram()->reply(...$answered);
        $this->telegram()->fail(502, 'Bad Gateway');

        $response = $this->postJson('/api/admin/bot/channels', ['link' => (string) self::PRIVATE_CHAT['id']]);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(TelegramUnreachableException::MESSAGE, $this->decode($response)['message']);
        self::assertSame(0, BotChannel::query()->count());
    }

    /** @param list<array<string, mixed>> $answered */
    #[DataProvider('outOfReach')]
    public function testTelegramOutOfReachWhileCheckingIsSaidSoAndChangesNothing(array $answered): void
    {
        $channel = $this->channel(self::PRIVATE_CHAT['id'], 'Private', ['username' => null, 'invite_link' => 'https://t.me/+kept', 'checked_at' => now()->subDay()]);
        $before = $channel->refresh()->toArray();
        $this->telegram()->reply(...$answered);
        $this->telegram()->fail(502, 'Bad Gateway');

        $response = $this->postJson("/api/admin/bot/channels/{$channel->id}/check");

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(TelegramUnreachableException::MESSAGE, $this->decode($response)['message']);
        self::assertSame($before, $channel->refresh()->toArray(), 'not flagged, not even marked checked, for a moment of Telegram');
    }

    public function testChannelsAreOrderedAndRemoved(): void
    {
        $a = $this->channel(-1, 'A');
        $b = $this->channel(-2, 'B');

        $reordered = $this->postJson('/api/admin/bot/channels/reorder', ['ids' => [$b->id, $a->id]]);
        self::assertSame(200, $reordered->getStatusCode());
        self::assertSame(['B', 'A'], array_column($this->decode($reordered)['channels'], 'title'));

        self::assertSame(204, $this->deleteJson("/api/admin/bot/channels/{$a->id}")->getStatusCode());
        self::assertSame(['B'], array_column($this->decode($this->get('/api/admin/bot/channels'))['channels'], 'title'));
        self::assertSame(404, $this->deleteJson("/api/admin/bot/channels/{$a->id}")->getStatusCode());
    }
}
