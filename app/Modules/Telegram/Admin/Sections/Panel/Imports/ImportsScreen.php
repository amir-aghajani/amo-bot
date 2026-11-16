<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Imports;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\PanelSettingsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as Vocabulary;

/**
 * «انتقال از ربات دیگر» (the owner's, in the main shop): another bot's data brought into the shop. With none under way,
 * a new import (ImportStartScreen) and the earlier ones, each with its report (ImportReportScreen). The one under way is
 * read, reviewed and written in turn: read and written a step a tap — about fifteen seconds of the server's work each,
 * as the panel asks for one after another while it is open —, reviewed on its own screens (its panels, what to bring)
 * and started on a yes; cancelled, asked first.
 *
 * Actions: `step`, `start` (asks), `go` (the choices saved as they stand, then started), `cancel` (asks), `cancelled`.
 */
final class ImportsScreen extends BaseScreen
{
    public const ID = 'ppi';

    public const TITLE = Icons::IMPORTS . ' انتقال از ربات دیگر';

    /**
     * The path of a screen under it — «… › تنظیمات پنل › انتقال از ربات دیگر» and the sections `$more` between.
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return PanelSettingsScreen::under(Fmt::bare(self::TITLE), ...$more);
    }

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $page = $admin->api->get('/imports');
        $current = is_array($page['current'] ?? null) ? $page['current'] : null;
        $view = View::make(self::TITLE, ...PanelSettingsScreen::under());
        if ($current === null) {
            return $this->start($view, $page);
        }

        return match ($current['phase'] ?? '') {
            'review' => $this->review($view, $current),
            default => $this->progress($view, $current),
        };
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $current = $admin->api->get('/imports')['current'] ?? null;
        $current = is_array($current) ? $current : null;
        $id = (int) ($current['id'] ?? 0);
        if ($current === null) {
            return $this->show($admin, []);
        }
        switch ($action) {
            case 'step':
                return $this->step($admin, $id);
            case 'start':
                return $admin->confirm(
                    'انتقال شروع شود؟',
                    Button::to(Vocabulary::yes('شروع شود'), self::ID, 'go'),
                    Button::to(Vocabulary::CANCEL, self::ID),
                    'داده‌های انتخاب‌شده در این فروشگاه نوشته می‌شود. روی پنل‌های VPN و ربات قبلی چیزی تغییر نمی‌کند و به مشتری‌ها پیامی فرستاده نمی‌شود. انتقال ممکن است چند دقیقه طول بکشد؛ تا تمام شود، «▶️ ادامه» را بزنید. اگر این فروشگاه از قبل مشتری یا سرویس دارد، اول از «💾 پشتیبان‌گیری» یک پشتیبان بگیرید تا در صورت نیاز به همین حالت برگردید.',
                );
            case 'go':
                $admin->api->put("/imports/{$id}/choices", ['choices' => Words::shown(Words::choices($current))]);
                $admin->api->post("/imports/{$id}/start");
                $admin->notice('✅ انتقال شروع شد.');

                return $this->step($admin, $id);
            case 'cancel':
                return $admin->confirm(
                    'انتقال لغو شود؟',
                    Button::to(Vocabulary::yes('لغو شود'), self::ID, 'cancelled'),
                    Button::to('▶️ ادامه انتقال', self::ID),
                    (($current['phase'] ?? '') === 'importing' ? 'آنچه تا این لحظه منتقل شده در فروشگاه می‌ماند و بقیه منتقل نمی‌شود. ' : 'هنوز چیزی در فروشگاه نوشته نشده است. ')
                    . (($current['source'] ?? '') === 'database' ? 'داده‌هایی که از دیتابیس ربات قبلی خوانده شده پاک می‌شود' : 'فایل پشتیبان و داده‌هایی که از آن خوانده شده پاک می‌شود')
                    . '؛ هر وقت خواستید دوباره منتقل کنید، آنچه آمده دوباره نمی‌آید.',
                    danger: true,
                );
            case 'cancelled':
                $admin->api->delete("/imports/{$id}");
                $admin->notice('✅ انتقال لغو شد.');
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * One step of the import's reading or writing: on the progress, or — once it ends — its report. A step another holds
     * is said; a refused one too, the import shown where it stands.
     */
    private function step(Admin $admin, int $id): View
    {
        try {
            $answer = $admin->api->post("/imports/{$id}/continue");
        } catch (ApiRefusal $refusal) {
            $admin->alert($refusal->lines());

            return $this->show($admin, []);
        }
        $import = Json::part($answer, 'import');
        if (($answer['stepped'] ?? true) === false) {
            $admin->alert('کار دیگری الان روی این انتقال است؛ چند لحظه بعد دوباره بزنید.');
        }
        if (in_array($import['phase'] ?? '', ['done', 'failed', 'cancelled'], true)) {
            return $admin->open(ImportReportScreen::ID, 'show', [$id]);
        }

        return $this->show($admin, []);
    }

