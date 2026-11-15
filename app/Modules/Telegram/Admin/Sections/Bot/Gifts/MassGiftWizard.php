<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Gifts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\Unit;

/**
 * «🎁 هدیه جدید» (the panel's mass gift dialog), field by field — to which services (every one, the agents', one
 * server's, one customer group's: each with how many it would reach), the days, the traffic, the reason (may be
 * skipped) —, then its summary: how many services it reaches as it stands, each field to edit, «سرویس‌های در انتظار
 * اولین اتصال» and «پیام به مشتری‌ها» switched at a tap, and «✔️ شروع هدیه» — one POST /mass-grants. The draft is the
 * screen's state.
 *
 * Actions: `new` (a new draft, its first question), `aud` (pick), `a` (audience), `t` (server or group id), `days`,
 * `gb`, `why` (asks), `q` (field, a quick amount), `skip` (field), `inc`, `msg` (switches: 1 on, 0 off), `sum`, `go`.
 */
final class MassGiftWizard extends BaseScreen
{
    public const ID = 'mgn';

    private const AUDIENCES = ['all' => 'همه سرویس‌ها', 'agents' => 'فقط سرویس‌های نماینده‌ها', 'server' => 'سرویس‌های یک سرور', 'group' => 'سرویس‌های مشتری‌های یک گروه'];

    private const DRAFT = ['audience' => 'all', 'server_id' => '', 'group_id' => '', 'days' => '', 'traffic_gb' => '', 'reason' => '', 'include_unstarted' => false, 'notify' => true];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    /** The draft where it was left (a question cancelled), or a new one. */
    public function show(Admin $admin, array $args): View
    {
        return $admin->state(self::ID) === [] ? $this->act($admin, 'new', []) : $this->summary($admin);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $draft = $this->draft($admin);
        switch ($action) {
            case 'new':
                $admin->forget(self::ID);
                $admin->remember(self::ID, self::DRAFT);

                return $this->audiences($admin);
            case 'q':
                $field = $args[0] ?? '';
                $admin->remember(self::ID, [$field === 'gb' ? 'traffic_gb' : 'days' => (string) self::id($args, 1)]);

                return $this->next($admin, $field === 'gb' ? 'gb' : 'days', $draft);
            case 'aud':
                return $this->audiences($admin);
            case 'a':
                $audience = $args[0] ?? '';
                if (!isset(self::AUDIENCES[$audience])) {
                    return $this->audiences($admin);
                }
                $admin->remember(self::ID, ['audience' => $audience, 'server_id' => '', 'group_id' => '']);
                if ($audience === 'server' || $audience === 'group') {
                    return $this->targets($admin, $audience);
                }

                return $draft['asked'] ? $this->summary($admin) : $this->ask($admin, 'days');
            case 't':
                $admin->remember(self::ID, [$draft['audience'] === 'group' ? 'group_id' : 'server_id' => (string) self::id($args)]);

                return $draft['asked'] ? $this->summary($admin) : $this->ask($admin, 'days');
            case 'days':
            case 'gb':
            case 'why':
                return $this->ask($admin, $action);
            case 'skip':
                $field = $args[0] ?? '';
                $admin->remember(self::ID, [match ($field) {
                    'days' => 'days',
                    'gb' => 'traffic_gb',
                    default => 'reason',
                } => '']);

                return $this->next($admin, $field, $draft);
            case 'inc':
            case 'msg':
                // As the button said (1 on, 0 off): a second tap leaves it so.
                $switch = $action === 'inc' ? 'include_unstarted' : 'notify';
                $admin->remember(self::ID, [$switch => isset($args[0]) ? $args[0] === '1' : !$draft[$switch]]);

                return $this->summary($admin);
            case 'go':
                return $this->start($admin, $draft);
        }

        return $this->summary($admin);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $draft = $this->draft($admin);
        switch ($action) {
            case 'days':
                $days = $answer->amount(Unit::Day) ?? $answer->integer() ?? throw new ApiRefusal(422, 'تعداد روز را با عدد بنویسید، مثل ۷.');
                $admin->remember(self::ID, ['days' => (string) $days]);
                break;
            case 'gb':
                $gb = $answer->amount(Unit::Gigabyte) ?? $answer->decimal() ?? throw new ApiRefusal(422, 'حجم را به گیگابایت بنویسید، مثل ۱۰ یا ۰٫۵.');
                $admin->remember(self::ID, ['traffic_gb' => (string) $gb]);
                break;
            case 'why':
                $admin->remember(self::ID, ['reason' => mb_substr($answer->text(), 0, 300)]);
                break;
            default:
                return $this->summary($admin);
        }

        return $this->next($admin, $action, $draft);
    }

