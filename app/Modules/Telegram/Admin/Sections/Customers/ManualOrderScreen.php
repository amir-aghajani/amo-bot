<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\Services\SessionPresenter;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Messages;
use App\Support\Unit;

/**
 * «➕ افزودن سفارش» (the panel's dialog of that name, the `manual_orders` grant): a service made for the customer by hand
 * — one of the shop's plans, or a service of support's own numbers (GB, days, devices; 0 for no limit) — on a server
 * that can take it now, named as typed or by the shop's pattern, free, from the customer's wallet or paid outside the
 * shop at the amount typed, with a note. A wizard: each field asked in turn — the plan's only open server picked by
 * itself, as the panel picks it —, the optional ones with «⏭ رد شدن», then what it delivers said in the panel's words with
 * «✏️» per field, and «✔️ ثبت و تحویل سفارش»: one POST /users/{id}/orders under an Idempotency-Key the draft keeps for
 * these very values (pressed twice, one order). What came of it — delivered, waiting for a retry, cancelled — links to
 * the order's and the service's screens.
 *
 * Its draft is the screen's state (`user`, `kind`, `plan`, `server`, `gb`, `days`, `devices`, `payment`, `amount`,
 * `name`, `note`, `nonce`). Actions, each with the customer's id first: `new`, `k` (kind), `pl` (plans page), `p` (plan),
 * `srv` (the servers), `s` (server), `pay`, `pm` (payment), `amt`, `amtp` (amount picked), `gb` / `days` / `dev` (asked)
 * and `gbq` / `dq` / `vq` (number picked), `name`, `nskip`, `note`, `ntskip`, `make`.
 */
final class ManualOrderScreen extends BaseScreen
{
    public const ID = 'uso';

    /** How it is paid, in the panel's words. */
    private const PAYMENTS = ['free' => 'رایگان', 'wallet' => 'از کیف پول مشتری', 'outside' => 'پرداخت خارج از فروشگاه'];

    private const KINDS = ['plan' => 'پلن', 'custom' => 'سرویس دلخواه'];

    /** Support's own numbers: each field's question, unit, what 0 means and its quick picks. */
    private const NUMBERS = [
        'gb' => ['حجم (گیگابایت)', '۰ یعنی نامحدود', [10, 30, 50, 100], 'gbq'],
        'days' => ['مدت (روز)', '۰ یعنی بدون پایان', [7, 30, 60, 90], 'dq'],
        'devices' => ['تعداد کاربر', '۰ یعنی نامحدود', [1, 2, 3, 5], 'vq'],
    ];

    public function __construct(private readonly SessionPresenter $shops) {}

