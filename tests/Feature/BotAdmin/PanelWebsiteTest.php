<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\Websites;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «🌐 تنظیمات وب‌سایت» in the bot (Telegram\Admin\Sections\Panel\Website): its sections as cards saved a field at a time
 * through PATCH /website — a switch the API refuses said in a popup, the store key renewed on a yes, a secret typed and
 * never shown back, a staff grant switched —, the captcha's described form as a draft saved whole; the owner's and an
 * agent's, never a shop's admin.
 */
final class PanelWebsiteTest extends BotTestCase
{
    private const OWNER = 7001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
    }

    public function testTheOwnerSetsTheConnectionAndRenewsTheStoreKeyOnAYes(): void
    {
        $this->tapAs(Nav::to('hc'));
        self::assertContains(Nav::to('ws'), $this->callbacks(0), 'the website on the settings menu');

        $this->tapAs(Nav::to('ws'));
        self::assertStringContainsString('تنظیمات وب‌سایت', $this->said()[0]);
        self::assertContains(Nav::to('wsc'), $this->callbacks(0));
        self::assertContains(Nav::to('wss'), $this->callbacks(0));

        $this->tapAs(Nav::to('wsc', 'e', 0));
        self::assertSame(Websites::URL_NEEDED, $this->popup(), 'switched on without an address: the API\'s refusal');
        self::assertFalse($this->site()->enabled);

        $this->tapAs(Nav::to('wsc', 'e', 1));
        self::assertStringContainsString('آدرس وب‌سایت', $this->said()[0], 'the address asked');
        $this->send($this->message('https://shop.example', chat: self::OWNER));
        self::assertSame('https://shop.example', $this->site()->url);
        self::assertStringContainsString('ذخیره شد', implode("\n", $this->said()));

        $this->tapAs(Nav::to('wsc', 'e', 0));
        self::assertTrue($this->site()->enabled, 'on, now that it has an address');

        $key = $this->site()->key;
        self::assertStringContainsString($key, $this->said()[0], 'the store key to hand over');
        $this->tapAs(Nav::to('wsc', 'key'));
        self::assertContains(Nav::to('wsc', 'rekey'), $this->callbacks(0), 'asked first');
        self::assertSame($key, $this->site()->key);
        $this->tapAs(Nav::to('wsc', 'rekey'));
        self::assertNotSame($key, $this->site()->key, 'a new key');
        self::assertStringContainsString($this->site()->key, $this->said()[0]);
    }

    public function testASecretTypedIsNeverShownBackAndAGrantIsSwitchedAlone(): void
    {
        $this->tapAs(Nav::to('wst'));
        self::assertStringContainsString('BotFather', $this->said()[0], 'the steps in BotFather');

        $this->tapAs(Nav::to('wst', 'e', 2));
        $this->send($this->message('client-SECRET-42', chat: self::OWNER));
        self::assertTrue($this->site()->hasTelegramSecret());
        self::assertSame('client-SECRET-42', $this->site()->telegramClientSecret());
        self::assertStringNotContainsString('client-SECRET-42', implode("\n", $this->said()), 'never shown back');
        self::assertContains('deleteMessage', $this->calls(), 'the typed secret taken out of the chat');

        $this->tapAs(Nav::to('wst', 'x', 2));
        self::assertFalse($this->site()->hasTelegramSecret(), 'emptied');

        $this->tapAs(Nav::to('wss'));
        self::assertStringContainsString('دو مدیر این کارها را برای هم انجام می‌دهند', $this->said()[0], 'the panel\'s warning');
        $this->tapAs(Nav::to('wss', 'e', 3));
        self::assertSame(['wallet'], $this->site()->staffGrants());
        $this->tapAs(Nav::to('wss', 'e', 2));
        self::assertSame(['catalog', 'wallet'], $this->site()->staffGrants(), 'in the grants\' order');
    }

    public function testTheCaptchaIsADraftSavedWholeWithItsRefusalsUnderEachField(): void
    {
        $this->tapAs(Nav::to('wse'));
        self::assertContains(Nav::to('wsk'), $this->callbacks(0), 'the captcha under email sign-in, as the panel has it');

        $this->tapAs(Nav::to('wsk', 'd'));
        self::assertStringContainsString('Cloudflare Turnstile', $this->said()[0]);
        $this->tapAs(Nav::to('wsk', 'dv', 1));
        self::assertStringContainsString('Site Key', $this->said()[0]);
        self::assertStringContainsString('ذخیره نشده', $this->said()[0], 'a draft until it is saved');
        self::assertNull($this->site()->captchaDriver());

        $this->tapAs(Nav::to('wsk', 'save'));
        self::assertNotNull($this->popup(), 'refused');
        self::assertStringContainsString('⚠️', $this->said()[0], 'each refusal under its field');
        self::assertNull($this->site()->captchaDriver());

        $this->tapAs(Nav::to('wsk', 'e', 0));
        $this->send($this->message('0x4AAAAAAAsite', chat: self::OWNER));
        $this->tapAs(Nav::to('wsk', 'e', 1));
        $this->send($this->message('0x4AAAAAAAsecret', chat: self::OWNER));
        self::assertStringNotContainsString('0x4AAAAAAAsecret', implode("\n", $this->said()));
        self::assertStringContainsString('تازه نوشته شده', implode("\n", $this->said()));

        $this->tapAs(Nav::to('wsk', 'save'));
        self::assertSame('turnstile', $this->site()->captchaDriver());
        self::assertSame(['site_key' => '0x4AAAAAAAsite', 'secret_key' => '0x4AAAAAAAsecret'], $this->site()->captchaConfig());
        self::assertStringNotContainsString('ذخیره نشده', $this->said()[0], 'saved: the draft gone');
    }

    public function testAnAgentSetsTheirOwnWebsiteAndAShopsAdminNone(): void
    {
        $bot = $this->agentBot();
        $this->sendTo($bot, $this->tap(Nav::to('hc'), chat: self::AGENT_TELEGRAM_ID));
        self::assertContains(Nav::to('ws'), $this->callbacks(0));
        self::assertContains(Nav::to('ma'), $this->callbacks(0));

        $this->sendTo($bot, $this->tap(Nav::to('wsg', 'e', 0), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('1234567890-abc123.apps.googleusercontent.com', chat: self::AGENT_TELEGRAM_ID));
        self::assertSame('1234567890-abc123.apps.googleusercontent.com', Website::query()->withoutGlobalScopes()->where('bot_id', $bot->id)->sole()->googleClientId(), 'their shop\'s website');
        self::assertNull(Website::query()->first()?->googleClientId(), 'not the main shop\'s');

        $staff = $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $this->send($this->tap(Nav::to('wsc', 'e', 0), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
        self::assertNotTrue(Website::query()->first()?->enabled);

        $seat = $this->service(AdminSeats::class)->of($staff);
        self::assertNotNull($seat);
        try {
            $this->service(PanelBridge::class)->for($seat)->patch('/website', ['enabled' => true]);
            self::fail('the door let a shop\'s admin set the website');
        } catch (ApiRefusal $refusal) {
            self::assertSame(404, $refusal->status, 'the configuration is no admin\'s');
        }
    }

    private function site(): Website
    {
        return Website::query()->sole();
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