    /**
     * No import under way: a new one, and the earlier ones with their reports.
     *
     * @param array<string, mixed> $page
     */
    private function start(View $view, array $page): View
    {
        $view->line(Words::ABOUT)
            ->row(Button::to('➕ انتقال تازه', ImportStartScreen::ID)->primary())
            ->section('🗂 انتقال‌های قبلی');
        $history = array_values(array_filter(Json::part($page, 'history'), is_array(...)));
        if ($history === []) {
            $view->line('<i>هنوز انتقالی انجام نشده است.</i>');
        }
        foreach ($history as $each) {
            $view->row(Button::to(
                (string) (Words::PHASE[(string) ($each['phase'] ?? '')] ?? '') . ' ' . (string) ($each['migrator_label'] ?? '') . ' · ' . Fmt::plain(Fmt::date(is_string($each['finished_at'] ?? null) ? $each['finished_at'] : (is_string($each['updated_at'] ?? null) ? $each['updated_at'] : null))),
                ImportReportScreen::ID,
                'show',
                (int) ($each['id'] ?? 0),
            ));
        }

        return $view->back(PanelSettingsScreen::ID);
    }

    /**
     * Reading or writing: how much of the file or the database is read, or every stage in order with how far the one
     * under way got.
     *
     * @param array<string, mixed> $import
     */
    private function progress(View $view, array $import): View
    {
        $progress = Json::part($import, 'progress');
        $done = (int) ($progress['done'] ?? 0);
        $total = is_int($progress['total'] ?? null) ? $progress['total'] : null;
        $label = Fmt::ltr((string) ($import['migrator_label'] ?? ''));
        if (($import['phase'] ?? '') === 'reading') {
            $count = static fn(int $value): string => ($import['source'] ?? '') === 'backup' ? Fmt::bytes($value) : Fmt::number($value) . ' ردیف';
            $view->section('📖 در حال خواندن داده‌ها')
                ->line('داده‌های ' . $label . ' از ' . Words::source($import) . ' خوانده می‌شود. هنوز چیزی در فروشگاه نوشته نشده است.')
                ->line()
                ->line(($total === null ? $count($done) . ' خوانده شد' : $count($done) . ' از ' . $count($total) . ' خوانده شد') . ($total !== null && $total > 0 ? ' · ' . Fmt::percent(min(100, intdiv($done * 100, $total))) : ''));
        } else {
            $view->section('⏳ در حال انتقال')
                ->line('داده‌های ' . $label . ' در فروشگاه نوشته می‌شود. روی پنل‌ها و ربات قبلی چیزی تغییر نمی‌کند و به مشتری‌ها پیامی فرستاده نمی‌شود.')
                ->line();
            $stage = (string) ($progress['stage'] ?? '');
            $at = array_search($stage, array_keys(Words::STAGES), true);
            $place = 0;
            foreach (Words::STAGES as $key => $title) {
                $mark = match (true) {
                    $at === false, $place > $at => '◻️',
                    $place < $at => '✅',
                    default => '⏳',
                };
                $line = $mark . ' ' . ($key === $stage ? '<b>' . $title . '</b>' : $title);
                if ($key === $stage) {
                    $line .= ' · ' . ($total === null ? Fmt::number($done) . ' مورد' : Fmt::number(min($done, $total)) . ' از ' . Fmt::number($total));
                }
                $view->line($line);
                $place++;
            }
        }
        $view->note('هر بار که «▶️ ادامه» را بزنید، یک قدم (حدود ۱۵ ثانیه کار سرور) جلو می‌رود؛ تا تمام شود ادامه دهید. اگر رها کنید، با برگشتن به همین‌جا از همان‌جایی که مانده ادامه پیدا می‌کند.');

        return $view->row(Button::to('▶️ ادامه', self::ID, 'step')->primary())
            ->row(Button::to('⏹ لغو انتقال', self::ID, 'cancel')->danger())
            ->back(PanelSettingsScreen::ID)
            ->refresh(self::ID);
    }

