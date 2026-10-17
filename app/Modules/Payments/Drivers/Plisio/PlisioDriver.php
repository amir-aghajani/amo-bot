<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Plisio;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use App\Support\Money;
use GuzzleHttp\ClientInterface;

/**
 * Plisio (plisio.net): crypto payments — the customer picks a coin on Plisio's page and sends it, and Plisio confirms it
 * on its chain. Plisio prices an invoice in a fiat currency; the shop's are Toman, which Plisio's own rates do not know at
 * the market's price, so the owner sets how many Toman a dollar is (`usd_rate`) and an order is asked of Plisio in
 * dollars. Its settings: that rate, the merchant's secret key (kept encrypted) and, optionally, the coins offered.
 */
final class PlisioDriver implements GatewayDriver
{
    /** The fewest and most Toman a dollar may be set to: a rate outside them is a mistyped one. */
    public const RATE_MIN = 1_000;
    public const RATE_MAX = 100_000_000;

    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return PlisioGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'Plisio',
            description: 'پرداخت با رمزارز در درگاه Plisio: مشتری در صفحه Plisio رمزارز دلخواهش (مثل تتر، ترون یا بیت‌کوین) را انتخاب و واریز می‌کند و پس از تایید در شبکه، سفارش بدون رسید و بدون بررسی شما تحویل می‌شود. مبلغ هر سفارش با نرخ دلاری که اینجا می‌نویسید به دلار تبدیل می‌شود. کلید مخفی را در پنل Plisio (API › API settings) بردارید.',
            form: new Form($this->key(), [
                new Secret(
                    'api_key',
                    'api_key',
                    '',
                    pattern: '/^[\x21-\x7e]{16,255}$/D',
                    mismatch: 'کلید مخفی را همان‌طور که پنل Plisio نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'کلید مخفی Plisio را وارد کنید.',
                    spec: new FieldSpec('کلید مخفی (Secret key)', hint: 'از پنل Plisio، بخش API › API settings. فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
                new Number(
                    'usd_rate',
                    'usd_rate',
                    0,
                    label: 'نرخ هر دلار',
                    min: self::RATE_MIN,
                    max: self::RATE_MAX,
                    unit: 'تومان',
                    spec: new FieldSpec('نرخ هر دلار', hint: 'هر دلار آمریکا چند تومان حساب شود: مبلغ سفارش با این نرخ به دلار تبدیل و از Plisio خواسته می‌شود، و Plisio آن را به رمزارزی که مشتری انتخاب می‌کند تبدیل می‌کند. با تغییر بازار به‌روزش کنید.', placeholder: '100000'),
                ),
                new Text(
                    'currencies',
                    'currencies',
                    '',
                    label: 'رمزارزهای مجاز',
                    max: 300,
                    pattern: '/^[A-Z0-9_]{2,20}(?:,[A-Z0-9_]{2,20})*$/D',
                    mismatch: 'شناسه رمزارزها را همان‌طور که Plisio می‌نویسد با کاما جدا کنید، مثل USDT_TRX,TRX,TON',
                    // Upper case, one comma between: «usdt_trx، trx ,ton» is USDT_TRX,TRX,TON.
                    normalize: static fn(string $typed): string => strtoupper((string) preg_replace('/[\s,،]+/u', ',', (string) preg_replace('/^[\s,،]+|[\s,،]+$/u', '', $typed))),
                    spec: new FieldSpec('رمزارزهای مجاز', hint: 'شناسه رمزارزهایی که به مشتری پیشنهاد می‌شود، با کاما جدا (مثل USDT_TRX,TRX,TON)؛ خالی یعنی همه رمزارزهایی که در پنل Plisio روشن کرده‌اید.', placeholder: 'USDT_TRX,TRX,TON', ltr: true),
                ),
            ]),
            notes: [
                'مبلغ هر سفارش با نرخ دلاری که می‌نویسید به دلار تبدیل می‌شود؛ مشتری در صفحه Plisio رمزارز را انتخاب و همان مبلغ را واریز می‌کند',
                'دامنه سایت فروشگاه باید در Plisio تایید شده باشد و آی‌پی سرور فروشگاه در «Request IP» تنظیمات API آن ثبت شود؛ آدرس سایت باید با https شروع شود تا Plisio نتیجه هر پرداخت را به آن خبر دهد',
                'هر صفحه پرداخت ۶۰ دقیقه مهلت دارد؛ پرداختی که در شبکه رسیده و منتظر تایید است تا تایید شدن صبر می‌کند',
                'کارمزد Plisio را شما یا مشتری می‌پردازید، هر کدام که در پنل Plisio انتخاب کرده باشید',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** The dollar's rate it prices orders at — the owner's to keep up with the market —, and the coins it offers. */
    public function summary(array $settings): string
    {
        $rate = is_int($settings['usd_rate'] ?? null) ? $settings['usd_rate'] : 0;
        $coins = is_string($settings['currencies'] ?? null) ? $settings['currencies'] : '';

        return 'هر دلار ' . Money::format($rate) . ($coins === '' ? '' : ' · ' . str_replace(',', '، ', $coins));
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new PlisioGateway(
            is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '',
            is_int($settings['usd_rate'] ?? null) ? $settings['usd_rate'] : 0,
            is_string($settings['currencies'] ?? null) ? $settings['currencies'] : '',
            $this->http,
        );
    }
}
