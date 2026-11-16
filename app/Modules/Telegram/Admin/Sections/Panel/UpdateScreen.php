<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «به‌روزرسانی» (the owner's): the running version, the newest release and its notes, and the one-press update — a step
 * a tap (each one request, as the panel drives it; a shared host gives a request half a minute) until it is installed,
 * with how far it got. A failed step offers a retry (and a cancel before the install starts); a done update can be
 * rolled back while the server allows. The start and the rollback are asked first, in the panel's words.
 *
 * Actions: `check`, `start` (asks), `go` (begins, and takes the first step), `step`, `cancel`, `back` (asks), `rollback`.
 */
final class UpdateScreen extends BaseScreen
{
    public const ID = 'ppu';

    public const TITLE = '⬆️ به‌روزرسانی';

    /** The update by hand — a host the shop cannot update itself on: the guide's page. */
    private const BY_HAND = 'https://github.com/amir-aghajani/amo-bot/wiki/Upgrading';

    /** An update's steps, in the order a run takes them, worded for the owner. */
    private const STEPS = [
        'download' => 'دانلود و بررسی امضا',
        'extract' => 'باز کردن بسته',
        'preflight' => 'بررسی هاست',
        'install' => 'نصب',
    ];

    /** The most of a release's notes shown: the rest is on GitHub. */
    private const NOTES_MOST = 1500;

    public function allows(AdminSeat $seat): bool
    {
        return Access::system($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        return $this->view((array) ($admin->api->get('/system/update')['update'] ?? []));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'check':
                $update = (array) ($admin->api->post('/system/update/check')['update'] ?? []);
                $admin->notice('✅ بررسی شد.');

                return $this->view($update);
            case 'start':
                $update = (array) ($admin->api->get('/system/update')['update'] ?? []);
                $version = (string) (((array) ($update['latest'] ?? []))['version'] ?? '');

                return $admin->confirm(
                    'به‌روزرسانی به نسخه ' . $version . '؟',
                    Button::to(Words::yes('به‌روز شود'), self::ID, 'go'),
                    Button::to(Words::CANCEL, self::ID),
                    "پیش از به‌روزرسانی از دیتابیس نسخه پشتیبان بگیرید («💾 پشتیبان‌گیری»). فایل‌های نسخه تازه از \u{2068}GitHub\u{2069} دانلود و امضایشان بررسی می‌شود، بعد فایل‌های برنامه عوض می‌شوند؛ در این چند لحظه فروشگاه به همه درخواست‌ها جواب «در حال به‌روزرسانی» می‌دهد. تنظیمات (\u{2068}config.php\u{2069}) و فایل‌های پوشه \u{2068}storage\u{2069} دست نمی‌خورند.",
                );
            case 'go':
                $update = (array) ($admin->api->get('/system/update')['update'] ?? []);
                $admin->api->post('/system/update/start', ['version' => (string) (((array) ($update['latest'] ?? []))['version'] ?? '')]);

                return $this->step($admin);
            case 'step':
                return $this->step($admin);
            case 'cancel':
                $update = (array) ($admin->api->post('/system/update/cancel')['update'] ?? []);
                $admin->notice('✅ به‌روزرسانی لغو شد.');

                return $this->view($update);
            case 'back':
                $run = (array) (((array) ($admin->api->get('/system/update')['update'] ?? []))['run'] ?? []);

                return $admin->confirm(
                    'بازگرداندن نسخه ' . (string) ($run['from'] ?? '') . '؟',
                    Button::to(Words::yes('نسخه قبلی برگردد'), self::ID, 'rollback'),
                    Button::to(Words::CANCEL, self::ID),
                    'نسخه ' . Fmt::ltr((string) ($run['version'] ?? '')) . ' برداشته می‌شود و نسخه ' . Fmt::ltr((string) ($run['from'] ?? '')) . ' که پیش از به‌روزرسانی روی فروشگاه بود برمی‌گردد؛ در این چند لحظه فروشگاه به همه درخواست‌ها جواب «در حال به‌روزرسانی» می‌دهد.',
                    danger: true,
                );
            case 'rollback':
                $update = (array) ($admin->api->post('/system/update/rollback')['update'] ?? []);
                $admin->notice('✅ نسخه قبلی برگشت.');

                return $this->view($update);
        }

        return $this->show($admin, $args);
    }

    /** One step of the run under way; a refused one is said, and the screen shows where it stopped. */
    private function step(Admin $admin): View
    {
        try {
            $update = (array) ($admin->api->post('/system/update/step')['update'] ?? []);
        } catch (ApiRefusal $refusal) {
            $admin->alert($refusal->lines());

            return $this->show($admin, []);
        }
        $run = (array) ($update['run'] ?? []);
        if (($run['step'] ?? null) === 'done') {
            $admin->notice('✅ به‌روزرسانی تمام شد.');
        }

        return $this->view($update);
    }

    /** @param array<string, mixed> $update GET /system/update's `update` */
    private function view(array $update): View
    {
        $current = (string) ($update['current'] ?? '');
        $latest = is_array($update['latest'] ?? null) ? $update['latest'] : null;
        $run = is_array($update['run'] ?? null) ? $update['run'] : null;
        $view = View::make(self::TITLE, ...PanelSettingsScreen::under())
            ->card([
                'نسخه فعلی' => Fmt::ltr($current),
                'آخرین بررسی' => is_string($update['checked_at'] ?? null) ? Fmt::date($update['checked_at'], true) : 'هنوز بررسی نشده',
            ]);

        $buttons = [];
        if ($latest === null) {
            $view->line('نسخه‌ای منتشر نشده است.');
        } elseif (!version_compare((string) ($latest['version'] ?? '0'), $current, '>')) {
            $view->line('✅ این تازه‌ترین نسخه است.');
        } else {
            $view->line('🆕 نسخه ' . Fmt::ltr((string) ($latest['version'] ?? '')) . ' منتشر شده است' . (is_string($latest['published_at'] ?? null) ? ' · ' . Fmt::date($latest['published_at']) : '') . '.');
            if (is_string($latest['notes'] ?? null) && trim($latest['notes']) !== '') {
                $view->line('<blockquote expandable>' . Fmt::e(mb_strlen($latest['notes']) > self::NOTES_MOST ? mb_substr($latest['notes'], 0, self::NOTES_MOST) . '…' : $latest['notes']) . '</blockquote>');
            }
            if (is_string($latest['url'] ?? null)) {
                $buttons[] = Button::url("🔗 این نسخه در \u{2068}GitHub\u{2069}", $latest['url']);
            }
        }
        $blocker = is_string($update['blocker'] ?? null) ? $update['blocker'] : null;
        if ($blocker !== null) {
            $view->line()->line('⚠️ ' . Fmt::e($blocker));
            $buttons[] = Button::url('📖 راهنمای به‌روزرسانی دستی', self::BY_HAND);
        }
        $view->grid(1, ...$buttons);
        if (Json::on($update, 'available') && $latest !== null && $blocker === null) {
            $view->row(Button::to('⬇️ به‌روزرسانی به نسخه ' . (string) ($latest['version'] ?? ''), self::ID, 'start')->primary());
        }
        if ($run !== null) {
            $this->run($view, $run);
        }

        return $view->row(Button::to('🔍 بررسی نسخه تازه', self::ID, 'check'))
            ->help("نسخه تازه AmoBot از \u{2068}GitHub\u{2069}، با یک دکمه. فقط نسخه‌ای نصب می‌شود که امضای سازنده AmoBot را دارد؛ تنظیمات و فایل‌های فروشگاه دست نمی‌خورند. هر بار که «ادامه» را بزنید، یک مرحله انجام می‌شود؛ اگر کار را نیمه رها کنید، از همان‌جا ادامه می‌دهید.")
            ->back(PanelSettingsScreen::ID);
    }

    /**
     * The update under way — its steps, how far the one at hand is, a refusal and the ways on — or the last one, over.
     *
     * @param array<string, mixed> $run
     */
    private function run(View $view, array $run): void
    {
        $version = Fmt::ltr((string) ($run['version'] ?? ''));
        $from = Fmt::ltr((string) ($run['from'] ?? ''));
        $finished = Fmt::date(is_string($run['finished_at'] ?? null) ? $run['finished_at'] : null, true);
        $step = (string) ($run['step'] ?? '');
        $view->line();
        if ($step === 'rolled_back') {
            $view->line('ℹ️ نسخه ' . $version . ' برداشته شد و نسخه ' . $from . ' برگشت (' . $finished . ').');

            return;
        }
        if ($step === 'done') {
            $view->line('✅ فروشگاه از نسخه ' . $from . ' به نسخه ' . $version . ' به‌روز شد (' . $finished . ').');
            if (Json::on($run, 'rollback')) {
                $view->row(Button::to('↩️ بازگرداندن نسخه قبلی', self::ID, 'back')->danger());
            }

            return;
        }

        $error = is_string($run['error'] ?? null) && $run['error'] !== '' ? $run['error'] : null;
        $at = array_search($step, array_keys(self::STEPS), true);
        $view->line('<b>به‌روزرسانی به نسخه ' . $version . '</b>');
        $place = 0;
        foreach (self::STEPS as $key => $title) {
            $mark = match (true) {
                $at === false, $place > $at => '◻️',
                $place < $at => '✅',
                $error !== null => '⚠️',
                default => '⏳',
            };
            $percent = $key === 'extract' && $key === $step && (int) ($run['progress'] ?? 0) > 0 ? ' · ' . Fmt::percent((int) $run['progress']) : '';
            $view->line($mark . ' ' . ($key === $step ? '<b>' . $title . '</b>' : $title) . $percent);
            $place++;
        }
        if ($error !== null) {
            $view->line('⚠️ ' . Fmt::e($error));
        }
        $view->row(
            Button::to($error !== null ? '🔁 تلاش دوباره' : '▶️ ادامه به‌روزرسانی', self::ID, 'step')->primary(),
            Json::on($run, 'cancel') ? Button::to('⏹ لغو به‌روزرسانی', self::ID, 'cancel')->danger() : null,
        );
    }
}
