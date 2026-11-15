<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Bluepal;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use App\Support\Persian;
use GuzzleHttp\ClientInterface;

/**
 * Bluepal (bluepal.ir): online payment with any Iranian bank card — the customer goes from Bluepal's page to the bank's,
 * enters the card there, and is sent back to the shop. Its settings: the gateway's merchant id (`merchant_id`) and the
 * API key, kept encrypted. Its sandbox is a switch on the gateway in Bluepal's dashboard, with a key of its own: the shop
 * cannot tell it from the key, so no summary says it.
 */
final class BluepalDriver implements GatewayDriver
{
    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return BluepalGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'بلو پال',
            description: 'پرداخت آنلاین با هر کارت بانکی از درگاه بلو پال: مشتری از صفحه بلو پال به صفحه بانک می‌رود و کارت را همان‌جا وارد می‌کند، به فروشگاه برمی‌گردد و سفارش پس از تایید بلو پال، بدون رسید و بدون بررسی شما تحویل می‌شود. مرچنت آیدی و کلید API درگاه را از داشبورد بلو پال بردارید.',
            form: new Form($this->key(), [
                new Text(
                    'merchant_id',
                    'merchant_id',
                    '',
                    label: 'مرچنت آیدی بلو پال',
                    max: 64,
                    required: true,
                    pattern: BluepalGateway::MERCHANT,
                    mismatch: 'مرچنت آیدی را همان‌طور که داشبورد بلو پال نشان می‌دهد کپی کنید؛ فقط حروف لاتین، رقم، خط تیره و زیرخط دارد.',
                    normalize: static fn(string $typed): string => trim(Persian::latinDigits($typed)),
                    spec: new FieldSpec('مرچنت آیدی (merchant_id)', hint: 'شناسه درگاه در داشبورد بلو پال.', ltr: true),
                ),
                new Secret(
                    'api_key',
                    'api_key',
                    '',
                    pattern: '/^[\x21-\x7e]{8,255}$/D',
                    mismatch: 'کلید API را همان‌طور که داشبورد بلو پال نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'کلید API بلو پال را وارد کنید.',
                    spec: new FieldSpec('کلید API', hint: 'کلید درگاه در داشبورد بلو پال (sk_live_…)؛ برای آزمودن، کلید حالت آزمایشی (sandbox) درگاه را بگذارید. فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
            ]),
            notes: [
                'آدرس بازگشت هر پرداخت را خود فروشگاه به بلو پال می‌دهد: چیزی برای کپی کردن در داشبورد بلو پال نیست و وب‌هوک هم لازم نیست، چون فروشگاه نتیجه هر پرداخت را خودش از بلو پال می‌پرسد',
                'اگر در داشبورد بلو پال «دامنه‌های مجاز» را پر کرده‌اید، دامنه آدرس سایت فروشگاه باید در آن باشد',
                'اگر لیست سفید IP را در بلو پال روشن کرده‌اید، IP خروجی سرور فروشگاه باید در آن باشد؛ آن را از پشتیبانی هاست بپرسید',
                'کمترین و بیشترین مبلغ هر پرداخت همان است که برای درگاه در داشبورد بلو پال گذاشته‌اید (کمترین پیش‌فرض ۱٬۰۰۰ تومان)',
                'صفحه پرداخت بلو پال ۱۰ دقیقه مهلت دارد؛ بعد از آن مشتری پرداخت را دوباره شروع می‌کند',
                'با کلید حالت آزمایشی (sandbox) پولی جابه‌جا نمی‌شود ولی پرداخت آزمایشی سفارش را واقعا تحویل می‌دهد',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** None: the merchant id is no one's to pay to, the key no screen shows, and the sandbox is Bluepal's own switch. */
    public function summary(array $settings): ?string
    {
        return null;
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new BluepalGateway(
            is_string($settings['merchant_id'] ?? null) ? $settings['merchant_id'] : '',
            is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '',
            $this->http,
        );
    }
}
