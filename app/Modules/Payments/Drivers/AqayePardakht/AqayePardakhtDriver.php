<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\AqayePardakht;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use GuzzleHttp\ClientInterface;

/**
 * AqayePardakht (aqayepardakht.ir): online payment with any Iranian bank card through Shaparak — the customer pays on the
 * bank's page and is sent back to the shop. Its one setting: the gateway's pin (`pin`), kept encrypted — `sandbox` its
 * test service, which moves no money.
 */
final class AqayePardakhtDriver implements GatewayDriver
{
    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return AqayePardakhtGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'آقای پرداخت',
            description: 'پرداخت آنلاین با هر کارت بانکی از درگاه شاپرکی آقای پرداخت: مشتری در صفحه بانک پرداخت می‌کند، به فروشگاه برمی‌گردد و سفارش بدون رسید و بدون بررسی شما تحویل می‌شود. کد پین درگاه را از پنل آقای پرداخت بردارید؛ دامنه‌ای که درگاه برای آن تایید شده باید همان دامنه آدرس سایت فروشگاه باشد.',
            form: new Form($this->key(), [
                new Secret(
                    'pin',
                    'pin',
                    '',
                    pattern: '/^[A-Za-z0-9_-]{4,128}$/D',
                    mismatch: 'کد پین درگاه را همان‌طور که پنل آقای پرداخت نشان می‌دهد کپی کنید؛ فقط حروف لاتین و رقم دارد.',
                    missing: 'کد پین درگاه آقای پرداخت را وارد کنید.',
                    spec: new FieldSpec('کد پین درگاه', hint: 'کد پین درگاه در پنل آقای پرداخت؛ برای آزمودن، sandbox بنویسید (پولی جابه‌جا نمی‌شود ولی پرداخت آزمایشی سفارش را واقعا تحویل می‌دهد). فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
            ]),
            notes: [
                'آدرس سایت فروشگاه باید روی همان دامنه‌ای باشد که درگاه برای آن تایید شده است: مشتری به آن برمی‌گردد و صفحه بانک فقط از آن باز می‌شود',
                'مشتری از صفحه کوتاهی روی آدرس فروشگاه با دکمه «ورود به درگاه پرداخت» به بانک می‌رود؛ شاپرک صفحه بانک را فقط از دامنه خود درگاه باز می‌کند',
                'هر صفحه پرداخت ۲۰ دقیقه نگه داشته می‌شود؛ بعد از آن مشتری پرداخت را دوباره شروع می‌کند',
                'کمترین مبلغ هر پرداخت ۱٬۰۰۰ تومان و بیشترین ۴۰۰ میلیون تومان است',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** «حالت آزمایشی» for the sandbox's pin: no real money moves; none otherwise (the pin no screen shows). */
    public function summary(array $settings): ?string
    {
        return ($settings['pin'] ?? null) === AqayePardakhtGateway::SANDBOX_PIN ? self::TEST_MODE : null;
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new AqayePardakhtGateway(is_string($settings['pin'] ?? null) ? $settings['pin'] : '', $this->http);
    }
}
