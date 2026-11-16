<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Gifts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🎁 هدیه همگانی» (the owner's, as the owner's broadcasts page has it): days and traffic for running services on every
 * server at once, or only agents', one customer group's or one server's — the latest gifts (servers' own grants among
 * them), each opening its own screen (MassGiftScreen), and «➕ هدیه تازه» (MassGiftWizard) while none runs.
 */
final class MassGiftsScreen extends BaseScreen
{
    public const ID = 'mg';

    /** Each state's mark (the panel's GRANT_STATUS). */
    public const MARKS = ['running' => '🟢', 'done' => '✅', 'cancelled' => '🔴'];

    public const TITLE = Icons::MASS_GIFT . ' هدیه همگانی';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $answer = $admin->api->get('/mass-grants');
        $grants = Json::rows($answer, 'grants');
        $running = array_filter($grants, static fn(array $grant): bool => ($grant['status'] ?? null) === 'running') !== [];
        $view = View::make(self::TITLE, ...BotScreens::PATH);
        if ($grants === []) {
            $view->line('هنوز هدیه‌ای داده نشده است.');
        } else {
            $view->total(count($grants), 'هدیه', $running ? 'یکی در حال انجام' : '');
        }
        foreach ($grants as $grant) {
            $view->row(Button::to((self::MARKS[(string) ($grant['status'] ?? '')] ?? '•') . ' ' . self::gift($grant) . ' · ' . self::audience($grant), MassGiftScreen::ID, 'show', (int) ($grant['id'] ?? 0)));
        }
        if ($running) {
            $view->line()->line('<i>هدیه تازه بعد از تمام شدن یا لغو هدیه در حال انجام.</i>');
        } else {
            $view->row(Button::to('➕ هدیه تازه', MassGiftWizard::ID, 'new')->success());
        }

        return $view->help('روز و حجم اضافه برای سرویس‌های فعال همه سرورها یکجا — یا فقط نماینده‌ها، مشتری‌های یک گروه یا یک سرور — با پیامی که هر مشتری در تلگرام یا با ایمیل می‌گیرد. هر هدیه روی هر سرور جدا اجرا می‌شود؛ سروری که در دسترس نباشد فقط بخش خودش را نگه می‌دارد و بخش هر سرور در صفحه همان سرور هم دیده می‌شود. '
            . self::MARKS['running'] . ' در حال انجام · ' . self::MARKS['done'] . ' انجام‌شده · ' . self::MARKS['cancelled'] . ' لغوشده')
            ->back(BotScreens::MENU)
            ->refresh(self::ID);
    }

    /**
     * What a gift gives each service, as the bot words it: "۳ روز و ۱۰ گیگابایت".
     *
     * @param array<string, mixed> $grant
     */
    public static function gift(array $grant): string
    {
        $days = (int) ($grant['days'] ?? 0);
        $bytes = (int) ($grant['traffic_bytes'] ?? 0);

        return Fmt::plain(implode(' و ', array_filter([$days > 0 ? Fmt::number($days) . ' روز' : null, $bytes > 0 ? Fmt::bytes($bytes) : null])));
    }

    /**
     * Whom a gift is for: everyone's services, the agents', one server's or one customer group's.
     *
     * @param array<string, mixed> $grant
     */
    public static function audience(array $grant): string
    {
        $server = is_array($grant['server'] ?? null) ? $grant['server'] : null;
        $group = is_array($grant['group'] ?? null) ? $grant['group'] : null;

        return match ($grant['audience'] ?? 'all') {
            'server' => 'سرویس‌های «' . (string) ($server['name'] ?? 'سرور حذف‌شده') . '»',
            'group' => $group !== null ? 'سرویس‌های گروه «' . (string) ($group['name'] ?? '') . '»' : 'سرویس‌های گروهی که حذف شد',
            'agents' => 'سرویس‌های نماینده‌ها',
            default => 'همه سرویس‌ها',
        };
    }
}
