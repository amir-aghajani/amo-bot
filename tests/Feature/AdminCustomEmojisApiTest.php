<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Support\FileCache;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Emoji\CustomEmojis;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * The editors' premium emoji: the ones an admin showed the bot (/emoji), whether the bot may send them, how Telegram
 * draws each one, its picture and its animation — fetched from Telegram as the panel asks, for kept ones and any other
 * a text has, not found while Telegram will not hand them over; a picture or a video served as itself, anything else
 * Telegram hands over only as a download — and forgetting one, which leaves the texts that use it as they are.
 */
final class AdminCustomEmojisApiTest extends HttpTestCase
{
    /** A one-pixel PNG: a picture Telegram might hand over. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** The start of a .tgs (gzip) and of a WebM (EBML): what an animated and a video sticker are told by. */
    private const TGS = "\x1f\x8b\x08\x00lottie";
    private const WEBM = "\x1a\x45\xdf\xa3webm";

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        $this->telegram()->on('getCustomEmojiStickers', static fn(array $params): array => array_map(
            static fn(string $id): array => ['file_id' => "sticker-{$id}", 'type' => 'custom_emoji', 'custom_emoji_id' => $id, 'emoji' => '🔥', 'is_animated' => true, 'is_video' => false, 'thumbnail' => ['file_id' => "thumb-{$id}"]],
            json_decode($params['custom_emoji_ids'], true),
        ));
    }

    public function testTheKeptOnesAndWhetherTheBotMaySendThem(): void
    {
        $emojis = $this->service(CustomEmojis::class);
        $emojis->remember([['id' => '111', 'emoji' => '🔥']]);
        $emojis->remember([['id' => '222', 'emoji' => '🎁']]);

        $listed = $this->decode($this->get('/api/admin/bot/custom-emojis'));
        self::assertSame(['222', '111'], array_column($listed['emojis'], 'id'), 'the latest seen first');
        self::assertSame('🔥', $listed['emojis'][1]['emoji']);
        self::assertSame('animated', $listed['emojis'][1]['format'], 'how Telegram draws it, from its sticker');
        self::assertFalse($listed['emojis'][1]['repaint']);
        self::assertNull($listed['status'], 'no /emoji yet: nobody knows');

        $this->service(PremiumEmojiStatus::class)->record(false);
        self::assertFalse($this->decode($this->get('/api/admin/bot/custom-emojis'))['status']['ok']);
    }

    public function testAPictureIsFetchedFromTelegramAsThePanelAsks(): void
    {
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥']]);
        $this->telegram()->reset();
        $this->picturesAt('thumbnails/111.png');

        $response = $this->get('/api/admin/bot/custom-emojis/111/image');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame(base64_decode(self::PNG, true), (string) $response->getBody());
        self::assertStringContainsString('max-age=86400', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['getFile', '111.png'], $this->telegram()->calls(), 'the kept picture: no need to ask for the sticker');
        self::assertSame('thumb-111', $this->telegram()->params(0)['file_id']);
    }

    public function testAPictureAndAnAnimationFetchedOnceAreKeptAWhileForThePickersReadsThatFollow(): void
    {
        $emojis = new CustomEmojis($this->service(BotApi::class), $this->service(LoggerInterface::class), new FileCache($this->scratchDir()));
        $emojis->remember([['id' => '111', 'emoji' => '🔥']]);
        $this->telegram()->reset();
        $this->filesAt(['thumb-111' => ['thumbnails/111.png', (string) base64_decode(self::PNG, true)], 'sticker-111' => ['animations/111.tgs', self::TGS]]);

        foreach ([1, 2] as $ignored) {
            self::assertSame(base64_decode(self::PNG, true), $emojis->picture('111')['body'] ?? null);
            self::assertSame(self::TGS, $emojis->animation('111')['body'] ?? null);
        }

        self::assertSame(['getFile', '111.png', 'getFile', '111.tgs'], $this->telegram()->calls(), 'each fetched once');
    }

    public function testOneNotKeptIsLookedUpAndOneWithoutAPictureIsNotFound(): void
    {
        $this->picturesAt('thumbnails/333.png');
        self::assertSame(200, $this->get('/api/admin/bot/custom-emojis/333/image')->getStatusCode(), 'a text may carry one nobody showed the bot');
        self::assertSame(['getCustomEmojiStickers', 'getFile', '333.png'], $this->telegram()->calls());

        $this->telegram()->on('getCustomEmojiStickers', static fn(): array => []);
        self::assertSame(404, $this->get('/api/admin/bot/custom-emojis/444/image')->getStatusCode());
    }

    public function testAnAnimatedOneIsHandedOverAsItsLottieAndAVideoOneAsItsWebm(): void
    {
        $this->stickers(['222' => ['is_video' => true]]);
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥'], ['id' => '222', 'emoji' => '🎁']]);
        $this->telegram()->reset();
        $this->filesAt(['sticker-111' => ['animations/111.tgs', self::TGS], 'sticker-222' => ['animations/222.webm', self::WEBM]]);

        $lottie = $this->get('/api/admin/bot/custom-emojis/111/animation');
        self::assertSame(200, $lottie->getStatusCode());
        self::assertSame(['application/octet-stream', 'attachment'], [$lottie->getHeaderLine('Content-Type'), $lottie->getHeaderLine('Content-Disposition')], 'the .tgs as Telegram has it, never rendered by the browser — the panel unzips it');
        self::assertSame(self::TGS, (string) $lottie->getBody());
        self::assertStringContainsString('max-age=86400', $lottie->getHeaderLine('Cache-Control'));
        self::assertSame(['getFile', '111.tgs'], $this->telegram()->calls(), 'the kept sticker: no need to ask for it again');

        $video = $this->get('/api/admin/bot/custom-emojis/222/animation');
        self::assertSame(200, $video->getStatusCode());
        self::assertSame(['video/webm', ''], [$video->getHeaderLine('Content-Type'), $video->getHeaderLine('Content-Disposition')], 'a video plays in the page');
        self::assertSame(self::WEBM, (string) $video->getBody());
        self::assertSame('video', $this->decode($this->get('/api/admin/bot/custom-emojis'))['emojis'][0]['format']);
    }

    public function testAPictureTelegramHandsOverInAnotherShapeIsOnlyADownload(): void
    {
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥']]);
        $this->telegram()->on('getFile', static fn(array $params): array => ['file_id' => $params['file_id'], 'file_path' => 'thumbnails/111.svg']);
        $this->telegram()->on('111.svg', static fn(): Response => new Response(200, [], '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));

        $response = $this->get('/api/admin/bot/custom-emojis/111/image');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['application/octet-stream', 'attachment'], [$response->getHeaderLine('Content-Type'), $response->getHeaderLine('Content-Disposition')], 'never run as a page of the panel');
    }

    public function testAStillOneHasNoAnimationAndOneNotKeptIsLookedUp(): void
    {
        $this->stickers(['111' => ['is_animated' => false]]);
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥']]);
        $this->telegram()->reset();

        self::assertSame(404, $this->get('/api/admin/bot/custom-emojis/111/animation')->getStatusCode());
        self::assertSame([], $this->telegram()->calls(), 'a kept still one: Telegram is not asked');

        $this->filesAt(['sticker-333' => ['animations/333.tgs', self::TGS]]);
        self::assertSame(200, $this->get('/api/admin/bot/custom-emojis/333/animation')->getStatusCode(), 'a text may carry one nobody showed the bot');
        self::assertSame(['getCustomEmojiStickers', 'getFile', '333.tgs'], $this->telegram()->calls());
    }

    public function testOneTelegramPaintsInTheTextsColourSaysSo(): void
    {
        $this->stickers(['111' => ['needs_repainting' => true]]);
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥'], ['id' => '222', 'emoji' => '🎁']]);

        $emojis = $this->decode($this->get('/api/admin/bot/custom-emojis'))['emojis'];

        self::assertSame(['222' => false, '111' => true], array_column($emojis, 'repaint', 'id'));
    }

    public function testOneKeptWhileTelegramCouldNotBeAskedLearnsHowItIsDrawnWithItsPicture(): void
    {
        $this->telegram()->on('getCustomEmojiStickers', static fn(): Response => FakeTelegram::error(502, 'Bad Gateway'));
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥']]);
        self::assertNull($this->decode($this->get('/api/admin/bot/custom-emojis'))['emojis'][0]['format'], 'not known yet');

        $this->stickers();
        $this->picturesAt('thumbnails/111.png');
        self::assertSame(200, $this->get('/api/admin/bot/custom-emojis/111/image')->getStatusCode());

        self::assertSame('animated', $this->decode($this->get('/api/admin/bot/custom-emojis'))['emojis'][0]['format'], 'learned when its picture was asked for');
    }

    public function testForgettingOneTakesItOffThePickerAndLeavesTheTextsThatUseIt(): void
    {
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥']]);
        $wording = '<tg-emoji emoji-id="111">🔥</tg-emoji> پشتیبانی هنوز آماده نیست.';
        $this->service(BotTexts::class)->save(BotText::SupportUnavailable, $wording);

        self::assertSame(204, $this->deleteJson('/api/admin/bot/custom-emojis/111')->getStatusCode());
        self::assertSame([], $this->decode($this->get('/api/admin/bot/custom-emojis'))['emojis']);
        self::assertSame(404, $this->deleteJson('/api/admin/bot/custom-emojis/111')->getStatusCode());

        self::assertSame($wording, $this->service(BotTexts::class)->get(BotText::SupportUnavailable), 'a text that uses it keeps it');
        $this->picturesAt('thumbnails/111.png');
        self::assertSame(200, $this->get('/api/admin/bot/custom-emojis/111/image')->getStatusCode(), 'and its preview still shows it');
    }

    public function testAPictureOrAnAnimationTelegramWillNotHandOverNowIsNotFound(): void
    {
        $this->service(CustomEmojis::class)->remember([['id' => '111', 'emoji' => '🔥']]);
        $logs = $this->logs();
        $this->telegram()->on('getFile', static fn(): mixed => FakeTelegram::error(400, 'Bad Request: wrong file_id or the file is temporarily unavailable'));

        self::assertSame(404, $this->get('/api/admin/bot/custom-emojis/111/image')->getStatusCode());
        self::assertSame(404, $this->get('/api/admin/bot/custom-emojis/111/animation')->getStatusCode());
        self::assertTrue($logs->hasInfoThatContains('has no picture now'));
        self::assertTrue($logs->hasInfoThatContains('has no animation now'));
    }

    /** Telegram's getFile answers with this path, and its download with the PNG. */
    private function picturesAt(string $path): void
    {
        $this->telegram()->on('getFile', static fn(array $params): array => ['file_id' => $params['file_id'], 'file_path' => $path]);
        $this->telegram()->on(basename($path), static fn(): Response => new Response(200, [], (string) base64_decode(self::PNG, true)));
    }

    /**
     * Telegram's getFile answers each file id with its path, and each download with its bytes.
     *
     * @param array<string, array{string, string}> $files file id => [path, bytes]
     */
    private function filesAt(array $files): void
    {
        $this->telegram()->on('getFile', static fn(array $params): array => ['file_id' => $params['file_id'], 'file_path' => $files[$params['file_id']][0] ?? '']);
        foreach ($files as [$path, $bytes]) {
            $this->telegram()->on(basename($path), static fn(): Response => new Response(200, [], $bytes));
        }
    }

    /**
     * Telegram's stickers as setUp has them — animated, with a thumbnail — but for what these emoji ids say otherwise.
     *
     * @param array<int|string, array<string, mixed>> $overrides emoji id => sticker fields
     */
    private function stickers(array $overrides = []): void
    {
        $this->telegram()->on('getCustomEmojiStickers', static fn(array $params): array => array_map(
            static fn(string $id): array => ($overrides[$id] ?? []) + ['file_id' => "sticker-{$id}", 'type' => 'custom_emoji', 'custom_emoji_id' => $id, 'emoji' => '🔥', 'is_animated' => !isset($overrides[$id]['is_video']), 'is_video' => false, 'needs_repainting' => false, 'thumbnail' => ['file_id' => "thumb-{$id}"]],
            json_decode($params['custom_emoji_ids'], true),
        ));
    }
}