    /**
     * After a field: the next one while the draft is new (days, traffic, reason), else the summary.
     *
     * @param array<string, mixed> $before The draft as it was before the field
     */
    private function next(Admin $admin, string $field, array $before): View
    {
        $fresh = !(bool) ($before['asked'] ?? false);
        if ($fresh && $field === 'days') {
            return $this->ask($admin, 'gb');
        }
        if ($fresh && $field === 'gb') {
            return $this->ask($admin, 'why');
        }
        $admin->remember(self::ID, ['asked' => true]);

        return $this->summary($admin);
    }

    private function audiences(Admin $admin): View
    {
        $reach = $this->reach($admin);
        $view = View::make('🎁 هدیه جدید: به چه سرویس‌هایی', 'مدیریت', 'ربات', 'هدیه همگانی');
        $current = $this->draft($admin)['audience'];
        $buttons = [];
        foreach (self::AUDIENCES as $key => $label) {
            $count = isset($reach[$key]) && is_array($reach[$key]) ? ' (' . Fmt::plain(Fmt::number((int) ($reach[$key]['running'] ?? 0))) . ')' : '…';
            $buttons[] = Button::to(($current === $key ? '● ' : '') . $label . $count, self::ID, 'a', $key);
        }

        return $view->line('عدد کنار هر گزینه، سرویس‌های فعالی است که هدیه به آن‌ها می‌رسد.')->grid(1, ...$buttons)->row(Button::to('✖️ انصراف', MassGiftsScreen::ID));
    }

    /** One server or one customer group to pick, each with how many of its services run. */
    private function targets(Admin $admin, string $audience): View
    {
        $options = array_values(array_filter((array) ($this->reach($admin)[$audience === 'group' ? 'groups' : 'servers'] ?? []), is_array(...)));
        $view = View::make($audience === 'group' ? '👥 گروه مشتریان' : '🖥 سرور', 'مدیریت', 'ربات', 'هدیه همگانی');
        if ($options === []) {
            $view->line($audience === 'group' ? 'هنوز گروهی نیست؛ گروه‌ها در صفحه کاربران ساخته می‌شوند.' : 'سروری سرویس فعال ندارد.');
        }
        $buttons = array_map(static fn(array $option): Button => Button::to((string) ($option['name'] ?? '') . ' (' . Fmt::plain(Fmt::number((int) ($option['running'] ?? 0))) . ' سرویس)', self::ID, 't', (int) ($option['id'] ?? 0)), $options);

        return $view->grid(1, ...$buttons)->row(Button::to('⬅️ بازگشت', self::ID, 'aud'));
    }

    private function ask(Admin $admin, string $field): View
    {
        $draft = $this->draft($admin);
        [$question, $hint, $current] = match ($field) {
            'days' => ['تعداد روز', 'به تاریخ پایان هر سرویس اضافه می‌شود.', $draft['days']],
            'gb' => ['حجم (گیگابایت)', 'روی حجم هر سرویس اضافه می‌شود.', $draft['traffic_gb']],
            default => ['دلیل (اختیاری)', 'در پیامی که مشتری در تلگرام یا با ایمیل می‌گیرد نشان داده می‌شود؛ مثلا: هدیه نوروز. حداکثر ۳۰۰ کاراکتر.', $draft['reason']],
        };
        $prompt = new Prompt($question, self::ID, $field, [], $hint . ($current !== '' ? "\nالان: " . Fmt::e((string) $current) : ''), Button::to('✖️ لغو', self::ID, 'sum'));
        $quick = match ($field) {
            'days' => [Button::to('۷ روز', self::ID, 'q', $field, 7), Button::to('۳۰ روز', self::ID, 'q', $field, 30)],
            'gb' => [Button::to('۱۰ گیگابایت', self::ID, 'q', $field, 10), Button::to('۵۰ گیگابایت', self::ID, 'q', $field, 50)],
            default => [],
        };

        return $admin->ask($prompt->choices(...[...$quick, Button::to('⏭ رد شدن', self::ID, 'skip', $field)]));
    }

