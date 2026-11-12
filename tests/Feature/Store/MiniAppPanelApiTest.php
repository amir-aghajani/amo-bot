<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Store\Services\MiniApps;
use App\Support\Validation;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * The mini app's settings in both panels: the row made switched off with a key of its own on the first read, partial
 * saves judged all at once, switching it on only on an https address, and the bot's menu button set as the app stands
 * after every save — the app's while it is on with its button, Telegram's own in place of the app's, a button the shop
 * set at @BotFather left alone —, Telegram's refusal said beside the save that stands; an agent's own shop's app.
 */
final class MiniAppPanelApiTest extends HttpTestCase
{
    private const URL = '/api/admin/mini-app';

    /** The shop's address on https, as Telegram opens a mini app. */
    private const HTTPS = 'https://shop.example';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->telegram();
        $this->config(['app.name' => 'فروشگاه امو', 'telegram.username' => 'amo_shop_bot']);
        $this->loginAsAdmin();
    }

    public function testTheMiniAppIsMadeSwitchedOffTheFirstTimeThePanelAsks(): void
    {
        $answer = $this->decode($this->get(self::URL));

        $key = MiniApp::query()->sole()->key;
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $key);
        self::assertSame([
            'mini_app' => [
                'enabled' => false,
                'url' => "http://localhost/app/{$key}/",
                'secure' => false,
                'bot' => ['username' => 'amo_shop_bot', 'url' => 'https://t.me/amo_shop_bot'],
                'title' => null,
                'shop_name' => 'فروشگاه امو',
                'accent_color' => null,
                'logo' => false,
                'announcement' => null,
                'announcement_url' => null,
                'fullscreen' => true,
                'reviews_enabled' => false,
                'menu_button' => true,
                'menu_button_text' => null,
                'menu_button_default' => MiniApps::MENU_BUTTON_TEXT,
            ],
            'menu_button_error' => null,
        ], $answer);
        self::assertSame($answer, $this->decode($this->get(self::URL)), 'asked again, the same app');
        self::assertSame(1, MiniApp::query()->count());
        self::assertSame([], $this->telegram()->calls(), 'reading it asks Telegram nothing');
        self::assertSame(404, $this->get("/api/store/v1/{$key}")->getStatusCode(), 'switched off, its key opens nothing');
    }

    public function testAFieldLeftOutStaysAsItIsKept(): void
    {
        $this->get(self::URL);
        $kept = $this->save([
            'title' => '  فروشگاه آبی ',
            'accent_color' => '#2481CC',
            'announcement' => "تخفیف ویژه\nفقط امروز",
            'announcement_url' => 'https://t.me/amo_channel',
            'fullscreen' => false,
            'reviews_enabled' => true,
            'menu_button_text' => 'خرید',
        ]);
        self::assertSame(['فروشگاه آبی', '#2481cc', "تخفیف ویژه\nفقط امروز", 'https://t.me/amo_channel', false, true, 'خرید'], [
            $kept['title'], $kept['accent_color'], $kept['announcement'], $kept['announcement_url'], $kept['fullscreen'], $kept['reviews_enabled'], $kept['menu_button_text'],
        ], 'trimmed, the colour in lower case, the announcement\'s lines kept');

        $title = $this->save(['title' => 'فروشگاه سبز']);
        self::assertSame(array_replace($kept, ['title' => 'فروشگاه سبز']), $title, 'the title alone: the rest as it was kept');

        $blank = $this->save(['title' => '', 'accent_color' => '', 'announcement_url' => '']);
        self::assertSame(array_replace($title, ['title' => null, 'accent_color' => null, 'announcement_url' => null]), $blank, 'blank, a text is none: the shop\'s name, the client\'s colour');
    }

    public function testSwitchingItOnNeedsAnHttpsAddress(): void
    {
        $refused = $this->patchJson(self::URL, ['enabled' => true, 'title' => 'فروشگاه آبی']);

        self::assertSame([422, ['enabled' => [MiniApps::HTTPS_NEEDED]]], [$refused->getStatusCode(), $this->decode($refused)['errors'] ?? null]);
        self::assertSame([false, null], [MiniApp::query()->sole()->enabled, MiniApp::query()->sole()->title], 'nothing kept, the title sent with it neither');
        self::assertSame([], $this->telegram()->calls());

        $this->config(['app.url' => self::HTTPS]);
        $on = $this->save(['enabled' => true]);
        self::assertSame([true, true, self::HTTPS . '/app/' . MiniApp::query()->sole()->key . '/'], [$on['enabled'], $on['secure'], $on['url']]);

        $this->config(['app.url' => 'http://shop.example']);
        self::assertTrue($this->save(['title' => 'فروشگاه آبی'])['enabled'], 'one already on is not refused because APP_URL changed since');
    }

    public function testEveryRefusalComesAtOnceUnderItsField(): void
    {
        $refused = $this->patchJson(self::URL, [
            'title' => str_repeat('ن', 65),
            'accent_color' => 'blue',
            'announcement' => str_repeat('ن', 501),
            'announcement_url' => 'http://t.me/amo_channel',
            'menu_button_text' => str_repeat('ن', 33),
        ]);

        self::assertSame(422, $refused->getStatusCode());
        $errors = $this->decode($refused)['errors'];
        self::assertSame(['title', 'accent_color', 'announcement', 'announcement_url', 'menu_button_text'], array_keys($errors));
        self::assertSame([Validation::tooLong('نام مینی‌اپ', 64)], $errors['title']);
        self::assertSame([Validation::tooLong('متن دکمه منو', 32)], $errors['menu_button_text']);
        self::assertSame(['enabled' => [Validation::NOT_A_SWITCH]], $this->decode($this->unchecked()->patchJson(self::URL, ['enabled' => 'maybe']))['errors'] ?? null);
        self::assertNull(MiniApp::query()->sole()->title, 'nothing kept');
    }

    public function testTheBotsMenuButtonOpensTheAppWhileItIsOnWithItsButton(): void
    {
        $this->config(['app.url' => self::HTTPS]);
        $this->get(self::URL);
        $url = self::HTTPS . '/app/' . MiniApp::query()->sole()->key . '/';

        self::assertNull($this->decode($this->patchJson(self::URL, ['enabled' => true]))['menu_button_error']);
        self::assertSame([['type' => 'web_app', 'text' => MiniApps::MENU_BUTTON_TEXT, 'web_app' => ['url' => $url]]], $this->menuButtonsSet(), "the default words, the app's address");
        self::assertSame(FakeTelegram::TOKEN, $this->telegram()->tokenOf(0), 'the main bot\'s');

        $this->telegram()->reset();
        $this->save(['menu_button_text' => 'خرید سرویس']);
        self::assertSame([['type' => 'web_app', 'text' => 'خرید سرویس', 'web_app' => ['url' => $url]]], $this->menuButtonsSet(), 'set again with its words');

        // Its button off: Telegram's own in place of the app's.
        $this->telegram()->reset();
        $this->telegram()->on('getChatMenuButton', static fn(): array => ['type' => 'web_app', 'text' => 'خرید سرویس', 'web_app' => ['url' => $url]]);
        $this->save(['menu_button' => false]);
        self::assertSame(['getChatMenuButton', 'setChatMenuButton'], $this->telegram()->calls());
        self::assertSame([['type' => 'default']], $this->menuButtonsSet());

        // The app switched off, its button left from an address the shop had before: Telegram's own too.
        $this->save(['menu_button' => true]);
        $this->telegram()->reset();
        $this->telegram()->on('getChatMenuButton', static fn(): array => ['type' => 'web_app', 'text' => 'فروشگاه', 'web_app' => ['url' => 'http://old.example/app/' . MiniApp::query()->sole()->key . '/']]);
        $this->save(['enabled' => false]);
        self::assertSame([['type' => 'default']], $this->menuButtonsSet());
    }

    public function testAButtonTheShopSetAtBotFatherIsLeftAlone(): void
    {
        $this->get(self::URL);

        foreach ([['type' => 'commands'], ['type' => 'web_app', 'text' => 'سایت ما', 'web_app' => ['url' => 'https://shop.example/']], ['type' => 'default']] as $button) {
            $this->telegram()->reset();
            $this->telegram()->on('getChatMenuButton', static fn(): array => $button);

            self::assertNull($this->decode($this->patchJson(self::URL, ['title' => 'فروشگاه آبی']))['menu_button_error']);
            self::assertSame(['getChatMenuButton'], $this->telegram()->calls(), (string) json_encode($button) . ': asked, never changed');
        }
    }

    public function testTelegramRefusingTheButtonIsSaidAndTheSaveStands(): void
    {
        $this->config(['app.url' => self::HTTPS]);
        $logs = $this->logs();
        $this->telegram()->on('setChatMenuButton', static fn(): mixed => FakeTelegram::error(400, 'Bad Request: invalid web app url'));

        $refused = $this->patchJson(self::URL, ['enabled' => true]);

        self::assertSame(200, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertSame('دکمه منوی ربات تنظیم نشد: تلگرام آن را نپذیرفت؛ توکن ربات و آدرس برنامه را بررسی کنید و دوباره ذخیره کنید.', $this->decode($refused)['menu_button_error']);
        self::assertTrue($this->decode($refused)['mini_app']['enabled'], 'saved all the same');
        self::assertTrue(MiniApp::query()->sole()->enabled);
        self::assertTrue($logs->hasWarningThatContains('menu button could not be set'));

        $this->telegram()->on('setChatMenuButton', static fn(): mixed => FakeTelegram::flood(30));
        self::assertSame('دکمه منوی ربات تنظیم نشد: تلگرام در دسترس نبود؛ کمی بعد دوباره ذخیره کنید.', $this->decode($this->patchJson(self::URL, ['title' => 'فروشگاه آبی']))['menu_button_error'], 'busy: save again a little later');
        self::assertSame('فروشگاه آبی', MiniApp::query()->sole()->title);
    }

    public function testAnAgentSetsTheirOwnShopsMiniApp(): void
    {
        $this->config(['app.url' => self::HTTPS]);
        $bot = $this->agentBot();
        $owners = $this->decode($this->get(self::URL))['mini_app'];
        $_SESSION = [];
        $this->loginAsAgent($bot);
        $this->telegram()->reset();

        $agents = $this->decode($this->patchJson('/api/agent/mini-app', ['enabled' => true]))['mini_app'];

        $app = CurrentBot::run($bot, static fn(): MiniApp => MiniApp::query()->sole());
        self::assertSame([true, self::HTTPS . "/app/{$app->key}/", 'فروشگاه نماینده', ['username' => 'agent_shop_bot', 'url' => 'https://t.me/agent_shop_bot']], [$agents['enabled'], $agents['url'], $agents['shop_name'], $agents['bot']]);
        self::assertNotSame($owners['url'], $agents['url'], 'a key of its own');
        self::assertFalse(MiniApp::query()->sole()->enabled, "the owner's is as it was");
        self::assertSame(['setChatMenuButton'], $this->telegram()->calls());
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0), "the agent's bot's menu button");
        self::assertSame(200, $this->get("/api/store/v1/{$app->key}")->getStatusCode(), 'its key opens the Store API in their shop');
    }

    /**
     * PATCH the settings, which must be taken with the menu button set: the app as the panel shows it now.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function save(array $fields): array
    {
        $response = $this->patchJson(self::URL, $fields);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->decode($response)['menu_button_error']);

        return $this->decode($response)['mini_app'];
    }

    /**
     * @return list<mixed> The menu buttons set (setChatMenuButton) since the calls were last forgotten, in order.
     * @phpstan-impure
     */
    private function menuButtonsSet(): array
    {
        $set = [];
        foreach ($this->telegram()->history as $call) {
            if ($call['method'] === 'setChatMenuButton') {
                $set[] = json_decode($call['params']['menu_button'] ?? 'null', true);
            }
        }

        return $set;
    }
}
