<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\Websites;
use App\Support\Validation;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * «وب‌سایت» in both panels (`/api/{panel}/website`): the shop's website is made, switched off with a store key of its
 * own, the first time the panel asks; the panel turns it on with its address and the other origins its pages call from,
 * and sets the ways its customers sign in — Telegram with the Client ID @BotFather shows and a secret it never gets back,
 * email sign-up, Google, the captcha its forms ask — a driver and its form, its secret never back either. A change is a
 * PATCH of the fields a card has: one sent is changed, one left
 * out stays as it is kept, and the change is judged as the website would stand after it — every refusal at once, under
 * its field. A new key ends the old base address. An agent's panel sets their own shop's website.
 */
final class WebsitePanelApiTest extends HttpTestCase
{
    private const URL = '/api/admin/website';

    /** What a panel sends to turn the website on, Telegram sign-in with it. */
    private const FORM = [
        'enabled' => true,
        'url' => 'https://Shop.Example/',
        'origins' => "http://localhost:3000\nhttps://www.shop.example:443, http://localhost:3000",
        'telegram_login' => true,
        'telegram_client_id' => '۷۳۵۴۸۶۹۱۲۰',
        'telegram_client_secret' => 'tg-secret-1',
    ];

    /** The captcha card's Cloudflare Turnstile, its keys as Cloudflare shows them. */
    private const CAPTCHA = ['captcha' => ['driver' => 'turnstile', 'site_key' => '0x4AAAAAAA-site', 'secret_key' => '0x4AAAAAAA-secret']];

    public function testTheWebsiteIsMadeSwitchedOffTheFirstTimeThePanelAsks(): void
    {
        $this->telegram();
        $this->loginAsAdmin();

        $website = $this->decode($this->get(self::URL))['website'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $website['key']);
        self::assertSame([
            'enabled' => false,
            'key' => $website['key'],
            'base_url' => "http://localhost/api/store/v1/{$website['key']}",
            'url' => null,
            'origins' => [],
            'telegram' => ['enabled' => false, 'client_id' => null, 'bot_id' => FakeTelegram::BOT_ID, 'has_secret' => false],
            'email' => ['enabled' => false, 'mail_ready' => false],
            'google' => ['client_id' => null],
            'reviews' => ['enabled' => false],
            'staff' => ['enabled' => false, 'strong_sign_in' => true, 'grants' => []],
        ], array_diff_key($website, ['captcha' => true]));
        self::assertSame('none', $website['captcha']['driver'], 'no captcha asked');
        self::assertSame(['turnstile', 'altcha'], array_column($website['captcha']['drivers'], 'key'), 'every captcha described, its form');
        self::assertSame(['site_key', 'secret_key'], array_column($website['captcha']['drivers'][0]['fields'], 'name'));
        self::assertSame([], $website['captcha']['drivers'][1]['fields'], 'ALTCHA asks no keys');
        self::assertSame(['turnstile' => ['site_key' => '', 'secret_key' => ['set' => false, 'hint' => '']], 'altcha' => []], $website['captcha']['values'], 'each one at its defaults');
        self::assertSame($website, $this->decode($this->get(self::URL))['website'], 'asked again, the same website');
        self::assertSame(1, Website::query()->count());
    }

    public function testTheOwnerTurnsTheWebsiteOnWithItsOriginsAndTelegramSignIn(): void
    {
        $this->loginAsAdmin();

        $response = $this->patchJson(self::URL, self::FORM);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $website = $this->decode($response)['website'];
        self::assertTrue($website['enabled']);
        self::assertSame('https://Shop.Example', $website['url'], 'kept as typed, without the trailing slash');
        self::assertSame(['http://localhost:3000', 'https://www.shop.example'], $website['origins'], 'as browsers send them: lower case, no default port, once each');
        self::assertSame(['enabled' => true, 'client_id' => '7354869120', 'bot_id' => null, 'has_secret' => true], $website['telegram'], 'no token: the bot has no id yet');
        self::assertStringNotContainsString('tg-secret-1', (string) $response->getBody(), 'the secret never comes back');
        self::assertSame('tg-secret-1', Website::query()->sole()->telegram_client_secret, 'kept, encrypted at rest');
        self::assertStringNotContainsString('tg-secret-1', (string) $this->db()->table('websites')->value('telegram_client_secret'));
    }

