<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Rikhtam;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use GuzzleHttp\ClientInterface;

/**
 * Rikhtam's crypto payments (rikhtam.com, «ریختم»): the customer pays in a coin on Rikhtam's page — TON's Gram, Tether on
 * TON or on TRON, or TRX — straight to the merchant's own wallet, and Rikhtam confirms it on its chain. The order is asked
 * in Toman, Rikhtam pricing the coin. Its settings: the gateway's «کلید امنیتی» (kept encrypted) — the same key the
 * shop's Rikhtam deposits are heard with — and the coin, or none for the customer to pick on the page.
 */
final class RikhtamDriver implements GatewayDriver
{
    /** The coin setting's value that leaves the coin to the customer, on Rikhtam's page (a choice's value is never blank). */
    public const ANY_COIN = 'any';

    /** Each coin setting's words: the customer's pick first, then the coins Rikhtam takes, by its own names for them. */
    private const COINS = [
        self::ANY_COIN => 'انتخاب مشتری در صفحه ریختم',
        'gram' => 'گرم (GRAM)',
        'usdt_ton' => 'تتر روی شبکه TON',
        'usdt_trc20' => 'تتر روی شبکه ترون (TRC20)',
        'trx' => 'ترون (TRX)',
    ];

    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return RikhtamGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'ریختم (ارز دیجیتال)',
            description: 'پرداخت با ارز دیجیتال در درگاه ریختم: مشتری در صفحه ریختم گرم، تتر (روی شبکه TON یا ترون) یا ترون (TRX) را مستقیم به کیف پول خود شما واریز می‌کند و پس از تایید در شبکه، سفارش بدون رسید و بدون بررسی شما تحویل می‌شود. کلید امنیتی درگاه را از پنل ریختم، بخش «درگاه‌ها» بردارید.',
            form: new Form($this->key(), [
                new Secret(
                    'secret',
                    'secret',
                    '',
                    pattern: '/^[\x21-\x7e]{8,255}$/D',
                    mismatch: 'کلید امنیتی را همان‌طور که پنل ریختم نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'کلید امنیتی درگاه ریختم را وارد کنید.',
                    spec: new FieldSpec('کلید امنیتی درگاه', hint: 'از پنل ریختم، بخش «درگاه‌ها»؛ همان کلیدی که در بخش «اتصال ریختم» می‌گذارید. فقط فروشگاه آن را دارد و رمزنگاری‌شده نگه داشته می‌شود.', ltr: true),
                ),
                new Choice(
                    'coin',
                    'coin',
                    self::ANY_COIN,
                    array_keys(self::COINS),
                    refusal: 'ارز پرداخت را از فهرست انتخاب کنید.',
                    spec: new FieldSpec('ارز پرداخت', hint: 'ارزی که مشتری با آن پرداخت می‌کند؛ با «انتخاب مشتری» خود مشتری در صفحه ریختم یکی را انتخاب می‌کند.', options: self::COINS),
                ),
            ]),
            notes: [
                'پول مستقیم به کیف پول خود شما می‌رود: آن را در پنل ریختم، «درگاه‌ها» › «پرداخت با ارز دیجیتال» بگذارید؛ یک کیف پول شخصی TON یا ترون، نه آدرس واریز صرافی',
                'برای این‌که ریختم هر پرداخت را همان لحظه خبر دهد، همین کلید امنیتی را در «روش‌های پرداخت»، بخش «اتصال ریختم» بگذارید تا فروشگاه آدرس خود را در ریختم ثبت کند؛ این کار تایید خودکار واریز کارت به کارت را هم روشن می‌کند؛ اگر فقط ارز دیجیتال می‌خواهید، بعد از اتصال آن را خاموش کنید و خبر پرداخت‌های ارزی همچنان می‌رسد. بدون اتصال هم فروشگاه هر چند دقیقه و وقتی مشتری برمی‌گردد از ریختم می‌پرسد',
                'مبلغ هر سفارش به تومان از ریختم خواسته می‌شود و ریختم آن را به ارز انتخاب‌شده تبدیل می‌کند؛ اگر آدرس سایت فروشگاه با https شروع شود، مشتری پس از پرداخت به آن برمی‌گردد',
                'پرداخت کمتر از مبلغ یا بعد از پایان مهلت خودکار تایید نمی‌شود؛ ریختم آن را در پنل خود نشان می‌دهد',
                'کارمزد پرداخت با ارز دیجیتال ریختم جدا از کارت به کارت است و در همان بخش پنل ریختم دیده می‌شود',
            ],
            traits: [self::KIND => GatewayKind::Online->value, self::BUILTIN => false],
        );
    }

    /** The coin its payments are made in, or that the customer picks it. */
    public function summary(array $settings): string
    {
        $coin = is_string($settings['coin'] ?? null) && isset(self::COINS[$settings['coin']]) ? $settings['coin'] : self::ANY_COIN;

        return 'ارز: ' . self::COINS[$coin];
    }

    public function gateway(array $settings): GatewayInterface
    {
        $coin = $settings['coin'] ?? null;

        return new RikhtamGateway(
            is_string($settings['secret'] ?? null) ? $settings['secret'] : '',
            is_string($coin) && $coin !== self::ANY_COIN && isset(self::COINS[$coin]) ? $coin : '',
            $this->http,
        );
    }
}
