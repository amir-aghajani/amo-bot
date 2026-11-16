<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * The mini app's logo: the screen is the logo itself — or, with none, the app's initial it shows instead —, a picture
 * sent here replaces it at once (the size limit said up front), and «🗑 حذف لوگو» takes it away, asked first.
 *
 * Actions: `up` (asks for the picture), `del` (asks), `gone` (removes it).
 */
final class LogoScreen extends BaseScreen
{
    public const ID = 'mag';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $app = MiniAppSource::app($admin->api->get('/mini-app'));
        $logo = Json::on($app, 'logo');
        $view = View::make('🖼 لوگوی مینی‌اپ', ...MiniAppScreen::under());
        if ($logo) {
            $view->picture($admin->api->file('/mini-app/logo'))->line('☝️ لوگوی فعلی');
        } else {
            $name = trim(is_string($app['title'] ?? null) && $app['title'] !== '' ? $app['title'] : (string) ($app['shop_name'] ?? ''));
            $view->line('بدون لوگو: مینی‌اپ «' . Fmt::e(mb_substr($name, 0, 1)) . '» را روی رنگ اصلی‌اش نشان می‌دهد.');
        }

        return $view->note('نشان فروشگاه در مینی‌اپ؛ تصویر مربعی بهترین نتیجه را می‌دهد.')
            ->row(Button::to($logo ? '📤 آپلود لوگوی جدید' : '📤 آپلود لوگو', self::ID, 'up')->primary(), $logo ? Button::to('🗑 حذف لوگو', self::ID, 'del')->danger() : null)
            ->back(MiniAppScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'up':
                return $admin->ask((new Prompt('تصویر لوگو', self::ID, 'up', [], 'عکس یا فایل تصویر را همین‌جا بفرستید؛ فایل تصویر کیفیت بهتری دارد.', file: true))
                    ->limits("\u{2068}JPG\u{2069}، \u{2068}PNG\u{2069} یا \u{2068}WebP\u{2069}، حداکثر ۲ مگابایت؛ بزرگ‌تر از ۵۱۲ پیکسل کوچک می‌شود"));
            case 'del':
                return $admin->confirm(
                    'لوگوی مینی‌اپ حذف شود؟',
                    Button::to(Words::yes('حذف شود'), self::ID, 'gone'),
                    Button::to(Words::CANCEL, self::ID),
                    'لوگو حذف می‌شود و مینی‌اپ به‌جای آن حرف اول نامش را نشان می‌دهد. این کار برگشت‌پذیر نیست؛ برای استفاده دوباره از همین تصویر باید آن را دوباره آپلود کنید.',
                    danger: true,
                );
            case 'gone':
                $admin->api->delete('/mini-app/logo');
                $admin->notice('✅ لوگوی مینی‌اپ حذف شد.');
                break;
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'up') {
            $picture = $answer->picture() ?? $answer->document() ?? throw new ApiRefusal(422, 'تصویری نرسید؛ یک عکس یا فایل تصویر بفرستید.');
            $admin->api->upload('/mini-app/logo', 'file', $picture['name'], $picture['bytes'], $picture['mime']);
            $admin->notice('✅ لوگوی مینی‌اپ ذخیره شد.');
        }

        return $this->show($admin, []);
    }
}
