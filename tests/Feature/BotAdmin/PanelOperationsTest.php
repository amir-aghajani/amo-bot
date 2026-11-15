<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Admin\Services\ScheduleScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Models\User;
use GuzzleHttp\Psr7\Response;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * The panel's own running in the bot (Telegram\Admin\Sections\Panel): the scheduled tasks' timings saved whole (PUT
 * /schedule) a field at a time or every default at once on a yes; a backup made and sent here as a file, its sending to
 * the report group switched, and the installation restored from a file sent back — described first, then on a yes —,
 * the state before kept to send; and how the bots get their messages.
 */
final class PanelOperationsTest extends BotTestCase
{
    private const OWNER = 7001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
    }

    public function testATasksTimingIsTypedOrPutBackAndEveryDefaultOnAYes(): void
    {
        $this->tapAs(Nav::to('ppj'));
        self::assertStringContainsString('زمان‌بندی کارها', $this->said()[0]);
        self::assertStringContainsString('هنوز اجرا نشده', $this->said()[0], 'no Cron yet: said');
        self::assertContains(Nav::to('ppjt', 'show', 0), $this->callbacks(0));
        self::assertNotContains(Nav::to('ppj', 'reset'), $this->callbacks(0), 'every timing at its default');

        $this->tapAs(Nav::to('ppjt', 'show', 0));
        $field = $this->field(0, 0);
        self::assertStringContainsString((string) $field['label'], $this->said()[0]);
        $this->tapAs(Nav::to('ppjt', 'e', 0, 0));
        $this->send($this->message('۷۷', chat: self::OWNER));
        self::assertSame(77, $this->field(0, 0)['value'], 'the schedule saved whole with the one changed');

        $this->tapAs(Nav::to('ppjt', 'e', 0, 0));
        self::assertContains(Nav::to('ppjt', 'df', 0, 0), $this->callbacks(0), 'its default a tap away');
        $this->tapAs(Nav::to('ppjt', 'df', 0, 0));
        self::assertSame($field['default'], $this->field(0, 0)['value']);

        $this->tapAs(Nav::to('ppjt', 'e', 0, 0));
        $this->send($this->message('55', chat: self::OWNER));
        $this->tapAs(Nav::to('ppj'));
        $this->tapAs(Nav::to('ppj', 'reset'));
        self::assertContains(Nav::to('ppj', 'defaults'), $this->callbacks(0), 'asked first');
        $this->tapAs(Nav::to('ppj', 'defaults'));
        self::assertSame($field['default'], $this->field(0, 0)['value']);
    }

    public function testABackupIsSentHereAndTheInstallationRestoredFromItOnAYes(): void
    {
        $this->tapAs(Nav::to('ppb'));
        self::assertStringContainsString('پشتیبان کل نصب', $this->said()[0]);
        self::assertStringContainsString('✅ روشن', $this->said()[0], 'its sending to the report group');

        $this->tapAs(Nav::to('ppb', 'dl'));
        $sent = array_search('sendDocument', $this->calls(), true);
        self::assertNotFalse($sent, 'the backup, as a file under the screen');
        $backup = (string) ($this->telegram()->files($sent)['document'] ?? '');
        $header = json_decode((string) strtok((string) gzdecode($backup), "\n"), true);
        self::assertSame('installation', is_array($header) ? $header['scope'] ?? null : null);

        $this->tapAs(Nav::to('ppb'));
        self::assertContains(Nav::to('ppb', 'auto', 0), $this->callbacks(0), 'the switch carries the value it sets');
        $this->tapAs(Nav::to('ppb', 'auto', 0));
        self::assertStringContainsString('⛔️ خاموش', $this->said()[1] ?? $this->said()[0], 'switched through PUT /backups/auto');
        $this->tapAs(Nav::to('ppb', 'auto', 0));
        self::assertStringContainsString('همین حالا خاموش است', (string) $this->popup(), 'tapped twice, it changes nothing');
        self::assertStringContainsString('⛔️ خاموش', $this->said()[0]);

        // A file Telegram refuses is said, never called sent.
        $this->telegram()->on('sendDocument', static fn(): Response => FakeTelegram::error(413, 'Request Entity Too Large'));
        $this->tapAs(Nav::to('ppb', 'dl'));
        self::assertStringContainsString('را نفرستاد', (string) $this->popup());
        self::assertStringNotContainsString('فایل پشتیبان فرستاده شد', implode("\n", $this->said()));

        $this->customer(['telegram_id' => 9901]);
        $this->tapAs(Nav::to('ppbr', 'up'));
        $this->telegram()->reply(['file_path' => 'documents/backup.json.gz']);
        $this->telegram()->raw(new Response(200, [], $backup));
        $this->send($this->message(null, ['document' => ['file_id' => 'bk-1', 'file_name' => 'backup.json.gz', 'mime_type' => 'application/gzip']], self::OWNER));
        self::assertStringContainsString('فایل انتخاب‌شده', implode("\n", $this->said()), 'described first');
        self::assertNotNull(User::query()->where('telegram_id', 9901)->first(), 'nothing restored yet');

        $this->tapAs(Nav::to('ppbr', 'ask'));
        self::assertStringContainsString('از دست می‌رود', $this->said()[0], 'the panel\'s warning');
        self::assertContains(Nav::to('ppbr', 'go'), $this->callbacks(0));
        $this->tapAs(Nav::to('ppbr', 'go'));
        self::assertNull(User::query()->where('telegram_id', 9901)->first(), 'the installation as it was');
        self::assertStringContainsString('به حالت پشتیبان برگشت', implode("\n", $this->said()));

        $this->tapAs(Nav::to('ppbr', 'before'));
        self::assertContains('sendDocument', $this->calls(), 'the state before, as a file');
    }

    public function testTheSystemSaysHowTheBotsGetTheirMessages(): void
    {
        $this->tapAs(Nav::to('ppw'));
        self::assertStringContainsString('دریافت پیام‌ها', $this->said()[0]);
        self::assertStringContainsString('کارهای زمان‌بندی‌شده', $this->said()[0]);
        self::assertContains(Nav::to('ppu'), $this->callbacks(0));
    }

    /**
     * Field `$field` of task `$task`, as the schedule now has it.
     *
     * @return array<string, mixed>
     */
    private function field(int $task, int $field): array
    {
        $fields = $this->service(ScheduleScreen::class)->present()['tasks'][$task]['fields'] ?? [];

        return is_array($fields[$field] ?? null) ? $fields[$field] : [];
    }

    private function tapAs(string $data): void
    {
        $this->send($this->tap($data, chat: self::OWNER));
    }

    /** The account 7001 linked as the owner. */
    private function owner(): User
    {
        $owner = User::query()->where('telegram_id', self::OWNER)->first() ?? $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);

        return $owner;
    }
}