    private function summary(Admin $admin): View
    {
        $draft = $this->draft($admin);
        $reach = $this->reach($admin);
        $target = $this->target($reach, $draft);
        $view = View::make('🎁 هدیه جدید', 'مدیریت', 'ربات', 'هدیه همگانی');
        $chosen = $draft['audience'] === 'server' || $draft['audience'] === 'group' ? ($target === null ? null : (string) ($target['name'] ?? '')) : '';
        $view->facts([
            'به چه سرویس‌هایی' => Fmt::e(self::AUDIENCES[$draft['audience']] ?? '') . ($chosen !== null && $chosen !== '' ? ': ' . Fmt::e($chosen) : ($chosen === null ? ': انتخاب نشده' : '')),
            'تعداد روز' => $draft['days'] === '' ? Fmt::NONE : Fmt::number((int) $draft['days']) . ' روز',
            'حجم' => $draft['traffic_gb'] === '' ? Fmt::NONE : Fmt::digits(str_replace('.', '٫', (string) $draft['traffic_gb'])) . ' گیگابایت',
            'دلیل' => $draft['reason'] === '' ? Fmt::NONE : Fmt::e((string) $draft['reason']),
            'سرویس‌های در انتظار اولین اتصال' => Fmt::yesNo((bool) $draft['include_unstarted']),
            'پیام به مشتری‌ها' => Fmt::yesNo((bool) $draft['notify']),
        ]);
        $view->line()->line(Fmt::e($this->reachText($target, $draft)));
        $total = $target === null ? 0 : (int) ($target['running'] ?? 0) + ((bool) $draft['include_unstarted'] ? (int) ($target['unstarted'] ?? 0) : 0);
        $unstarted = $target === null ? 0 : (int) ($target['unstarted'] ?? 0);
        $view->note('سرویس‌های در انتظار اولین اتصال: سرویس‌هایی که مشتری هنوز به آن‌ها وصل نشده و زمانشان شروع نشده است. پیام به مشتری‌ها: هر مشتری در تلگرام یا با ایمیل می‌بیند چه چیزی به سرویسش اضافه شد.');

        $view->row(Button::to('✏️ مخاطب', self::ID, 'aud'), Button::to('✏️ روز', self::ID, 'days'))
            ->row(Button::to('✏️ حجم', self::ID, 'gb'), Button::to('✏️ دلیل', self::ID, 'why'));
        $switches = [Button::to(((bool) $draft['notify'] ? '✅' : '◻️') . ' پیام به مشتری‌ها', self::ID, 'msg', (bool) $draft['notify'] ? 0 : 1)];
        if ($unstarted > 0 || (bool) $draft['include_unstarted']) {
            array_unshift($switches, Button::to(((bool) $draft['include_unstarted'] ? '✅' : '◻️') . ' در انتظار اولین اتصال', self::ID, 'inc', (bool) $draft['include_unstarted'] ? 0 : 1));
        }
        $view->row(...$switches);
        if ($target !== null && $total > 0) {
            $view->row(Button::to('✔️ شروع هدیه', self::ID, 'go'));
        }

        return $view->row(Button::to('✖️ انصراف', MassGiftsScreen::ID));
    }