    public function show(Admin $admin, array $args): View
    {
        $id = Customer::current($admin, $args);
        if ($id === 0) {
            return $admin->open(UsersScreen::ID);
        }

        return $this->draft($admin, $id) === null ? $this->act($admin, 'new', [(string) $id]) : $this->next($admin, $id);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        if ($id === 0) {
            return $admin->open(UsersScreen::ID);
        }
        if ($action === 'new') {
            $admin->forget(self::ID);
            $admin->remember(self::ID, ['user' => $id, 'nonce' => bin2hex(random_bytes(8))]);

            return $this->next($admin, $id);
        }
        $draft = $this->draft($admin, $id);
        if ($draft === null) {
            return $this->act($admin, 'new', [(string) $id]);
        }
        $arg = (string) ($args[1] ?? '');

        switch ($action) {
            case 'k':
                if (isset(self::KINDS[$arg])) {
                    $admin->remember(self::ID, ['kind' => $arg, 'plan' => null, 'server' => null, 'gb' => null, 'days' => null, 'devices' => null]);
                } else {
                    $admin->remember(self::ID, ['kind' => null]);
                }
                break;
            case 'pl':
                return $this->plans($admin, $id, max(1, (int) $arg));
            case 'p':
                return $this->pickPlan($admin, $id, (int) $arg);
            case 'srv':
                return $this->servers($admin, $id);
            case 's':
                return $this->pickServer($admin, $id, (int) $arg);
            case 'pay':
                return $this->payments($admin, $id);
            case 'pm':
                if (isset(self::PAYMENTS[$arg])) {
                    $admin->remember(self::ID, ['payment' => $arg, 'amount' => $arg === 'free' ? null : ($draft['amount'] ?? null)]);
                }
                break;
            case 'amt':
                return $this->askAmount($admin, $id);
            case 'amtp':
                $admin->remember(self::ID, ['amount' => max(0, (int) $arg)]);
                break;
            case 'gb':
            case 'days':
            case 'dev':
                return $this->askNumber($admin, $id, $action === 'dev' ? 'devices' : $action);
            case 'gbq':
            case 'dq':
            case 'vq':
                $field = ['gbq' => 'gb', 'dq' => 'days', 'vq' => 'devices'][$action];
                $admin->remember(self::ID, [$field => max(0, (int) $arg)]);
                break;
            case 'name':
                return $this->askName($admin, $id);
            case 'nskip':
                $admin->remember(self::ID, ['name' => '', 'named' => true]);
                break;
            case 'note':
                return $this->askNote($admin, $id);
            case 'ntskip':
                $admin->remember(self::ID, ['note' => '', 'noted' => true]);
                break;
            case 'make':
                return $this->make($admin, $id);
        }

        return $this->next($admin, $id);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);
        if ($id === 0 || $this->draft($admin, $id) === null) {
            return $this->show($admin, $args);
        }
        switch ($action) {
            case 'gb':
            case 'days':
            case 'devices':
                $unit = ['gb' => Unit::Gigabyte, 'days' => Unit::Day, 'devices' => Unit::Device][$action];
                $number = $answer->amount($unit) ?? throw new ApiRefusal(422, 'یک عدد بدون اعشار بنویسید؛ ' . self::NUMBERS[$action][1] . '.');
                $admin->remember(self::ID, [$action => $number]);
                break;
            case 'amt':
                $amount = $answer->amount(Unit::Toman) ?? throw new ApiRefusal(422, 'مبلغ را به تومان و بدون اعشار بنویسید، مثل ۱۵۰۰۰۰ یا «۱۵۰ هزار تومان».');
                $admin->remember(self::ID, ['amount' => $amount]);
                break;
            case 'name':
                $admin->remember(self::ID, ['name' => $answer->latin(), 'named' => true]);
                break;
            case 'note':
                $admin->remember(self::ID, ['note' => $answer->text(), 'noted' => true]);
                break;
        }

