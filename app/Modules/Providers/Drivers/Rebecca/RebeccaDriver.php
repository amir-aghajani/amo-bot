<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Rebecca;

use App\Core\Drivers\Descriptor;
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
 * The Rebecca connector (1.0 and later: the Go panel, Marzban's descendant, with personal API keys). A server's connection
 * is the panel's address (the dashboard's, without /dashboard), an admin's `rk_…` API key — the way Rebecca asks bots in;
 * no admin's password is ever typed here — and the shared options. Its client is RebeccaProvider.
 */
final class RebeccaDriver implements PanelDriver
{
    private const KEY = 'rebecca';

    /** `rk_` and 32 random bytes in base64url (43 characters) — the whole key, shown once when it is made. */
    private const KEY_PATTERN = '/^rk_[A-Za-z0-9_-]{43}$/';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: self::KEY,
            label: 'Rebecca',
            description: 'پنل مدیریت Xray با نودهای جداگانه، از نسل Marzban. هر کاربر در یک سرویس پنل است و هاست‌های همان سرویس را می‌گیرد؛ اینجا هر سرویس پنل یک اینباند قابل فروش است.',
            form: new Form(self::KEY, [
                // Paths are wrapped in Unicode isolates (U+2068…U+2069): in a right-to-left line «/dashboard» reads «dashboard/».
                PanelConnection::address(
                    hint: "همان آدرس داشبورد، بدون \u{2068}/dashboard\u{2069}.",
                    placeholder: 'https://panel.example.com:8000',
                    pages: '#/(dashboard|api|docs)(/|$)#i',
                    pagesRefusal: "فقط آدرس پایه پنل را وارد کنید؛ \u{2068}/dashboard\u{2069} یا \u{2068}/api\u{2069} را حذف کنید.",
                ),
                ...PanelCredentials::fields(
                    token: 'کلید API',
                    tokenHint: 'در «حساب من ← کلیدهای API» پنل کلیدی بسازید (این بخش برای ادمینی دیده می‌شود که «مدیریت کلیدهای API خود» را دارد)؛ پنل آن را فقط یک بار، همان لحظه ساخت نشان می‌دهد. کلید همه دسترسی‌ها و سرویس‌های ادمینش را دارد.',
                    tokenPattern: self::KEY_PATTERN,
                    tokenMismatch: "کلید API باید کامل باشد و با \u{2068}rk_\u{2069} شروع شود؛ پنل آن را فقط یک بار، همان لحظه ساخت نشان می‌دهد.",
                    loginHint: null,
                ),
                ...PanelConnection::options(
                    subscriptionHint: 'اگر لینک اشتراک باید از آدرس دیگری داده شود، پیشوند کامل تا پیش از توکن را بدهید؛ خالی = از تنظیمات پنل.',
                    subscriptionPlaceholder: 'https://sub.example.com/sub',
                ),
            ]),
            notes: [
                'نسخه 1.0 و بالاتر پشتیبانی می‌شود (پنل Go با کلید API).',
                'کلید API را در «حساب من ← کلیدهای API» پنل بسازید؛ ورود با نام کاربری و رمز ندارد. کلید یک ادمین full access همه‌چیز را دارد؛ ادمین دیگر باید دسترسی ساخت، حذف، ریست مصرف و تغییر لینک کاربرها، ساخت در حالت انتظار، حجم و زمان نامحدود و محدودیت IP داشته باشد، و فقط کاربرهای خودش را می‌بیند.',
                'هر کاربر Rebecca در یک سرویس است، پس روی این سرور هر پلن فقط یک سرویس انتخاب می‌کند. پلنی که کل سرور را می‌فروشد، اکانت تست و سرویس دلخواه همه سرویس‌هایی را می‌گیرند که برای فروش علامت خورده‌اند: برای آن‌ها فقط یک سرویس را برای فروش علامت بزنید. سرویسی که هاست فعال ندارد کاربر نمی‌گیرد.',
                'اگر برای ادمین کلید «حجم ساخته‌شده» با پنهان بودن مصرف کاربرها تنظیم شده، مصرف کاربرها صفر دیده می‌شود؛ کلید ادمینی بدهید که مصرف کاربرها را می‌بیند.',
                'افزودن سرویس با لینک کانفیگ فقط با کانفیگ VLESS یا VMess کار می‌کند؛ برای بقیه لینک اشتراک را بدهید.',
            ],
            traits: [self::MARK => 'RB', self::VENDOR => 'rebeccapanel', self::DOCS => 'https://github.com/rebeccapanel/Rebecca'] + $this->capabilities()->traits(),
        );
    }

    /**
     * Its services are what a plan picks from (inbounds); revoking a subscription gives a new credential key and link
     * (rotation); its nodes hold a user to its device limit (IP limit). Rebecca takes a username of 3 to 32 letters,
     * digits and `_ . @ -`, matched whatever its case.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(inbounds: true, linkRotation: true, ipLimit: true, names: new ClientNameRule(min: 3, max: 32, characters: 'A-Za-z0-9_.@-'), oneInbound: true);
    }

    public function connect(Server $server, PanelHttp $http): ProviderInterface
    {
        $connection = PanelConnection::of($server);

        return new RebeccaProvider(new RebeccaClient($http, $connection, PanelCredentials::of($server)), $connection);
    }
}
