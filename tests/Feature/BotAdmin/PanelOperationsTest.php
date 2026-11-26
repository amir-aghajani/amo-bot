<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Scheduling\Scheduler;
use App\Modules\Admin\Services\ScheduleScreen;
use App\Modules\Backups\Tasks\SendBackupsTask;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words;
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

    public function testATasksStartIsTypedAsATimeOfDayOrPutBack(): void
    {
        $backups = array_search(SendBackupsTask::class, array_keys($this->service(Scheduler::class)->tasks()), true);
        self::assertIsInt($backups);

        $this->tapAs(Nav::to('ppjt', 'show', $backups));
        self::assertStringContainsString('ساعت شروع', $this->said()[0]);
        self::assertStringContainsString('۰۰:۰۰', $this->said()[0], 'the backup at midnight by default');
        $this->tapAs(Nav::to('ppjt', 'es', $backups));
        $this->send($this->message('۲:۳۰', chat: self::OWNER));
        self::assertSame(150, $this->service(Scheduler::class)->start(SendBackupsTask::class), 'Persian digits, one for the hour');
        self::assertStringContainsString('۰۲:۳۰ ✏️', $this->said()[0], 'its default said beside it');

        $this->tapAs(Nav::to('ppjt', 'es', $backups));
        $this->send($this->message('25:00', chat: self::OWNER));
        self::assertStringContainsString('ساعت شروع را به شکل', (string) $this->popup() . implode("\n", $this->said()), 'no time of day: refused, in the server\'s words');
        self::assertSame(150, $this->service(Scheduler::class)->start(SendBackupsTask::class));

        $this->tapAs(Nav::to('ppjt', 'es', $backups));
        self::assertContains(Nav::to('ppjt', 'ds', $backups), $this->callbacks(0), 'its default a tap away');
        $this->tapAs(Nav::to('ppjt', 'ds', $backups));
        self::assertSame(0, $this->service(Scheduler::class)->start(SendBackupsTask::class));

        $this->tapAs(Nav::to('ppj'));
        $list = array_column(array_merge(...$this->inlineKeyboard($this->buttonsCall())), 'text');
        self::assertContains('⚙️ پاک‌سازی انتقال‌ها · هر ۱٬۴۴۰ دقیقه از ۰۳:۰۰', $list, 'the list says when each runs');
        self::assertContains('🏪 ارسال گزارش‌ها · هر ۱ دقیقه', $list, 'every minute: its start makes no difference, so it is left out');
    }

    public function testABackupIsSentHereAndTheInstallationRestoredFromItOnAYes(): void
    {
        $this->tapAs(Nav::to('ppb'));
        self::assertStringContainsString('کل نصب: همه فروشگاه‌ها', $this->said()[0], 'a backup of the whole installation');
        self::assertStringContainsString(Words::ON, $this->said()[0], 'its sending to the report group');

        $this->tapAs(Nav::to('ppb', 'dl'));
        $sent = array_search('sendDocument', $this->calls(), true);
        self::assertNotFalse($sent, 'the backup, as a file under the screen');
        $backup = (string) ($this->telegram()->files($sent)['document'] ?? '');
        $header = json_decode((string) strtok((string) gzdecode($backup), "\n"), true);
        self::assertSame('installation', is_array($header) ? $header['scope'] ?? null : null);

        $this->tapAs(Nav::to('ppb'));
        self::assertContains(Nav::to('ppb', 'auto', 0), $this->callbacks(0), 'the switch carries the value it sets');
        $this->tapAs(Nav::to('ppb', 'auto', 0));
        self::assertStringContainsString(Words::OFF, $this->said()[1] ?? $this->said()[0], 'switched through PUT /backups/auto');
        $this->tapAs(Nav::to('ppb', 'auto', 0));
        self::assertStringContainsString('همین حالا خاموش است', (string) $this->popup(), 'tapped twice, it changes nothing');
        self::assertStringContainsString(Words::OFF, $this->said()[0]);

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

    public function testTheWebhookIsSetFromAnHttpsAddressAndTheAddressFixedFirstElse(): void
    {
        $file = $this->configFile(['APP_NAME' => 'AmoBot', 'APP_URL' => 'https://shop.example', 'APP_KEY' => 'base64:x', 'LOG_LEVEL' => 'info']);
        $this->config(['app.url' => 'https://shop.example']);

        $this->tapAs(Nav::to('ppw'));
        self::assertStringNotContainsString('فقط روی آدرس https', $this->said()[0], 'the shop is on https: nothing to fix');
        self::assertStringNotContainsString('دریافت پیام‌های ربات اصلی', $this->said()[0], 'the shop\'s bot is the main one: how it gets its messages said once');
        self::assertContains(['text' => '🔗 ثبت Webhook', 'callback_data' => Nav::to('ppw', 'on'), 'style' => 'success'], array_merge(...$this->inlineKeyboard(0)));
        $this->tapAs(Nav::to('ppw', 'on'));
        self::assertContains(Nav::to('ppw', 'onn'), $this->callbacks(0), 'asked first');
        $this->tapAs(Nav::to('ppw', 'onn'));
        self::assertContains('setWebhook', $this->calls(), 'every bot put on its webhook');
        self::assertStringContainsString('نتیجه برای هر ربات', implode("\n", $this->said()));

        // The bot switched off in its settings: its line says so, and the way it still gets its messages is said apart.
        $this->botSettings('general', ['enabled' => false]);
        $this->tapAs(Nav::to('ppw'));
        self::assertStringContainsString('غیرفعال از تنظیمات', $this->said()[0]);
        self::assertStringContainsString("دریافت پیام‌های ربات اصلی: <b>\u{2068}Webhook\u{2069}</b>", $this->said()[0]);
        $this->botSettings('general', ['enabled' => true]);

        $file->setMany(['APP_URL' => 'http://shop.example']);
        $this->tapAs(Nav::to('ppw'));
        self::assertStringContainsString('فقط روی آدرس https', $this->said()[0], 'Telegram takes no http address: said');
        self::assertNotContains(Nav::to('ppw', 'on'), $this->callbacks(0));
        self::assertContains(Nav::to('ppa'), $this->callbacks(0), 'the address a tap away');

        // In an agent's shop: its own bot, and the main bot's way apart.
        $this->tapAs(Nav::to('shop', 'pick', $this->agentBot()->id));
        $this->tapAs(Nav::to('ppw'));
        self::assertStringContainsString('ربات این فروشگاه', $this->said()[0]);
        self::assertStringContainsString('دریافت پیام‌های ربات اصلی', $this->said()[0]);
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
}
