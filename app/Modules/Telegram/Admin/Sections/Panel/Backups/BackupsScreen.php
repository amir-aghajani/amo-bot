<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Backups;

use App\Modules\Telegram\Admin\Door\ApiFile;
use App\Modules\Telegram\Admin\Sections\Bot\ReportGroupScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\PanelSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Schedule\ScheduleScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «پشتیبان‌گیری» (the owner's and an agent's): of the whole installation for the owner in the main shop, of the shop alone
 * for any other seat — the API says which. A backup made now, sent here as a file; its sending to the report group
 * switched (how often, and when it last went or why it did not); a restore from a file sent here (RestoreScreen); and the
 * states kept from just before each restore, each sent as a file.
 *
 * Actions: `dl` (a backup made now), `auto` (1|0: the group's sending switched to it), `kept` (n: a kept state).
 */
final class BackupsScreen extends BaseScreen
{
    public const ID = 'ppb';

    public const TITLE = '💾 پشتیبان‌گیری';

    public function show(Admin $admin, array $args): View
    {
        $status = self::status($admin);
        $auto = Json::part($status, 'auto');
        $kept = self::kept($status);
        $whole = ($status['scope'] ?? '') === 'installation';
        $on = Json::on($auto, 'enabled');
        $problem = is_string($auto['last_problem'] ?? null) ? $auto['last_problem'] : null;
        $sent = is_string($auto['last_sent_at'] ?? null) ? $auto['last_sent_at'] : null;
        $group = ($auto['group'] ?? false) === true;

        $view = View::make(self::TITLE, ...PanelSettingsScreen::under());
        if (!$group) {
            $view->line(($on ? '⚠️ ' : 'ℹ️ ') . 'برای ارسال خودکار، اول باید گروه گزارش‌ها به ربات وصل شود.');
        }
        if ($problem !== null) {
            $view->line('⚠️ آخرین ارسال' . ($sent !== null ? ' (' . Fmt::date($sent, true) . ')' : '') . ' انجام نشد: ' . Fmt::e($problem));
        }
        $view->card([
            'پشتیبان از' => Tables::holds($status),
            'ارسال خودکار به گروه گزارش‌ها' => Fmt::onOff($on),
            'فاصله ارسال' => self::every((int) ($auto['every_minutes'] ?? 1440)),
            'آخرین ارسال' => $sent !== null ? Fmt::date($sent, true) : 'هنوز فرستاده نشده',
            'حالت‌های پیش از بازگردانی' => $kept === [] ? 'هیچ؛ هنوز بازگردانی‌ای نشده' : Fmt::number(count($kept)),
        ]);

        $view->row(Button::to('📥 دریافت فایل پشتیبان', self::ID, 'dl')->primary())
            ->row(Button::to(Words::turn(!$on, 'ارسال خودکار'), self::ID, 'auto', $on ? 0 : 1))
            ->row(
                $group ? null : Button::to(Icons::REPORT_GROUP . ' اتصال گروه گزارش‌ها', ReportGroupScreen::ID),
                Access::main($admin->seat) ? Button::to(ScheduleScreen::TITLE, ScheduleScreen::ID) : null,
            )
            ->row(Button::to(RestoreScreen::TITLE . ' از فایل', RestoreScreen::ID));
        foreach ($kept as $n => $each) {
            $view->row(Button::to('📥 پیش از بازگردانی · ' . Fmt::date((string) ($each['made_at'] ?? ''), true) . ' · ' . Fmt::plain(Fmt::bytes((int) ($each['size'] ?? 0))), self::ID, 'kept', $n));
        }

        return $view->help(implode("\n\n", [
            '<b>📥 دریافت پشتیبان</b>: یک فایل پشتیبان همین حالا ساخته و همین‌جا فرستاده می‌شود؛ ساختنش ممکن است چند ثانیه طول بکشد. ' . "فایل فقط در همین نصب \u{2068}AmoBot\u{2069} بازگردانی می‌شود. آن را جای امنی نگه دارید؛ هرکس آن را داشته باشد، داده‌های مشتری‌ها را دارد.",
            '<b>🔁 ارسال خودکار</b>: فایل پشتیبان به تاپیک «پشتیبان‌گیری» گروه گزارش‌ها فرستاده می‌شود. گروه را خصوصی نگه دارید؛ هرکس در آن باشد، فایل را دارد. ' . (Access::main($admin->seat) ? 'فاصله ارسال در «تنظیمات پنل › زمان‌بندی کارها» تعیین می‌شود.' : 'فاصله ارسال را مالک فروشگاه اصلی تعیین می‌کند.'),
            '<b>♻️ بازگردانی</b>: ' . ($whole ? 'کل نصب' : 'فروشگاه ' . Tables::shop(is_array($status['shop'] ?? null) ? $status['shop'] : null)) . ' را به حالت یک فایل پشتیبان برگردانید. اول فایل بررسی و محتوایش نشان داده می‌شود؛ تا تایید نکنید، چیزی تغییر نمی‌کند.',
            '<b>🗂 پیش از بازگردانی‌ها</b>: پیش از هر بازگردانی، حالت همان لحظه نگه داشته می‌شود (سه مورد آخر)، تا اگر فایل اشتباهی بازگردانی شد، بتوانید با آن به حالت قبل برگردید.',
        ]))->back(PanelSettingsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'dl':
                $status = self::status($admin);
                if (self::deliver($admin, $admin->api->file('/backups/download'), '💾 پشتیبان ' . Tables::holds($status) . ' · ' . Fmt::date(now()->toIso8601String(), true))) {
                    $admin->notice('✅ فایل پشتیبان فرستاده شد.');
                }
                break;
            case 'auto':
                // The button sets the value it showed: tapped twice, or from an old message, it changes nothing.
                $want = ($args[0] ?? '') === '1';
                if ((((array) (self::status($admin)['auto'] ?? []))['enabled'] ?? false) === $want) {
                    $admin->notice('ارسال خودکار همین حالا ' . ($want ? 'روشن' : 'خاموش') . ' است.');
                    break;
                }
                $admin->api->put('/backups/auto', ['enabled' => $want]);
                $admin->notice($want ? '▶️ ارسال خودکار روشن شد.' : '⏸ ارسال خودکار خاموش شد.');
                break;
            case 'kept':
                $each = self::kept(self::status($admin))[self::id($args)] ?? null;
                if ($each !== null && self::deliver($admin, $admin->api->file('/backups/kept/' . rawurlencode((string) ($each['name'] ?? ''))), '🗂 حالت پیش از بازگردانی · ' . Fmt::date((string) ($each['made_at'] ?? ''), true))) {
                    $admin->notice('✅ فایل فرستاده شد.');
                }
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * A backup file sent under the screen; false — said in a popup (Admin::send()) — when Telegram would not take it (a bot
     * sends no file past 50 MB) or was out of reach.
     */
    public static function deliver(Admin $admin, ApiFile $file, string $caption): bool
    {
        return $admin->send($file, $caption);
    }

    /**
     * GET /backups's `backups`.
     *
     * @return array<string, mixed>
     */
    public static function status(Admin $admin): array
    {
        return (array) ($admin->api->get('/backups')['backups'] ?? []);
    }

    /**
     * The states kept from before a restore, newest first.
     *
     * @param array<string, mixed> $status
     * @return list<array<string, mixed>>
     */
    private static function kept(array $status): array
    {
        return array_values(array_filter(Json::part($status, 'kept'), is_array(...)));
    }

    /** How often the backup goes, in words: «هر ۲۴ ساعت», «هر ۲ روز», «هر ۹۰ دقیقه». */
    private static function every(int $minutes): string
    {
        return match (true) {
            $minutes >= 2 * 1440 && $minutes % 1440 === 0 => 'هر ' . Fmt::number(intdiv($minutes, 1440)) . ' روز',
            $minutes >= 60 && $minutes % 60 === 0 => 'هر ' . Fmt::number(intdiv($minutes, 60)) . ' ساعت',
            default => 'هر ' . Fmt::number($minutes) . ' دقیقه',
        };
    }
}
