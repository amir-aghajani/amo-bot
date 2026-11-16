<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🔀 انتقال چند سرویس» (the panel's selection on the services list and its MoveDialog): the active services — as the
 * services list is narrowed (server, kind, customer) —, each ticked at a tap or a page at once, then a server to move
 * them to (those every ticked service is on already, or that cannot take one now, not offered), asked first. The move
 * goes one request a service, a few a tap so each is seen to go or fail: a previous server that does not answer pauses
 * it on the question whether to carry on without it (the rest on that server following the answer) or cancel; a target
 * that refuses one stops it, the next would fail the same way.
 *
 * Actions: `t` (id), `pa` (the page), `pn` (none), `pick` (servers), `to` (server: asks), `go` (server: starts — a move
 * kept, until `end`, shown as it stands), `run` (on), `yes` / `nope` (the question's answer), `stop`, `end`.
 */
final class MoveScreen extends ListScreen
{
    public const ID = 'sbm';

    /** The screen's title, as the services list's button says it. */
    public const TITLE = Sales::MOVE . ' انتقال چند سرویس';

    /** Services moved a tap: each asks two panels, and the tap waits for them. */
    private const CHUNK = 5;

    /** What moving does (the panel's move dialog). */
    private const MOVING = 'هر سرویس با زمان و حجم باقی‌مانده‌اش روی سرور مقصد ساخته و از سرور قبلی حذف می‌شود؛ لینکش عوض می‌شود و لینک جدید در تلگرام یا با ایمیل برای مشتری می‌رود. اگر سرور قبلی جواب ندهد، می‌پرسیم بدون حذف از آن ادامه دهیم یا انتقال لغو شود.';

    /**
     * @var array<int, array{n: string, s: int, sn: string}> The services ticked (show())
     */
    private array $ticked = [];

    /**
     * @var array<string, string> The services list's narrowing this one follows (show())
     */
    private array $narrowing = [];

    public function show(Admin $admin, array $args): View
    {
        $run = self::run($admin);
        if ($run !== null) {
            return $this->progress($admin, $run);
        }
        $this->ticked = self::tickedOf($admin);
        $this->narrowing = array_intersect_key(Narrowing::query($admin->state(SubscriptionsScreen::ID)), ['server' => 1, 'kind' => 1, 'user' => 1]);

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return Sales::under(Sales::SERVICES);
    }

    protected function noun(): string
    {
        return 'سرویس فعال';
    }

    protected function parent(): string
    {
        return SubscriptionsScreen::ID;
    }

    protected function endpoint(): string
    {
        return '/subscriptions';
    }

    protected function key(): string
    {
        return 'subscriptions';
    }

    protected function fixed(): array
    {
        return ['status' => 'active'] + $this->narrowing;
    }

    protected function searchHint(): string
    {
        return 'شماره یا نام کاربری سرویس، لینک اشتراک، یا نام، یوزرنیم، ایمیل یا شناسه تلگرام مشتری.';
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function empty(): string
    {
        return 'سرویس فعالی نیست که منتقل شود.';
    }

    protected function summary(array $meta): array
    {
        return [
            '<i>سرویس‌های فعالی را که می‌خواهید به سرور دیگر ببرید انتخاب کنید' . ($this->narrowing === [] ? '.' : '؛ همان‌طور که لیست اشتراک‌ها فیلتر شده.') . '</i>',
            '☑️ ' . Fmt::number(count($this->ticked)) . ' سرویس انتخاب شده',
        ];
    }

    protected function item(array $row): Button
    {
        $id = (int) ($row['id'] ?? 0);

        return Button::to(Words::tick(isset($this->ticked[$id])) . (string) ($row['name'] ?? '') . ' · ' . (string) (Json::part($row, 'server')['name'] ?? '') . ' · ' . Fmt::cut(Sales::customer(Json::part($row, 'user')), 14), self::ID, 't', $id);
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(
            Button::to(Words::TICK_PAGE, self::ID, 'pa'),
            $this->ticked === [] ? null : Button::to(Words::TICK_NONE, self::ID, 'pn'),
        );
        if ($this->ticked !== []) {
            $view->row(Button::to(Sales::MOVE . ' انتقال ' . Fmt::number(count($this->ticked)) . ' سرویس به سرور دیگر', self::ID, 'pick')->primary());
        }
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        $ticked = self::tickedOf($admin);
        $rows = (array) ($admin->state(self::ID)['rows'] ?? []);
        switch ($action) {
            case 't':
                $id = self::id($args);
                if (isset($ticked[$id])) {
                    unset($ticked[$id]);
                } elseif (is_array($rows[$id] ?? null)) {
                    $ticked[$id] = self::kept($rows[$id]);
                }
                $admin->remember(self::ID, ['ticked' => $ticked]);
                break;
            case 'pa':
                foreach ($rows as $id => $row) {
                    if (is_array($row)) {
                        $ticked[(int) $id] = self::kept($row);
                    }
                }
                $admin->remember(self::ID, ['ticked' => $ticked]);
                break;
            case 'pn':
                $admin->remember(self::ID, ['ticked' => []]);
                break;
            case 'pick':
                return $ticked === [] ? $this->show($admin, []) : $this->targets($admin, $ticked);
            case 'to':
                return $this->asking($admin, $ticked, self::id($args));
            case 'go':
                $run = self::run($admin);
                if ($run !== null) {
                    // The confirmation tapped again (a second tap, an old window): the move under way — or done — as it
                    // stands, never started over on services it moved already.
                    return $this->progress($admin, $run);
                }
                $target = self::id($args);
                $queue = array_values(array_filter(array_keys($ticked), static fn(int $id): bool => $ticked[$id]['s'] !== $target));
                if ($queue === []) {
                    break;
                }
                $admin->remember(self::ID, ['run' => ['to' => $target, 'name' => Sales::serverName($admin, $target), 'queue' => $queue, 'done' => [], 'left' => [], 'ask' => null, 'stopped' => null, 'over' => false]]);

                return $this->step($admin);
            case 'run':
                return $this->step($admin);
            case 'yes':
            case 'nope':
                return $this->answered($admin, $action === 'yes');
            case 'stop':
                $run = self::run($admin);
                if ($run !== null) {
                    $run['over'] = true;
                    $admin->remember(self::ID, ['run' => $run]);
                }
                break;
            case 'end':
                $admin->forget(self::ID);

                return $admin->open(SubscriptionsScreen::ID);
        }

        return $this->show($admin, []);
    }

    /**
     * @return array<int, array{n: string, s: int, sn: string}>
     */
    private static function tickedOf(Admin $admin): array
    {
        $ticked = [];
        foreach ((array) ($admin->state(self::ID)['ticked'] ?? []) as $id => $service) {
            if (is_array($service)) {
                $ticked[(int) $id] = ['n' => (string) ($service['n'] ?? ''), 's' => (int) ($service['s'] ?? 0), 'sn' => (string) ($service['sn'] ?? '')];
            }
        }

        return $ticked;
    }

    /**
     * What is kept of a ticked service: its name, its server and the server's name.
     *
     * @param array<array-key, mixed> $row
     * @return array{n: string, s: int, sn: string}
     */
    private static function kept(array $row): array
    {
        $server = Json::part($row, 'server');

        return ['n' => (string) ($row['name'] ?? ''), 's' => (int) ($server['id'] ?? 0), 'sn' => (string) ($server['name'] ?? '')];
    }

    /**
     * The move under way; null for none.
     *
     * @return array{to: int, name: string, queue: list<int>, done: array<int, array{0: string, 1: string}>, left: list<int>, ask: array{id: int, reason: string}|null, stopped: int|null, over: bool}|null
     */
    private static function run(Admin $admin): ?array
    {
        $run = $admin->state(self::ID)['run'] ?? null;
        if (!is_array($run)) {
            return null;
        }
        $done = [];
        foreach ((array) ($run['done'] ?? []) as $id => $outcome) {
            if (is_array($outcome)) {
                $done[(int) $id] = [(string) ($outcome[0] ?? ''), (string) ($outcome[1] ?? '')];
            }
        }
        $ask = is_array($run['ask'] ?? null) ? ['id' => (int) ($run['ask']['id'] ?? 0), 'reason' => (string) ($run['ask']['reason'] ?? '')] : null;

        return [
            'to' => (int) ($run['to'] ?? 0),
            'name' => (string) ($run['name'] ?? ''),
            'queue' => array_values(array_map(intval(...), (array) ($run['queue'] ?? []))),
            'done' => $done,
            'left' => array_values(array_map(intval(...), (array) ($run['left'] ?? []))),
            'ask' => $ask,
            'stopped' => is_int($run['stopped'] ?? null) ? $run['stopped'] : null,
            'over' => Json::on($run, 'over'),
        ];
    }

    /**
     * The servers the ticked services may go to: not one every one of them is on already; one that cannot take a service
     * now listed with its reason.
     *
     * @param array<int, array{n: string, s: int, sn: string}> $ticked
     */
    private function targets(Admin $admin, array $ticked): View
    {
        $on = array_values(array_unique(array_column($ticked, 's')));
        // A server every ticked service is on already is no target; one some are on is (those stay where they are).
        [$choices, $closed] = Sales::targets($admin, count($on) === 1 ? $on : [], self::ID, 'to', []);
        $view = View::make(Icons::SERVERS . ' سرور مقصد', ...Sales::under(Sales::SERVICES, Fmt::bare(self::TITLE)))
            ->card(['سرویس‌های انتخاب‌شده' => Fmt::number(count($ticked)), 'روی سرورهای' => Fmt::e(implode('، ', array_unique(array_column($ticked, 'sn'))))]);
        if ($closed !== []) {
            $view->section('⛔️ سرورهایی که الان سرویس نمی‌گیرند')->lines(...$closed);
        }
        if ($choices === []) {
            $view->line()->line('سروری نیست که این سرویس‌ها الان به آن منتقل شوند.');
        }

        return $view->grid(1, ...$choices)->help(Fmt::e(self::MOVING))->back(self::ID);
    }

    /**
     * The move to `$target` asked first: how many go, how many are there already, what it does.
     *
     * @param array<int, array{n: string, s: int, sn: string}> $ticked
     */
    private function asking(Admin $admin, array $ticked, int $target): View
    {
        $queue = array_filter($ticked, static fn(array $service): bool => $service['s'] !== $target);
        if ($queue === []) {
            $admin->notice('همه سرویس‌های انتخاب‌شده همین حالا روی این سرور هستند');

            return $this->show($admin, []);
        }
        $already = count($ticked) - count($queue);

        return Sales::choose(
            $admin,
            Fmt::number(count($queue)) . ' سرویس به «' . Sales::serverName($admin, $target) . '» منتقل شود؟',
            Fmt::e(self::MOVING) . ($already > 0 ? "\n" . Fmt::number($already) . ' سرویس همین حالا روی این سرور است و منتقل نمی‌شود.' : ''),
            false,
            Button::to(Words::yes(Fmt::number(count($queue)) . ' سرویس منتقل شود'), self::ID, 'go', $target),
            Button::to(Words::CANCEL, self::ID),
        );
    }

    /** The next few services of the move, one request each, then where it stands. */
    private function step(Admin $admin): View
    {
        $run = self::run($admin);
        if ($run === null) {
            return $this->show($admin, []);
        }
        $ticked = self::tickedOf($admin);
        $moved = 0;
        foreach ($run['queue'] as $id) {
            if (isset($run['done'][$id])) {
                continue;
            }
            if ($run['ask'] !== null || $run['stopped'] !== null || $run['over'] || $moved >= self::CHUNK) {
                break;
            }
            $moved++;
            $run = $this->moveOne($admin, $run, $id, in_array($ticked[$id]['s'] ?? 0, $run['left'], true), $ticked[$id]['sn'] ?? '');
        }
        if ($run['ask'] === null && count($run['done']) >= count($run['queue'])) {
            $run['over'] = true;
        }
        $admin->remember(self::ID, ['run' => $run]);

        return $this->progress($admin, $run);
    }

    /**
     * One service's move — without its previous server when `$leave` —, recorded; a previous server that failed is not
     * recorded yet: the admin is asked first.
     *
     * @param array{to: int, name: string, queue: list<int>, done: array<int, array{0: string, 1: string}>, left: list<int>, ask: array{id: int, reason: string}|null, stopped: int|null, over: bool} $run
     * @return array{to: int, name: string, queue: list<int>, done: array<int, array{0: string, 1: string}>, left: list<int>, ask: array{id: int, reason: string}|null, stopped: int|null, over: bool}
     */
    private function moveOne(Admin $admin, array $run, int $id, bool $leave, string $from): array
    {
        try {
            $admin->api->post("/subscriptions/{$id}/move", ['server_id' => $run['to'], 'leave_previous' => $leave]);
            $run['done'][$id] = ['ok', $leave ? 'کلاینت قبلی روی ' . $from . ' ماند' : ''];
        } catch (ApiRefusal $refusal) {
            $previous = $refusal->field('previous');
            if ($previous !== null && !$leave) {
                $run['ask'] = ['id' => $id, 'reason' => $previous];

                return $run;
            }
            // The target refusing it refuses the next one too, and a refusal to leave the previous server's client
            // refuses the rest of that server's: the move stops there.
            $refused = $refusal->field('server_id') ?? $refusal->field('target');
            $run['done'][$id] = ['fail', $refused ?? $previous ?? $refusal->lines()];
            if ($refused !== null || $leave) {
                $run['stopped'] = $id;
            }
        }

        return $run;
    }

    /** The question answered: carry on without the previous server (the rest on it following), or cancel the move. */
    private function answered(Admin $admin, bool $skip): View
    {
        $run = self::run($admin);
        if ($run === null || $run['ask'] === null) {
            return $this->show($admin, []);
        }
        $id = $run['ask']['id'];
        $ticked = self::tickedOf($admin);
        $run['ask'] = null;
        if (!$skip) {
            $run['done'][$id] = ['cancel', ''];
            $run['over'] = true;
            $admin->remember(self::ID, ['run' => $run]);

            return $this->progress($admin, $run);
        }
        $run['left'][] = $ticked[$id]['s'] ?? 0;
        $run = $this->moveOne($admin, $run, $id, true, $ticked[$id]['sn'] ?? '');
        $admin->remember(self::ID, ['run' => $run]);

        return $this->step($admin);
    }

    /**
     * Where the move stands: each service's line, the question while it waits on one, and the way on.
     *
     * @param array{to: int, name: string, queue: list<int>, done: array<int, array{0: string, 1: string}>, left: list<int>, ask: array{id: int, reason: string}|null, stopped: int|null, over: bool} $run
     */
    private function progress(Admin $admin, array $run): View
    {
        $ticked = self::tickedOf($admin);
        $count = static fn(string $state): int => count(array_filter($run['done'], static fn(array $outcome): bool => $outcome[0] === $state));
        $view = View::make(Sales::MOVE . ' انتقال به «' . $run['name'] . '»', ...Sales::under(Sales::SERVICES))->card([
            'منتقل شد' => Fmt::number($count('ok')) . ' از ' . Fmt::number(count($run['queue'])),
            'ناموفق' => $count('fail') > 0 ? Fmt::number($count('fail')) : null,
            'لغو شد' => $count('cancel') > 0 ? Fmt::number($count('cancel')) : null,
        ])->section('📋 سرویس‌ها');
        foreach ($run['queue'] as $id) {
            $name = Fmt::ltr($ticked[$id]['n'] ?? (string) $id);
            $from = Fmt::e($ticked[$id]['sn'] ?? '');
            [$state, $words] = $run['done'][$id] ?? ['', ''];
            $view->line(match (true) {
                $state === 'ok' => '✅ ' . $name . ' — منتقل شد' . ($words !== '' ? ' · ' . Fmt::e($words) : ''),
                $state === 'fail' => '⚠️ ' . $name . ' — روی ' . $from . ': ' . Fmt::e($words),
                $state === 'cancel' => '✋ ' . $name . ' — لغو شد؛ روی ' . $from . ' ماند',
                $run['ask'] !== null && $run['ask']['id'] === $id => '❓ ' . $name . ' — منتظر تصمیم شما',
                default => '◻️ ' . $name . ' — روی ' . $from,
            });
        }

        if ($run['ask'] !== null) {
            $id = $run['ask']['id'];
            $from = $ticked[$id]['s'] ?? 0;
            $others = count(array_filter($run['queue'], static fn(int $other): bool => $other !== $id && !isset($run['done'][$other]) && ($ticked[$other]['s'] ?? 0) === $from));
            $server = $ticked[$id]['sn'] ?? '';

            return $view->line()->line('⚠️ ' . Fmt::e($run['ask']['reason']))->line(Fmt::e($others > 0
                ? 'این سرویس و ' . Fmt::number($others) . ' سرویس دیگر همین سرور بدون حذف از «' . $server . '» منتقل شوند؟ کلاینت‌های قبلی روی آن سرور می‌مانند تا سرور دوباره جواب بدهد، بعد خودکار حذف می‌شوند.'
                : 'بدون حذف از «' . $server . '» منتقل شود؟ کلاینت قبلی روی آن سرور می‌ماند تا سرور دوباره جواب بدهد، بعد خودکار حذف می‌شود.'))
                ->row(Button::to(Sales::MOVE . ' انتقال بدون حذف از سرور قبلی', self::ID, 'yes')->success())
                ->row(Button::to(Sales::CANCEL . ' لغو انتقال این سرویس', self::ID, 'nope')->danger())
                ->back(SubscriptionsScreen::ID);
        }
        if ($run['stopped'] !== null) {
            $view->line()->line('⛔️ انتقال بعد از خطای ' . Fmt::ltr($ticked[$run['stopped']]['n'] ?? '') . ' متوقف شد: سرویس‌های بعدی هم به همین دلیل منتقل نمی‌شدند.');
        }
        $left = count($run['queue']) - count($run['done']);
        if (!$run['over'] && $run['stopped'] === null && $left > 0) {
            return $view->row(Button::to('▶️ ادامه انتقال (' . Fmt::number($left) . ' مانده)', self::ID, 'run')->primary())
                ->row(Button::to('⏹ توقف', self::ID, 'stop'))
                ->back(SubscriptionsScreen::ID);
        }

        return $view->row(Button::to('✔️ بستن', self::ID, 'end')->primary())->back(SubscriptionsScreen::ID);
    }
}
