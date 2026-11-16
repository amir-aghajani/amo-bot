<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «کیف پول» (`wallet`): the top-up amounts — the least taken, the buttons offered before "any amount" (the presets, and
 * those drawn at random within a range: the panel's part «دکمه‌های مبلغ تصادفی») — and the small random sum added to a
 * card-to-card top-up so its receipt is told apart: an amount whose 0 is off, so it reads and is set as a switch with
 * its most — turned on by typing the most, off at a tap from its question.
 */
final class WalletCard extends BotCard
{
    public const ID = 'bsw';

    public const TITLE = Icons::WALLET . ' کیف پول';

    /** The random sum's field. */
    private const UNIQUE = 'topup_unique_max';

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
        return self::TITLE;
    }

    protected function intro(): string
    {
        return 'مبلغ‌های شارژ کیف پول در ربات و سایت. مشتری یکی از مبلغ‌های پیشنهادی یا تصادفی را می‌زند یا مبلغ دلخواه را تایپ می‌کند.';
    }

    protected function fields(): array
    {
        return $this->described([
            FieldDef::money('topup_min', 'حداقل مبلغ شارژ', 'کمتر از این مبلغ پذیرفته نمی‌شود.'),
            FieldDef::list('topup_presets', 'مبلغ‌های پیشنهادی', 'تومان', 'مبلغ‌ها به تومان؛ خالی باشد فقط «مبلغ دلخواه» می‌ماند.')->nullable(),
            FieldDef::number('topup_random_count', 'تعداد دکمه‌های مبلغ تصادفی', 'دکمه', '۰ یعنی بدون دکمه تصادفی.')->example('۳'),
            FieldDef::money('topup_random_round', 'گرد کردن مبلغ تصادفی', 'مثلا ۱۰۰۰ یعنی مبلغ‌ها هزار تومانی گرد می‌شوند.')->example('۱۰۰۰'),
            FieldDef::money('topup_random_min', 'کمترین مبلغ تصادفی', 'کمتر از حداقل مبلغ شارژ نمی‌شود.'),
            FieldDef::money('topup_random_max', 'بیشترین مبلغ تصادفی', 'کمتر از کمترین مبلغ تصادفی نمی‌شود.'),
            FieldDef::money(self::UNIQUE, 'مبلغ تصادفی افزوده به شارژ کارت به کارت', 'بیشترین مبلغی که به هر شارژ کیف پول با کارت به کارت اضافه می‌شود تا هر واریز مبلغی متفاوت داشته باشد و رسیدش راحت پیدا شود.')->example('۵۰۰۰'),
        ]);
    }

    protected function parts(): array
    {
        return [['🎲 دکمه‌های مبلغ تصادفی', ['topup_random_count', 'topup_random_round', 'topup_random_min', 'topup_random_max']]];
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
        if ($field->name === self::UNIQUE) {
            return (int) $value === 0 ? Words::OFF : Words::ON . ' · تا ' . Fmt::money((int) $value);
        }

        return null;
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        $most = (int) ($values[self::UNIQUE] ?? 0);
        if ($field->name !== self::UNIQUE || $most <= 0) {
            return null;
        }

        return 'به هر شارژ کیف پول با کارت به کارت، مبلغی تصادفی بین ۱ تا ' . Fmt::plain(Fmt::money($most)) . ' اضافه می‌شود تا هر واریز مبلغی متفاوت داشته باشد و رسیدش راحت پیدا شود؛ مشتری همه مبلغ را واریز می‌کند و همه به کیف پولش می‌رود.';
    }

    /** The random sum, off: a switch's button that turns it on by asking its most; on, the most edited. */
    protected function fieldButton(int $index, FieldDef $field, array $values): Button
    {
        if ($field->name === self::UNIQUE && (int) ($values[$field->name] ?? 0) === 0) {
            return Button::to(Words::turn(true, $field->label), self::ID, 'e', $index);
        }

        return parent::fieldButton($index, $field, $values);
    }

    /** The random sum's question: on, «⏸ خاموش کردن» puts it back to 0 — the value it comes with, said as what it does. */
    protected function prompt(int $index, FieldDef $field, array $values): Prompt
    {
        $prompt = parent::prompt($index, $field, $values);
        if ($field->name === self::UNIQUE) {
            $prompt->reset = null;
            if ((int) ($values[$field->name] ?? 0) > 0) {
                $prompt->choices(Button::to(Words::TURN_OFF, self::ID, 'd', $index)->danger());
            }
        }

        return $prompt;
    }
}