        return $this->next($admin, $id);
    }

    /**
     * The order being written for customer `$id`; null when none is.
     *
     * @return array<string, mixed>|null
     */
    private function draft(Admin $admin, int $id): ?array
    {
        $draft = $admin->state(self::ID);

        return ($draft['user'] ?? null) === $id ? $draft : null;
    }

    /** The next field the order lacks, asked; the summary once it lacks none. */
    private function next(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $kind = $draft['kind'] ?? null;
        if (!is_string($kind)) {
            return $this->kinds($admin, $id);
        }
        if ($kind === 'plan' && !is_int($draft['plan'] ?? null)) {
            return $this->plans($admin, $id, 1);
        }
        if ($kind === 'custom') {
            foreach (array_keys(self::NUMBERS) as $field) {
                if (!is_int($draft[$field] ?? null)) {
                    return $this->askNumber($admin, $id, $field);
                }
            }
        }
        if (!is_int($draft['server'] ?? null)) {
            return $this->servers($admin, $id);
        }
        if (!is_string($draft['payment'] ?? null)) {
            return $this->payments($admin, $id);
        }
        if ($draft['payment'] !== 'free' && !is_int($draft['amount'] ?? null)) {
            return $this->askAmount($admin, $id);
        }
        if (($draft['named'] ?? false) !== true) {
            return $this->askName($admin, $id);
        }
        if (($draft['noted'] ?? false) !== true) {
            return $this->askNote($admin, $id);
        }

        return $this->summary($admin, $id);
    }

    /** A step's screen: the dialog's title, whose order, and the shop's selling suspended said first. */
    private function step(Admin $admin, int $id, string $what): View
    {
        $view = View::make('➕ افزودن سفارش', 'مدیریت', 'فروش', 'کاربران', Customer::known($admin, $id));
        if ($this->shops->shop($admin->seat->shop())['selling_suspended'] !== null) {
            $view->line('⚠️ فروش این فروشگاه متوقف است و تا دوباره باز نشود سفارشی در آن ثبت نمی‌شود.')->line();
        }

        return $view->line($what);
    }

    /** «✖️ انصراف»: out of the order, back to the customer's page; its draft stays until a new one begins. */
    private function cancel(int $id): Button
    {
        return Button::to('✖️ انصراف', CustomerScreen::ID, 'show', $id);
    }

    /** «✖️» out of a step: back to the summary while the field it asks is set (an edit), else out of the order. */
    private function leave(int $id, bool $set): Button
    {
        return $set ? Button::to('✖️ لغو', self::ID, 'show', $id) : $this->cancel($id);
    }

    private function kinds(Admin $admin, int $id): View
    {
        return $this->step($admin, $id, 'سرویسی برای ' . Fmt::e(Customer::known($admin, $id)) . ' که بلافاصله تحویل و به او خبر داده می‌شود. چه سرویسی؟')
            ->row(Button::to('📦 یکی از پلن‌ها', self::ID, 'k', $id, 'plan'), Button::to('🛠 سرویس دلخواه', self::ID, 'k', $id, 'custom'))
            ->row($this->cancel($id));
    }

    private function plans(Admin $admin, int $id, int $page): View
    {
        $plans = Json::rows($admin->api->get('/plans'), 'plans');
        [$shown, $page, $pages] = View::slice($plans, $page);
        $view = $this->step($admin, $id, 'یک پلن انتخاب کنید:' . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
        if ($plans === []) {
            $view->line()->line('هنوز پلنی ندارید؛ اول در «📦 پلن‌ها» یکی بسازید، یا «🛠 سرویس دلخواه» بدهید.');
        }
        foreach ($shown as $plan) {
            $mark = match (true) {
                Json::on($plan, 'hidden_by_owner') => '🔒 ',
                ($plan['is_active'] ?? true) !== true => '⛔️ ',
                default => '',
            };
            $view->row(Button::to($mark . self::planLine($plan), self::ID, 'p', $id, Customer::int($plan, 'id')));
        }
        $view->note('⛔️ خاموش برای مشتری‌ها · 🔒 پنهان‌شده توسط پشتیبانی: از پلن پنهان سفارشی ساخته نمی‌شود.');
        $view->pager($page, $pages, self::ID, 'pl', $id);

        return $view->row(Button::to('✏️ نوع سرویس', self::ID, 'k', $id, '_'), $this->leave($id, is_int(($this->draft($admin, $id) ?? [])['plan'] ?? null)));
    }

    /**
     * A plan as the picker's button says it, short enough for one: its name, price, gigabytes and term.
     *
     * @param array<string, mixed> $plan
     */
    private static function planLine(array $plan): string
    {
        $gb = self::gbOf($plan['traffic_gb'] ?? 0);

        return (Customer::text($plan, 'name') ?? '') . ' · ' . Fmt::money(Customer::money($plan['price'] ?? null)) . ' · ' . ($gb > 0 ? Fmt::number($gb) . ' گیگ' : 'نامحدود') . ' · ' . Fmt::hours(Customer::int($plan, 'duration_hours'));
    }

    /** A package in the panel's words: "۳۰ گیگابایت · ۳۰ روز · ۱ کاربر". */
    private static function package(int|float $gb, int $hours, int $devices): string
    {
        return Customer::gb($gb) . ' · ' . Fmt::hours($hours) . ' · ' . Messages::devices($devices);
    }

    /** Gigabytes as the panel or the draft holds them (a plan's may have decimals); 0 for none. */
    private static function gbOf(mixed $gb): int|float
    {
        return is_int($gb) || is_float($gb) ? $gb : 0;
    }

    private function pickPlan(Admin $admin, int $id, int $planId): View
    {
        $plan = $this->plan($admin, $planId);
        if ($plan === null) {
            $admin->alert('این پلن دیگر پیدا نشد.');

            return $this->plans($admin, $id, 1);
        }
        if (Json::on($plan, 'hidden_by_owner')) {
            $admin->alert('پشتیبانی فروشگاه اصلی این پلن را پنهان کرده است؛ از آن سفارشی ساخته نمی‌شود.');

            return $this->plans($admin, $id, 1);
        }
        $open = array_values(array_filter(self::planServers($plan), static fn(array $server): bool => $server['reason'] === null));
        $admin->remember(self::ID, ['plan' => $planId, 'server' => count($open) === 1 ? $open[0]['id'] : null, 'amount' => null]);

        return $this->next($admin, $id);
    }

    /**
     * The plan of `$planId` as the plans list answers it; null for none.
     *
     * @return array<string, mixed>|null
     */
    private function plan(Admin $admin, int $planId): ?array
    {
        foreach (Json::rows($admin->api->get('/plans'), 'plans') as $plan) {
            if (Customer::int($plan, 'id') === $planId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * The servers a plan is sold on, and why one cannot take an order now (null when it can).
     *
     * @param array<string, mixed> $plan
     * @return list<array{id: int, name: string, reason: string|null}>
     */
    private static function planServers(array $plan): array
    {
        $servers = [];
        foreach (Json::rows($plan, 'servers') as $entry) {
            $server = Json::part($entry, 'server');
            $servers[] = ['id' => Customer::int($server, 'id'), 'name' => Customer::text($server, 'name') ?? '', 'reason' => Customer::text($entry, 'unsellable_reason')];
        }

        return $servers;
    }

    /**
     * The servers the order may go to: its plan's, or — for support's own numbers — every server, each with why it
     * cannot take one now.
     *
     * @return list<array{id: int, name: string, reason: string|null}>
     */
    private function serverChoices(Admin $admin, int $id): array
    {
        $draft = $this->draft($admin, $id) ?? [];
        if (($draft['kind'] ?? null) === 'plan') {
            $plan = is_int($draft['plan'] ?? null) ? $this->plan($admin, $draft['plan']) : null;

            return $plan === null ? [] : self::planServers($plan);
        }
        $servers = [];
        foreach (Json::rows($admin->api->get('/plans/options'), 'servers') as $server) {
            $servers[] = ['id' => Customer::int($server, 'id'), 'name' => Customer::text($server, 'name') ?? '', 'reason' => Customer::text($server, 'unsellable_reason')];
        }

        return $servers;
    }

    private function servers(Admin $admin, int $id): View
    {
        $choices = $this->serverChoices($admin, $id);
        $view = $this->step($admin, $id, 'سرور سرویس را انتخاب کنید:');
        foreach ($choices as $choice) {
            if ($choice['reason'] !== null) {
                $view->line('⛔️ ' . Fmt::e($choice['name']) . ': ' . Fmt::e($choice['reason']));
            }
            $view->row(Button::to(($choice['reason'] !== null ? '⛔️ ' : '🖥 ') . $choice['name'], self::ID, 's', $id, $choice['id']));
        }
        if ($choices === []) {
            $view->line()->line('سروری برای این سرویس نیست.');
        }

        return $view->row(Button::to('✏️ نوع سرویس', self::ID, 'k', $id, '_'), $this->leave($id, is_int(($this->draft($admin, $id) ?? [])['server'] ?? null)));
    }

    private function pickServer(Admin $admin, int $id, int $serverId): View
    {
        foreach ($this->serverChoices($admin, $id) as $choice) {
            if ($choice['id'] !== $serverId) {
                continue;
            }
            if ($choice['reason'] !== null) {
                $admin->alert($choice['reason']);

                return $this->servers($admin, $id);
            }
            $admin->remember(self::ID, ['server' => $serverId]);

            return $this->next($admin, $id);
        }

        return $this->servers($admin, $id);
    }

    private function payments(Admin $admin, int $id): View
    {
        $current = ($this->draft($admin, $id) ?? [])['payment'] ?? null;
        $buttons = [];
        foreach (self::PAYMENTS as $payment => $label) {
            $buttons[] = Button::to(($current === $payment ? '● ' : '') . ['free' => '🎁 ', 'wallet' => '👛 ', 'outside' => '💵 '][$payment] . $label, self::ID, 'pm', $id, $payment);
        }

        return $this->step($admin, $id, 'پرداخت چطور است؟')
            ->line('<i>رایگان هدیه است و خرید مشتری حساب نمی‌شود؛ از کیف پول، از موجودی‌اش کم می‌شود؛ پرداخت خارج از فروشگاه در درآمد فروشگاه حساب می‌شود.</i>')
            ->grid(1, ...$buttons)
            ->row($this->leave($id, is_string($current)));
    }

    private function askAmount(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $hint = ($draft['payment'] ?? null) === 'wallet'
            ? 'موجودی کیف پول مشتری: ' . Fmt::e(Messages::balance(Customer::money(Customer::row(Customer::read($admin, $id))['balance'] ?? null)))
            : 'مبلغی که بیرون از فروشگاه گرفته‌اید؛ در درآمد فروشگاه حساب می‌شود.';
        $prompt = new Prompt('مبلغ (تومان)', self::ID, 'amt', [$id], $hint . "\nمثل ۱۵۰۰۰۰ یا «۱۵۰ هزار تومان»." . (is_int($draft['amount'] ?? null) ? "\nالان: " . Fmt::money($draft['amount']) : ''), $this->leave($id, is_int($draft['amount'] ?? null)));
        $plan = ($draft['kind'] ?? null) === 'plan' && is_int($draft['plan'] ?? null) ? $this->plan($admin, $draft['plan']) : null;
        $price = $plan === null ? 0 : (int) round((float) Customer::money($plan['price'] ?? null));
        if ($price > 0) {
            $prompt->choices(Button::to('💰 قیمت پلن: ' . Fmt::money($price), self::ID, 'amtp', $id, $price));
        }

        return $admin->ask($prompt);
    }

    private function askNumber(Admin $admin, int $id, string $field): View
    {
        [$label, $zero, $picks, $quick] = self::NUMBERS[$field];
        $draft = $this->draft($admin, $id) ?? [];
        $prompt = new Prompt($label, self::ID, $field, [$id], $zero . (is_int($draft[$field] ?? null) ? "\nالان: " . Fmt::number($draft[$field]) : ''), $this->leave($id, is_int($draft[$field] ?? null)));
        $choices = array_map(static fn(int $pick): Button => Button::to(Fmt::number($pick), self::ID, $quick, $id, $pick), $picks);
        $choices[] = Button::to($field === 'days' ? 'بدون پایان' : 'نامحدود', self::ID, $quick, $id, 0);

        return $admin->ask($prompt->choices(...$choices));
    }

    private function askName(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $now = is_string($draft['name'] ?? null) && $draft['name'] !== '' ? "\nالان: " . Fmt::ltr($draft['name']) : '';

        return $admin->ask((new Prompt('نام کاربری سرویس (اختیاری)', self::ID, 'name', [$id], 'خالی بماند، با الگوی فروشگاه نام‌گذاری می‌شود. مثل ' . Fmt::ltr('ali_vip') . $now, $this->leave($id, Json::on($draft, 'named'))))
            ->choices(Button::to('⏭ رد شدن: با الگوی فروشگاه', self::ID, 'nskip', $id)));
    }

    private function askNote(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $now = is_string($draft['note'] ?? null) && $draft['note'] !== '' ? "\nالان: " . Fmt::e($draft['note']) : '';

        return $admin->ask((new Prompt('یادداشت (اختیاری)', self::ID, 'note', [$id], 'کنار سفارش می‌ماند و در گروه گزارش‌ها گفته می‌شود؛ مشتری آن را نمی‌بیند. مثلا: پرداخت نقدی در دفتر' . $now, $this->leave($id, Json::on($draft, 'noted'))))
            ->choices(Button::to('⏭ رد شدن: بدون یادداشت', self::ID, 'ntskip', $id)));
    }

    /** What the order delivers, said before it is made (the panel's preview), with «✏️» per field and «✔️ ثبت و تحویل سفارش». */
    private function summary(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $kind = (string) ($draft['kind'] ?? 'plan');
        $plan = $kind === 'plan' && is_int($draft['plan'] ?? null) ? $this->plan($admin, $draft['plan']) : null;
        $server = null;
        foreach ($this->serverChoices($admin, $id) as $choice) {
            if ($choice['id'] === ($draft['server'] ?? null)) {
                $server = $choice;
            }
        }
        $gb = $kind === 'plan' ? self::gbOf($plan['traffic_gb'] ?? 0) : self::gbOf($draft['gb'] ?? 0);
        $service = $kind === 'plan'
            ? ($plan === null ? 'این پلن دیگر پیدا نشد' : '«' . Fmt::e(Customer::text($plan, 'name')) . '»: ' . self::package($gb, Customer::int($plan, 'duration_hours'), Customer::int($plan, 'ip_limit')))
            : 'سرویس دلخواه: ' . self::package($gb, Customer::int($draft, 'days') * 24, Customer::int($draft, 'devices'));
        $payment = (string) ($draft['payment'] ?? 'free');
        $amount = is_int($draft['amount'] ?? null) ? $draft['amount'] : 0;
        $paid = match ($payment) {
            'wallet' => Fmt::money($amount) . ' از کیف پول مشتری کم می‌شود (موجودی ' . Fmt::e(Messages::balance(Customer::money(Customer::row(Customer::read($admin, $id))['balance'] ?? null))) . ').',
            'outside' => Fmt::money($amount) . ' پرداخت خارج از فروشگاه ثبت و در درآمد فروشگاه حساب می‌شود.',
            default => 'رایگان؛ هدیه است و خرید مشتری حساب نمی‌شود.',
        };
        $name = is_string($draft['name'] ?? null) && $draft['name'] !== '' ? $draft['name'] : null;
        $note = is_string($draft['note'] ?? null) && $draft['note'] !== '' ? $draft['note'] : null;

        $view = $this->step($admin, $id, '<b>تحویل می‌شود</b>')
            ->line($service . ($server !== null ? ' · روی ' . Fmt::e($server['name']) : ''))
            ->line($name === null ? 'نام کاربری سرویس: با الگوی فروشگاه' : 'نام کاربری سرویس: ' . Fmt::ltr($name))
            ->line('پرداخت: ' . $paid)
            ->line('یادداشت: ' . ($note === null ? Fmt::NONE : Fmt::e($note)));
        $traffic = $this->agentTraffic($admin);
        if (!$admin->seat->shop()->isMain() && $gb > 0) {
            $view->line('<i>' . Customer::gb($gb) . ' از حجم ربات این فروشگاه کم می‌شود' . ($traffic === null ? '' : ' (' . Fmt::bytes($traffic) . ' مانده)') . '.</i>');
        }

        $edits = $kind === 'plan'
            ? [Button::to('✏️ پلن', self::ID, 'pl', $id, 1)]
            : [Button::to('✏️ حجم', self::ID, 'gb', $id), Button::to('✏️ مدت', self::ID, 'days', $id), Button::to('✏️ تعداد کاربر', self::ID, 'dev', $id)];

        return $view->row(Button::to('✔️ ثبت و تحویل سفارش', self::ID, 'make', $id))
            ->grid(3, ...$edits)
            ->grid(
                3,
                Button::to('✏️ سرور', self::ID, 'srv', $id),
                Button::to('✏️ پرداخت', self::ID, 'pay', $id),
                $payment !== 'free' ? Button::to('✏️ مبلغ', self::ID, 'amt', $id) : null,
            )
            ->row(Button::to('✏️ نام کاربری سرویس', self::ID, 'name', $id), Button::to('✏️ یادداشت', self::ID, 'note', $id))
            ->row(Button::to('✏️ نوع سرویس', self::ID, 'k', $id, '_'), $this->cancel($id));
    }

    /** The traffic an agent's bot may still sell, in bytes — their own account's, as their panel reads it —; null for any other seat. */
    private function agentTraffic(Admin $admin): ?int
    {
        if (!$admin->seat->isAgent()) {
            return null;
        }
        try {
            $account = $admin->api->get('/account')['account'] ?? null;
        } catch (ApiRefusal) {
            return null;
        }
        $bot = is_array($account) && Json::part($account, 'bot');

        return is_int($bot['traffic_balance'] ?? null) ? $bot['traffic_balance'] : null;
    }

    /** The order made once, under the key its draft keeps for these very values; what came of it, with the ways on. */
    private function make(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $body = ($draft['kind'] ?? null) === 'plan'
            ? ['plan_id' => (int) ($draft['plan'] ?? 0)]
            : ['traffic_gb' => (string) ($draft['gb'] ?? ''), 'days' => (string) ($draft['days'] ?? ''), 'devices' => (string) ($draft['devices'] ?? '')];
        $body += [
            'server_id' => (int) ($draft['server'] ?? 0),
            'client_name' => (string) ($draft['name'] ?? ''),
            'payment' => (string) ($draft['payment'] ?? ''),
            'note' => (string) ($draft['note'] ?? ''),
        ];
        if (($draft['payment'] ?? null) !== 'free') {
            $body['amount'] = (string) ($draft['amount'] ?? '');
        }
        $key = 'bot-' . (string) ($draft['nonce'] ?? '') . '-' . substr(sha1((string) json_encode($body)), 0, 16);
        $answer = $admin->api->post("/users/{$id}/orders", $body, ['Idempotency-Key' => $key]);
        $admin->forget(self::ID);

        $order = Json::part($answer, 'order');
        $service = is_array($answer['subscription'] ?? null) ? $answer['subscription'] : null;
        $number = Customer::int($order, 'id');
        $notes = Customer::text($order, 'notes');
        [$title, $words] = match ($order['status'] ?? null) {
            'fulfilled' => ['✅ سفارش برای ' . Customer::known($admin, $id) . ' ثبت و تحویل شد', 'به مشتری خبر داده شد.'],
            'failed' => ['⚠️ سفارش ثبت شد ولی تحویلش ناموفق بود', trim(($notes ?? '') . ' از صفحه همین سفارش دوباره امتحان کنید.')],
            'cancelled' => ['❌ سفارش ثبت نشد', $notes ?? ''],
            default => ['⏳ سفارش ثبت شد و تحویلش در جریان است', 'نتیجه را در صفحه سفارش ببینید.'],
        };
        $view = View::make($title, 'مدیریت', 'فروش', 'کاربران', Customer::known($admin, $id))
            ->facts([
                'سفارش' => Fmt::id($number),
                'سرویس' => $service === null ? null : Fmt::ltr(Customer::text($service, 'name')) . (is_array($service['server'] ?? null) ? ' · ' . Fmt::e(Customer::text($service['server'], 'name')) : ''),
            ]);
        if ($words !== '') {
            $view->line()->line(Fmt::e($words));
        }

        return $view->row(
            Button::to('🛒 سفارش ' . Fmt::id($number), 'orv', 'show', $number),
            $service === null ? null : Button::to('📡 سرویس', 'sbv', 'show', Customer::int($service, 'id')),
        )
            ->row(Button::to('➕ سفارش دیگر', self::ID, 'new', $id))
            ->back(CustomerScreen::ID, 'show', $id);
    }
}
