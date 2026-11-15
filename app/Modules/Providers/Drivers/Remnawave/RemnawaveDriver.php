<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Remnawave;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Providers\Contracts\PanelDriver;
use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientNameRule;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;

/**
 * The Remnawave connector (2.1 and later, 3.x included: a panel of Xray nodes that reach users through internal squads).
 * A server's connection is the panel's address (its domain, where the dashboard opens), an API token made in the panel —
 * the one way in: Remnawave refuses an admin's own sign-in for API calls —, for a panel behind an auth portal the
 * portal's key (the gate key, kept encrypted in `servers.password`, which a token-only connector has no other use for),
 * and the shared options. Its client is RemnawaveProvider.
 */
final class RemnawaveDriver implements PanelDriver
{
    private const KEY = 'remnawave';

    /** The field of the auth portal's key, kept in `servers.password`. */
    public const GATE_KEY = 'gate_key';

    /**
     * An API token: a JWT signed with HS256 — its header and its claims base64url JSON (both start `eyJ`), then the
     * 32-byte signature in base64url (43 characters) —, shown once when it is made.
     */
    private const TOKEN_PATTERN = '/^eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/';

    /** A portal's key as it is sent: printable ASCII, words apart by one space (TinyAuth's «Basic …»), at most 512. */
    private const GATE_PATTERN = '/^(?=.{1,512}$)[!-~]+(?: [!-~]+)*$/';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: self::KEY,
            label: 'Remnawave',
            description: "پنل مدیریت \u{2068}Xray\u{2069} با نودهای جداگانه. کاربرها با اسکوادهای داخلی به اینباندها می‌رسند؛ اینجا هر اسکواد داخلی یک اینباند قابل فروش است و هر پلن می‌تواند چند اسکواد را با هم بدهد.",
            form: new Form(self::KEY, [
                // Latin words and paths are wrapped in Unicode isolates (U+2068…U+2069): in a right-to-left line «/api» reads «api/».
                PanelConnection::address(
                    hint: "آدرس دامنه پنل، همان که داشبورد \u{2068}Remnawave\u{2069} با آن باز می‌شود، بدون مسیر صفحه‌ها؛ بهتر است آدرس \u{2068}https\u{2069} ریورس‌پراکسی پنل باشد.",
                    placeholder: 'https://panel.example.com',
                    pages: '#/(api|auth|dashboard)(/|$)#i',
                    pagesRefusal: "فقط آدرس پایه پنل را وارد کنید؛ مسیرهایی مثل \u{2068}/dashboard\u{2069}، \u{2068}/auth\u{2069} یا \u{2068}/api\u{2069} را حذف کنید.",
                ),
                ...PanelCredentials::fields(
                    token: "توکن \u{2068}API\u{2069}",
                    tokenHint: "در «\u{2068}Remnawave Settings\u{2069} ← \u{2068}API Tokens\u{2069}» پنل توکنی با دسترسی‌های \u{2068}users:*\u{2069}، \u{2068}internal-squads:read\u{2069} و \u{2068}system:read\u{2069} بسازید (دسترسی * همه پنل را باز می‌کند)؛ پنل آن را فقط یک بار، همان لحظه ساخت نشان می‌دهد.",
                    tokenPattern: self::TOKEN_PATTERN,
                    tokenMismatch: "توکن \u{2068}API\u{2069} باید کامل باشد: سه بخش با نقطه از هم جدا، که با \u{2068}eyJ\u{2069} شروع می‌شود؛ پنل آن را فقط یک بار، همان لحظه ساخت نشان می‌دهد.",
                    loginHint: null,
                ),
                // A portal's key is a password of its own: its hint gives none of it away.
                new Secret(
                    self::GATE_KEY,
                    'password',
                    '',
                    pattern: self::GATE_PATTERN,
                    mismatch: 'کلید دروازه همان کلیدی است که دروازه ورود داده: حداکثر 512 کاراکتر، بدون کاراکترهای کنترلی و فاصله در ابتدا و انتها.',
                    hint: Secret::hidden(),
                    boundTo: PanelConnection::bound(),
                    moved: PanelConnection::MOVED . 'کلید دروازه را دوباره وارد کنید.',
                    spec: new FieldSpec(
                        'کلید دروازه',
                        hint: "فقط برای پنلی پشت دروازه ورود \u{2068}Caddy\u{2069} یا \u{2068}TinyAuth\u{2069}: کلید \u{2068}API\u{2069} همان دروازه (در \u{2068}TinyAuth\u{2069} مقدار \u{2068}Basic …\u{2069})، که با هر درخواست در هدر \u{2068}X-Api-Key\u{2069} فرستاده می‌شود. خالی = پنل بدون دروازه.",
                        advanced: true,
                    ),
                ),
                ...PanelConnection::options(
                    subscriptionHint: 'اگر لینک اشتراک باید از آدرس دیگری داده شود، پیشوند کامل تا پیش از شناسه کوتاه کاربر را بدهید؛ خالی = همان لینک پنل.',
                    subscriptionPlaceholder: 'https://sub.example.com',
                ),
            ]),
            notes: [
                'نسخه 2.1 و بالاتر پشتیبانی می‌شود، نسخه‌های 3 هم.',
                "توکن \u{2068}API\u{2069} را در «\u{2068}Remnawave Settings\u{2069} ← \u{2068}API Tokens\u{2069}» پنل بسازید، با دسترسی‌های \u{2068}users:*\u{2069}، \u{2068}internal-squads:read\u{2069} و \u{2068}system:read\u{2069} (دسترسی * هم کار می‌کند، ولی همه پنل را باز می‌کند)؛ ورود با نام کاربری و رمز ندارد. هر توکن تاریخ انقضا دارد: پیش از آن، توکن تازه‌ای بسازید و اینجا عوض کنید.",
                "\u{2068}Remnawave\u{2069} فقط به درخواستی جواب می‌دهد که از ریورس‌پراکسی \u{2068}HTTPS\u{2069} گذشته باشد؛ آدرس \u{2068}https\u{2069} دامنه پنل را بدهید. آدرس \u{2068}http\u{2069} پورت خود پنل فقط وقتی کار می‌کند که پنل روی همین سرور یا شبکه خصوصی آن باشد (فروشگاه هدرهای پراکسی را آن وقت خودش می‌فرستد)؛ از اینترنت، توکن و اطلاعات مشتری‌ها بی‌رمز فرستاده می‌شد.",
                "اگر جلوی پنل دروازه ورود \u{2068}Caddy\u{2069} یا \u{2068}TinyAuth\u{2069} است، کلید \u{2068}API\u{2069} همان دروازه را در «کلید دروازه» بدهید و آدرس را بدون مسیر مخفی صفحه ورود وارد کنید.",
                'اینباندهای این سرور اسکوادهای داخلی پنل هستند؛ اسکوادی که اینباند ندارد خاموش دیده می‌شود.',
                "\u{2068}Remnawave\u{2069} زمان از اولین اتصال ندارد: زمان سرویس از لحظه فروش شروع می‌شود.",
                "تعداد کاربر پلن و «کاربر اضافه» محدودیت دستگاه (\u{2068}HWID\u{2069}) سرویس در \u{2068}Remnawave\u{2069} است (صفر یعنی بدون محدودیت). این محدودیت فقط وقتی اعمال می‌شود که «\u{2068}HWID Device Limit\u{2069}» در تنظیمات اشتراک پنل روشن باشد، و فقط برنامه‌هایی را می‌شمارد که شناسه دستگاه می‌فرستند (مثل \u{2068}Happ\u{2069} و \u{2068}v2RayTun\u{2069})؛ با روشن بودن آن، برنامه‌ای که شناسه نمی‌فرستد اشتراک را نمی‌گیرد.",
            ],
            traits: [self::MARK => 'RW', self::VENDOR => 'remnawave', self::DOCS => 'https://github.com/remnawave/panel'] + $this->capabilities()->traits(),
        );
    }

    /**
     * Its internal squads are what a plan picks from (inbounds), several for one user; revoking a subscription gives a
     * new short id, link and credentials (rotation); its HWID device limit holds a user to the plan's devices (ipLimit)
     * while the panel's HWID limit is on — the apps that send a device id counted. Remnawave takes a username of 3 to 36
     * letters, digits, `_` and `-`, matched as it is written.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(inbounds: true, linkRotation: true, ipLimit: true, names: new ClientNameRule(min: 3, max: 36, characters: 'A-Za-z0-9_-'));
    }

    public function connect(Server $server, PanelHttp $http): ProviderInterface
    {
        $connection = PanelConnection::of($server);

        return new RemnawaveProvider(new RemnawaveClient($http, $connection, PanelCredentials::of($server), $server->password), $connection);
    }
}