    public function testAFieldLeftOutStaysAsItIsKept(): void
    {
        $this->loginAsAdmin();
        $this->mail();
        $kept = $this->decode($this->patchJson(self::URL, self::FORM + self::CAPTCHA + ['email_signup' => true, 'google_client_id' => self::GOOGLE_CLIENT_ID]))['website'];

        $origins = $this->decode($this->patchJson(self::URL, ['origins' => ['https://staging.shop.example']]))['website'];
        self::assertSame(array_replace($kept, ['origins' => ['https://staging.shop.example']]), $origins, 'the origins alone: the rest as it was kept');

        $google = $this->decode($this->patchJson(self::URL, ['google_client_id' => '']))['website'];
        self::assertSame(array_replace($origins, ['google' => ['client_id' => null]]), $google, 'Google sign-in off, and nothing else');

        $website = Website::query()->sole();
        self::assertSame(['tg-secret-1', '0x4AAAAAAA-secret'], [$website->telegram_client_secret, $website->captcha_config['secret_key'] ?? null], 'the secrets left out are kept');
    }

    public function testABlankSecretKeepsTheOneKeptAndTheClearSwitchEmptiesIt(): void
    {
        $this->loginAsAdmin();
        $this->patchJson(self::URL, self::FORM);

        $blank = $this->decode($this->patchJson(self::URL, ['telegram_client_secret' => '']))['website'];
        self::assertTrue($blank['telegram']['has_secret']);
        self::assertSame('tg-secret-1', Website::query()->sole()->telegram_client_secret, 'blank, the one kept stays');

        $new = $this->decode($this->patchJson(self::URL, ['telegram_client_secret' => ' tg-secret-2 ']))['website'];
        self::assertTrue($new['telegram']['has_secret']);
        self::assertSame('tg-secret-2', Website::query()->sole()->telegram_client_secret, 'typed, it replaces the one kept');

        $cleared = $this->decode($this->patchJson(self::URL, ['clear_telegram_client_secret' => true]))['website'];
        self::assertFalse($cleared['telegram']['has_secret']);
        self::assertNull(Website::query()->sole()->telegram_client_secret);
        self::assertSame(['enabled' => true, 'client_id' => '7354869120'], array_intersect_key($cleared['telegram'], ['enabled' => 0, 'client_id' => 0]), 'Telegram sign-in stays on: the popup needs no secret');
    }