    /**
     * The review, before anything is written: what the other bot holds, where its panels' services go, what to bring,
     * what never comes and the warnings; then «شروع انتقال».
     *
     * @param array<string, mixed> $import
     */
    private function review(View $view, array $import): View
    {
        $survey = Json::part($import, 'survey');
        $counts = Json::part($survey, 'counts');
        $count = static fn(string $kind): string => Fmt::number((int) ($counts[$kind] ?? 0));
        $version = is_string($import['version'] ?? null) ? $import['version'] : (is_string($survey['version'] ?? null) ? $survey['version'] : null);
        $view->section('📝 آنچه در ربات قبلی پیدا شد')
            ->line('داده‌های ' . Fmt::ltr((string) ($import['migrator_label'] ?? '')) . ($version !== null ? ' نسخه ' . Fmt::ltr($version) : '') . ' از ' . Words::source($import) . ' خوانده شد. هنوز چیزی در فروشگاه نوشته نشده است؛ انتخاب کنید چه چیزهایی منتقل شود.')
            ->card([
                'مشتری‌ها' => $count('customers') . ((int) ($counts['resellers'] ?? 0) > 0 ? ' (' . $count('resellers') . ' نفر از ربات‌های نماینده)' : ''),
                'سرویس‌های فعال' => $count('services') . ((int) ($counts['tests'] ?? 0) > 0 ? ' و ' . $count('tests') . ' اکانت تست' : ''),
                'موجودی کیف پول‌ها' => Fmt::money(is_scalar($survey['balance_total'] ?? null) ? (string) $survey['balance_total'] : '0') . ' (' . $count('balances') . ' مشتری موجودی دارند)',
                'پلن‌ها' => $count('plans'),
                'کدهای تخفیف' => $count('discount_codes'),
                'کدهای هدیه' => $count('gift_codes'),
                'کانال‌های اجباری' => $count('channels'),
                'کارت‌های بانکی' => $count('cards'),
            ]);
        $left = array_values(array_filter(Json::part($survey, 'left'), is_array(...)));
        if ($left !== []) {
            $view->section('🚫 آنچه منتقل نمی‌شود')->line('این‌ها در ربات قبلی می‌مانند؛ اگر لازمشان دارید، پیش از کنار گذاشتن ربات قبلی از آن‌ها نسخه بگیرید.');
            foreach ($left as $each) {
                $view->line('• ' . Fmt::e((string) ($each['what'] ?? '')) . ': ' . Fmt::number((int) ($each['count'] ?? 0)));
            }
        }
        $warnings = array_filter(Json::part($survey, 'warnings'), is_string(...));
        if ($warnings !== []) {
            $view->line()->line('⚠️ <b>پیش از شروع بدانید:</b>');
            foreach ($warnings as $warning) {
                $view->line('• ' . Fmt::e($warning));
            }
        }
        $view->line()->line('<i>' . (($import['source'] ?? '') === 'database'
            ? 'اگر این انتقال نهایی است، ربات قبلی باید پیش از خوانده شدن دیتابیسش خاموش شده باشد'
            : 'اگر این انتقال نهایی است، این پشتیبان باید بعد از خاموش کردن ربات قبلی گرفته شده باشد') . '؛ آنچه بعد از آن در ربات قبلی عوض شده (کدهای استفاده‌شده، خریدها، موجودی‌ها) منتقل نمی‌شود.</i>');

        $panels = is_array($survey['panels'] ?? null) ? count($survey['panels']) : 0;
        if ($panels > 0) {
            $view->row(Button::to('🖥 پنل‌ها' . Fmt::badge($panels), ImportPanelsScreen::ID));
        }

        return $view->row(Button::to('✅ چه چیزهایی منتقل شود', ImportChoicesScreen::ID))
            ->row(Button::to('▶️ شروع انتقال', self::ID, 'start')->primary(), Button::to('⏹ لغو انتقال', self::ID, 'cancel')->danger())
            ->back(PanelSettingsScreen::ID);
    }
}
