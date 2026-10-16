<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Zarinpal;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use GuzzleHttp\ClientInterface;

/**
 * Zarinpal (zarinpal.com): Iran's online card payment — the customer pays with any bank card on Zarinpal's page, and is
 * sent back to the shop. Its settings: the merchant's 36-character code (`merchant_id`), kept encrypted, and the sandbox
 * switch — Zarinpal's test service, which takes any code and no real money.
 */
final class ZarinpalDriver implements GatewayDriver
{
    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return ZarinpalGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'زرین‌پال',
            description: 'پرداخت آنلاین با هر کارت بانکی در درگاه زرین‌پال: مشتری در صفحه زرین‌پال پرداخت می‌کند، به فروشگاه برمی‌گردد و سفارش بدون رسید و بدون بررسی شما تحویل می‌شود. مرچنت کد را از پنل زرین‌پال (بخش درگاه پرداخت) بردارید؛ دامنه‌ای که برای درگاه ثبت کرده‌اید باید همان دامنه آدرس سایت فروشگاه باشد.',
            form: new Form($this->key(), [
                new Secret(
                    'merchant_id',
                    'merchant_id',
                    '',
                    pattern: '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D',
                    mismatch: 'مرچنت کد ۳۶ کاراکتر است، مثل xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx؛ آن را همان‌طور که پنل زرین‌پال نشان می‌دهد کپی کنید.',
                    missing: 'مرچنت کد زرین‌پال را وارد کنید.',
                    spec: new FieldSpec('مرچنت کد', hint: 'کد ۳۶ کاراکتری درگاه در پنل زرین‌پال. فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', placeholder: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', ltr: true),
                ),
                new Toggle(
                    'sandbox',
                    'sandbox',
                    false,
                    spec: new FieldSpec('حالت آزمایشی (sandbox)', hint: 'سرویس تست زرین‌پال: هر کدی به شکل مرچنت کد پذیرفته می‌شود و پولی جابه‌جا نمی‌شود، ولی پرداخت آزمایشی سفارش را واقعا تحویل می‌دهد. فقط برای آزمودن روشنش کنید.'),
                ),
            ]),
            notes: [
                'کارمزد هر پرداخت را زرین‌پال برمی‌دارد؛ اگر در پنل زرین‌پال کارمزد را بر عهده خریدار گذاشته باشید، مشتری آن را جدا می‌بیند',
                'هر صفحه پرداخت ۱۵ دقیقه نگه داشته می‌شود؛ بعد از آن مشتری پرداخت را دوباره شروع می‌کند',
                'کمترین مبلغ هر پرداخت ۱۰۰ تومان و بیشترین ۱۰۰ میلیون تومان است',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** «حالت آزمایشی» while the sandbox is on: no real money moves; none otherwise (the code no screen shows). */
    public function summary(array $settings): ?string
    {
        return ($settings['sandbox'] ?? false) === true ? 'حالت آزمایشی: پولی جابه‌جا نمی‌شود' : null;
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new ZarinpalGateway(is_string($settings['merchant_id'] ?? null) ? $settings['merchant_id'] : '', ($settings['sandbox'] ?? false) === true, $this->http);
    }
}
