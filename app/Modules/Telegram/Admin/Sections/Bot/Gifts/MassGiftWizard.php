<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Gifts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Unit;

/**
 * «🎁 هدیه تازه» (the panel's mass gift dialog), field by field, each step saying where it is («گام ۲ از ۴») — to which
 * services (every one, the agents', one server's, one customer group's: each with how many it would reach), the days,
 * the traffic, the reason (may be skipped), each question with the value now, an example and its limits, «⬅️ گام قبل»
 * and «⏭ رد شدن» —, then its review: the draft whole in one card and how many services it reaches as it stands, each
 * field to edit, «سرویس‌های در انتظار اولین اتصال» and «پیام به مشتری‌ها» switched as their buttons say, and «✔️ شروع
 * هدیه» — one POST /mass-grants. A question cancelled lands on the review, which every field then comes back to. The
 * draft is the screen's state.
 *
 * Actions: `new` (a new draft, its first question), `aud` (pick), `a` (audience), `t` (server or group id), `tp` (a page
 * of them), `days`, `gb`, `why` (asks), `q` (field, a quick amount), `skip` (field), `inc`, `msg` (switches: 1 on, 0
 * off), `sum`, `go`.
 */
final class MassGiftWizard extends BaseScreen
{
    public const ID = 'mgn';

    private const AUDIENCES = ['all' => 'همه سرویس‌ها', 'agents' => 'فقط سرویس‌های نماینده‌ها', 'server' => 'سرویس‌های یک سرور', 'group' => 'سرویس‌های مشتری‌های یک گروه'];

    /** The fields a new draft walks: the audience, the days, the traffic, the reason; then its review. */
    private const STEPS = 4;

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
            case 'tp':
                // A page of the servers or groups to pick.
                return in_array($draft['audience'], ['server', 'group'], true) ? $this->targets($admin, $draft['audience'], self::page($args)) : $this->audiences($admin);
            case 'sum':
                // A question cancelled lands on the review, which from then on is where every field goes back to.
                $admin->remember(self::ID, ['asked' => true]);

                return $this->summary($admin);
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

    /** «گام ۲ از ۴» while the draft is new (its fields asked one by one); nothing once it is on its review. */
    private function step(Admin $admin, int $step): string
    {
        return $this->draft($admin)['asked'] ? '' : Words::step($step, self::STEPS);
    }

    private function audiences(Admin $admin): View
    {
        $reach = $this->reach($admin);
        $step = $this->step($admin, 1);
        $draft = $this->draft($admin);
        $view = View::make(Icons::MASS_GIFT . ' هدیه تازه: به چه سرویس‌هایی', ...[...BotScreens::PATH, Fmt::bare(MassGiftsScreen::TITLE)]);
        if ($step !== '') {
            $view->line('<i>' . $step . '</i>');
        }
        $buttons = [];
        foreach (self::AUDIENCES as $key => $label) {
            $count = isset($reach[$key]) && is_array($reach[$key]) ? ' (' . Fmt::plain(Fmt::number((int) ($reach[$key]['running'] ?? 0))) . ')' : '…';
            $buttons[] = Button::to(($draft['audience'] === $key ? Words::PICKED : '') . $label . $count, self::ID, 'a', $key);
        }

        return $view->line('عدد کنار هر گزینه، سرویس‌های فعالی است که هدیه به آن‌ها می‌رسد.')
            ->grid(1, ...$buttons)
            ->row($draft['asked'] ? Button::to(Words::CANCEL, self::ID, 'sum') : Button::to(Words::CANCEL, MassGiftsScreen::ID));
    }

    /**
     * One server or one customer group to pick, each with how many of its services run — the one picked marked —, a page
     * of View::CHOICES_MOST at a time.
     */
    private function targets(Admin $admin, string $audience, int $page = 1): View
    {
        $draft = $this->draft($admin);
        $options = array_values(array_filter((array) ($this->reach($admin)[$audience === 'group' ? 'groups' : 'servers'] ?? []), is_array(...)));
        $picked = (int) $draft[$audience === 'group' ? 'group_id' : 'server_id'];
        $view = View::make($audience === 'group' ? Icons::GROUPS . ' گروه مشتریان' : Icons::SERVERS . ' سرور', ...[...BotScreens::PATH, Fmt::bare(MassGiftsScreen::TITLE)]);
        if ($options === []) {
            $view->line($audience === 'group' ? 'هنوز گروهی نیست؛ گروه‌ها در صفحه کاربران ساخته می‌شوند.' : 'سروری سرویس فعال ندارد.');
        }
        $buttons = array_map(static fn(array $option): Button => Button::to(((int) ($option['id'] ?? 0) === $picked ? Words::PICKED : '') . (string) ($option['name'] ?? '') . ' (' . Fmt::plain(Fmt::number((int) ($option['running'] ?? 0))) . ' سرویس)', self::ID, 't', (int) ($option['id'] ?? 0)), $options);

        return $view->paged(1, $buttons, $page, static fn(int $at): array => [self::ID, 'tp', [$at]])->back(self::ID, 'aud');
    }

