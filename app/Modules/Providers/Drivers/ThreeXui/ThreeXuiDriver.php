<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Providers\Contracts\PanelDriver;
use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\Forms\TotpSecret;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;

/**
 * The 3x-ui connector (MHSanaei's panel, v3 and later — older versions have another API). A server's connection is the
 * panel's address with its web base path, an API token or an admin's username and password (plus the TOTP secret when
 * the panel's two-factor login is on), and the shared options. Its client is ThreeXuiProvider.
 *
 * Naming: the product's words say «3X-UI»; code, keys and files say 3x-ui (key `3x-ui`, namespace ThreeXui).
 */
final class ThreeXuiDriver implements PanelDriver
{
    private const KEY = '3x-ui';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: self::KEY,
            label: '3X-UI',
            description: 'پنل مدیریت Xray با پشتیبانی از VLESS، VMess، Trojan، Shadowsocks و WireGuard. اتصال از طریق API پنل انجام می‌شود.',
            form: new Form(self::KEY, [
                // Paths are wrapped in Unicode isolates (U+2068…U+2069): in a right-to-left line «/panel» reads «panel/».
                // Refused: the panel's pages after the web base path — its dashboard (/panel and below) and its sign-in
                // (/login). A web base path that is itself «/panel» (the address's only segment) is allowed.
                PanelConnection::address(
                    hint: "با مسیر پایه وب (web base path)؛ بدون \u{2068}/panel\u{2069} یا \u{2068}/login\u{2069} بعد از آن.",
                    placeholder: 'https://host:2053/AbCdEf',
                    pages: '#/panel/|/[^/]+/panel$|/login$#',
                    pagesRefusal: "فقط آدرس پایه پنل را وارد کنید؛ \u{2068}/panel\u{2069} یا \u{2068}/login\u{2069} بعد از مسیر پایه را حذف کنید.",
                ),
                ...PanelCredentials::fields(
                    token: 'توکن API',
                    tokenHint: 'توکن را از Settings → Security → API Token در پنل بسازید. پیشنهاد می‌شود.',
                    tokenPattern: '/^[^\s\x00-\x1f\x7f]{1,1024}$/u',
                    tokenMismatch: 'توکن API را همان‌طور که پنل نشان می‌دهد کپی کنید؛ بدون فاصله.',
                    loginHint: 'همان نام کاربری و رمزی که با آن وارد پنل می‌شوید.',
                ),
                new TotpSecret(
                    'totp_secret',
                    'totp_secret',
                    boundTo: PanelConnection::bound(),
                    moved: PanelConnection::MOVED . 'کلید TOTP را دوباره وارد کنید یا پاکش کنید.',
                    spec: new FieldSpec(
                        'کلید TOTP',
                        hint: 'فقط اگر ورود دومرحله‌ای پنل روشن است: کلید Base32 که هنگام روشن کردنش نشان داده شد.',
                        placeholder: 'JBSW Y3DP EHPK 3PXP',
                        when: PanelCredentials::WITH_PASSWORD,
                    ),
                ),
                ...PanelConnection::options(
                    subscriptionHint: 'اگر لینک اشتراک پشت ریورس‌پراکسی است، پیشوند کامل را بدهید؛ خالی = از تنظیمات پنل.',
                    subscriptionPlaceholder: 'https://sub.example.com/sub',
                ),
            ]),
            notes: [
                'فقط نسخه 3 و بالاتر پشتیبانی می‌شود؛ نسخه‌های قدیمی‌تر API متفاوتی دارند.',
                'توکن API را از مسیر Settings → Security → API Token در پنل بسازید (پیشنهادی).',
                'آدرس پنل باید شامل مسیر پایه وب (web base path) باشد، مثل https://host:2053/AbCdEf',
            ],
            traits: [self::MARK => '3X', self::VENDOR => 'MHSanaei', self::DOCS => 'https://github.com/MHSanaei/3x-ui'] + $this->capabilities()->traits(),
        );
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(inbounds: true, linkRotation: true, ipLimit: true);
    }

    public function connect(Server $server, PanelHttp $http): ProviderInterface
    {
        $connection = PanelConnection::of($server);

        return new ThreeXuiProvider(new ThreeXuiApi(new ThreeXuiClient($http, $connection, PanelCredentials::of($server), $server->totp_secret)), $connection);
    }
}
