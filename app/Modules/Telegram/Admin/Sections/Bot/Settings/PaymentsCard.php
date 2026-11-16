<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «پرداخت کارت به کارت» (`payments`): the card's message protected from forwarding and screenshots, the customer sent to
 * support's private chat instead of a card (warned of while the support contact is empty), and one receipt at a time.
 */
final class PaymentsCard extends BotCard
{
    public const ID = 'bsp';

    public const TITLE = Icons::CARD_TO_CARD . ' پرداخت کارت به کارت';

    protected function group(): string
    {
        return 'payments';
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
        return 'پیام شماره کارت در ربات، و رسیدهایی که مشتری‌ها برای پرداخت کارت به کارت می‌فرستند.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('card_protect', 'جلوگیری از اسکرین‌شات و فوروارد', 'پیام شماره کارت محافظت‌شده فرستاده می‌شود: فوروارد، ذخیره و کپی متنش بسته است و اپ‌های موبایل تلگرام از آن اسکرین‌شات نمی‌گیرند. دکمه‌های کپی شماره کارت و مبلغ همچنان کار می‌کنند.'),
            FieldDef::toggle('card_to_support', 'هدایت پرداخت کارت به کارت به پیوی پشتیبانی', 'شماره کارتی نشان داده نمی‌شود: مشتری شماره سفارش و مبلغ را می‌بیند و به پیوی پشتیبانی (راه ارتباط با پشتیبانی در بخش «عمومی») فرستاده می‌شود تا کارت را بگیرد و رسید را همان‌جا بفرستد؛ یادآوری پرداخت هم او را به همان‌جا می‌فرستد. کارتی از کارت‌های روش برای پرداخت انتخاب نمی‌شود (سقف روزانه‌شان پر نمی‌شود) و در صفحه پرداخت‌ها به جای کارت «کارت در پیوی پشتیبانی داده شد» می‌آید. پرداخت در انتظار می‌ماند تا پشتیبانی در صفحه پرداخت‌ها تاییدش کند و خودبه‌خود لغو نمی‌شود، حتی بعد از خاموش کردن این گزینه؛ پرداختی را که انجام نشد همان‌جا لغو کنید. اگر راه ارتباط با پشتیبانی پاک شود، شماره کارت مثل قبل نشان داده می‌شود.'),
            FieldDef::toggle('receipt_single', 'هر مشتری فقط یک رسید در انتظار بررسی', 'تا رسید قبلی مشتری بررسی نشده، رسید دیگری از او پذیرفته نمی‌شود و پرداخت کارت به کارت تازه‌ای شروع نمی‌کند؛ ربات و سایت به او می‌گویند رسید قبلی‌اش در حال بررسی پشتیبانی است. پرداخت با کیف پول آزاد است.'),
        ];
    }

    protected function warnings(Admin $admin, array $values): array
    {
        if (self::supportMissing($values)) {
            return ['راه ارتباط با پشتیبانی در بخش «عمومی» خالی است؛ تا آن را ننویسید، ربات و سایت شماره کارت را مثل قبل نشان می‌دهند.'];
        }

        return [];
    }

    /** While card payments go to support's chat and its contact is empty: the way to fill it in, on «عمومی». */
    protected function extra(View $view, Admin $admin, array $values): void
    {
        if (self::supportMissing($values)) {
            $view->row(Button::to(Words::edit('راه ارتباط با پشتیبانی'), GeneralCard::ID, SettingsScreen::FIELD, 'support_contact')->primary());
        }
    }

    /** @param array<string, mixed> $values */
    private static function supportMissing(array $values): bool
    {
        return Json::on($values, 'card_to_support') && trim((string) ($values['support_contact'] ?? '')) === '';
    }

    protected function notes(): array
    {
        return [
            'زیر پیام شماره کارت، دکمه‌های کپی شماره کارت و مبلغ می‌آید؛ مبلغ با رقم انگلیسی و بدون جداکننده کپی می‌شود، همان‌طور که اپلیکیشن بانک می‌پذیرد.',
            'رسیدها در «پرداخت‌ها» بررسی می‌شوند؛ برای رد کردن چند رسید در انتظار با هم، همان‌جا انتخابشان کنید.',
        ];
    }
}
