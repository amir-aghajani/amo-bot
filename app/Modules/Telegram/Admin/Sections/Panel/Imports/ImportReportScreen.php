<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Imports;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * An import that ended — done, failed or cancelled —: how it ended (a failure's words), what it did of each kind and why
 * some things did not come, each listed a page at a time (ImportProblemsScreen). «➕ انتقال تازه» starts another; its
 * record can be deleted, asked first (what it brought stays, and a later import still knows it).
 *
 * Actions: `del` (id: asks), `gone` (id: deletes).
 */
final class ImportReportScreen extends BaseScreen
{
    public const ID = 'ppio';

    private const TITLES = ['done' => '✅ انتقال تمام شد', 'failed' => '⚠️ انتقال انجام نشد', 'cancelled' => '✖️ انتقال لغو شد'];

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $id = self::id($args);
        $import = Words::import($admin, $id);
        $phase = (string) ($import['phase'] ?? '');
        if (!isset(self::TITLES[$phase])) {
            return $admin->open(ImportsScreen::ID);
        }
        $started = is_string($import['started_at'] ?? null);
        $view = View::make(self::TITLES[$phase], 'مدیریت', 'تنظیمات پنل', 'انتقال از ربات دیگر')
            ->line('داده‌های ' . Fmt::ltr((string) ($import['migrator_label'] ?? '')) . ' از ' . Words::source($import)
                . ' · ' . Fmt::date(is_string($import['finished_at'] ?? null) ? $import['finished_at'] : (is_string($import['updated_at'] ?? null) ? $import['updated_at'] : null), true)
                . (is_string($import['reviewer'] ?? null) ? ' · ' . Fmt::e($import['reviewer']) : ''))
            ->line()
            ->line(match ($phase) {
                'done' => 'داده‌های ربات قبلی در فروشگاه نوشته شد. اگر پشتیبان تازه‌تری از ربات قبلی دارید (مثلا با مشتری‌هایی که بعد از این پشتیبان آمده‌اند)، می‌توانید با آن دوباره منتقل کنید؛ آنچه این بار آمده دوباره نمی‌آید و موجودی‌ها دوباره اضافه نمی‌شوند. آخرین فایل پشتیبان ربات قبلی را نگه دارید: اگر نسخه‌ای بعدی از AmoBot چیزی از «آنچه نیامد» را آورد، با همان فایل دوباره منتقل کنید.',
                'failed' => '⚠️ ' . Fmt::e(is_string($import['error'] ?? null) ? $import['error'] : 'انتقال ناتمام ماند.') . ' ' . ($started ? 'آنچه پیش از آن منتقل شده بود در فروشگاه مانده است.' : 'چیزی در فروشگاه نوشته نشد.'),
                default => $started ? 'آنچه پیش از لغو منتقل شده بود در فروشگاه مانده است. هر وقت خواستید دوباره منتقل کنید؛ آنچه آمده دوباره نمی‌آید.' : 'پیش از نوشتن لغو شد؛ چیزی در فروشگاه نوشته نشد.',
            });

        $counts = is_array($import['counts'] ?? null) ? $import['counts'] : null;
        if ($counts !== null) {
            $view->line()->line('<b>آنچه منتقل شد</b>');
            $lines = [];
            $kinds = Words::COUNT_KINDS;
            foreach (array_keys($counts) as $kind) {
                $kinds[(string) $kind] ??= Words::thing((string) $kind);
            }
            foreach ($kinds as $kind => $label) {
                $count = is_array($counts[$kind] ?? null) ? $counts[$kind] : null;
                if ($count === null || (int) ($count['created'] ?? 0) + (int) ($count['linked'] ?? 0) + (int) ($count['skipped'] ?? 0) === 0) {
                    continue;
                }
                $lines[] = '• ' . Fmt::e((string) $label) . ': ' . Fmt::number((int) ($count['created'] ?? 0)) . ' تازه · ' . Fmt::number((int) ($count['linked'] ?? 0)) . ' از قبل بود · ' . Fmt::number((int) ($count['skipped'] ?? 0)) . ' نیامد';
            }
            $view->lines(...($lines === [] ? ['چیزی منتقل نشد.'] : $lines));
        }

        $problems = Json::part($import, 'problems');
        $reasons = array_values(array_filter(Json::part($problems, 'by_reason'), is_array(...)));
        $listed = array_filter($reasons, static fn(array $reason): bool => !in_array($reason['reason'] ?? '', Words::COUNTED, true));
        if ($reasons !== []) {
            $view->line()->line('<b>آنچه نیامد</b>: هر مورد با دلیلش. مواردی که پنلشان جواب نداد، با اجرای دوباره انتقال از همان پشتیبان یا پشتیبان تازه‌تر منتقل می‌شوند.');
            foreach ($reasons as $reason) {
                $view->line('• ' . Fmt::e((string) ($reason['label'] ?? '')) . ': ' . Fmt::number((int) ($reason['count'] ?? 0)));
            }
        }
        if ($listed !== []) {
            $view->row(Button::to('📋 آنچه نیامد', ImportProblemsScreen::ID, 'show', $id));
        }

        return $view->row(Button::to('➕ انتقال تازه', ImportStartScreen::ID), Button::to('🗑 حذف گزارش', self::ID, 'del', $id))
            ->back(ImportsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'del':
                return $admin->confirm(
                    'گزارش این انتقال حذف شود؟',
                    Button::to('🗑 حذف گزارش', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    'فقط گزارش این انتقال حذف می‌شود. آنچه منتقل شده در فروشگاه می‌ماند و انتقال بعدی هم آن را دوباره نمی‌آورد.',
                );
            case 'gone':
                $admin->api->delete('/imports/' . $id);
                $admin->notice('✅ گزارش انتقال حذف شد.');

                return $admin->open(ImportsScreen::ID);
        }

        return $this->show($admin, $args);
    }
}
