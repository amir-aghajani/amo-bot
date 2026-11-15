<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🔀 انتقال سرویس‌ها» on a server (the panel's migrations card): every shop's services on it moved to another server —
 * «مهاجرت» while its panel answers, «نجات» when it does not —, the latest moves with how far each got and what holds one
 * up or ended it; the open one paused, resumed, cancelled or carried on as a rescue (the last two asked first, in the
 * panel's words); the services a move could not carry; and the clients moves left on its panel, removed now or
 * forgotten (asked first). «🔄 پیشبرد» works a turn of the move under way, as the panel's card does while open.
 *
 * Actions: `run` (job), `pause` (job), `resume` (job), `cancel` (job: asks), `cancelled` (job), `rescue` (job: asks),
 * `rescued` (job), `clean`, `forget` (asks), `forgot`.
 */
final class ServerMigrationsScreen extends BaseScreen
{
    public const ID = 'svm';

    /** The two ways services move (MIGRATION_MODE). */
    public const MODES = ['migrate' => 'مهاجرت', 'rescue' => 'نجات'];

    /** A move's status (MIGRATION_STATUS). */
    private const STATUS = ['queued' => '🕓 در صف', 'running' => '⏳ در حال انجام', 'paused' => '⏸ متوقف‌شده', 'done' => '✅ انجام‌شده', 'cancelled' => '🚫 لغوشده', 'failed' => '⛔️ ناتمام'];

    /** What each owner's action is said as once done. */
    private const DONE = ['pause' => '⏸ انتقال متوقف شد', 'resume' => '▶️ انتقال ادامه پیدا کرد', 'cancelled' => '🚫 انتقال لغو شد', 'rescued' => '🛟 انتقال در حالت نجات ادامه پیدا می‌کند'];

    /** The moves shown at most, the latest first. */
    private const SHOWN = 5;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $id = ServerWords::current($admin, $args);
        if ($id === 0) {
            return $admin->open(ServersScreen::ID);
        }
        $admin->remember(ServerScreen::ID, ['id' => $id]);
        $server = (array) ($admin->api->get("/servers/{$id}")['server'] ?? []);
        $answer = $admin->api->get("/servers/{$id}/migrations");
        $jobs = Json::rows($answer, 'migrations');
        $leftovers = (array) ($answer['leftovers'] ?? []);

        $view = View::make('🔀 انتقال سرویس‌ها', 'مدیریت', 'سرورها', (string) ($server['name'] ?? ''))
            ->line('همه سرویس‌های این سرور، از همه فروشگاه‌ها، به یک سرور دیگر؛ برای خالی کردن سرور، یا نجات سرویس‌ها وقتی پنلش از دسترس خارج شده است.');
        $pending = (int) ($leftovers['pending'] ?? 0);
        if ($pending > 0) {
            $view->line()->line('🧹 ' . Fmt::number($pending) . ' کلاینت از انتقال سرویس‌ها روی پنل این سرور مانده است. اگر پنل دوباره جواب بدهد این کلاینت‌ها کار می‌کنند، پس همان موقع خودکار حذف می‌شوند.');
            if (is_string($leftovers['last_error'] ?? null)) {
                $view->line('آخرین تلاش: ' . Fmt::e($leftovers['last_error']));
            }
            $view->row(Button::to('🧹 حذف همین حالا', self::ID, 'clean'), Button::to('🫥 فراموش کردن', self::ID, 'forget'));
        }

        $open = null;
        foreach (array_slice($jobs, 0, self::SHOWN) as $job) {
            $view->line()->lines(...self::lines($job));
            $jobId = (int) ($job['id'] ?? 0);
            $status = (string) ($job['status'] ?? '');
            if (in_array($status, ['queued', 'running', 'paused'], true)) {
                $open = $job;
                $view->row(
                    in_array($status, ['queued', 'running'], true) ? Button::to('🔄 پیشبرد', self::ID, 'run', $jobId) : null,
                    in_array($status, ['queued', 'running'], true) ? Button::to('⏸ توقف موقت', self::ID, 'pause', $jobId) : null,
                    $status === 'paused' ? Button::to('▶️ ادامه', self::ID, 'resume', $jobId) : null,
                    Button::to('🚫 لغو انتقال', self::ID, 'cancel', $jobId),
                );
                // A rescue goes on without the source alone: a target out of reach holds it as well.
                if (($job['waiting_on'] ?? null) === 'source') {
                    $view->row(Button::to('🛟 ادامه با حالت نجات', self::ID, 'rescue', $jobId));
                }
            }
            if ((int) ($job['failed'] ?? 0) > 0) {
                $view->row(Button::to('❌ سرویس‌های ناموفق «' . (string) ($job['target']['name'] ?? '') . '»', ServerMigrationFailuresScreen::ID, 'show', $jobId));
            }
        }
        if ($open === null) {
            $view->row(Button::to('🔀 انتقال سرویس‌ها', ServerMigrationFormScreen::ID, 'new', $id));
        }

        return $view->back(ServerScreen::ID, 'show', $id);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = ServerWords::current($admin, []);
        $job = self::id($args);
        switch ($action) {
            case 'run':
                $row = (array) ($admin->api->post("/servers/{$id}/migrations/{$job}/run")['migration'] ?? []);
                $admin->notice(match ($row['status'] ?? null) {
                    'done' => (int) ($row['moved'] ?? 0) > 0 ? '✅ ' . Fmt::number((int) $row['moved']) . ' سرویس به «' . (string) ($row['target']['name'] ?? '') . '» منتقل شد' : 'انتقال تمام شد؛ سرویسی منتقل نشد',
                    'failed' => '⛔️ انتقال ناتمام ماند؛ دلیلش این‌جا آمده است',
                    default => Fmt::plain(self::reached($row)),
                });
                break;
            case 'pause':
            case 'resume':
            case 'cancelled':
            case 'rescued':
                $route = ['cancelled' => 'cancel', 'rescued' => 'rescue'][$action] ?? $action;
                $admin->api->post("/servers/{$id}/migrations/{$job}/{$route}");
                $admin->notice(self::DONE[$action]);
                break;
            case 'cancel':
                return $admin->confirm(
                    'انتقال لغو شود؟',
                    Button::to('🚫 بله، لغو شود', self::ID, 'cancelled', $job),
                    Button::to('✖️ انصراف', self::ID),
                    $this->target($admin, $id, $job) . 'سرویس‌هایی که منتقل شده‌اند روی سرور مقصد می‌مانند و بقیه روی همین سرور.',
                );
            case 'rescue':
                return $admin->confirm(
                    'ادامه با حالت نجات؟',
                    Button::to('🛟 ادامه با حالت نجات', self::ID, 'rescued', $job),
                    Button::to('✖️ انصراف', self::ID),
                    $this->target($admin, $id, $job) . 'از این به بعد پنل این سرور پرسیده نمی‌شود: هر سرویس با آخرین اطلاعاتی که فروشگاه از آن دارد روی سرور مقصد ساخته می‌شود و کلاینت قبلی روی پنل این سرور می‌ماند تا وقتی دوباره جواب بدهد حذف شود.',
                );
            case 'clean':
                $left = (int) ($admin->api->post("/servers/{$id}/leftovers/clean")['leftovers']['pending'] ?? 0);
                $admin->notice($left === 0 ? '✅ کلاینت‌های مانده از پنل این سرور حذف شدند' : '⚠️ ' . Fmt::number($left) . ' کلاینت هنوز روی پنل مانده است');
                break;
            case 'forget':
                return $admin->confirm(
                    'کلاینت‌های مانده فراموش شوند؟',
                    Button::to('🫥 بله، فراموش شوند', self::ID, 'forgot'),
                    Button::to('✖️ انصراف', self::ID),
                    'فقط برای پنلی که دیگر برنمی‌گردد: این کلاینت‌ها دیگر حذف نمی‌شوند و اگر پنل دوباره جواب بدهد، کار می‌کنند.',
                );
            case 'forgot':
                $admin->api->delete("/servers/{$id}/leftovers");
                $admin->notice('✅ فراموش شد');
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * A move's lines: where to and how, its status, how far it got (or how it went), what holds it up or ended it, the
     * last refusal, who started it when, and its options.
     *
     * @param array<string, mixed> $job
     * @return list<string>
     */
    private static function lines(array $job): array
    {
        $status = (string) ($job['status'] ?? '');
        $open = in_array($status, ['queued', 'running', 'paused'], true);
        $lines = ['<b>به «' . Fmt::e((string) ($job['target']['name'] ?? '')) . '»</b> · ' . Fmt::e(self::MODES[(string) ($job['mode'] ?? '')] ?? '') . ' · ' . Fmt::e(self::STATUS[$status] ?? $status)];
        $lines[] = $open ? self::reached($job) . ' · ' . self::outcome($job) : self::outcome($job);
        if ($open && is_string($job['waiting_reason'] ?? null)) {
            $lines[] = '⚠️ ' . (($job['waiting_on'] ?? null) === 'source'
                ? 'انتقال تا جواب دادن این پنل منتظر می‌ماند و خودکار دوباره تلاش می‌شود. اگر پنل این سرور از دسترس خارج شده است، با حالت نجات ادامه دهید.'
                : 'انتقال تا جواب دادن این پنل منتظر می‌ماند و خودکار دوباره تلاش می‌شود.');
            $lines[] = Fmt::e($job['waiting_reason']);
        }
        if ($status === 'failed' && is_string($job['reason'] ?? null)) {
            $lines[] = '⛔️ ' . Fmt::e($job['reason']) . ' سرویس‌های باقی‌مانده روی همین سرور هستند؛ بعد از رفع مشکل، انتقال تازه‌ای شروع کنید.';
        }
        if (is_string($job['last_failure'] ?? null)) {
            $lines[] = '❌ آخرین خطا: ' . Fmt::e($job['last_failure']);
        }
        $plans = is_array($job['plans'] ?? null) ? implode('، ', array_map(static fn(mixed $plan): string => is_array($plan) ? (string) ($plan['name'] ?? '') : '', $job['plans'])) : null;
        $lines[] = '<i>' . implode(' · ', array_filter([
            Fmt::date(is_string($job['created_at'] ?? null) ? $job['created_at'] : null, true),
            Words::reviewer($job),
            Json::on($job, 'include_disabled') ? 'با سرویس‌های غیرفعال‌شده' : null,
            $plans !== null ? 'فقط ' . Fmt::e($plans) : null,
            ($job['notify'] ?? true) !== true ? 'بدون پیام به مشتری‌ها' : null,
        ])) . '</i>';

        return $lines;
    }

    /**
     * How far a move got: «۵ از ۱۲ سرویس بررسی شد».
     *
     * @param array<string, mixed> $job
     */
    private static function reached(array $job): string
    {
        $total = (int) ($job['total'] ?? 0);

        return Fmt::number(min((int) ($job['moved'] ?? 0) + (int) ($job['skipped'] ?? 0) + (int) ($job['failed'] ?? 0), $total)) . ' از ' . Fmt::number($total) . ' سرویس بررسی شد';
    }

    /**
     * What a move did: «۴ سرویس منتقل شد · ۱ سرویس رد شد · ۱ ناموفق · ۴ کلاینت روی پنل مانده است».
     *
     * @param array<string, mixed> $job
     */
    private static function outcome(array $job): string
    {
        $moved = (int) ($job['moved'] ?? 0);

        return implode(' · ', array_filter([
            $moved > 0 ? Fmt::number($moved) . ' سرویس منتقل شد' : 'سرویسی منتقل نشد',
            (int) ($job['skipped'] ?? 0) > 0 ? Fmt::number((int) $job['skipped']) . ' سرویس رد شد' : null,
            (int) ($job['failed'] ?? 0) > 0 ? Fmt::number((int) $job['failed']) . ' ناموفق' : null,
            (int) ($job['left_behind'] ?? 0) > 0 ? Fmt::number((int) $job['left_behind']) . ' کلاینت روی پنل مانده است' : null,
        ]));
    }

    /** «انتقال به «…»», the move's target, over its confirmation (HTML, a line). */
    private function target(Admin $admin, int $server, int $job): string
    {
        $row = (array) ($admin->api->get("/servers/{$server}/migrations/{$job}")['migration'] ?? []);

        return 'انتقال به «' . Fmt::e((string) ($row['target']['name'] ?? '')) . '»' . "\n";
    }
}
