<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🎟 کدهای هدیه» (the panel's «کدهای هدیه»): whether customers may redeem them (GiftCodeSettingsScreen) — with the
 * panel's warning when the bot's menu has no button for it —, the batches, newest first, each opening its own screen
 * (GiftBatchScreen: its counts, its unused codes as a file, discarding them), every code (GiftCodeListScreen, the
 * panel's list with its tabs) and «➕ ساخت کدها» (GiftCodesForm). The batches' names are kept for the buttons, which
 * carry their place in the list.
 *
 * Actions: `page` (n), `info`.
 */
final class GiftCodesScreen extends BaseScreen
{
    public const ID = 'gc';

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'هر کد هدیه یک بار استفاده می‌شود: مبلغی به کیف پول مشتری اضافه می‌کند یا یک سرویس رایگان از یک پلن به او می‌دهد. مشتری کد را در ربات از دکمه «کد هدیه» یا در وب‌سایت فروشگاه می‌فرستد؛ بزرگ و کوچکی حروف و خط تیره و فاصله مهم نیست. کسی که پشت سر هم کد اشتباه بفرستد مدتی باید صبر کند، پس کدها حدس زده نمی‌شوند.';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $enabled = (bool) (BotGroupSource::settings($admin)['gift_codes_enabled'] ?? false);
        $batches = array_values(array_filter(self::batches($admin), static fn(array $batch): bool => is_string($batch['batch'] ?? null)));
        $admin->remember(self::ID, ['batches' => array_column($batches, 'batch')]);
        $pages = max(1, (int) ceil(count($batches) / ListScreen::PER_PAGE));
        $page = min($pages, max(1, (int) ($admin->state(self::ID)['page'] ?? 1)));

        $view = View::make('🎟 کدهای هدیه', ...Words::PATH)
            ->line('کدهایی که هر کدام یک بار، مبلغی به کیف پول مشتری اضافه می‌کند یا یک سرویس رایگان به او می‌دهد.')
            ->line('استفاده از کد هدیه: ' . Fmt::onOff($enabled));
        $notice = Links::menuNotice($admin, $enabled, 'gift', 'کد هدیه', 'فرستادن کد هدیه در ربات');
        if ($notice !== null) {
            $view->line(Fmt::e($notice[0]));
        }
        $view->line()->line('🗂 سری‌ها: ' . Fmt::number(count($batches)) . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
        if ($batches === []) {
            $view->line('هنوز کد هدیه‌ای نساخته‌اید. یک سری کد بسازید — مثلا برای جشنواره یا هدیه به مشتری‌های وفادار — و کدها را به مشتری‌ها بدهید.');
        }
        foreach (array_slice($batches, ($page - 1) * ListScreen::PER_PAGE, ListScreen::PER_PAGE, true) as $index => $batch) {
            $view->row(Button::to('🗂 ' . (string) $batch['batch'] . ' · ' . Fmt::number((int) ($batch['unused'] ?? 0)) . ' از ' . Fmt::number((int) ($batch['total'] ?? 0)) . ' مانده', GiftBatchScreen::ID, 'show', $index));
        }
        $view->pager($page, $pages, self::ID, 'page');

        return $view
            ->row(Button::to('➕ ساخت کدها', GiftCodesForm::ID, 'new'), Button::to('📋 همه کدها', GiftCodeListScreen::ID, 'all'))
            ->row($notice[1] ?? null)
            ->row(Button::to('⚙️ تنظیمات کد هدیه', GiftCodeSettingsScreen::ID), Button::to('❔ راهنما', self::ID, 'info'))
            ->back('hm');
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'page') {
            $admin->remember(self::ID, ['page' => self::page($args)]);
        }
        if ($action === 'info') {
            return Words::help(self::ID, 'کدهای هدیه', self::PROGRAM);
        }

        return $this->show($admin, []);
    }

    /**
     * Every batch with its counts, newest first (GET /gift-codes/batches).
     *
     * @return list<array<string, mixed>>
     */
    public static function batches(Admin $admin): array
    {
        return Json::rows($admin->api->get('/gift-codes/batches'), 'batches');
    }
}