    /**
     * Start the gift as drafted; refused, the summary stays and the refusal is said.
     *
     * @param array<string, mixed> $draft
     */
    private function start(Admin $admin, array $draft): View
    {
        $grant = (array) ($admin->api->post('/mass-grants', [
            'audience' => $draft['audience'],
            'server_id' => $draft['audience'] === 'server' ? $draft['server_id'] : '',
            'group_id' => $draft['audience'] === 'group' ? $draft['group_id'] : '',
            'days' => $draft['days'],
            'traffic_gb' => $draft['traffic_gb'],
            'reason' => $draft['reason'],
            'include_unstarted' => (bool) $draft['include_unstarted'],
            'notify' => (bool) $draft['notify'],
        ])['grant'] ?? []);
        $admin->forget(self::ID);
        $admin->notice('✅ هدیه همگانی شروع شد');

        return $admin->open(MassGiftScreen::ID, 'show', [(int) ($grant['id'] ?? 0)]);
    }

    /**
     * Whom the gift reaches as drafted, as the panel's form says it.
     *
     * @param array<string, mixed>|null $target
     * @param array<string, mixed> $draft
     */
    private function reachText(?array $target, array $draft): string
    {
        $untouched = 'سرویس‌های تمام‌شده و غیرفعال‌شده دست نمی‌خورند.';
        if ($target === null) {
            return $draft['audience'] === 'group' ? 'اول گروهی انتخاب کنید که به سرویس‌های مشتری‌هایش اضافه شود.' : 'اول سروری انتخاب کنید که به سرویس‌هایش اضافه شود.';
        }
        $running = (int) ($target['running'] ?? 0);
        $unstarted = (int) ($target['unstarted'] ?? 0);
        $total = $running + ((bool) $draft['include_unstarted'] ? $unstarted : 0);
        $active = $running + $unstarted;
        if ($total > 0) {
            return ($active > $total ? 'از ' . Fmt::plain(Fmt::number($active)) . ' سرویس فعال، به ' . Fmt::plain(Fmt::number($total)) . ' سرویس' : 'به ' . Fmt::plain(Fmt::number($total)) . ' سرویس') . ' اضافه می‌شود؛ ' . $untouched;
        }

        return 'فعلا سرویسی نیست که این به آن اضافه شود؛ ' . $untouched . ($unstarted > 0 ? ' سرویس‌های در انتظار اولین اتصال را می‌شود شامل کرد.' : '');
    }

    /**
     * Whom the drafted audience reaches: everyone's or the agents' reach, the picked server's or group's; null while
     * one of them is meant and none is picked.
     *
     * @param array<string, mixed> $reach
     * @param array<string, mixed> $draft
     * @return array<string, mixed>|null
     */
    private function target(array $reach, array $draft): ?array
    {
        $audience = (string) $draft['audience'];
        if ($audience === 'server' || $audience === 'group') {
            $id = (int) $draft[$audience === 'server' ? 'server_id' : 'group_id'];
            foreach ((array) ($reach[$audience === 'server' ? 'servers' : 'groups'] ?? []) as $option) {
                if (is_array($option) && (int) ($option['id'] ?? 0) === $id) {
                    return $option;
                }
            }

            return null;
        }

        return is_array($reach[$audience] ?? null) ? $reach[$audience] : null;
    }

    /**
     * Whom a new gift would reach, per audience (GET /mass-grants' `audience`).
     *
     * @return array<string, mixed>
     */
    private function reach(Admin $admin): array
    {
        $audience = $admin->api->get('/mass-grants')['audience'] ?? [];

        return is_array($audience) ? $audience : [];
    }

    /**
     * The draft, every field there.
     *
     * @return array{audience: string, server_id: string, group_id: string, days: string, traffic_gb: string, reason: string, include_unstarted: bool, notify: bool, asked?: bool}
     */
    private function draft(Admin $admin): array
    {
        $state = $admin->state(self::ID) + self::DRAFT;

        return [
            'audience' => (string) $state['audience'],
            'server_id' => (string) $state['server_id'],
            'group_id' => (string) $state['group_id'],
            'days' => (string) $state['days'],
            'traffic_gb' => (string) $state['traffic_gb'],
            'reason' => (string) $state['reason'],
            'include_unstarted' => (bool) $state['include_unstarted'],
            'notify' => (bool) $state['notify'],
            'asked' => (bool) ($state['asked'] ?? false),
        ];
    }
}
