<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\HmPanel;

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
 * The HMPanel («HMray Panel») connector (2.5 and later): a panel that manages several 3x-ui panels beneath it. A
 * server's connection is the panel's address (its sign-in page's, without the path of a page), an `hmp_…` API key —
 * the one way in: HMPanel's key API takes no admin's password — and the shared options. Its client is HmPanelProvider.
 */
final class HmPanelDriver implements PanelDriver
{
    private const KEY = 'hmpanel';

    /** `hmp_` and the key's random part in base64url — the whole key, shown once when it is made (the list shows its first 12 characters). */
    private const KEY_PATTERN = '/^hmp_[A-Za-z0-9_-]{16,256}$/';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: self::KEY,
            label: 'HMPanel (HMray)',
            description: 'پنل مدیریت و نمایندگی که چند پنل 3X-UI را از یک جا اداره می‌کند. فروشگاه با کلید API آن روی اینباندهای پنل‌های 3X-UI زیرمجموعه‌اش کاربر می‌سازد؛ هر کاربر روی یک پنل.',
            form: new Form(self::KEY, [
                // Paths are wrapped in Unicode isolates (U+2068…U+2069): in a right-to-left line «/dashboard» reads «dashboard/».
                PanelConnection::address(
                    hint: 'همان آدرسی که صفحه ورود HMPanel با آن باز می‌شود، بدون مسیر صفحه‌ها.',
                    placeholder: 'https://panel.example.com',
                    pages: '#/(api|api-keys|clients|dashboard|login|settings|s)(/|$)#i',
                    pagesRefusal: "فقط آدرس پایه پنل را وارد کنید؛ مسیرهایی مثل \u{2068}/dashboard\u{2069} یا \u{2068}/api\u{2069} را حذف کنید.",
                ),
                ...PanelCredentials::fields(
                    token: 'کلید API',
                    tokenHint: 'در «تنظیمات ← API» پنل کلیدی با دسترسی * بسازید (یا با clients.read، clients.write، clients.renew و inbounds.read). پنل کلید را فقط یک بار نشان می‌دهد.',
                    tokenPattern: self::KEY_PATTERN,
                    tokenMismatch: 'کلید API باید کامل باشد و با hmp_ شروع شود؛ پنل آن را فقط یک بار، همان لحظه ساخت نشان می‌دهد.',
                    loginHint: null,
                ),
                ...PanelConnection::options(
                    subscriptionHint: 'اگر لینک اشتراک باید از آدرس دیگری داده شود، پیشوند کامل تا پیش از توکن را بدهید؛ خالی = همان لینک پنل.',
                    subscriptionPlaceholder: 'https://sub.example.com/s',
                ),
            ]),
            notes: [
                'نسخه 2.5 و بالاتر پشتیبانی می‌شود.',
                'کلید API را در «تنظیمات ← API» پنل بسازید، با دسترسی * (یا clients.read، clients.write، clients.renew و inbounds.read)؛ ورود با نام کاربری و رمز ندارد.',
                'فقط اینباندهای پنل‌های 3X-UI زیرمجموعه HMPanel فروخته می‌شوند. هر کاربر روی یک پنل ساخته می‌شود، پس اینباندهای یک پلن روی این سرور باید از یک پنل باشند.',
                'HMPanel به هر کلید API به‌طور پیش‌فرض 60 درخواست در دقیقه جواب می‌دهد؛ برای فروشگاه پرکار کلیدی با سقف بالاتر بسازید.',
            ],
            traits: [self::MARK => 'HM', self::VENDOR => 'neoauroraproject', self::DOCS => 'https://github.com/neoauroraproject/hmpanel/blob/main/docs/admin-api.md'] + $this->capabilities()->traits(),
        );
    }

    /**
     * The 3x-ui inbounds beneath it are what a plan picks from (inbounds); a client's device limit is 3x-ui's (IP limit).
     * Its key API renews no credentials (no rotation). A name is 2 to 64 letters, digits and `@ . _ + -` — the panel
     * refuses any other.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(inbounds: true, ipLimit: true, names: new ClientNameRule(min: 2, max: 64, characters: 'A-Za-z0-9@._+-'));
    }

    public function connect(Server $server, PanelHttp $http): ProviderInterface
    {
        $connection = PanelConnection::of($server);

        return new HmPanelProvider(new HmPanelClient($http, $connection, PanelCredentials::of($server)), $connection);
    }
}
