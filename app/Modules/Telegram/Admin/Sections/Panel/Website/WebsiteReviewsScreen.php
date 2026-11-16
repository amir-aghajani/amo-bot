<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Sections\Support\ReviewsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «نظرات» of the website: whether it shows approved reviews and takes new ones — guests only behind the website's
 * captcha, which the card says —, with the way to the reviews to approve.
 */
final class WebsiteReviewsScreen extends WebsiteCard
{
    public const ID = 'wsr';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '⭐️ نظرات مشتریان در وب‌سایت';
    }

    protected function intro(): string
    {
        return 'مشتری‌ها در وب‌سایت درباره فروشگاه نظر می‌نویسند و وب‌سایت نظرهایی را که پشتیبانی تایید کرده نشان می‌دهد.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('reviews_enabled', 'نظرات در وب‌سایت', 'روشن باشد، وب‌سایت نظرهای تاییدشده را نشان می‌دهد و نظر تازه می‌گیرد؛ خاموش باشد، هیچ‌کدام.'),
        ];
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $none = (self::part($values, 'captcha')['driver'] ?? DraftForm::NONE) === DraftForm::NONE;
        $view->note('هر نظر تا وقتی در «' . Icons::REVIEWS . ' نظرات» تایید نشود، در وب‌سایت دیده نمی‌شود. ' . ($none
            ? 'تایید امنیتی وب‌سایت خاموش است، پس فقط مشتری‌هایی که وارد حسابشان شده‌اند نظر می‌نویسند؛ برای نظر مهمان، تایید امنیتی را روشن کنید.'
            : 'مهمان‌ها هم پشت تایید امنیتی وب‌سایت نظر می‌نویسند.'))
            ->row(Button::to(Icons::REVIEWS . ' نظرات', ReviewsScreen::ID), $none ? Button::to('🛡 تایید امنیتی', CaptchaScreen::ID) : null);
    }
}
