<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\PasarGuard;

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
 * The PasarGuard connector (3.1 and later). A server's connection is the panel's address (the dashboard's, without
 * /dashboard), an API key (5.1 and later, kept in `api_token`) or an admin's username and password, and the shared
 * options. Its client is PasarGuardProvider.
 */
final class PasarGuardDriver implements PanelDriver
{
    private const KEY = 'pasarguard';

    /** `pg_key_` and a UUID — the whole key, shown once when it is made (the list shows it cut short). */
    private const KEY_PATTERN = '/^pg_key_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: self::KEY,
            label: 'PasarGuard',
            description: 'پنل مدیریت Xray و WireGuard با نودهای جداگانه، از نسل Marzban. کاربرها با گروه‌های پنل به اینباندها وصل می‌شوند؛ اینجا هر گروه پنل یک اینباند قابل فروش است.',
            form: new Form(self::KEY, [
                // Paths are wrapped in Unicode isolates (U+2068…U+2069): in a right-to-left line «/dashboard» reads «dashboard/».
                PanelConnection::address(
                    hint: "همان آدرس داشبورد، بدون \u{2068}/dashboard\u{2069}.",
                    placeholder: 'https://panel.example.com:8000',
                    pages: '#/(dashboard|api)(/|$)#i',
                    pagesRefusal: "فقط آدرس پایه پنل را وارد کنید؛ \u{2068}/dashboard\u{2069} یا \u{2068}/api\u{2069} را حذف کنید.",
                ),
                ...PanelCredentials::fields(
                    token: 'کلید API',
                    tokenHint: 'کلید را از بخش API Keys پنل بسازید (نسخه 5.1 به بالا)، بهتر است بدون تاریخ انقضا و سقف درخواست. پیشنهاد می‌شود.',
                    tokenPattern: self::KEY_PATTERN,
                    tokenMismatch: "کلید API باید کامل باشد و با \u{2068}pg_key_\u{2069} شروع شود؛ پنل آن را فقط یک بار، همان لحظه ساخت نشان می‌دهد.",
                    loginHint: 'نام کاربری و رمز یک ادمین پنل؛ همان که با آن وارد داشبورد می‌شوید.',
                ),
                ...PanelConnection::options(
                    subscriptionHint: 'اگر لینک اشتراک باید از آدرس دیگری داده شود، پیشوند کامل تا پیش از توکن را بدهید؛ خالی = از تنظیمات پنل.',
                    subscriptionPlaceholder: 'https://sub.example.com/sub',
                ),
            ]),
            notes: [
                'نسخه 3.1 و بالاتر پشتیبانی می‌شود.',
                'کلید API را از بخش API Keys پنل بسازید (پیشنهادی، از نسخه 5.1)؛ یا با نام کاربری و رمز یک ادمین وصل شوید.',
                "آدرس پنل همان آدرس داشبورد بدون \u{2068}/dashboard\u{2069} است، مثل https://panel.example.com:8000",
                'PasarGuard محدودیت IP ندارد؛ تعداد کاربر پلن روی این سرور اعمال نمی‌شود و کاربر اضافه فروخته نمی‌شود.',
                'ادمین غیر sudo (پیش از نسخه 5.0)، یا ادمینی با نقش operator یا هر نقشی که دسترسی «خودی» به کاربران دارد، فقط کاربرهای خودش را می‌بیند؛ برای انتقال سرویس‌ها از ربات دیگر، کلید یا ادمینی با دسترسی «همه» به کاربران بدهید (مثل نقش administrator)، وگرنه سرویس‌های ادمین‌های دیگر روی پنل پیدا نمی‌شوند.',
                'با نقشی که فقط فهرست ساده گروه‌ها را می‌بیند (مثل operator از نسخه 5.0)، خاموش بودن یک گروه در پنل اینجا دیده نمی‌شود و همه گروه‌ها روشن نشان داده می‌شوند؛ گروهی را که در پنل خاموش می‌کنید از پلن‌های این سرور هم بردارید، وگرنه سرویس روی آن بدون کانفیگ ساخته می‌شود.',
                'با نام کاربری و رمز، پنل هر ورود فروشگاه را به تلگرام یا دیسکورد خود گزارش می‌دهد (اگر اعلان ورود ادمین در تنظیمات اعلان‌های پنل روشن باشد) و ورود ناموفق را همراه رمزی که فرستاده شده؛ با کلید API ورودی گزارش نمی‌شود.',
            ],
            traits: [self::MARK => 'PG', self::VENDOR => 'PasarGuard', self::DOCS => 'https://github.com/PasarGuard/panel'] + $this->capabilities()->traits(),
        );
    }

    /**
     * Groups are what a plan picks from (inbounds); revoking a subscription gives new secrets and a new link
     * (rotation). No IP limit (PasarGuardProvider): no extra devices are sold on it. A username is 3 to 128 letters,
     * digits and `_ - . @`, never two of those side by side — the panel refuses any other.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(inbounds: true, linkRotation: true, names: new ClientNameRule(min: 3, max: 128, characters: 'A-Za-z0-9_.@-', separatorRuns: false));
    }

    public function connect(Server $server, PanelHttp $http): ProviderInterface
    {
        $connection = PanelConnection::of($server);

        return new PasarGuardProvider(new PasarGuardClient($http, $connection, PanelCredentials::of($server)), $connection);
    }
}
