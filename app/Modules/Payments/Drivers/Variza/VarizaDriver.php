<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Variza;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Core\Http\Urls;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use App\Support\Persian;
use GuzzleHttp\ClientInterface;

/**
 * Variza (variza.ir): card-to-card payments it confirms by itself from the bank's text message (its phone app reads it),
 * to the merchant's own cards — or to Variza's, settled to a wallet and on in Tether. Its settings: the API key and the
 * webhook's signing secret, both kept encrypted, and the card a payment goes to (`card`: random, variza, or a card's last
 * four digits). It answers no question about a payment: its signed webhook, to the shop's one address for it, is its word.
 */
final class VarizaDriver implements GatewayDriver
{
    /** The Persian words an owner may type for the card, each the value Variza takes for it. */
    private const CARD_WORDS = ['تصادفی' => VarizaGateway::RANDOM, 'واریزا' => VarizaGateway::VARIZA];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly Urls $urls,
    ) {}

    public function key(): string
    {
        return VarizaGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'واریزا',
            description: 'پرداخت کارت به کارت با تایید خودکار واریزا: مشتری در صفحه واریزا مبلغ دقیقی را که نشان داده می‌شود به کارت شما (یا کارت‌های خود واریزا) واریز می‌کند، واریزا آن را از پیامک بانک تایید و با وب‌هوک امضاشده خبر می‌دهد و سفارش بدون رسید و بدون بررسی شما تحویل می‌شود. کلید API و کلید امضای وب‌هوک را از پروفایل پنل واریزا بردارید.',
            form: new Form($this->key(), [
                new Secret(
                    'api_key',
                    'api_key',
                    '',
                    pattern: '/^[\x21-\x7e]{16,255}$/D',
                    mismatch: 'کلید API را همان‌طور که پنل واریزا نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'کلید API واریزا را وارد کنید.',
                    spec: new FieldSpec('کلید API', hint: 'از پروفایل پنل واریزا. فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
                new Secret(
                    'webhook_secret',
                    'webhook_secret',
                    '',
                    pattern: '/^[\x21-\x7e]{8,255}$/D',
                    mismatch: 'کلید امضای وب‌هوک را همان‌طور که پنل واریزا نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'کلید امضای وب‌هوک واریزا را وارد کنید.',
                    spec: new FieldSpec('کلید امضای وب‌هوک (webhook_secret)', hint: 'کلیدی که واریزا هر وب‌هوک را با آن امضا می‌کند، از پروفایل پنل واریزا: فروشگاه فقط وب‌هوکی را باور می‌کند که با آن امضا شده باشد. رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
                new Text(
                    'card',
                    'card',
                    VarizaGateway::RANDOM,
                    label: 'کارت مقصد',
                    max: 6,
                    required: true,
                    pattern: '/^(?:random|variza|[0-9]{4})$/D',
                    mismatch: 'تصادفی، واریزا یا چهار رقم آخر یکی از کارت‌های ثبت‌شده در واریزا را بنویسید.',
                    // Kept as Variza names it: «تصادفی» is random, «واریزا» variza.
                    normalize: static fn(string $typed): string => self::CARD_WORDS[trim($typed)] ?? strtolower(trim(Persian::latinDigits($typed))),
                    spec: new FieldSpec('کارت مقصد', hint: '«تصادفی» (random): از میان کارت‌های فعال شما، کارتی که امروز کمترین تراکنش را داشته (نیازمند اشتراک «کارت تصادفی» و دست‌کم ۲ کارت)؛ «واریزا» (variza): کارت‌های خود واریزا با تسویه به کیف پول و تتر (نیازمند اشتراک «کارت‌های واریزا»، هر لینک تا ۲ میلیون تومان)؛ یا چهار رقم آخر یکی از کارت‌های ثبت‌شده شما.', placeholder: 'تصادفی'),
                ),
            ]),
            notes: [
                'در پروفایل پنل واریزا، آدرس callback_url را ' . $this->urls->paymentWebhook($this->key()) . ' بگذارید: واریزا هر پرداخت را با وب‌هوک امضاشده به همین آدرس خبر می‌دهد و فروشگاه جز همین خبر راهی برای پرسیدن ندارد',
                'آدرس سایت فروشگاه باید با https شروع شود: مشتری پس از پرداخت به آن برمی‌گردد',
                'تایید خودکار به نرم‌افزار اندروید یا شرتکات آیفون واریزا روی گوشی شما نیاز دارد که پیامک بانک را می‌خواند',
                'هر صفحه پرداخت ۳۰ دقیقه مهلت دارد؛ کمترین مبلغ هر پرداخت ۱٬۰۰۰ تومان است',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** The card its payments go to, in a word. */
    public function summary(array $settings): string
    {
        $card = is_string($settings['card'] ?? null) ? $settings['card'] : VarizaGateway::RANDOM;

        return match ($card) {
            VarizaGateway::RANDOM => 'کارت مقصد: تصادفی',
            VarizaGateway::VARIZA => 'کارت مقصد: کارت‌های واریزا (تسویه تتر)',
            default => 'کارت مقصد: •••• ' . $card,
        };
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new VarizaGateway(
            is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '',
            is_string($settings['webhook_secret'] ?? null) ? $settings['webhook_secret'] : '',
            is_string($settings['card'] ?? null) && $settings['card'] !== '' ? $settings['card'] : VarizaGateway::RANDOM,
            $this->http,
        );
    }
}
