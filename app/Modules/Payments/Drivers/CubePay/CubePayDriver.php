<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\CubePay;

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
use App\Support\Persian;
use GuzzleHttp\ClientInterface;

/**
 * CubePay (cubevps.ir): card-to-card and crypto payments its service confirms by itself — a card transfer from the
 * bank's text message, a crypto one from the chain. The customer pays on CubePay's page, which the merchant's settings
 * at CubePay make a card's, a crypto one's or a choice of the two; or, for a card, in the bot itself, when the merchant
 * asked CubePay to show the card there. Its settings: the merchant's API token, kept encrypted, and the fee passed on to
 * customers — a percent and a fixed sum on every payment (CubePayGateway::fee()).
 */
final class CubePayDriver implements GatewayDriver
{
    /** The most a fixed fee may be, in Toman. */
    private const FEE_FIXED_MAX = 1_000_000;

    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return CubePayGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'CubePay',
            description: 'پرداخت کارت به کارت و ارز دیجیتال با تایید خودکار CubePay: مشتری در صفحه CubePay پرداخت می‌کند و پرداخت بدون رسید و بدون بررسی شما تایید و سفارش تحویل می‌شود. در ربات ‎@cubepy_bot حساب فروشندگی بسازید و پس از تایید آن، توکن API را از «🔗 پنل من» بردارید؛ کارت‌ها، ارز دیجیتال و نمایش کارت در ربات را همان‌جا تنظیم کنید.',
            form: new Form($this->key(), [
                new Secret(
                    'token',
                    'token',
                    '',
                    pattern: '/^[\x21-\x7e]{8,255}$/',
                    mismatch: 'توکن API را همان‌طور که ربات CubePay نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'توکن API را از ربات CubePay وارد کنید.',
                    spec: new FieldSpec('توکن API', hint: 'از ربات ‎@cubepy_bot، بخش «🔗 پنل من». فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
                new Text(
                    'fee_percent',
                    'fee_percent',
                    '',
                    label: 'کارمزد درصدی مشتری',
                    max: 5,
                    pattern: '/^[0-9]{1,2}(?:\.[0-9]{1,2})?$/D',
                    mismatch: 'کارمزد درصدی مشتری باید عددی از 0 تا 99.99 باشد؛ مثلا 3 یا 3.1.',
                    normalize: static fn(string $typed): string => str_replace(['٫', '/'], '.', Persian::latinDigits($typed)),
                    spec: new FieldSpec(
                        'کارمزد درصدی بر عهده مشتری',
                        hint: 'درصدی از مبلغ هر پرداخت که به آن اضافه می‌شود و مشتری می‌پردازد؛ خالی یا 0 یعنی هیچ. کارمزد درصدی CubePay (مثل کارمزد ارز دیجیتال) از کل مبلغ برداشته می‌شود، پس برای پوشاندن کامل آن کمی بیشتر بنویسید: برای ۳ درصد، 3.1. کارمزد کارت به کارت CubePay مبلغی ثابت است و در کارمزد ثابت نوشته می‌شود. سفارش با همان قیمت خودش تحویل و کیف پول با همان مبلغ شارژ می‌شود.',
                        placeholder: '0',
                        ltr: true,
                    ),
                ),
                new Number(
                    'fee_fixed',
                    'fee_fixed',
                    0,
                    label: 'کارمزد ثابت مشتری',
                    min: 0,
                    max: self::FEE_FIXED_MAX,
                    unit: 'تومان',
                    spec: new FieldSpec('کارمزد ثابت بر عهده مشتری', hint: 'مبلغی که روی هر پرداخت اضافه می‌شود و مشتری می‌پردازد؛ 0 یعنی هیچ. با کارمزد درصدی جمع می‌شود.', placeholder: '0'),
                ),
            ]),
            notes: [
                'آدرس سایت فروشگاه باید با https شروع شود: CubePay نتیجه هر پرداخت را به آن خبر می‌دهد',
                'کارمزد هر پرداخت را CubePay از کیف پول کارمزد حساب شما برمی‌دارد',
                'هر صفحه پرداخت مهلتی دارد که CubePay تعیین می‌کند (۳۰ تا ۶۰ دقیقه)؛ بعد از آن مشتری پرداخت را دوباره شروع می‌کند',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** The fee passed on to customers — «کارمزد مشتری: ۳٪ + ۵۰۰ تومان» —; null for none (the token no screen shows). */
    public function summary(array $settings): ?string
    {
        $percent = self::feePercent($settings);
        $fixed = self::feeFixed($settings);
        $parts = array_filter([
            $percent !== '0' ? Persian::digits($percent) . '٪' : null,
            $fixed > 0 ? Money::format($fixed) : null,
        ]);

        return $parts === [] ? null : 'کارمزد مشتری: ' . implode(' + ', $parts);
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new CubePayGateway(is_string($settings['token'] ?? null) ? $settings['token'] : '', $this->http, self::feePercent($settings), self::feeFixed($settings));
    }

    /**
     * The percent fee as the gateway counts it: Latin digits, "0" for none.
     *
     * @param array<string, mixed> $settings
     */
    private static function feePercent(array $settings): string
    {
        $percent = $settings['fee_percent'] ?? '';

        return is_string($percent) && preg_match('/^[0-9]{1,2}(?:\.[0-9]{1,2})?$/D', $percent) === 1 && (float) $percent > 0 ? $percent : '0';
    }

    /** @param array<string, mixed> $settings */
    private static function feeFixed(array $settings): int
    {
        $fixed = $settings['fee_fixed'] ?? 0;

        return is_int($fixed) && $fixed > 0 ? $fixed : 0;
    }
}
