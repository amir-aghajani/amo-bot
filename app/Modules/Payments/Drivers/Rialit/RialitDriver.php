<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Rialit;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Core\Http\Urls;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use GuzzleHttp\ClientInterface;

/**
 * Rialit (rialit.io): online payments it confirms by itself — the customer pays on Rialit's page, any fee the merchant
 * passes on to the payer in Rialit's dashboard added there and shown in the bot. Its one setting: the merchant's API key,
 * kept encrypted. Its webhook, to the shop's one address for it — which the shop registers with Rialit itself when the
 * method is saved and the merchant's account has none (Services\GatewayWebhooks: another address is replaced only when
 * the owner says so), or the merchant pastes in Rialit's dashboard —, only names a payment for the shop to ask about.
 */
final class RialitDriver implements GatewayDriver
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly Urls $urls,
    ) {}

    public function key(): string
    {
        return RialitGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'ریالیت',
            description: 'پرداخت آنلاین با تایید خودکار ریالیت: مشتری در صفحه ریالیت پرداخت می‌کند و پرداخت بدون رسید و بدون بررسی شما تایید و سفارش تحویل می‌شود. کلید API را از داشبورد ریالیت بردارید؛ با ذخیره روش، فروشگاه آدرس وب‌هوک خودش را در ریالیت ثبت می‌کند.',
            form: new Form($this->key(), [
                new Secret(
                    'api_key',
                    'api_key',
                    '',
                    pattern: '/^[\x21-\x7e]{8,255}$/D',
                    mismatch: 'کلید API را همان‌طور که داشبورد ریالیت نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'کلید API ریالیت را وارد کنید.',
                    spec: new FieldSpec('کلید API', hint: 'از داشبورد ریالیت. فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
            ]),
            notes: [
                'آدرس وب‌هوک فروشگاه ' . $this->urls->paymentWebhook($this->key()) . ' است و وقتی روش را ذخیره می‌کنید، اگر حساب ریالیت آدرسی نداشته باشد، فروشگاه خودش آن را در ریالیت ثبت می‌کند (ریالیت این تغییر را به صاحب حساب در تلگرام و ایمیل خبر می‌دهد). ریالیت هر پرداخت را به همین آدرس خبر می‌دهد و فروشگاه با هر خبر وضعیت پرداخت را از خود ریالیت می‌پرسد',
                'اگر وب‌هوک حساب ریالیت به آدرس دیگری برود (مثلا همین حساب برای سایت دیگری هم هست)، فروشگاه آن را تغییر نمی‌دهد: کارت همین روش این را می‌گوید و جایگزین کردن آن با آدرس فروشگاه را پیشنهاد می‌کند. گذاشتن همین آدرس در بخش Webhook داشبورد ریالیت هم مثل قبل کار می‌کند',
                'اگر در داشبورد ریالیت IPهای مجاز گذاشته‌اید، IP خروجی سرور فروشگاه باید میان آن‌ها باشد، وگرنه ریالیت هیچ پرداختی باز نمی‌کند؛ آن را از پشتیبانی هاست بپرسید',
                'کارمزد مشتری را در داشبورد ریالیت تعیین می‌کنید: مبلغی که مشتری می‌پردازد همراه کارمزد در ربات و سایت نشان داده می‌شود و سفارش با همان قیمت خودش تحویل و کیف پول با همان مبلغ شارژ می‌شود',
                'هر صفحه پرداخت ۳۰ دقیقه نگه داشته می‌شود؛ بعد از آن مشتری پرداخت را دوباره شروع می‌کند و پرداختی که ریالیت دیرتر تایید کند هم پذیرفته می‌شود',
                'کمترین مبلغ هر پرداخت ۵۰٬۰۰۰ تومان و بیشترین ۱۰ میلیون تومان است؛ کمترین و بیشترین مبلغ همین روش را هم همین‌طور بگذارید',
                'ریالیت پرداخت‌کننده را با حساب تلگرامش می‌شناسد: این روش به مشتری‌ای که حساب تلگرام ندارد (مثلا با پیامک وارد سایت شده) نشان داده نمی‌شود و او با روش دیگری پرداخت می‌کند',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** None: its only setting is the key, which no screen shows. */
    public function summary(array $settings): ?string
    {
        return null;
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new RialitGateway(is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '', $this->http);
    }
}
