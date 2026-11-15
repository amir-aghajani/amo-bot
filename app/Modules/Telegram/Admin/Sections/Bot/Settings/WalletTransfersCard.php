<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/**
 * «انتقال موجودی» (`wallet_transfers`): whether a customer may send money from their wallet to another customer of the
 * shop by their Telegram id, the least and the most one transfer moves, and the most a customer sends in a day (0: no
 * cap) — the amounts shown while transfers are on, as the panel holds them while off.
 */
final class WalletTransfersCard extends BotCard
{
    public const ID = 'bst';

    protected function group(): string
    {
        return 'wallet_transfers';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '💸 انتقال موجودی';
    }

    protected function intro(): string
    {
        return 'مشتری از موجودی کیف پولش به مشتری دیگری از همین فروشگاه، با شناسه عددی تلگرام او، پول منتقل می‌کند: در ربات با دکمه «💸 انتقال موجودی» در صفحه کیف پول، یا در سایت. انتقال کارمزد ندارد.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('wallet_transfer_enabled', 'انتقال موجودی به مشتری دیگر', 'روشن که باشد، مشتری شناسه عددی تلگرام گیرنده را می‌فرستد، نام او را می‌بیند، مبلغ را می‌نویسد و با تایید، مبلغ از کیف پول او کم و به کیف پول گیرنده اضافه می‌شود.'),
            FieldDef::money('wallet_transfer_min', 'کمترین مبلغ انتقال', 'کمتر از این مبلغ منتقل نمی‌شود.')->when('wallet_transfer_enabled', [true]),
            FieldDef::money('wallet_transfer_max', 'بیشترین مبلغ هر انتقال', 'بیشتر از این مبلغ در یک انتقال منتقل نمی‌شود.')->when('wallet_transfer_enabled', [true]),
            FieldDef::money('wallet_transfer_daily', 'سقف روزانه هر مشتری', '۰ یعنی بدون سقف روزانه؛ جز آن، کمتر از کمترین مبلغ انتقال نمی‌شود.')->when('wallet_transfer_enabled', [true]),
        ];
    }

    protected function notes(): array
    {
        return [
            'فقط موجودی خود کیف پول منتقل می‌شود، نه اعتبار نمایندگی. گیرنده باید مشتری همین فروشگاه باشد و یک بار ربات را استارت کرده باشد.',
            'در تاریخچه کیف پول هر دو یک خط ثبت می‌شود که نام دیگری را دارد، و هر دو خبردار می‌شوند. انتقال برگشت ندارد؛ برای اصلاح، موجودی را از کیف پول مشتری دستی کم و زیاد کنید.',
            'سقف روزانه جمع انتقال‌های هر مشتری از نیمه‌شب، به ساعت فروشگاه است. هر مشتری در ساعت حداکثر ۱۰ انتقال انجام می‌دهد.',
            'هر انتقال در گروه گزارش‌ها، تاپیک «شارژ کیف پول»، گزارش می‌شود.',
        ];
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        return $field->name === 'wallet_transfer_daily' && (int) $value === 0 ? 'بدون سقف' : null;
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        if ($field->name !== 'wallet_transfer_daily') {
            return null;
        }
        $daily = (int) ($values['wallet_transfer_daily'] ?? 0);

        return $daily === 0
            ? 'بدون سقف روزانه؛ فقط موجودی مشتری و بیشترین مبلغ هر انتقال محدودش می‌کند.'
            : '= ' . Fmt::plain(Fmt::money($daily)) . ' در روز برای هر مشتری؛ کمتر از کمترین مبلغ انتقال نمی‌شود.';
    }
}
