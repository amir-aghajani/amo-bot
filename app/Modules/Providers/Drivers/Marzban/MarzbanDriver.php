<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Marzban;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Providers\Contracts\PanelDriver;
use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientNameRule;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;

/**
 * The Marzban connector (0.8 and later). A server's connection is the panel's address (the dashboard's, without
 * /dashboard), an admin's username and password — the one way in: Marzban's API has no keys — and the shared options.
 * Its client is MarzbanProvider; the tokens it signs in for outlive a request (MarzbanTokens).
 */
final class MarzbanDriver implements PanelDriver
{
    private const KEY = 'marzban';

    private const LOGIN_HINT = 'نام کاربری و رمز یک ادمین پنل؛ همان که با آن وارد داشبورد می‌شوید. ادمین sudo همه کاربرهای پنل را می‌بیند، ادمین دیگر فقط کاربرهای خودش را.';

    public function __construct(private readonly MarzbanTokens $tokens) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: self::KEY,
            label: 'Marzban',
            description: 'پنل مدیریت Xray با نودهای جداگانه، از تیم Gozargah. کاربرها روی اینباندهای هسته Xray پنل ساخته می‌شوند؛ اینجا هر اینباند پنل یک اینباند قابل فروش است.',
            form: new Form(self::KEY, [
                // Paths are wrapped in Unicode isolates (U+2068…U+2069): in a right-to-left line «/dashboard» reads «dashboard/».
                PanelConnection::address(
                    hint: "همان آدرس داشبورد، بدون \u{2068}/dashboard\u{2069}.",
                    placeholder: 'https://panel.example.com:8000',
                    pages: '#/(dashboard|api|docs)(/|$)#i',
                    pagesRefusal: "فقط آدرس پایه پنل را وارد کنید؛ \u{2068}/dashboard\u{2069} یا \u{2068}/api\u{2069} را حذف کنید.",
                ),
                ...self::login(),
                ...PanelConnection::options(
                    subscriptionHint: 'اگر لینک اشتراک باید از آدرس دیگری داده شود، پیشوند کامل تا پیش از توکن را بدهید؛ خالی = از تنظیمات پنل.',
                    subscriptionPlaceholder: 'https://sub.example.com/sub',
                ),
            ]),
            notes: [
                'نسخه 0.8 و بالاتر پشتیبانی می‌شود.',
                'Marzban کلید API ندارد؛ با نام کاربری و رمز یک ادمین پنل وصل شوید، بهتر است ادمین sudo که در دیتابیس پنل ساخته شده (نه ادمین SUDO_USERNAME فایل env). ادمین غیر sudo فقط کاربرهای خودش را می‌بیند؛ برای انتقال سرویس‌ها از ربات دیگر، ادمین sudo بدهید.',
                "آدرس پنل همان آدرس داشبورد بدون \u{2068}/dashboard\u{2069} است، مثل https://panel.example.com:8000",
                'Marzban محدودیت IP ندارد؛ تعداد کاربر پلن روی این سرور اعمال نمی‌شود و کاربر اضافه فروخته نمی‌شود.',
                'Marzban هر ورود را به ادمین‌های تلگرام پنل گزارش می‌دهد؛ برای نگرفتن گزارش ورودهای فروشگاه، IP هاست فروشگاه را در LOGIN_NOTIFY_WHITE_LIST تنظیمات پنل بگذارید. ورود ناموفق همیشه گزارش می‌شود، همراه رمزی که فرستاده شده؛ NOTIFY_LOGIN=false همه این گزارش‌ها را خاموش می‌کند.',
            ],
            traits: [self::MARK => 'MZ', self::VENDOR => 'Gozargah', self::DOCS => 'https://github.com/Gozargah/Marzban'] + $this->capabilities()->traits(),
        );
    }

    /**
     * The inbounds of the panel's Xray config are what a plan picks from (inbounds); revoking a subscription gives new
     * secrets and a new link (rotation). No IP limit (MarzbanProvider): no extra devices are sold on it. Marzban takes a
     * username of 3 to 32 letters, digits and `_` — and `- . @` after three of those, which the shop's names do without:
     * the rule is that narrower one, so the panel never refuses a name the shop gives.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(inbounds: true, linkRotation: true, names: new ClientNameRule(min: 3, max: 32, characters: 'A-Za-z0-9_'));
    }

    public function connect(Server $server, PanelHttp $http): ProviderInterface
    {
        $connection = PanelConnection::of($server);

        // A server being tried before it is saved keeps its token for the client's life alone.
        $saved = $server->exists ? $server->id : null;

        return new MarzbanProvider(new MarzbanClient($http, $connection, PanelCredentials::of($server), $this->tokens, $saved), $connection);
    }

    /**
     * The way in: an admin's username and password, the one Marzban's API takes — the shared fields of the password way
     * (PanelCredentials::fields()), under a choice that offers it alone.
     *
     * @return list<Field<mixed>>
     */
    private static function login(): array
    {
        $shared = PanelCredentials::fields(token: 'توکن API', tokenHint: '', tokenPattern: '/^$/', tokenMismatch: '', loginHint: self::LOGIN_HINT);
        $password = AuthMode::Password->value;

        return [
            new Choice(PanelCredentials::MODE, PanelCredentials::MODE, $password, [$password], refusal: 'روش احراز هویت نامعتبر است.', spec: new FieldSpec('احراز هویت', options: [$password => 'نام کاربری و رمز'])),
            ...array_values(array_filter($shared, static fn(Field $field): bool => in_array($field->key, ['username', 'password'], true))),
        ];
    }
}
