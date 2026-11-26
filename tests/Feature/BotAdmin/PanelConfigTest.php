<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Config\ConfigFile;
use App\Core\Database\Drivers\ProbeFailedException;
use App\Core\Database\Drivers\ProbeResult;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\BotState;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;
use Tests\Fakes\ProbedDriver;

/**
 * «🧰 تنظیمات پنل» › config.php's groups in the bot (Telegram\Admin\Sections\Panel\Config): each a draft the chat keeps
 * until «💾 ذخیره» puts the group whole (PUT /settings/config/{group}) — a long choice found by typing, a secret typed
 * and never shown back, the token checked with Telegram, a driver picked with its form, each refusal under its field —,
 * and each group's «تست»: the database connection, an email, an SMS. The owner's, in the main shop alone.
 */
final class PanelConfigTest extends BotTestCase
{
    private const TOKEN = '123456789:AAF' . 'yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy';

    private ConfigFile $file;

    private ProbedDriver $mysql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->file = $this->configFile([
            'APP_NAME' => 'AmoBot',
            'APP_URL' => 'http://localhost',
            'APP_KEY' => 'base64:x',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'amobot',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => 'old pass',
            'TELEGRAM_BOT_TOKEN' => '',
            'LOG_LEVEL' => 'info',
        ]);
        $this->mysql = $this->probedDatabase('mysql');
        $this->probedDatabase('sqlite', installable: true);
        $this->owner();
    }

    public function testTheMenuSaysTheFileAndAGroupIsADraftUntilSaved(): void
    {
        $this->tapAs(Nav::to('hc'));
        $this->tapAs(Nav::to('pp'));
        self::assertStringContainsString('config.php', $this->said()[0]);
        self::assertStringContainsString('قابل ویرایش', $this->said()[0]);
        self::assertStringContainsString('ظاهر', $this->said()[0], 'the panel\'s look: said to be the browser\'s');
        foreach (['ppt', 'ppd', 'ppa', 'ppm', 'ppn', 'ppl', 'ppu', 'ppj', 'ppb', 'ppi', 'ppv', 'ppw'] as $section) {
            self::assertContains(Nav::to($section), $this->callbacks(0), $section);
        }

        $this->tapAs(Nav::to('ppa', 'e', 0));
        $this->send($this->message('فروشگاه تازه', chat: self::OWNER));
        self::assertStringContainsString('تغییرها هنوز ذخیره نشده‌اند', $this->said()[0]);
        self::assertSame('AmoBot', $this->file->get('APP_NAME'), 'a draft: nothing written yet');

        $this->tapAs(Nav::to('ppa', 'e', 2));
        self::assertStringContainsString('چند حرف از نامش', $this->said()[0], 'a long list searched by typing');
        $this->send($this->message('tehran', chat: self::OWNER));
        self::assertStringContainsString('ایران', $this->said()[0], 'found, and shown by its name');

        $this->tapAs(Nav::to('ppa', 'save'));
        self::assertSame(['فروشگاه تازه', 'Asia/Tehran'], [$this->file->get('APP_NAME'), $this->file->get('APP_TIMEZONE')], 'the group put whole');
        self::assertStringContainsString('ذخیره شد', (string) $this->popup());
        self::assertStringNotContainsString('ذخیره نشده', $this->said()[1] ?? $this->said()[0]);
    }

    public function testTheTokenIsCheckedWithTelegramAndNeverShownBack(): void
    {
        $this->tapAs(Nav::to('ppt', 'e', 0));
        $this->send($this->message(self::TOKEN, chat: self::OWNER));
        self::assertStringNotContainsString(self::TOKEN, implode("\n", $this->said()), 'never shown back');
        self::assertStringContainsString('تازه نوشته شده', $this->said()[0]);
        self::assertContains('deleteMessage', $this->calls(), 'the typed token taken out of the chat');

        $this->telegram()->reply(['id' => 123456789, 'is_bot' => true, 'first_name' => 'فروشگاه', 'username' => 'new_shop_bot']);
        $this->tapAs(Nav::to('ppt', 'test'));
        self::assertStringContainsString('new_shop_bot', implode("\n", $this->said()), 'whose it is, and its @username filled in');

        $this->tapAs(Nav::to('ppt', 'save'));
        self::assertSame([self::TOKEN, 'new_shop_bot'], [$this->file->get('TELEGRAM_BOT_TOKEN'), $this->file->get('TELEGRAM_BOT_USERNAME')]);
        self::assertStringNotContainsString(self::TOKEN, implode("\n", $this->said()));
    }

    public function testTheBotsCardSaysWhenASaveReachesTheMainBot(): void
    {
        $this->tapAs(Nav::to('ppt'));
        self::assertStringContainsString('ربات الان پیامی نمی‌گیرد', $this->said()[0], 'offline: no messages until the webhook is set');

        $this->service(BotState::class)->heartbeat();
        $this->tapAs(Nav::to('ppt'));
        self::assertStringContainsString('ربات با پولینگ کار می‌کند', $this->said()[0], 'polling: read again when it restarts');

        $this->service(BotState::class)->webhookSet('https://shop.example/webhooks/telegram/secret');
        $this->tapAs(Nav::to('ppt'));
        self::assertStringContainsString('ربات روی Webhook است و مقادیر تازه از پیام بعدی به کار می‌روند', $this->said()[0], 'on a webhook: from the next message');
    }

    public function testADriverIsPickedWithItsFormAndEachRefusalIsSaidUnderItsField(): void
    {
        $mail = $this->mail();
        $this->tapAs(Nav::to('ppm', 'd'));
        $smtp = $this->buttonFor('SMTP');
        $this->tapAs($smtp);
        self::assertStringContainsString('سرور SMTP', $this->said()[0], 'its described form');

        $this->tapAs(Nav::to('ppm', 'save'));
        self::assertStringContainsString('ذخیره نشد', (string) $this->popup());
        self::assertStringContainsString('⚠️', $this->said()[1] ?? $this->said()[0], 'each refusal under its field');
        self::assertNotSame('smtp', $this->file->get('MAIL_TRANSPORT'));

        $this->tapAs(Nav::to('ppm', 'e', 0));
        $this->send($this->message('mail.example.com', chat: self::OWNER));
        $this->tapAs(Nav::to('ppm', 'e', 5));
        $this->send($this->message('no-reply@example.com', chat: self::OWNER));
        $this->tapAs(Nav::to('ppm', 'save'));
        self::assertSame(['smtp', 'mail.example.com', 'no-reply@example.com'], [$this->file->get('MAIL_TRANSPORT'), $this->file->get('MAIL_HOST'), $this->file->get('MAIL_FROM_ADDRESS')]);

        $this->tapAs(Nav::to('ppm', 'mt'));
        $this->send($this->message('owner@example.com', chat: self::OWNER));
        self::assertCount(1, $mail->sent(), 'a test email, by the settings saved');
        self::assertStringContainsString('ایمیل تست فرستاده شد', implode("\n", $this->said()));
    }

    public function testTheDatabaseIsTestedAndAnSmsTestShowsItsCode(): void
    {
        $this->mysql->answers[] = new ProbeResult('MySQL 8.0.36', 3);
        $this->tapAs(Nav::to('ppd', 'test'));
        self::assertStringContainsString('MySQL 8.0.36', implode("\n", $this->said()));

        $this->mysql->answers[] = new ProbeFailedException('اتصال به سرور رد شد.');
        $this->tapAs(Nav::to('ppd', 'test'));
        self::assertStringContainsString('اتصال به سرور رد شد', implode("\n", $this->said()), 'why it would not connect, on the card');
        self::assertSame('127.0.0.1', $this->file->get('DB_HOST'), 'a test writes nothing');

        $sms = $this->sms();
        $this->tapAs(Nav::to('ppn', 'st'));
        $this->send($this->message('۰۹۱۲۱۲۳۴۵۶۷', chat: self::OWNER));
        $code = $sms->lastCodeTo('+989121234567');
        self::assertNotNull($code, 'sent by the settings saved');
        self::assertStringContainsString($code, implode("\n", $this->said()), 'the code shown, to match the SMS against');

        $this->tapAs(Nav::to('ppn', 'credit'));
        self::assertStringContainsString('اعتبار حساب', implode("\n", $this->said()), 'the account\'s credit asked of its provider — or why not');
    }

    public function testTheWholeCronAddressIsShownOnATapAndGoneWithTheNext(): void
    {
        $this->file->setMany(['CRON_TOKEN' => 'cron-token-0123456789abcdef']);

        $this->tapAs(Nav::to('ppv'));
        self::assertStringNotContainsString('cron-token-0123456789abcdef', $this->said()[0], 'masked, as the panel shows it');
        self::assertContains(Nav::to('ppv', 'cron'), $this->callbacks(0));

        $this->tapAs(Nav::to('ppv', 'cron'));
        self::assertStringContainsString('cron-token-0123456789abcdef', $this->said()[0], 'the whole address, asked for');
        self::assertStringContainsString('curl -fsS', $this->said()[0], 'and the cPanel line');

        $this->tapAs(Nav::to('ppv'));
        self::assertStringNotContainsString('cron-token-0123456789abcdef', $this->said()[0], 'kept nowhere');

        $this->tapAs(Nav::to('ppv', 'cron'));
        self::assertContains(['text' => '📋 کپی آدرس', 'copy_text' => ['text' => 'http://localhost/cron/cron-token-0123456789abcdef']], array_merge(...$this->inlineKeyboard(0)), 'copied at a tap, on the view that shows it');
    }

    public function testADraftsSwitchSaysAndCarriesTheStateItSets(): void
    {
        $this->file->setMany(['APP_DEBUG' => 'false']);

        $this->tapAs(Nav::to('ppa'));
        self::assertStringContainsString("حالت \u{2068}Debug\u{2069}: <b>" . Words::OFF . '</b>', $this->said()[0], 'its state in the summary, once');
        self::assertContains(['text' => "▶️ روشن کردن «حالت \u{2068}Debug\u{2069}»", 'callback_data' => Nav::to('ppa', 'e', 3, 1)], array_merge(...$this->inlineKeyboard(0)));

        $this->tapAs(Nav::to('ppa', 'e', 3, 1));
        $this->tapAs(Nav::to('ppa', 'e', 3, 1));
        self::assertStringContainsString("حالت \u{2068}Debug\u{2069}: <b>" . Words::ON . '</b>', $this->said()[0], 'a second tap of the same button sets what it said');
        self::assertStringContainsString('تغییرها هنوز ذخیره نشده‌اند', $this->said()[0]);
        self::assertSame('false', $this->file->get('APP_DEBUG'), 'a draft until saved');
        self::assertContains(['text' => Words::SAVE, 'callback_data' => Nav::to('ppa', 'save'), 'style' => 'success'], array_merge(...$this->inlineKeyboard(0)));
    }

    /**
     * The main bot's token, @username, Bot API address and webhook secret are the owner's themselves alone: an admin sees
     * them with «🔒», is offered none to edit — an older window's button is refused in the API's words —, saves the rest
     * of the card, and a token checked fills in no @username of theirs; the owner's own linked account edits them all.
     */
    public function testAnAdminSeesTheMainBotsOwnersOnlyFieldsLockedAndSavesTheRest(): void
    {
        $this->file->setMany(['TELEGRAM_WEBHOOK_SECRET' => 'owners_secret-0123456789', 'TELEGRAM_BOT_TOKEN' => self::TOKEN, 'TELEGRAM_API_URL' => 'https://api.telegram.org']);
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('ppt'), chat: 7100));
        self::assertStringContainsString('🔒 «توکن ربات اصلی»، «نام کاربری ربات اصلی»، «آدرس', $this->said()[0], 'each said to be the owner\'s');
        foreach ([0, 1, 2, 5] as $field) {
            self::assertNotContains(Nav::to('ppt', 'e', $field), $this->callbacks(0), "field {$field}: not offered");
        }
        self::assertContains(Nav::to('ppt', 'e', 4), $this->callbacks(0), 'the polling timeout is theirs');

        foreach ([0 => ActorRefusedException::OWNERS_BOT, 1 => ActorRefusedException::OWNERS_BOT, 2 => ActorRefusedException::OWNERS_API, 5 => ActorRefusedException::OWNERS_SECRET] as $field => $refusal) {
            $this->send($this->tap(Nav::to('ppt', 'e', $field), chat: 7100));
            self::assertSame($refusal, $this->popup(), "field {$field}: an older window's button, refused in the API's words");
            self::assertNotContains('deleteMessage', $this->calls());
        }

        $this->telegram()->reply(['id' => 123456789, 'is_bot' => true, 'first_name' => 'فروشگاه', 'username' => 'another_name_bot']);
        $this->send($this->tap(Nav::to('ppt', 'test'), chat: 7100));
        self::assertStringContainsString('another_name_bot', implode("\n", $this->said()), 'the kept token checked');
        $this->send($this->tap(Nav::to('ppt', 'e', 4), chat: 7100));
        $this->send($this->message('25', chat: 7100));
        $this->send($this->tap(Nav::to('ppt', 'save'), chat: 7100));
        self::assertSame('✅ تنظیمات ربات ذخیره شد.', $this->popup(), 'no @username filled in that the save would be refused');
        self::assertSame(
            [25, self::TOKEN, 'owners_secret-0123456789', 'https://api.telegram.org'],
            [$this->file->get('TELEGRAM_POLL_TIMEOUT'), $this->file->get('TELEGRAM_BOT_TOKEN'), $this->file->get('TELEGRAM_WEBHOOK_SECRET'), $this->file->get('TELEGRAM_API_URL')],
            'the rest saved, the owner\'s left as they are',
        );
        self::assertNotSame('another_name_bot', $this->file->get('TELEGRAM_BOT_USERNAME'), 'the @username the check told left out');

        $this->tapAs(Nav::to('ppt'));
        self::assertStringNotContainsString('🔒', $this->said()[0], 'nothing locked to the owner');
        $this->tapAs(Nav::to('ppt', 'e', 5));
        $this->sayAs('new_secret-0123456789ab');
        $this->tapAs(Nav::to('ppt', 'save'));
        self::assertSame('new_secret-0123456789ab', $this->file->get('TELEGRAM_WEBHOOK_SECRET'), 'the owner\'s own linked account saves it');
    }

    /** The site's address is the owner's themselves alone: an admin sees it with «🔒» and saves the card's rest. */
    public function testAnAdminSeesTheSiteAddressLockedAndSavesTheRest(): void
    {
        $this->file->setMany(['APP_URL' => 'https://shop.example.com']);
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('ppa'), chat: 7100));
        self::assertStringContainsString('🔒 «آدرس سایت» را فقط خود مالک عوض می‌کند.', $this->said()[0]);
        self::assertNotContains(Nav::to('ppa', 'e', 1), $this->callbacks(0));
        $this->send($this->tap(Nav::to('ppa', 'e', 1), chat: 7100));
        self::assertSame(ActorRefusedException::OWNERS_ADDRESS, $this->popup());

        $this->send($this->tap(Nav::to('ppa', 'e', 0), chat: 7100));
        $this->send($this->message('فروشگاه تازه', chat: 7100));
        $this->send($this->tap(Nav::to('ppa', 'save'), chat: 7100));
        self::assertSame(['فروشگاه تازه', 'https://shop.example.com'], [$this->file->get('APP_NAME'), $this->file->get('APP_URL')], 'the name saved, the address sent as it stands');

        $this->tapAs(Nav::to('ppa', 'e', 1));
        $this->sayAs('https://new.example.com');
        $this->tapAs(Nav::to('ppa', 'save'));
        self::assertSame('https://new.example.com', $this->file->get('APP_URL'), 'the owner\'s own linked account moves it');
    }

    /** The panels' session is the owner's own sign-in: an admin sees its two fields with «🔒» and saves the card's rest. */
    public function testAnAdminSeesThePanelsSessionLockedAndSavesTheRest(): void
    {
        $this->file->setMany(['SESSION_LIFETIME' => 120, 'SESSION_SECURE_COOKIE' => false, 'OUTGOING_HTTP_TIMEOUT' => 30]);
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('ppv'), chat: 7100));
        self::assertStringContainsString("🔒 «طول \u{2068}Session\u{2069} پنل‌ها»، «کوکی \u{2068}Session\u{2069} همیشه امن» را فقط خود مالک عوض می‌کند.", $this->said()[0]);
        self::assertNotContains(Nav::to('ppv', 'e', 1), $this->callbacks(0));
        self::assertNotContains(Nav::to('ppv', 'e', 3, 1), $this->callbacks(0));
        foreach ([1, 3] as $field) {
            $this->send($this->tap(Nav::to('ppv', 'e', $field, 1), chat: 7100));
            self::assertSame(ActorRefusedException::OWNERS_SESSION, $this->popup(), "field {$field}");
        }

        $this->send($this->tap(Nav::to('ppv', 'e', 0), chat: 7100));
        $this->send($this->tap(Nav::to('ppv', 'c', 0, 3), chat: 7100));
        $this->send($this->tap(Nav::to('ppv', 'save'), chat: 7100));
        self::assertSame('✅ تنظیمات پیشرفته ذخیره شد.', $this->popup());
        self::assertSame(['warning', 120, false], [$this->file->get('LOG_LEVEL'), $this->file->get('SESSION_LIFETIME'), $this->file->get('SESSION_SECURE_COOKIE')], 'the log saved, the session as it stood');
    }

    /** The database is the owner's themselves alone to save or try: an admin is not offered its card at all. */
    public function testAnAdminIsNotOfferedTheDatabase(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('pp'), chat: 7100));
        self::assertNotContains(Nav::to('ppd'), $this->callbacks(0));
        self::assertContains(Nav::to('ppa'), $this->callbacks(0), 'the rest of config.php is still theirs');

        $this->mysql->probed = [];
        $this->send($this->tap(Nav::to('ppd', 'test'), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
        self::assertSame([], $this->mysql->probed, 'nothing tried');

        $this->tapAs(Nav::to('pp'));
        self::assertContains(Nav::to('ppd'), $this->callbacks(0), 'the owner\'s own linked account has it');
    }

    public function testAnAgentHasNoneOfTheFileButTheirBackups(): void
    {
        $bot = $this->agentBot();
        $this->sendTo($bot, $this->tap(Nav::to('pp'), chat: self::AGENT_TELEGRAM_ID));
        self::assertContains(Nav::to('ppb'), $this->callbacks(0));
        self::assertNotContains(Nav::to('ppa'), $this->callbacks(0));
        self::assertNotContains(Nav::to('ppu'), $this->callbacks(0));

        $this->sendTo($bot, $this->tap(Nav::to('ppa', 'save'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
    }

    /** The data of the first button whose label holds `$label`, among the window's. */
    private function buttonFor(string $label): string
    {
        foreach (array_keys($this->calls()) as $call) {
            foreach (array_merge(...$this->inlineKeyboard($call)) as $button) {
                if (str_contains((string) ($button['text'] ?? ''), $label) && is_string($button['callback_data'] ?? null)) {
                    return $button['callback_data'];
                }
            }
        }

        self::fail("No button holds «{$label}».");
    }
}