    public function testTheChangeIsJudgedAsTheWebsiteWouldStandAfterIt(): void
    {
        $this->loginAsAdmin();
        $noAddress = ['url' => ['آدرس وب‌سایت را وارد کنید؛ وب‌سایت بدون آدرس روشن نمی‌شود.']];
        $noClientId = ['telegram_client_id' => ['برای ورود با تلگرام، Client ID را از BotFather وارد کنید.']];

        $off = $this->patchJson(self::URL, ['enabled' => true]);
        self::assertSame([422, $noAddress], [$off->getStatusCode(), $this->decode($off)['errors']], 'switched on, with no address sent and none kept');

        $this->patchJson(self::URL, ['url' => 'https://shop.example']);
        $on = $this->patchJson(self::URL, ['enabled' => true]);
        self::assertSame(200, $on->getStatusCode(), (string) $on->getBody());
        self::assertSame([true, 'https://shop.example'], [$this->decode($on)['website']['enabled'], $this->decode($on)['website']['url']], 'switched on by the address kept, not sent');

        $blanked = $this->patchJson(self::URL, ['url' => '']);
        self::assertSame($noAddress, $this->decode($blanked)['errors'], 'the address taken away from a website kept on');

        self::assertSame($noClientId, $this->decode($this->patchJson(self::URL, ['telegram_login' => true]))['errors']);
        $this->patchJson(self::URL, ['telegram_client_id' => '7354869120']);
        self::assertTrue($this->decode($this->patchJson(self::URL, ['telegram_login' => true]))['website']['telegram']['enabled'], 'by the Client ID kept');
        self::assertSame($noClientId, $this->decode($this->patchJson(self::URL, ['telegram_client_id' => '']))['errors'], 'the Client ID taken away from Telegram sign-in kept on');

        $this->patchJson(self::URL, self::CAPTCHA);
        $moved = $this->patchJson(self::URL, ['captcha' => ['driver' => 'turnstile', 'site_key' => '0x4AAAAAAA-other-site']]);
        self::assertSame(['captcha.secret_key' => ['با Site Key تازه، Secret Key همان ویجت را هم وارد کنید.']], $this->decode($moved)['errors'], 'the secret kept belongs with its site key');
        $newSecret = $this->patchJson(self::URL, ['captcha' => ['driver' => 'turnstile', 'site_key' => '0x4AAAAAAA-other-site', 'secret_key' => '0x4AAAAAAA-other']]);
        self::assertSame(['site_key' => '0x4AAAAAAA-other-site', 'secret_key' => ['set' => true, 'hint' => '••••••ther']], $this->decode($newSecret)['website']['captcha']['values']['turnstile'], 'a new widget, its own secret');
        self::assertSame(['site_key' => '0x4AAAAAAA-other-site', 'secret_key' => '0x4AAAAAAA-other'], Website::query()->sole()->captcha_config);

        $website = Website::query()->sole();
        self::assertSame([true, 'https://shop.example', true, '7354869120'], [$website->enabled, $website->url, $website->telegram_login, $website->telegram_client_id], 'nothing refused was kept');
    }

    public function testAnEmptyChangeChangesNothingAndAnswersTheWebsite(): void
    {
        $this->loginAsAdmin();
        $this->patchJson(self::URL, self::FORM + self::CAPTCHA);
        $before = $this->db()->table('websites')->first();
        Carbon::setTestNow(now()->addHour());

        $response = $this->patchJson(self::URL, []);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($this->decode($this->get(self::URL)), $this->decode($response));
        self::assertEquals($before, $this->db()->table('websites')->first(), 'not even written again: its time and its secrets as they were');
    }

