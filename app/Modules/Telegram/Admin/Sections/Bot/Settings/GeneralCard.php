<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «عمومی» (`general`): the master switch; the phone rule — before everything or only at the moments switched on, and
 * Iranian numbers alone or any —; the way to support (warned of while card payments go to support's chat and it is
 * empty); and whether a message support writes comes under the bot's heading for it.
 */
final class GeneralCard extends BotCard
{
    public const ID = 'bsg';

    public const TITLE = Icons::SETTINGS . ' عمومی';

    private const SCOPE = [
        'everywhere' => 'تا مشتری شماره‌اش را نفرستد، ربات هیچ کاری برایش انجام نمی‌دهد و مینی‌اپ هم سفارش، اکانت تست یا جایزه‌ای به او نمی‌دهد. وب‌سایت فروشگاه شماره را فقط قبل از اکانت تست رایگان و چرخاندن گردونه شانس می‌خواهد.',
        'moments' => 'ربات و مینی‌اپ آزادانه کار می‌کنند و شماره فقط قبل از کارهایی که روشن کرده‌اید خواسته می‌شود. وب‌سایت فروشگاه فقط قبل از اکانت تست رایگان می‌پرسد، اگر «گرفتن اکانت تست» روشن باشد.',
    ];

    private const MOMENTS = ['phone_on_purchase', 'phone_on_renewal', 'phone_on_test', 'phone_on_card'];

    protected function group(): string
    {
        return 'general';
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
        return 'روشن و خاموش کردن ربات، تایید شماره مشتری، راه تماس با پشتیبانی و پیام پشتیبانی به مشتری.';
    }

    protected function fields(): array
    {
        $moment = static fn(string $name, string $label): FieldDef => FieldDef::toggle($name, $label)->when('phone_required', [true])->when('phone_scope', ['moments']);

        return [
            // Switching the bot off or the phone rule on holds customers back: asked first; the other way is one tap.
            FieldDef::toggle('enabled', 'ربات فعال باشد', 'خاموش که باشد، ربات به هر پیام مشتری فقط یک اطلاعیه غیرفعال بودن می‌دهد و منو را برمی‌دارد، و وب‌سایت فروشگاه هم سفارش نمی‌گیرد. برای تعطیلی موقت یا وقتی سرورها در دسترس نیستند.')
                ->asks(false, 'ربات خاموش شود؟', Words::yes('خاموش شود', '⏸'), 'مشتری‌ها به جای منو فقط پیام «ربات فعلا غیرفعال است» می‌بینند و وب‌سایت فروشگاه سفارش نمی‌گیرد، تا دوباره روشنش کنید. مدیریت فروشگاه (مالک، نماینده و مدیران ربات) همچنان از ربات استفاده می‌کند.'),
            FieldDef::toggle('phone_required', 'تایید شماره موبایل', 'مشتری باید شماره تلگرامش را با دکمه «ارسال شماره موبایل» بفرستد؛ شماره تایپ‌شده یا شماره دیگران پذیرفته نمی‌شود. کسی که یک بار تایید کرده دوباره پرسیده نمی‌شود. از مدیرهای ربات و مشتری‌هایی که در صفحه‌شان «معاف از ارسال شماره موبایل» روشن است هیچ‌وقت پرسیده نمی‌شود.')
                ->asks(true, 'تایید شماره موبایل روشن شود؟', Words::yes('روشن شود', '✅'), 'مشتری‌ای که شماره‌اش را تایید نکرده، تا شماره را با دکمه «ارسال شماره موبایل» نفرستد، در ربات جلو نمی‌رود (یا فقط سر کارهایی که انتخاب می‌کنید). کجا پرسیده شود را بعد از روشن شدن انتخاب کنید.'),
            FieldDef::choice('phone_scope', 'کجا پرسیده شود', ['everywhere' => 'کل ربات', 'moments' => 'فقط موقع این کارها'], '«کل ربات»: ' . self::SCOPE['everywhere'] . "\n«فقط موقع این کارها»: " . self::SCOPE['moments'])->when('phone_required', [true]),
            $moment('phone_on_purchase', 'شماره برای خرید سرویس'),
            $moment('phone_on_renewal', 'شماره برای تمدید (و حجم، زمان یا کاربر اضافه)'),
            $moment('phone_on_test', 'شماره برای گرفتن اکانت تست'),
            $moment('phone_on_card', 'شماره برای پرداخت کارت به کارت'),
            FieldDef::toggle('phone_iran_only', "فقط شماره‌های ایران (\u{2068}+98\u{2069})", 'روشن که باشد، شماره کشورهای دیگر پذیرفته نمی‌شود و ربات به مشتری می‌گوید فقط شماره ایران قبول است. مشتری‌ای که پیش‌تر با شماره خارجی تایید شده، دوباره شماره را می‌خواهد. برای کسی که شماره ایرانی ندارد، او را در صفحه‌اش از ارسال شماره معاف کنید.')->when('phone_required', [true]),
            FieldDef::text('support_contact', 'راه ارتباط با پشتیبانی', 'آی‌دی تلگرام یا لینکی که در بخش پشتیبانی ربات نشان داده می‌شود؛ اختیاری.')->nullable()->example('@my_support'),
            FieldDef::toggle('support_header', 'عنوان پیام پشتیبانی', 'روشن که باشد، پیامی که از صفحه کاربر یا کارت او در گروه گزارش‌ها با «ارسال پیام» می‌فرستید زیر «📩 پیام از طرف پشتیبانی:» می‌رسد (این متن در «متن‌های ربات» عوض می‌شود)؛ خاموش، فقط خود متن.'),
        ];
    }

    protected function warnings(Admin $admin, array $values): array
    {
        if (Json::on($values, 'card_to_support') && trim((string) ($values['support_contact'] ?? '')) === '') {
            return ['«هدایت پرداخت کارت به کارت به پیوی پشتیبانی» روشن است و بدون راه ارتباط با پشتیبانی کار نمی‌کند: تا این خانه خالی است، ربات و سایت شماره کارت را مثل قبل نشان می‌دهند.'];
        }

        return [];
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        if ($field->name !== 'phone_scope') {
            return null;
        }
        $scope = (string) ($values['phone_scope'] ?? 'everywhere');
        if ($scope !== 'moments') {
            return self::SCOPE['everywhere'];
        }
        $ticked = array_filter(self::MOMENTS, static fn(string $moment): bool => ($values[$moment] ?? false) === true);

        return self::SCOPE['moments'] . ' ' . ($ticked === []
            ? 'هیچ کاری روشن نیست: شماره از هیچ مشتری‌ای پرسیده نمی‌شود.'
            : 'سر این کارها ربات اول شماره را می‌خواهد و تا مشتری آن را نفرستد، آن کار انجام نمی‌شود. «پرداخت کارت به کارت» یعنی پرداخت با روش کارت به کارت خود فروشگاه (با رسید یا تایید خودکار واریز)، برای هر چیزی، شارژ کیف پول هم؛ درگاه‌های آنلاین شماره نمی‌خواهند.');
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        return $field->name === 'support_contact' ? Fmt::ltr(is_string($value) ? $value : null) : null;
    }
}
