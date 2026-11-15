<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Ui\Admin;

/** The payments list's «🎛 فیلتر و ترتیب»: the panel's date filter — by when the payment was made — and the order of the rows. */
final class PaymentFilters extends NarrowScreen
{
    public const ID = 'pyf';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function list(): string
    {
        return PaymentsScreen::ID;
    }

    protected function listTitle(): string
    {
        return 'پرداخت‌ها';
    }

    protected function days(): string
    {
        return 'بر اساس زمان ثبت پرداخت';
    }

    protected function groups(Admin $admin): array
    {
        return [
            ['key' => 'sort', 'label' => 'ترتیب', 'options' => ['' => 'تازه‌ترین', 'created.asc' => 'قدیمی‌ترین', 'amount.desc' => 'بیشترین مبلغ', 'amount.asc' => 'کمترین مبلغ']],
        ];
    }
}
