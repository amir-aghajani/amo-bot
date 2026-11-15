<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/**
 * «کیف پول» (`wallet`): the top-up amounts — the least taken, the buttons offered before "any amount" (the presets and
 * those drawn at random within a range) — and the small random sum added to a card-to-card top-up so its receipt is told
 * apart.
 */
final class WalletCard extends BotCard
{
    public const ID = 'bsw';

    protected function group(): string
    {
        return 'wallet';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '👛 کیف پول';
    }

    protected function intro(): string
    {
        return 'مبلغ‌های شارژ کیف پول در ربات و سایت. مشتری یکی از مبلغ‌های پیشنهادی یا تصادفی را می‌زند یا مبلغ دلخواه را تایپ می‌کند.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::money('topup_min', 'حداقل مبلغ شارژ', 'کمتر از این مبلغ پذیرفته نمی‌شود.'),
            FieldDef::list('topup_presets', 'مبلغ‌های پیشنهادی', 'تومان', 'مبلغ‌ها به تومان؛ خالی باشد فقط «مبلغ دلخواه» می‌ماند.')->nullable(),
            FieldDef::number('topup_random_count', 'تعداد دکمه‌های مبلغ تصادفی', 'دکمه', '۰ یعنی بدون دکمه تصادفی؛ حداکثر ۸.'),
            FieldDef::money('topup_random_round', 'گرد کردن مبلغ تصادفی', 'مثلا ۱۰۰۰ یعنی مبلغ‌ها هزار تومانی گرد می‌شوند.'),
            FieldDef::money('topup_random_min', 'کمترین مبلغ تصادفی', 'کمتر از حداقل مبلغ شارژ نمی‌شود.'),
            FieldDef::money('topup_random_max', 'بیشترین مبلغ تصادفی', 'کمتر از کمترین مبلغ تصادفی نمی‌شود.'),
            FieldDef::number('topup_unique_max', 'مبلغ تصادفی افزوده به شارژ کارت به کارت', 'تومان', '۰ یعنی خاموش. روشن که باشد، به هر شارژ کیف پول با کارت به کارت مبلغی تصادفی (تا ۹۹۹۹ تومان) اضافه می‌شود تا هر واریز مبلغی متفاوت داشته باشد و رسیدش راحت پیدا شود.'),
        ];
    }

    protected function notes(): array
    {
        return ['دکمه‌های مبلغ تصادفی: کنار مبلغ‌های پیشنهادی، دکمه‌هایی با مبلغ تصادفی؛ هر بار که صفحه شارژ باز می‌شود مبلغ‌های تازه‌ای کشیده می‌شود، هر کدام بین کمترین و بیشترین مبلغ، گردشده و هیچ‌کدام تکراری یا برابر یک مبلغ پیشنهادی نیست.'];
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        if ($field->name === 'topup_presets') {
            $amounts = array_filter(is_array($value) ? $value : [], is_numeric(...));

            return $amounts === [] ? 'هیچ؛ فقط «مبلغ دلخواه»' : implode(' · ', array_map(static fn(mixed $amount): string => Fmt::money((string) $amount), $amounts));
        }
        if ($field->name === 'topup_unique_max' && (int) $value === 0) {
            return 'خاموش';
        }

        return null;
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        $most = (int) ($values['topup_unique_max'] ?? 0);
        if ($field->name !== 'topup_unique_max' || $most <= 0) {
            return null;
        }

        return 'به هر شارژ کیف پول با کارت به کارت، مبلغی تصادفی بین ۱ تا ' . Fmt::plain(Fmt::money($most)) . ' اضافه می‌شود تا هر واریز مبلغی متفاوت داشته باشد و رسیدش راحت پیدا شود؛ مشتری همه مبلغ را واریز می‌کند و همه به کیف پولش می‌رود.';
    }
}
