<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Aban;

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
 * AbanGateway (abangateway.ir): card-to-card payments it confirms by itself from the bank's text message, the money
 * going straight to the merchant's own cards. Its settings: the merchant's API key (`ag_live_…`, or `ag_test_…` for its
 * test mode), kept encrypted, and whether the bot and the site show the card under the page.
 */
final class AbanDriver implements GatewayDriver
{
    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return AbanGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'آبان گیت وی',
            description: 'پرداخت کارت به کارت با تایید خودکار آبان گیت وی: مشتری مبلغ دقیقی را که صفحه آبان گیت وی نشان می‌دهد به کارت خود شما واریز می‌کند، آبان گیت وی آن را از پیامک بانک تایید می‌کند و سفارش بدون رسید و بدون بررسی شما تحویل می‌شود. کلید API را در پنل آبان گیت وی (تنظیمات، کلیدهای API) بسازید و همان لحظه کپی کنید.',
            form: new Form($this->key(), [
                new Secret(
                    'api_key',
                    'api_key',
                    '',
                    pattern: '/^ag_(live|test)_[a-z2-9]{48}$/D',
                    mismatch: 'کلید API آبان گیت وی با ag_live_ (یا ag_test_ برای حالت آزمایشی) شروع می‌شود و بعد از آن ۴۸ حرف و رقم دارد؛ پنل آن را فقط همان لحظه ساخت کامل نشان می‌دهد.',
                    missing: 'کلید API آبان گیت وی را وارد کنید.',
                    spec: new FieldSpec('کلید API', hint: 'کلیدی که پنل آبان گیت وی هنگام ساخت نشان می‌دهد؛ کلید ag_test_ حالت آزمایشی است. فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', placeholder: 'ag_live_…', ltr: true),
                ),
                new Toggle(
                    'show_card',
                    'show_card',
                    true,
                    spec: new FieldSpec('نمایش کارت در ربات و سایت', hint: 'شماره کارت، نام صاحب آن و مبلغ دقیق با دکمه‌های کپی زیر صفحه پرداخت هم نشان داده می‌شود تا مشتری بدون باز کردن صفحه آبان گیت وی واریز کند. کارت فقط برای مبلغ‌های تا ۱۰ میلیون تومان نشان داده می‌شود؛ مبلغ بالاتر فقط در صفحه آبان گیت وی پرداخت می‌شود، که مبلغ بالای سقف روزانه کارت به کارت (حدود ۱۵ میلیون تومان) را چندتکه می‌کند.'),
                ),
            ]),
            notes: [
                'آدرس سایت فروشگاه باید با https شروع شود و از اینترنت در دسترس باشد: آبان گیت وی نتیجه هر پرداخت را به آن خبر می‌دهد و مشتری را به آن برمی‌گرداند',
                'کارمزد هر پرداخت هنگام ساخت آن از کیف پول کارمزد حساب آبان گیت وی شما برداشته می‌شود؛ وقتی خالی شود پرداختی باز نمی‌شود',
                'هر صفحه پرداخت ۳۰ دقیقه مهلت دارد؛ بعد از آن مشتری پرداخت را دوباره شروع می‌کند',
                'با کلید ag_test_ پولی جابه‌جا نمی‌شود ولی پرداختی که در آبان گیت وی شبیه‌سازی شود سفارش را واقعا تحویل می‌دهد',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** «حالت آزمایشی» for a test key: no real money moves; none otherwise (the key no screen shows). */
    public function summary(array $settings): ?string
    {
        return is_string($settings['api_key'] ?? null) && str_starts_with($settings['api_key'], AbanGateway::TEST_PREFIX) ? 'حالت آزمایشی: پولی جابه‌جا نمی‌شود' : null;
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new AbanGateway(is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '', ($settings['show_card'] ?? true) !== false, $this->http);
    }
}
