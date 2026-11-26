<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Subscriptions\DTO\Gift;
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
use App\Support\Unit;

/**
 * «🎁 افزایش زمان و حجم» for one service (the panel's ExtendForm): days and GB on top of what it
 * has — the panel's presets at a tap, any amount typed; each off when the service cannot take it (no end date, no
 * quota) —, a note for the customer and whether to tell them, then one request. In an agent's shop the GB come out of
 * the bot's traffic, said with what is left; days cost nothing. The draft is the screen's state until sent.
 *
 * Actions (each with the service's id): `new` (a fresh form, as the service's button opens it), `d` (days: 0 none),
 * `g` (GB: 0 none), `dt` / `gt` / `nt` (ask days, GB, the note), `nx` (the note off), `nf` (1|0: tell the customer or
 * not), `go`.
 */
final class ExtendScreen extends BaseScreen
{
    public const ID = 'sbe';

    /** The screen's title, as the service's button says it. */
    public const TITLE = Sales::EXTEND . ' افزایش زمان و حجم';

    /** The presets offered at a tap. */
    private const DAYS = [7, 30];
    private const GB = [10, 50];

    public function show(Admin $admin, array $args): View
    {
        $id = self::id($args);

        return $this->view($admin, self::service($admin, $id), self::draft($admin, $id));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        $draft = self::draft($admin, $id);
        switch ($action) {
            case 'new':
                $admin->forget(self::ID);
                break;
            case 'd':
                $days = self::id($args, 1);
                $admin->remember(self::ID, ['days' => $draft['days'] === $days ? 0 : $days]);
                break;
            case 'g':
                $gb = (string) self::id($args, 1);
                $admin->remember(self::ID, ['gb' => $gb === '0' || $draft['gb'] === $gb ? '' : $gb]);
                break;
            case 'dt':
                $prompt = (new Prompt('تعداد روز', self::ID, 'dt', [$id], 'روزهایی که به سرویس اضافه می‌شود.'))
                    ->current($draft['days'] > 0 ? Fmt::plain(Fmt::number($draft['days'])) . ' روز' : null, false)
                    ->example('۱۵')
                    ->limits('از ۱ تا ' . Fmt::plain(Fmt::number(Gift::MAX_DAYS)) . ' روز');
                if ($draft['days'] > 0) {
                    $prompt->clearable(self::ID, 'd', $id, 0);
                }

                return $admin->ask($prompt);
            case 'gt':
                $prompt = (new Prompt('حجم (گیگابایت)', self::ID, 'gt', [$id], 'گیگابایتی که به سرویس اضافه می‌شود؛ «۲۰ گیگ» هم پذیرفته می‌شود.'))
                    ->current((float) $draft['gb'] > 0 ? Fmt::plain(self::gigabytes($draft['gb'])) : null, false)
                    ->example('۱٫۵')
                    ->limits('بیش از صفر، حداکثر ' . Fmt::plain(Fmt::number(Gift::MAX_GB)) . ' گیگابایت');
                if ((float) $draft['gb'] > 0) {
                    $prompt->clearable(self::ID, 'g', $id, 0);
                }

                return $admin->ask($prompt);
            case 'nt':
                $prompt = (new Prompt('توضیح برای مشتری', self::ID, 'nt', [$id], 'در پیامی که مشتری در تلگرام یا با ایمیل می‌گیرد نشان داده می‌شود.'))
                    ->current($draft['note'] === '' ? null : $draft['note'])
                    ->example('جبران قطعی دیروز')
                    ->limits(Sales::NOTE_LIMIT);
                if ($draft['note'] !== '') {
                    $prompt->clearable(self::ID, 'nx', $id);
                }

                return $admin->ask($prompt);
            case 'nx':
                $admin->remember(self::ID, ['note' => '']);
                break;
            case 'nf':
                $admin->remember(self::ID, ['notify' => ($args[1] ?? '') === '1']);
                break;
            case 'go':
                return $this->extended($admin, $id, $draft);
        }

        return $this->show($admin, [(string) $id]);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);
        self::draft($admin, $id);
        switch ($action) {
            case 'dt':
                $days = $answer->amount(Unit::Day);
                if ($days === null) {
                    throw new ApiRefusal(422, 'تعداد روز را به عدد بنویسید، مثل ۱۵.');
                }
                $admin->remember(self::ID, ['days' => $days]);
                break;
            case 'gt':
                $gb = $answer->decimal() ?? (($whole = $answer->amount(Unit::Gigabyte)) === null ? null : (string) $whole);
                if ($gb === null) {
                    throw new ApiRefusal(422, 'حجم را به گیگابایت بنویسید، مثل ۲۰ یا ۱٫۵.');
                }
                $admin->remember(self::ID, ['gb' => $gb]);
                break;
            case 'nt':
                $admin->remember(self::ID, ['note' => $answer->text()]);
                break;
        }

