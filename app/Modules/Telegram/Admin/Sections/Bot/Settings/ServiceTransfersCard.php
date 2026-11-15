<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/** «انتقال سرویس» (`service_transfers`): whether a customer may hand one of their services to another customer of the shop by their Telegram id. */
final class ServiceTransfersCard extends BotCard
{
    public const ID = 'bsv';

    protected function group(): string
    {
        return 'service_transfers';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🔀 انتقال سرویس';
    }

    protected function intro(): string
    {
        return 'مشتری یکی از سرویس‌هایش را با شناسه عددی تلگرام به مشتری دیگری از همین فروشگاه می‌دهد: در ربات با دکمه «🔁 انتقال سرویس» در صفحه سرویس، یا در سایت. انتقال رایگان است.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('transfer_enabled', 'انتقال سرویس به مشتری دیگر', 'روشن که باشد، مشتری شناسه عددی تلگرام گیرنده را می‌فرستد، نام او را می‌بیند و با تایید، سرویس به حساب گیرنده می‌رود. خاموش، فقط پشتیبانی سرویس را منتقل می‌کند.'),
        ];
    }

    protected function notes(): array
    {
        return [
            'سرویس با همان لینک، زمان و حجم منتقل می‌شود و «تمدید خودکار» آن خاموش می‌شود؛ گیرنده در ربات خبردار می‌شود و سرویس در «سرویس‌های من» او می‌آید.',
            'لینک هم با سرویس می‌رود و دستگاه‌های صاحب قبلی وصل می‌مانند تا گیرنده «تغییر لینک» را بزند؛ پیام گیرنده این را به او می‌گوید.',
            'گیرنده باید یک بار ربات را استارت کرده باشد. اکانت تست، سرویسی که کلاینتش دیگر روی سرور نیست و سرویسی که سفارشی از آن هنوز باز است (در انتظار پرداخت یا تحویل) منتقل نمی‌شود.',
            'هر انتقال در گروه گزارش‌ها، تاپیک «خریدها»، گزارش می‌شود.',
            'پشتیبانی همیشه، حتی وقتی این گزینه خاموش است، سرویس را از صفحه آن در «اشتراک‌ها» با «انتقال به مشتری دیگر» منتقل می‌کند.',
        ];
    }
}