    public function testEveryRefusalIsSaidAtOnceUnderItsFieldAndNothingIsKept(): void
    {
        $this->loginAsAdmin();

        $response = $this->patchJson(self::URL, [
            'enabled' => true,
            'url' => '',
            'origins' => ['https://shop.example/app', 'localhost:3000'],
            'telegram_login' => true,
            'telegram_client_id' => '',
            'telegram_client_secret' => 'رمز من',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $errors = $this->decode($response)['errors'];
        ksort($errors);
        self::assertSame([
            'origins' => ['«https://shop.example/app»، «localhost:3000» Origin نیستند؛ فقط scheme، دامنه و در صورت نیاز پورت را بنویسید، مثل https://example.com یا http://localhost:3000.'],
            'telegram_client_id' => ['برای ورود با تلگرام، Client ID را از BotFather وارد کنید.'],
            'telegram_client_secret' => ['Client Secret را همان‌طور که BotFather نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.'],
            'url' => ['آدرس وب‌سایت را وارد کنید؛ وب‌سایت بدون آدرس روشن نمی‌شود.'],
        ], $errors);
        self::assertFalse(Website::query()->sole()->enabled, 'nothing kept');

        $more = $this->patchJson(self::URL, [
            'enabled' => false,
            'url' => 'ftp://user:pass@shop.example?x=1',
            'origins' => implode(',', array_map(static fn(int $port): string => "http://localhost:{$port}", range(3000, 3010))),
            'telegram_login' => false,
            'telegram_client_id' => '73548-6912',
        ]);
        self::assertSame([
            'url' => ['آدرس وب‌سایت باید با http:// یا https:// شروع شود و شامل نام دامنه باشد، بدون نام کاربری و رمز.'],
            'origins' => ['حداکثر 10 Origin می‌توانید اضافه کنید.'],
            'telegram_client_id' => ['Client ID باید فقط عدد باشد.'],
        ], $this->decode($more)['errors']);

        $query = $this->patchJson(self::URL, ['url' => 'https://shop.example/?ref=1']);
        self::assertSame(['url' => ['آدرس وب‌سایت نباید شامل پارامتر (?) یا قطعه (#) باشد.']], $this->decode($query)['errors']);
    }

    public function testTheSwitchesMustBeSwitches(): void
    {
        $this->loginAsAdmin();

        $response = $this->unchecked()->patchJson(self::URL, ['enabled' => 'maybe', 'telegram_login' => null, 'url' => 'https://shop.example']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['enabled' => [Validation::NOT_A_SWITCH], 'telegram_login' => [Validation::NOT_A_SWITCH]], $this->decode($response)['errors']);
        self::assertSame([false, null], [Website::query()->sole()->enabled, Website::query()->sole()->url], 'never read as "off" either: nothing was kept');
    }

    public function testANewKeyEndsTheOldAddress(): void
    {
        $this->loginAsAdmin();
        $this->patchJson(self::URL, self::FORM);
        $old = $this->decode($this->get(self::URL))['website'];
        self::assertSame(200, $this->get("/api/store/v1/{$old['key']}")->getStatusCode());

        $new = $this->decode($this->postJson('/api/admin/website/key'))['website'];

        self::assertNotSame($old['key'], $new['key']);
        self::assertSame("http://localhost/api/store/v1/{$new['key']}", $new['base_url']);
        $gone = $this->get("/api/store/v1/{$old['key']}");
        self::assertSame([404, StoreMiddleware::CLOSED], [$gone->getStatusCode(), $this->decode($gone)['message']]);
        self::assertSame(200, $this->get("/api/store/v1/{$new['key']}")->getStatusCode());
    }

    public function testAnAgentSetsTheirOwnShopsWebsite(): void
    {
        $this->telegram();
        $bot = $this->agentBot();
        $this->loginAsAdmin();
        $owners = $this->decode($this->get(self::URL))['website'];
        $_SESSION = [];
        $this->loginAsAgent($bot);

        $agents = $this->decode($this->patchJson('/api/agent/website', self::FORM))['website'];

        self::assertNotSame($owners['key'], $agents['key']);
        self::assertSame(777000, $agents['telegram']['bot_id'], "the agent's bot's own id, the hint beside the Client ID");
        self::assertTrue(CurrentBot::run($bot, static fn(): Website => Website::query()->sole())->enabled, "the agent's shop's website");
        self::assertFalse(Website::query()->sole()->enabled, "the owner's is as it was");
    }

    public function testEmailSignUpIsNotSwitchedOnWhileNoEmailGoesOut(): void
    {
        $this->loginAsAdmin();

        $refused = $this->patchJson(self::URL, ['email_signup' => true]);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['email_signup' => [Websites::MAIL_OFF]], $this->decode($refused)['errors']);
        self::assertFalse(Website::query()->sole()->email_signup);

        $this->mail();
        $website = $this->decode($this->patchJson(self::URL, ['email_signup' => true]))['website'];
        self::assertSame(['enabled' => true, 'mail_ready' => true], $website['email']);

        $this->config(['mail.from_address' => '']);
        $kept = $this->patchJson(self::URL, ['email_signup' => true]);
        self::assertSame(200, $kept->getStatusCode(), 'on already, sending it on again switches nothing on');
        self::assertSame(['enabled' => true, 'mail_ready' => false], $this->decode($kept)['website']['email'], 'the panel says no email goes out');
        self::assertSame(['enabled' => true, 'mail_ready' => false], $this->decode($this->patchJson(self::URL, self::FORM))['website']['email'], 'left out, as it is');
        self::assertSame(['enabled' => false, 'mail_ready' => false], $this->decode($this->patchJson(self::URL, ['email_signup' => false]))['website']['email'], 'switched off whatever the email does');
    }

    public function testGoogleSignInTakesTheClientIdGoogleCloudShows(): void
    {
        $this->loginAsAdmin();

        $wrong = $this->patchJson(self::URL, ['google_client_id' => 'my-google-app']);
        self::assertSame(['google_client_id' => ['Client ID گوگل را همان‌طور که Google Cloud نشان می‌دهد کپی کنید؛ مثل 1234567890-abc123.apps.googleusercontent.com.']], $this->decode($wrong)['errors']);

        $set = $this->patchJson(self::URL, ['google_client_id' => ' ' . self::GOOGLE_CLIENT_ID . ' ']);
        self::assertSame(['client_id' => self::GOOGLE_CLIENT_ID], $this->decode($set)['website']['google']);
        self::assertSame(['client_id' => self::GOOGLE_CLIENT_ID], $this->decode($this->patchJson(self::URL, self::FORM))['website']['google'], 'left out, as it is');
        self::assertSame(['client_id' => null], $this->decode($this->patchJson(self::URL, ['google_client_id' => '']))['website']['google'], 'blank: Google sign-in off');
    }

    public function testTheCaptchaIsADriverWithItsFormItsSecretNeverBack(): void
    {
        $this->loginAsAdmin();
        $captcha = fn(array $card): array => $this->decode($this->patchJson(self::URL, ['captcha' => $card]));

        self::assertSame(['captcha.site_key' => ['Site Key را وارد کنید.'], 'captcha.secret_key' => ['Secret Key را هم از Cloudflare وارد کنید.']], $captcha(['driver' => 'turnstile', 'site_key' => ''])['errors'], "Turnstile's form whole: both keys");
        self::assertSame([
            'captcha.site_key' => ['Site Key را همان‌طور که Cloudflare نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.'],
            'captcha.secret_key' => ['Secret Key را همان‌طور که Cloudflare نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.'],
        ], $captcha(['driver' => 'turnstile', 'site_key' => 'site key', 'secret_key' => 'secret key'])['errors']);
        $unknown = $this->unchecked()->patchJson(self::URL, ['captcha' => ['driver' => 'recaptcha']]);
        self::assertSame(['captcha.driver' => ['تایید امنیتی را از گزینه‌ها انتخاب کنید.']], $this->decode($unknown)['errors']);
        self::assertNull(Website::query()->sole()->captcha_driver, 'nothing kept');

        $both = $this->patchJson(self::URL, self::CAPTCHA);
        self::assertSame('turnstile', $this->decode($both)['website']['captcha']['driver']);
        self::assertSame(['site_key' => '0x4AAAAAAA-site', 'secret_key' => ['set' => true, 'hint' => '••••••cret']], $this->decode($both)['website']['captcha']['values']['turnstile']);
        self::assertStringNotContainsString('0x4AAAAAAA-secret', (string) $both->getBody(), 'the secret never comes back');
        self::assertSame(['site_key' => '0x4AAAAAAA-site', 'secret_key' => '0x4AAAAAAA-secret'], Website::query()->sole()->captcha_config, 'kept, encrypted at rest');
        self::assertStringNotContainsString('0x4AAAAAAA', (string) $this->db()->table('websites')->value('captcha_config'));

        $kept = $captcha(['driver' => 'turnstile', 'site_key' => '0x4AAAAAAA-site', 'secret_key' => '']);
        self::assertTrue($kept['website']['captcha']['values']['turnstile']['secret_key']['set'], 'blank, the secret kept stays');

        $altcha = $captcha(['driver' => 'altcha']);
        self::assertSame(['altcha', ['site_key' => '', 'secret_key' => ['set' => false, 'hint' => '']]], [$altcha['website']['captcha']['driver'], $altcha['website']['captcha']['values']['turnstile']], 'no keys to type; Turnstile at its defaults again');
        self::assertSame(['altcha', null], [Website::query()->sole()->captcha_driver, Website::query()->sole()->captcha_config]);
        self::assertSame(['captcha.secret_key' => ['Secret Key را هم از Cloudflare وارد کنید.']], $captcha(['driver' => 'turnstile', 'site_key' => '0x4AAAAAAA-site'])['errors'], 'a secret kept for Turnstile went with it');

        $none = $captcha(['driver' => 'none']);
        self::assertSame('none', $none['website']['captcha']['driver']);
        self::assertSame([null, null], [Website::query()->sole()->captcha_driver, Website::query()->sole()->captcha_config]);
    }

    /**
     * The door the website opens to the shop's admins — shut, a strong sign-in asked, nothing granted at first —, set
     * from its card: the grants the whole list sent, each once, in their own order; anything that is no grant refused,
     * and the switches switches, before anything is kept; what the card leaves out stays.
     */
    public function testTheShopsAdminsAreLetInFromTheWebsitesCard(): void
    {
        $this->loginAsAdmin();

        $opened = $this->patchJson(self::URL, ['staff_enabled' => true, 'staff_strong_sign_in' => false, 'staff_grants' => ['refunds', 'catalog', 'refunds']]);

        self::assertSame(200, $opened->getStatusCode(), (string) $opened->getBody());
        self::assertSame(['enabled' => true, 'strong_sign_in' => false, 'grants' => ['catalog', 'refunds']], $this->decode($opened)['website']['staff']);
        self::assertSame(['catalog', 'refunds'], Website::query()->sole()->staff_grants);

        $refused = $this->unchecked()->patchJson(self::URL, ['staff_enabled' => 'yes', 'staff_grants' => ['catalog', 'owner']]);
        self::assertSame(['staff_enabled' => [Validation::NOT_A_SWITCH], 'staff_grants' => ['دسترسی‌ها را از گزینه‌ها انتخاب کنید.']], $this->decode($refused)['errors']);
        self::assertSame(['catalog', 'refunds'], Website::query()->sole()->staff_grants, 'nothing kept');
        self::assertSame(['staff_grants' => ['دسترسی‌ها را از گزینه‌ها انتخاب کنید.']], $this->decode($this->unchecked()->patchJson(self::URL, ['staff_grants' => 'catalog']))['errors'], 'a list, not a word');

        $kept = $this->decode($this->patchJson(self::URL, ['staff_grants' => []]))['website']['staff'];
        self::assertSame(['enabled' => true, 'strong_sign_in' => false, 'grants' => []], $kept, 'the switches left out stay; none granted');
    }

    /** The reviews, off at first: switched on from their card, a switch strictly — the rest of the website as it is kept. */
    public function testTheReviewsAreSwitchedOnFromTheirCard(): void
    {
        $this->loginAsAdmin();

        $on = $this->patchJson(self::URL, ['reviews_enabled' => true]);

        self::assertSame(200, $on->getStatusCode(), (string) $on->getBody());
        self::assertSame(['enabled' => true], $this->decode($on)['website']['reviews']);
        self::assertTrue(Website::query()->sole()->reviews_enabled);
        self::assertFalse($this->decode($on)['website']['enabled'], 'nothing else moved');

        $refused = $this->unchecked()->patchJson(self::URL, ['reviews_enabled' => 'off']);
        self::assertSame(['reviews_enabled' => [Validation::NOT_A_SWITCH]], $this->decode($refused)['errors']);
        self::assertTrue(Website::query()->sole()->reviews_enabled, 'never read as off: nothing kept');
    }

    public function testNothingIsTakenThatIsNotOnTheForm(): void
    {
        $this->loginAsAdmin();

        $response = $this->unchecked()->patchJson(self::URL, self::FORM + ['key' => str_repeat('a', 24)]);

        self::assertSame(200, $response->getStatusCode(), 'what the server does not read is the sender\'s mistake, not a refusal');
        self::assertNotSame(str_repeat('a', 24), $this->decode($response)['website']['key'], 'a key is never set by hand');
    }
}
