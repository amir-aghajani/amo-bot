<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🎟 کدهای هدیه» (the panel's «کدهای هدیه»): whether customers may redeem them — off, a tap turns it on through its card
 * (GiftCodeSettingsScreen, «⚙️ تنظیمات»); on with no button for it on the bot's menu, the panel's warning and the way to
 * the keyboards —, the batches, newest first, each a button with what is left of it, opening its own screen
 * (GiftBatchScreen: its counts, its unused codes copied or as a file, discarding them), every code (GiftCodeListScreen,
 * the panel's list with its tabs and search) and «➕ افزودن کدهای هدیه» (GiftCodesForm). The batches' names are kept
 * for the buttons, which carry their place in the list.
 *
 * Actions: `page` (n); `info` (an older window's «❔ راهنما»: the menu, its help folded in it).
 */
final class GiftCodesScreen extends BaseScreen
{
    public const ID = 'gc';

    public const TITLE = Icons::GIFT_CODES . ' ' . Marketing::GIFT_CODES;

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'هر کد هدیه یک بار استفاده می‌شود: مبلغی به کیف پول مشتری اضافه می‌کند یا یک سرویس رایگان از یک پلن به او می‌دهد. مشتری کد را در ربات از دکمه «کد هدیه» یا در وب‌سایت فروشگاه می‌فرستد؛ بزرگ و کوچکی حروف و خط تیره و فاصله مهم نیست. کسی که پشت سر هم کد اشتباه بفرستد مدتی باید صبر کند، پس کدها حدس زده نمی‌شوند.';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $on = (bool) (BotGroupSource::settings($admin)['gift_codes_enabled'] ?? false);
        $batches = array_values(array_filter(self::batches($admin), static fn(array $batch): bool => is_string($batch['batch'] ?? null)));
        $admin->remember(self::ID, ['batches' => array_column($batches, 'batch')]);
        $pages = max(1, (int) ceil(count($batches) / ListScreen::PER_PAGE));
        $page = min($pages, max(1, (int) ($admin->state(self::ID)['page'] ?? 1)));
        $left = array_sum(array_map(static fn(array $batch): int => (int) ($batch['unused'] ?? 0), $batches));
        $notice = self::notice($admin, $on);

        // As the program lists lay it out (ListScreen): how many first, then whether it runs and its warning.
        $view = View::make(self::TITLE, ...Marketing::PATH)
            ->total(count($batches), 'سری', $batches === [] ? '' : Fmt::plain(Fmt::number($left)) . ' کد مانده')
            ->line('استفاده از کد هدیه: ' . Fmt::onOff($on) . ($on ? '' : ' · کدی پذیرفته نمی‌شود'));
        if ($notice !== null) {
            $view->line('⚠️ ' . Fmt::e($notice[0]));
        }
        if ($batches === []) {
            $view->line()->line('هنوز کد هدیه‌ای نساخته‌اید. یک سری کد بسازید — مثلا برای جشنواره یا هدیه به مشتری‌های وفادار — و کدها را به مشتری‌ها بدهید.');
        }
        foreach (array_slice($batches, ($page - 1) * ListScreen::PER_PAGE, ListScreen::PER_PAGE, true) as $index => $batch) {
            // A long name (up to 64) cut, so what is left of the batch stays on its button.
            $view->row(Button::to(Icons::GIFT_CODES . ' ' . Fmt::cut((string) $batch['batch'], 24) . ' · ' . Fmt::number((int) ($batch['unused'] ?? 0)) . ' از ' . Fmt::number((int) ($batch['total'] ?? 0)) . ' مانده', GiftBatchScreen::ID, 'show', $index));
        }

        return $view
            ->row(Button::to(Words::add(Marketing::GIFT_CODES), GiftCodesForm::ID, 'new')->success(), $batches === [] ? null : Button::to(Icons::GIFT_CODES . ' همه کدها', GiftCodeListScreen::ID, 'all'))
            ->row(...GiftCodeSettingsScreen::buttons($on, 'کد هدیه'))
            ->row($notice[1] ?? null)
            ->pager($page, $pages, self::ID, 'page')
            ->help(Fmt::e(self::PROGRAM))
            ->back(Marketing::MENU)->refresh(self::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'page') {
            $admin->remember(self::ID, ['page' => self::page($args)]);
        }

        return $this->show($admin, []);
    }

    /**
     * Every batch with its counts, newest first (GET /gift-codes/batches).
     *
     * @return list<array<string, mixed>>
     * @throws ApiRefusal
     */
    public static function batches(Admin $admin): array
    {
        return Json::rows($admin->api->get('/gift-codes/batches'), 'batches');
    }

    /**
     * The panel's warning while redeeming is on and the bot's menu has no button for it: its words and the way to the
     * keyboards (Links::menuNotice()); null for none.
     *
     * @return array{0: string, 1: ?Button}|null
     * @throws ApiRefusal
     */
    public static function notice(Admin $admin, bool $on): ?array
    {
        return Links::menuNotice($admin, $on, 'gift', 'کد هدیه', 'فرستادن کد هدیه در ربات');
    }
}
