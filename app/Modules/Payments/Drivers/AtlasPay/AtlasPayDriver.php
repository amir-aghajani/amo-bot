<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\AtlasPay;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Core\Security\Encrypter;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use GuzzleHttp\ClientInterface;

/**
 * AtlasPay (atlaspay.space): card-to-card payments its service confirms by itself from the bank's text message, paid in
 * its Telegram mini app — the amount, the card, a receipt when the text does not come —, the merchant settled in TRX.
 * Its one setting: the merchant's API key, kept encrypted.
 */
final class AtlasPayDriver implements GatewayDriver
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly Encrypter $encrypter,
    ) {}

    public function key(): string
    {
        return AtlasPayGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'اطلس‌پی',
            description: 'پرداخت کارت به کارت با تایید خودکار اطلس‌پی: مشتری در مینی‌اپ اطلس‌پی داخل تلگرام مبلغ دقیق را به کارتی که می‌بیند واریز می‌کند، اطلس‌پی آن را از پیامک بانک تایید می‌کند و سفارش بدون رسید و بدون بررسی شما تحویل می‌شود. کلید API را از ربات اطلس‌پی بگیرید.',
            form: new Form($this->key(), [
                new Secret(
                    'api_key',
                    'api_key',
                    '',
                    pattern: '/^[\x21-\x7e]{8,255}$/',
                    mismatch: 'کلید API را همان‌طور که اطلس‌پی داده است کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'کلید API اطلس‌پی را وارد کنید.',
                    spec: new FieldSpec('کلید API', hint: 'کلید اختصاصی فروشگاه شما در اطلس‌پی. فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
            ]),
            notes: [
                'پرداخت در مینی‌اپ اطلس‌پی داخل تلگرام انجام می‌شود: مشتری سایت هم برای پرداخت به تلگرام نیاز دارد',
                'هر صفحه پرداخت ۲۰ دقیقه مهلت دارد؛ بعد از آن مشتری پرداخت را دوباره شروع می‌کند',
                'پرداختی که مشتری کمتر از مبلغ واریز کرده و همان را پذیرفته، تحویل نمی‌شود و برای بررسی پشتیبانی نگه داشته می‌شود',
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
        return new AtlasPayGateway(is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '', $this->http, $this->encrypter);
    }
}
