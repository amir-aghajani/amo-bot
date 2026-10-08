<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\PasarGuard;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Providers\Contracts\PanelDriver;
use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;

/**
 * PasarGuard panels, 3.1 and later (the users API this connector speaks): the server's connection is the panel's address
 * — the dashboard's, without /dashboard —, an API key (5.1 and later; `api_token`) or an admin's username and password,
 * and the options every connection takes; its client is PasarGuardProvider.
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
                // The panel's paths set apart (U+2068…U+2069, isolates): in a right-to-left line «/dashboard» reads «dashboard/».
                PanelConnection::address(
                    hint: "همان آدرس داشبورد، بدون \u{2068}/dashboard\u{2069}.",
                    placeholder: 'https://panel.example.com:8000',
                    pages: '#/(dashboard|api)(/|$)#i',
                    pagesRefusal: "فقط آدرس پایه پنل را وارد کنید؛ \u{2068}/dashboard\u{2069} یا \u{2068}/api\u{2069} را حذف کنید.",
                ),
                ...PanelCredentials::fields(
                    token: 'کلید API',
                    tokenHint: 'کلید را از بخش API Keys پنل بسازید (نسخه 5.1 به بالا). پیشنهاد می‌شود.',
                    tokenPattern: self::KEY_PATTERN,
                    tokenMismatch: 'کلید API باید کامل باشد و با pg_key_ شروع شود؛ پنل آن را فقط یک بار، همان لحظه ساخت نشان می‌دهد.',
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
                'آدرس پنل همان آدرس داشبورد بدون /dashboard است، مثل https://panel.example.com:8000',
                'PasarGuard محدودیت IP ندارد؛ تعداد دستگاه پلن روی این سرور اعمال نمی‌شود.',
            ],
            traits: [self::MARK => 'PG', self::VENDOR => 'PasarGuard', self::DOCS => 'https://github.com/PasarGuard/panel'] + $this->capabilities()->traits(),
        );
    }

    /** Groups are what a plan picks from; a revoked subscription gives the user new secrets and a new link. */
    public function capabilities(): Capabilities
    {
        return new Capabilities(inbounds: true, linkRotation: true);
    }

    public function connect(Server $server, PanelHttp $http): ProviderInterface
    {
        $connection = PanelConnection::of($server);

        return new PasarGuardProvider(new PasarGuardClient($http, $connection, PanelCredentials::of($server)), $connection);
    }
}
