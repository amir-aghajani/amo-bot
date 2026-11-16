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
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * «🎁 افزودن زمان و حجم» on a server (the panel's grants card): days and traffic for the services running on it, the
 * latest grants with how far each got — what holds one up, the last service its panel refused —, a new one (not while
 * one runs: ServerGrantFormScreen), and stopping the one under way, asked first. The panel works the grant under way
 * while its page is open; here «⏩ پیشبرد» works a batch of it and shows where it is (the scheduler carries on anyway).
 *
 * Actions: `run` (grant id), `stop` (grant id: asks), `stopped` (grant id: cancels).
 */
final class ServerGrantsScreen extends BaseScreen
{
    public const ID = 'svg';

    public const TITLE = '🎁 افزودن زمان و حجم';

    /** The status words of a grant. */
    private const STATUS = ['running' => '⏳ در حال انجام', 'done' => '✅ انجام‌شده', 'cancelled' => '🚫 لغوشده'];

    /** The grants shown at most, the latest first. */
    private const SHOWN = 6;

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
        ServerWords::opened($admin, $id);
        $server = (array) ($admin->api->get("/servers/{$id}")['server'] ?? []);
        $grants = Json::rows($admin->api->get("/servers/{$id}/grants"), 'grants');

        $view = View::make(self::TITLE, UiWords::ROOT, Fmt::bare(ServersScreen::TITLE), (string) ($server['name'] ?? ''))
            ->line('روز و حجم اضافه برای سرویس‌های فعال این سرور، مثلا برای جبران قطعی.');
        if ($grants === []) {
            $view->line()->line('هنوز موردی نیست.');
        }
        $running = null;
        foreach (array_slice($grants, 0, self::SHOWN) as $grant) {
            $view->line()->lines(...self::lines($grant));
            if (($grant['status'] ?? null) === 'running') {
                $running = $grant;
            }
        }
        if ($running !== null) {
            $view->row(Button::to('⏩ پیشبرد', self::ID, 'run', (int) $running['id'])->primary(), Button::to('🚫 لغو افزودن زمان و حجم', self::ID, 'stop', (int) $running['id'])->danger());
        } else {
            $view->row(Button::to('🎁 افزودن به سرویس‌ها', ServerGrantFormScreen::ID, 'new', $id)->success());
        }

        return $view->back(ServerScreen::ID, 'show', $id)->refresh(self::ID, 'show', $id);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = ServerWords::current($admin, []);
        $grant = self::id($args);
        switch ($action) {
            case 'run':
                $row = (array) ($admin->api->post("/servers/{$id}/grants/{$grant}/run")['grant'] ?? []);
                $admin->notice(match ($row['status'] ?? null) {
                    'done' => (int) ($row['granted'] ?? 0) > 0 ? '✅ ' . self::gift($row) . ' به ' . Fmt::number((int) $row['granted']) . ' سرویس اضافه شد' : 'افزودن زمان و حجم تمام شد؛ سرویسی شامل نشد',
                    'running' => Fmt::plain(self::progress($row)),
                    default => 'به‌روز شد',
                });
                break;
            case 'stop':
                $row = $this->grant($admin, $id, $grant);

                return $admin->confirm(
                    'افزودن زمان و حجم لغو شود؟',
                    Button::to(UiWords::yes('لغو شود', '🚫'), self::ID, 'stopped', $grant),
                    Button::to(UiWords::CANCEL, self::ID),
                    Fmt::e(self::gift($row)) . ' به بقیه سرویس‌ها اضافه نمی‌شود.' . "\n" . 'سرویس‌هایی که تا این لحظه گرفته‌اند همان را نگه می‌دارند.',
                    danger: true,
                );
            case 'stopped':
                $admin->api->post("/servers/{$id}/grants/{$grant}/cancel");
                $admin->notice('🚫 افزودن زمان و حجم لغو شد');
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * What a grant gives each service, worded as the bot words it: «۳ روز و ۱۰ گیگابایت».
     *
     * @param array<string, mixed> $grant
     */
    public static function gift(array $grant): string
    {
        return implode(' و ', array_filter([
            (int) ($grant['days'] ?? 0) > 0 ? Fmt::number((int) $grant['days']) . ' روز' : null,
            (int) ($grant['traffic_bytes'] ?? 0) > 0 ? Fmt::bytes((int) $grant['traffic_bytes']) : null,
        ]));
    }

    /**
     * A grant's lines: what it gives and its status, the reason, how far it got (or how it went), what holds it up, the
     * last refusal, who issued it when, and its options.
     *
     * @param array<string, mixed> $grant
     * @return list<string>
     */
    private static function lines(array $grant): array
    {
        $running = ($grant['status'] ?? null) === 'running';
        $lines = ['<b>' . Fmt::e(self::gift($grant)) . '</b> · ' . Fmt::e(self::STATUS[(string) ($grant['status'] ?? '')] ?? (string) ($grant['status'] ?? ''))];
        if (is_string($grant['reason'] ?? null) && $grant['reason'] !== '') {
            $lines[] = '«' . Fmt::e(Fmt::cut($grant['reason'], 120)) . '»';
        }
        $lines[] = $running ? self::progress($grant) : self::outcome($grant);
        if ($running && is_string($grant['waiting_reason'] ?? null)) {
            $lines[] = '⚠️ کار روی این سرور تا رفع این مشکل پنل منتظر می‌ماند؛ خودکار دوباره تلاش می‌شود.';
            $lines[] = Fmt::e($grant['waiting_reason']);
        }
        if (is_string($grant['last_failure'] ?? null)) {
            $lines[] = '❌ آخرین خطا: ' . Fmt::e($grant['last_failure']);
        }
        $about = array_filter([
            Fmt::date(is_string($grant['created_at'] ?? null) ? $grant['created_at'] : null, true),
            Words::reviewer($grant),
            is_int($grant['mass_grant_id'] ?? null) ? 'بخشی از هدیه همگانی ' . Fmt::id($grant['mass_grant_id']) : null,
            Json::on($grant, 'agents_only') ? 'فقط سرویس‌های نماینده‌ها' : null,
            Json::on($grant, 'include_unstarted') ? 'با سرویس‌های در انتظار اولین اتصال' : null,
            ($grant['notify'] ?? true) !== true ? 'بدون پیام به مشتری‌ها' : null,
        ]);
        $lines[] = '<i>' . implode(' · ', $about) . '</i>';

        return $lines;
    }

    /**
     * A running grant's progress: «۵ از ۱۲ سرویس فعال بررسی شد · به ۳ سرویس اضافه شد · ۲ سرویس شامل نشد».
     *
     * @param array<string, mixed> $grant
     */
    private static function progress(array $grant): string
    {
        $total = (int) ($grant['total'] ?? 0);
        $reached = min((int) ($grant['granted'] ?? 0) + (int) ($grant['skipped'] ?? 0) + (int) ($grant['failed'] ?? 0), $total);
        $parts = [Fmt::number($reached) . ' از ' . Fmt::number($total) . ' سرویس فعال بررسی شد', 'به ' . Fmt::number((int) ($grant['granted'] ?? 0)) . ' سرویس اضافه شد'];

        return implode(' · ', [...$parts, ...self::rest($grant)]);
    }

    /**
     * How a finished grant went: «به ۱۲ سرویس اضافه شد · ۳ سرویس شامل نشد · ۱ ناموفق».
     *
     * @param array<string, mixed> $grant
     */
    private static function outcome(array $grant): string
    {
        $granted = (int) ($grant['granted'] ?? 0);

        return implode(' · ', [$granted > 0 ? 'به ' . Fmt::number($granted) . ' سرویس اضافه شد' : 'به سرویسی اضافه نشد', ...self::rest($grant)]);
    }

    /**
     * @param array<string, mixed> $grant
     * @return list<string>
     */
    private static function rest(array $grant): array
    {
        return array_values(array_filter([
            (int) ($grant['skipped'] ?? 0) > 0 ? Fmt::number((int) $grant['skipped']) . ' سرویس شامل نشد' : null,
            (int) ($grant['failed'] ?? 0) > 0 ? Fmt::number((int) $grant['failed']) . ' ناموفق' : null,
        ]));
    }

    /**
     * The grant among the server's latest; none when it is not there.
     *
     * @return array<string, mixed>
     */
    private function grant(Admin $admin, int $server, int $id): array
    {
        foreach ((array) ($admin->api->get("/servers/{$server}/grants")['grants'] ?? []) as $grant) {
            if (is_array($grant) && (int) ($grant['id'] ?? 0) === $id) {
                return $grant;
            }
        }

        return [];
    }
}