        return $this->show($admin, [(string) $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function service(Admin $admin, int $id): array
    {
        return Json::part($admin->api->get("/subscriptions/{$id}"), 'subscription');
    }

    /**
     * The draft of service `$id`'s extension — a new one when the state holds another service's.
     *
     * @return array{id: int, days: int, gb: string, note: string, notify: bool}
     */
    private static function draft(Admin $admin, int $id): array
    {
        $state = $admin->state(self::ID);
        if (($state['id'] ?? null) !== $id) {
            $admin->forget(self::ID);
            $admin->remember(self::ID, ['id' => $id, 'days' => 0, 'gb' => '', 'note' => '', 'notify' => true]);
            $state = $admin->state(self::ID);
        }

        return [
            'id' => $id,
            'days' => is_int($state['days'] ?? null) ? $state['days'] : 0,
            'gb' => is_string($state['gb'] ?? null) ? $state['gb'] : '',
            'note' => is_string($state['note'] ?? null) ? $state['note'] : '',
            'notify' => ($state['notify'] ?? true) !== false,
        ];
    }

    /**
     * Send the extension: the service as it now is, said with what it got.
     *
     * @param array{id: int, days: int, gb: string, note: string, notify: bool} $draft
     */
    private function extended(Admin $admin, int $id, array $draft): View
    {
        $service = Json::part($admin->api->post("/subscriptions/{$id}/extend", [
            'days' => $draft['days'] > 0 ? $draft['days'] : '',
            'traffic_gb' => $draft['gb'],
            'note' => $draft['note'],
            'notify' => $draft['notify'],
        ]), 'subscription');
        $gift = implode(' و ', array_filter([$draft['days'] > 0 ? 'زمان' : '', (float) $draft['gb'] > 0 ? 'حجم' : '']));
        $admin->forget(self::ID);
        $admin->notice(Sales::EXTEND . ' ' . $gift . ' به سرویس ' . "\u{2068}" . (string) ($service['name'] ?? '') . "\u{2069}" . ' اضافه شد');

        return $admin->open(SubscriptionScreen::ID, 'show', [$id]);
    }

    /**
     * What days do to the service — onto its end date, or its term while it waits for its first connection —, or why it
     * takes none: [hint, off].
     *
     * @param array<string, mixed> $service
     * @return array{0: string, 1: bool}
     */
    private static function daysHint(array $service): array
    {
        return match (true) {
            Json::text($service, 'expires_at') !== null => ['به تاریخ پایان سرویس اضافه می‌شود.', false],
            (int) ($service['duration_hours'] ?? 0) > 0 => ['به مدت سرویس اضافه می‌شود؛ زمانش از اولین اتصال مشتری شروع می‌شود.', false],
            default => ['این سرویس تاریخ پایان ندارد.', true],
        };
    }

    /**
     * What traffic does to the service — and, in an agent's shop, what it comes out of, with what is left of it (the
     * agent's own) —, or why it takes none: [hint, off, the bot's traffic left or null].
     *
     * @param array<string, mixed> $service
     * @return array{0: string, 1: bool, 2: int|null}
     */
    private static function trafficHint(Admin $admin, array $service): array
    {
        if ((int) (Json::part($service, 'traffic')['limit'] ?? 0) <= 0) {
            return ['حجم این سرویس نامحدود است.', true, null];
        }
        if ($admin->seat->shop()->isMain()) {
            return ['روی حجم سرویس اضافه می‌شود.', false, null];
        }
        $left = null;
        if ($admin->seat->isAgent()) {
            $bot = Json::part(Json::part($admin->api->get('/account'), 'account'), 'bot');
            $left = is_numeric($bot['traffic_balance'] ?? null) ? (int) $bot['traffic_balance'] : null;
        }

        return ['روی حجم سرویس اضافه و از حجم ربات این فروشگاه کم می‌شود.', false, $left];
    }

    /** GB as typed («1.5»), said: «۱٫۵ گیگابایت». */
    private static function gigabytes(string $gb): string
    {
        return Fmt::digits(str_replace('.', '٫', $gb)) . ' گیگابایت';
    }

    /**
     * @param array<string, mixed> $service
     * @param array{id: int, days: int, gb: string, note: string, notify: bool} $draft
     */
    private function view(Admin $admin, array $service, array $draft): View
    {
        $id = $draft['id'];
        [$daysHint, $daysOff] = self::daysHint($service);
        [$trafficHint, $trafficOff, $pool] = self::trafficHint($admin, $service);
        $view = View::make(self::TITLE, ...Sales::under(Sales::SERVICES, (string) ($service['name'] ?? '')))->card([
            'روز' => $daysOff ? Fmt::e($daysHint) : ($draft['days'] > 0 ? Fmt::number($draft['days']) . ' روز' : Fmt::NONE),
            'حجم' => $trafficOff ? Fmt::e($trafficHint) : ((float) $draft['gb'] > 0 ? self::gigabytes($draft['gb']) : Fmt::NONE),
            'توضیح برای مشتری' => $draft['note'] === '' ? Fmt::NONE : Fmt::e(Fmt::cut($draft['note'], 120)),
            'پیام به مشتری' => Fmt::onOff($draft['notify']),
            'حجم مانده ربات' => $pool === null ? null : Fmt::bytes($pool),
        ]);

        $chip = static fn(bool $picked): string => $picked ? Words::CHIP : '';
        if (!$daysOff) {
            $view->row(...[
                ...array_map(static fn(int $days): Button => Button::to($chip($draft['days'] === $days) . Fmt::number($days) . ' روز', self::ID, 'd', $id, $days), self::DAYS),
                Button::to(Words::edit('روز دلخواه'), self::ID, 'dt', $id),
            ]);
        }
        if (!$trafficOff) {
            $view->row(...[
                ...array_map(static fn(int $gb): Button => Button::to($chip($draft['gb'] === (string) $gb) . Fmt::number($gb) . ' گیگ', self::ID, 'g', $id, $gb), self::GB),
                Button::to(Words::edit('حجم دلخواه'), self::ID, 'gt', $id),
            ]);
        }
        $view->row(Button::to(Words::edit('توضیح برای مشتری'), self::ID, 'nt', $id), Button::to(Words::turn(!$draft['notify'], 'پیام به مشتری'), self::ID, 'nf', $id, $draft['notify'] ? 0 : 1));
        if ($draft['days'] > 0 || (float) $draft['gb'] > 0) {
            $view->row(Button::to(Words::yes('اضافه شود'), self::ID, 'go', $id)->success());
        }

        return $view->help(implode(' ', array_filter([
            $daysOff ? null : 'روز: ' . Fmt::e($daysHint),
            $trafficOff ? null : 'حجم: ' . Fmt::e($trafficHint),
            'مشتری در تلگرام یا با ایمیل می‌بیند چه چیزی به سرویسش اضافه شد، مگر «پیام به مشتری» خاموش باشد.',
        ])))->back(SubscriptionScreen::ID, 'show', $id);
    }
}