    private function ask(Admin $admin, string $field): View
    {
        $draft = $this->draft($admin);
        // Each field with the step it is in a new draft's walk, and the step before it.
        [$question, $hint, $current, $example, $limits, $step, $before] = match ($field) {
            'days' => ['تعداد روز', 'به تاریخ پایان هر سرویس اضافه می‌شود.', $draft['days'] === '' ? null : Fmt::plain(Fmt::number((int) $draft['days'])) . ' روز', '۷', 'عدد درست؛ بی روز اضافه: «' . Words::SKIP . '»', 2, 'aud'],
            'gb' => ['حجم (گیگابایت)', 'روی حجم هر سرویس اضافه می‌شود.', $draft['traffic_gb'] === '' ? null : Fmt::digits(str_replace('.', '٫', $draft['traffic_gb'])) . ' گیگابایت', '۱۰', 'گیگابایت، کسری هم می‌شود؛ بی حجم اضافه: «' . Words::SKIP . '»', 3, 'days'],
            default => ['دلیل (اختیاری)', 'در پیامی که مشتری در تلگرام یا با ایمیل می‌گیرد نشان داده می‌شود.', $draft['reason'] === '' ? null : $draft['reason'], 'هدیه نوروز', 'حداکثر ۳۰۰ کاراکتر', 4, 'gb'],
        };
        $progress = $this->step($admin, $step);
        $prompt = (new Prompt($question, self::ID, $field, [], ($progress === '' ? '' : '<i>' . $progress . '</i>' . "\n") . $hint, Button::to(Words::CANCEL, self::ID, 'sum')))
            ->example($example)
            ->limits($limits);
        if ($current !== null) {
            $prompt->current($current, $field === 'why');
        }
        $quick = match ($field) {
            'days' => [Button::to('۷ روز', self::ID, 'q', $field, 7), Button::to('۳۰ روز', self::ID, 'q', $field, 30)],
            'gb' => [Button::to('۱۰ گیگابایت', self::ID, 'q', $field, 10), Button::to('۵۰ گیگابایت', self::ID, 'q', $field, 50)],
            default => [],
        };
        // Asked from the walk or the review, a question says it is the new gift's, whichever window came before it.
        $admin->here([...BotScreens::PATH, Fmt::bare(MassGiftsScreen::TITLE), 'هدیه تازه']);

        return $admin->ask($prompt->choices(...$quick)->ways(
            $progress === '' ? null : Button::to(Words::PREVIOUS_STEP, self::ID, $before),
            Button::to(Words::SKIP, self::ID, 'skip', $field),
        ));
    }

    /**
     * The review step: the draft whole in one card — whom it reaches, the days, the traffic, the reason, the two switches —,
     * how many services it reaches as it stands, each field to edit, the switches as their buttons say, and «✔️ شروع
     * هدیه» (green) while it reaches someone.
     */
    private function summary(Admin $admin): View
    {
        $draft = $this->draft($admin);
        $reach = $this->reach($admin);
        $target = $this->target($reach, $draft);
        $chosen = $draft['audience'] === 'server' || $draft['audience'] === 'group' ? ($target === null ? null : (string) ($target['name'] ?? '')) : '';
        $total = $target === null ? 0 : $this->total($target, $draft);
        $unstarted = $target === null ? 0 : (int) ($target['unstarted'] ?? 0);
        $view = View::make(Icons::MASS_GIFT . ' هدیه تازه: بازبینی', ...[...BotScreens::PATH, Fmt::bare(MassGiftsScreen::TITLE)])
            ->card([
                'به چه سرویس‌هایی' => Fmt::e(self::AUDIENCES[$draft['audience']] ?? '') . ($chosen !== null && $chosen !== '' ? ': ' . Fmt::e($chosen) : ($chosen === null ? ': ⚠️ انتخاب نشده' : '')),
                'تعداد روز' => $draft['days'] === '' ? Fmt::NONE : Fmt::number((int) $draft['days']) . ' روز',
                'حجم' => $draft['traffic_gb'] === '' ? Fmt::NONE : Fmt::digits(str_replace('.', '٫', $draft['traffic_gb'])) . ' گیگابایت',
                'دلیل' => $draft['reason'] === '' ? Fmt::NONE : Fmt::e($draft['reason']),
                'سرویس‌های در انتظار اولین اتصال' => Fmt::onOff($draft['include_unstarted']),
                'پیام به مشتری‌ها' => Fmt::onOff($draft['notify']),
            ])
            ->line(($total > 0 ? '✅ ' : '⚠️ ') . Fmt::e($this->reachText($target, $draft)));

        $view->tiles(
            Button::to(Words::edit('مخاطب'), self::ID, 'aud')->styled($target === null ? 'primary' : null),
            Button::to(Words::edit('روز'), self::ID, 'days'),
            Button::to(Words::edit('حجم'), self::ID, 'gb'),
            Button::to(Words::edit('دلیل'), self::ID, 'why'),
        )
            ->row(Button::to(Words::turn(!$draft['notify'], 'پیام به مشتری‌ها'), self::ID, 'msg', $draft['notify'] ? 0 : 1))
            ->row($unstarted > 0 || $draft['include_unstarted'] ? Button::to(Words::turn(!$draft['include_unstarted'], 'در انتظار اولین اتصال'), self::ID, 'inc', $draft['include_unstarted'] ? 0 : 1) : null)
            ->row(
                $total > 0 ? Button::to('✔️ شروع هدیه', self::ID, 'go')->success() : null,
                Button::to(Words::CANCEL, MassGiftsScreen::ID),
            );

        return $view->help('<b>سرویس‌های در انتظار اولین اتصال</b>: سرویس‌هایی که مشتری هنوز به آن‌ها وصل نشده و زمانشان شروع نشده است.' . "\n\n" . '<b>پیام به مشتری‌ها</b>: هر مشتری در تلگرام یا با ایمیل می‌بیند چه چیزی به سرویسش اضافه شد.');
    }

    /**
     * How many services the drafted gift reaches: the running ones, and those waiting when ticked in.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $draft
     */
    private function total(array $target, array $draft): int
    {
        return (int) ($target['running'] ?? 0) + ((bool) $draft['include_unstarted'] ? (int) ($target['unstarted'] ?? 0) : 0);
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
        $unstarted = (int) ($target['unstarted'] ?? 0);
        $total = $this->total($target, $draft);
        $active = (int) ($target['running'] ?? 0) + $unstarted;
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
