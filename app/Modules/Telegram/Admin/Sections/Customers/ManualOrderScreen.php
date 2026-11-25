<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\Services\SessionPresenter;
use App\Modules\Catalog\DTO\Package;
use App\Modules\Catalog\Services\CustomServiceSettings;
use App\Modules\Orders\Services\ManualOrders;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Unit;

/**
 * «➕ افزودن سفارش» (the panel's dialog of that name, the `manual_orders` grant): a service made for the customer by hand
 * — one of the shop's plans, or a service of support's own numbers (GB, days, devices; 0 for no limit) — on a server
 * that can take it now, free, from the customer's wallet or paid outside the shop at the amount typed. A short guided
 * flow: what (a plan, or the numbers), where — the plan's only open server picked by itself, as the panel picks it —,
 * how it is paid and the amount; then the order as a card, its amount the total, with the optional name (the shop's
 * pattern unless typed) and note offered there beside «✏️» for each field, and «✔️ ثبت و تحویل سفارش»: one POST
 * /users/{id}/orders under an Idempotency-Key the draft keeps for these very values (pressed twice, one order). What came
 * of it — delivered, waiting for a retry, cancelled — links to the order's and the service's screens.
 *
 * Its draft is the screen's state (`user`, `kind`, `plan`, `server`, `gb`, `days`, `devices`, `payment`, `amount`,
 * `name`, `note`, `nonce`). Actions, each with the customer's id first: `new`, `k` (kind; `_`: the kinds asked again),
 * `pl` (plans page), `p` (plan), `srv` (the servers), `s` (server), `pay`, `pm` (payment), `amt`, `amtp` (amount picked),
 * `gb` / `days` / `dev` (asked) and `gbq` / `dq` / `vq` (number picked), `name`, `nskip`, `note`, `ntskip`, `make` (an
 * order no longer written: nothing made, the window left as it is).
 */
final class ManualOrderScreen extends BaseScreen
{
    public const ID = 'uso';

    public const TITLE = '➕ افزودن سفارش';

    /** How it is paid, in the panel's words, each with its mark. */
    private const PAYMENTS = ['free' => '🎁 رایگان', 'wallet' => Icons::WALLET . ' از کیف پول مشتری', 'outside' => '💵 پرداخت خارج از فروشگاه'];

    /** What each way of paying counts as, in the panel's words. */
    private const PAID = [
        'free' => 'رایگان؛ هدیه است و خرید مشتری حساب نمی‌شود',
        'wallet' => 'از کیف پول مشتری؛ از موجودی‌اش کم می‌شود',
        'outside' => 'پرداخت خارج از فروشگاه؛ در درآمد فروشگاه حساب می‌شود',
    ];

    private const KINDS = ['plan' => Icons::PLANS . ' یکی از پلن‌ها', 'custom' => Icons::CUSTOM . ' سرویس دلخواه'];

    /** Support's own numbers: each field's question, what 0 means, its quick picks, its quick action and its bound. */
    private const NUMBERS = [
        'gb' => ['حجم (گیگابایت)', '۰ یعنی نامحدود', [10, 30, 50, 100], 'gbq', Package::MAX_GB],
        'days' => ['مدت (روز)', '۰ یعنی بدون پایان', [7, 30, 60, 90], 'dq', Package::MAX_DAYS],
        'devices' => ['تعداد کاربر', '۰ یعنی نامحدود', [1, 2, 3, 5], 'vq', CustomServiceSettings::MAX_DEVICES],
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
        if ($draft === null && $action === 'make') {
            // «✔️ ثبت» of an order no longer written (made already — a double tap —, or another begun since): nothing is
            // made, and the window stays.
            $admin->alert('این سفارش دیگر در جریان نیست؛ اگر ثبت شده باشد، در سفارش‌های مشتری پیداست.');

            return View::stay();
        }
        if ($draft === null) {
            return $this->act($admin, 'new', [(string) $id]);
        }
        $arg = (string) ($args[1] ?? '');

        switch ($action) {
            case 'k':
                if (!isset(self::KINDS[$arg])) {
                    // «✏️ نوع سرویس»: the kinds again, the order kept until another kind is picked.
                    return $this->kinds($admin, $id);
                }
                if ($arg !== ($draft['kind'] ?? null)) {
                    // Another service: what it was, where and for how much asked again.
                    $admin->remember(self::ID, ['kind' => $arg, 'plan' => null, 'server' => null, 'gb' => null, 'days' => null, 'devices' => null, 'amount' => null]);
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
                $admin->remember(self::ID, ['name' => '']);
                break;
            case 'note':
                return $this->askNote($admin, $id);
            case 'ntskip':
                $admin->remember(self::ID, ['note' => '']);
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
                $admin->remember(self::ID, ['name' => $answer->latin()]);
                break;
            case 'note':
                $admin->remember(self::ID, ['note' => $answer->text()]);
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

    /** The next field the order lacks, asked — what, where, how it is paid, the amount —; the order said once it lacks none. */
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

        return $this->summary($admin, $id);
    }

    /**
     * The dialog's screen — a step on the way, not a place: its title, whose order, and the shop's selling suspended said
     * first.
     */
    private function head(Admin $admin, int $id): View
    {
        $view = View::make(self::TITLE, ...Customer::under(Customer::known($admin, $id)))->transient();
        if ($this->shops->shop($admin->seat->shop())['selling_suspended'] !== null) {
            $view->line('⚠️ فروش این فروشگاه متوقف است و تا دوباره باز نشود سفارشی در آن ثبت نمی‌شود.');
        }

        return $view;
    }

    /** A step's screen (head()): what is picked so far as a card, then the step's question. */
    private function step(Admin $admin, int $id, string $question): View
    {
        return $this->head($admin, $id)->card($this->picked($admin, $id))->line('<b>' . $question . '</b>');
    }

    /**
     * What the draft holds so far, as a card's facts: the service, its server, how it is paid.
     *
     * @return array<string, string|null>
     */
    private function picked(Admin $admin, int $id): array
    {
        $draft = $this->draft($admin, $id) ?? [];
        $kind = $draft['kind'] ?? null;
        $plan = $kind === 'plan' && is_int($draft['plan'] ?? null) ? $this->plan($admin, $draft['plan']) : null;
        $server = is_int($draft['server'] ?? null) ? $this->server($admin, $id) : null;

        return [
            'سرویس' => match (true) {
                $plan !== null => '«' . Fmt::e(Customer::text($plan, 'name')) . '»',
                $kind === 'custom' => Fmt::bare(self::KINDS['custom']),
                default => null,
            },
            'سرور' => $server === null ? null : Fmt::e($server['name']),
            'پرداخت' => is_string($draft['payment'] ?? null) && isset(self::PAYMENTS[$draft['payment']]) ? Fmt::e(Fmt::bare(self::PAYMENTS[$draft['payment']])) : null,
        ];
    }

    /** «✖️ انصراف»: out of the order, back to the customer's page; its draft stays until a new one begins. */
    private function cancel(int $id): Button
    {
        return Button::to(Words::CANCEL, CustomerScreen::ID, Nav::SHOW, $id);
    }

    /** «✖️ انصراف» out of a step: back to the order while the field it asks is set (an edit), else out of the order. */
    private function leave(int $id, bool $set): Button
    {
        return $set ? Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id) : $this->cancel($id);
    }

    /** What kind of service: the one picked marked — an edit's «✖️ انصراف» back to the order, else out of it. */
    private function kinds(Admin $admin, int $id): View
    {
        $current = ($this->draft($admin, $id) ?? [])['kind'] ?? null;
        $choices = [];
        foreach (self::KINDS as $kind => $label) {
            $choices[] = Button::to(($kind === $current ? Words::PICKED : '') . $label, self::ID, 'k', $id, $kind);
        }

        return $this->step($admin, $id, 'چه سرویسی برای ' . Fmt::e(Customer::known($admin, $id)) . '؟')
            ->row(...$choices)
            ->note('بلافاصله تحویل و به مشتری خبر داده می‌شود.')
            ->row($this->leave($id, is_string($current)));
    }

    private function plans(Admin $admin, int $id, int $page): View
    {
        $plans = Json::rows($admin->api->get('/plans'), 'plans');
        $current = ($this->draft($admin, $id) ?? [])['plan'] ?? null;
        $view = $this->step($admin, $id, 'کدام پلن؟');
        if ($plans === []) {
            $view->line('هنوز پلنی ندارید؛ اول در «' . Icons::PLANS . ' پلن‌ها» یکی بسازید، یا «' . Fmt::e(self::KINDS['custom']) . '» بدهید.');
        }
        $choices = [];
        $marked = false;
        foreach ($plans as $plan) {
            $mark = match (true) {
                Customer::int($plan, 'id') === $current => Words::PICKED,
                Json::on($plan, 'hidden_by_owner') => '🔒 ',
                ($plan['is_active'] ?? true) !== true => '⛔️ ',
                default => '',
            };
            $marked = $marked || in_array($mark, ['🔒 ', '⛔️ '], true);
            $choices[] = Button::to($mark . self::planLine($plan), self::ID, 'p', $id, Customer::int($plan, 'id'));
        }
        if ($marked) {
            $view->note('⛔️ خاموش برای مشتری‌ها · 🔒 پنهان‌شده توسط پشتیبانی: از پلن پنهان سفارشی ساخته نمی‌شود.');
        }

        return $view->paged(1, $choices, $page, static fn(int $at): array => [self::ID, 'pl', [$id, $at]])
            ->row(Button::to(Words::edit('نوع سرویس'), self::ID, 'k', $id, '_'), $this->leave($id, is_int($current)));
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
        return array_map(static fn(array $entry): array => self::choice(Json::part($entry, 'server'), Customer::text($entry, 'unsellable_reason')), Json::rows($plan, 'servers'));
    }

    /**
     * A server as the order may pick it: its id and name, and why it cannot take an order now (null when it can).
     *
     * @param array<string, mixed> $server
     * @return array{id: int, name: string, reason: string|null}
     */
    private static function choice(array $server, ?string $reason): array
    {
        return ['id' => Customer::int($server, 'id'), 'name' => Customer::text($server, 'name') ?? '', 'reason' => $reason];
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

        return array_map(static fn(array $server): array => self::choice($server, Customer::text($server, 'unsellable_reason')), Json::rows($admin->api->get('/plans/options'), 'servers'));
    }

    /**
     * The server the draft picked, among the ones it may go to; null for none.
     *
     * @return array{id: int, name: string, reason: string|null}|null
     */
    private function server(Admin $admin, int $id): ?array
    {
        $picked = ($this->draft($admin, $id) ?? [])['server'] ?? null;
        foreach ($this->serverChoices($admin, $id) as $choice) {
            if ($choice['id'] === $picked) {
                return $choice;
            }
        }

        return null;
    }

    private function servers(Admin $admin, int $id): View
    {
        $choices = $this->serverChoices($admin, $id);
        $current = ($this->draft($admin, $id) ?? [])['server'] ?? null;
        $view = $this->step($admin, $id, 'روی کدام سرور؟');
        foreach ($choices as $choice) {
            // Why a server cannot take it is more than its button holds: said over the buttons.
            if ($choice['reason'] !== null) {
                $view->line('⛔️ ' . Fmt::e($choice['name']) . ': ' . Fmt::e($choice['reason']));
            }
            $mark = match (true) {
                $choice['id'] === $current => Words::PICKED,
                $choice['reason'] !== null => '⛔️ ',
                default => Icons::SERVERS . ' ',
            };
            $view->row(Button::to($mark . $choice['name'], self::ID, 's', $id, $choice['id']));
        }
        if ($choices === []) {
            $view->line('سروری برای این سرویس نیست.');
        }

        return $view->row(Button::to(Words::edit('نوع سرویس'), self::ID, 'k', $id, '_'), $this->leave($id, is_int($current)));
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
        $balance = Customer::balance(Customer::read($admin, $id));
        $buttons = [];
        foreach (self::PAYMENTS as $payment => $label) {
            $buttons[] = Button::to(($current === $payment ? Words::PICKED : '') . $label . ($payment === 'wallet' ? ' · ' . Messages::balance($balance) : ''), self::ID, 'pm', $id, $payment);
        }

        return $this->step($admin, $id, 'پرداخت چطور است؟')
            ->grid(1, ...$buttons)
            ->help(Fmt::e(implode(".\n", self::PAID) . '.'))
            ->row($this->leave($id, is_string($current)));
    }

    private function askAmount(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $hint = ($draft['payment'] ?? null) === 'wallet'
            ? 'موجودی کیف پول مشتری: <b>' . Fmt::e(Messages::balance(Customer::balance(Customer::read($admin, $id)))) . '</b>'
            : 'مبلغی که بیرون از فروشگاه گرفته‌اید؛ در درآمد فروشگاه حساب می‌شود.';
        $prompt = (new Prompt('مبلغ (تومان)', self::ID, 'amt', [$id], $hint, $this->leave($id, is_int($draft['amount'] ?? null))))
            ->example('۱۵۰۰۰۰')
            ->limits('بیش از صفر تا ' . Fmt::plain(Fmt::money(ManualOrders::AMOUNT_MAX)) . '؛ «۱۵۰ هزار تومان» هم پذیرفته می‌شود');
        if (is_int($draft['amount'] ?? null)) {
            $prompt->current(Fmt::plain(Fmt::money($draft['amount'])), false);
        }
        $plan = ($draft['kind'] ?? null) === 'plan' && is_int($draft['plan'] ?? null) ? $this->plan($admin, $draft['plan']) : null;
        $price = $plan === null ? 0 : (int) round((float) Customer::money($plan['price'] ?? null));
        if ($price > 0) {
            $prompt->choices(Button::to('💰 قیمت پلن: ' . Fmt::money($price), self::ID, 'amtp', $id, $price));
        }

        return $admin->ask($prompt);
    }

    private function askNumber(Admin $admin, int $id, string $field): View
    {
        [$label, $zero, $picks, $quick, $most] = self::NUMBERS[$field];
        $draft = $this->draft($admin, $id) ?? [];
        $prompt = (new Prompt($label, self::ID, $field, [$id], $zero . '.', $this->leave($id, is_int($draft[$field] ?? null))))
            ->limits('از ۰ تا ' . Persian::number($most));
        if (is_int($draft[$field] ?? null)) {
            $prompt->current(Persian::number($draft[$field]), false);
        }
        $choices = array_map(static fn(int $pick): Button => Button::to(Fmt::number($pick), self::ID, $quick, $id, $pick), $picks);
        $choices[] = Button::to($field === 'days' ? 'بدون پایان' : 'نامحدود', self::ID, $quick, $id, 0);

        return $admin->ask($prompt->choices(...$choices));
    }

    private function askName(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $prompt = (new Prompt('نام کاربری سرویس', self::ID, 'name', [$id], 'خالی بماند، با الگوی فروشگاه نام‌گذاری می‌شود.', $this->leave($id, true)))
            ->example('ali_vip')
            ->choices(Button::to('⏭ با الگوی فروشگاه', self::ID, 'nskip', $id));
        if (is_string($draft['name'] ?? null) && $draft['name'] !== '') {
            $prompt->current($draft['name']);
        }

        return $admin->ask($prompt);
    }

    private function askNote(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $prompt = (new Prompt('یادداشت', self::ID, 'note', [$id], 'کنار سفارش می‌ماند و در گروه گزارش‌ها گفته می‌شود؛ مشتری آن را نمی‌بیند.', $this->leave($id, true)))
            ->example('پرداخت نقدی در دفتر')
            ->limits('تا ' . Persian::number(Input::NOTE_MAX) . ' نویسه')
            ->choices(Button::to('⏭ بدون یادداشت', self::ID, 'ntskip', $id));
        if (is_string($draft['note'] ?? null) && $draft['note'] !== '') {
            $prompt->current($draft['note']);
        }

        return $admin->ask($prompt);
    }

    /**
     * The customer's wallet now: its balance and the credit below zero an agent's may use ('0' for none), as the panel's
     * decimal strings.
     *
     * @return array{0: string, 1: string}
     */
    private static function wallet(Admin $admin, int $id): array
    {
        $detail = Customer::read($admin, $id);

        return [Customer::balance($detail), Customer::money(Json::part($detail, 'agency')['credit_limit'] ?? null)];
    }

    /**
     * The order said before it is made (the panel's preview) as a card — what it delivers, where, its name and note, how
     * it is paid and the amount, its total —, a wallet's balance after it, «✔️ ثبت و تحویل سفارش» and «✏️» per field.
     */
    private function summary(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $kind = (string) ($draft['kind'] ?? 'plan');
        $plan = $kind === 'plan' && is_int($draft['plan'] ?? null) ? $this->plan($admin, $draft['plan']) : null;
        $server = $this->server($admin, $id);
        $gb = $kind === 'plan' ? self::gbOf($plan['traffic_gb'] ?? 0) : self::gbOf($draft['gb'] ?? 0);
        $service = $kind === 'plan'
            ? ($plan === null ? 'این پلن دیگر پیدا نشد' : '«' . Fmt::e(Customer::text($plan, 'name')) . '» · ' . self::package($gb, Customer::int($plan, 'duration_hours'), Customer::int($plan, 'ip_limit')))
            : Fmt::bare(self::KINDS['custom']) . ' · ' . self::package($gb, Customer::int($draft, 'days') * 24, Customer::int($draft, 'devices'));
        $payment = (string) ($draft['payment'] ?? 'free');
        $amount = is_int($draft['amount'] ?? null) ? $draft['amount'] : 0;
        $name = is_string($draft['name'] ?? null) && $draft['name'] !== '' ? $draft['name'] : null;
        $note = is_string($draft['note'] ?? null) && $draft['note'] !== '' ? $draft['note'] : null;

        $view = $this->head($admin, $id);
        [$balance, $credit] = $payment === 'wallet' ? self::wallet($admin, $id) : [null, '0'];
        // What the wallet lacks — an agent's credit counted, as the panel counts it —, said in the panel's words.
        $missing = $balance === null ? null : Money::subtract($amount, Money::add($balance, $credit));
        $short = $balance !== null && $missing !== null && Money::isPositive($missing);
        if ($short) {
            $view->line('⚠️ ' . Fmt::e(sprintf(ManualOrders::WALLET_SHORT, Messages::balance($balance), Money::format($missing))) . ' مبلغ یا روش پرداخت را عوض کنید.');
        }
        $view->card([
            'سرویس' => $service,
            'سرور' => $server === null ? '' : Fmt::e($server['name']),
            'نام کاربری سرویس' => $name === null ? 'با الگوی فروشگاه' : Fmt::ltr($name),
            'یادداشت' => $note === null ? '' : Fmt::e($note),
            'پرداخت' => Fmt::e(self::PAID[$payment] ?? self::PAID['free']),
            'مبلغ' => $payment === 'free' ? null : Fmt::money($amount),
            'موجودی کیف پول' => $balance === null ? null : Fmt::e(Messages::balance($balance)) . ($short ? '' : ' · بعد از سفارش ' . Fmt::e(Messages::balance(Money::subtract($balance, $amount)))),
        ]);
        if (!$admin->seat->shop()->isMain() && $gb > 0) {
            $traffic = $this->agentTraffic($admin);
            $view->line(Customer::gb($gb) . ' از حجم ربات این فروشگاه کم می‌شود' . ($traffic === null ? '' : ' (' . Fmt::bytes($traffic) . ' مانده)') . '.');
        }
        $view->note('با ثبت، سرویس بلافاصله ساخته و تحویل می‌شود و به مشتری خبر داده می‌شود.');

        $edits = $kind === 'plan'
            ? [Button::to(Words::edit('پلن'), self::ID, 'pl', $id, 1)]
            : [Button::to(Words::edit('حجم'), self::ID, 'gb', $id), Button::to(Words::edit('مدت'), self::ID, 'days', $id), Button::to(Words::edit('تعداد کاربر'), self::ID, 'dev', $id)];

        return $view->row(Button::to('✔️ ثبت و تحویل سفارش', self::ID, 'make', $id)->success())
            ->grid(
                2,
                ...$edits,
                ...[
                    Button::to(Words::edit('سرور'), self::ID, 'srv', $id),
                    Button::to(Words::edit('پرداخت'), self::ID, 'pay', $id),
                    $payment !== 'free' ? Button::to(Words::edit('مبلغ'), self::ID, 'amt', $id) : null,
                    Button::to(Words::edit('نام کاربری سرویس'), self::ID, 'name', $id),
                    Button::to(Words::edit('یادداشت'), self::ID, 'note', $id),
                    Button::to(Words::edit('نوع سرویس'), self::ID, 'k', $id, '_'),
                ],
            )
            ->row($this->cancel($id));
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
        $bot = is_array($account) ? Json::part($account, 'bot') : [];

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
        $answer = $admin->api->post("/users/{$id}/orders", $body, ['Idempotency-Key' => Customer::requestKey($draft, $body)]);
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
        $view = View::make($title, ...Customer::under(Customer::known($admin, $id)))
            ->card([
                'سفارش' => Fmt::id($number),
                'سرویس' => $service === null ? null : Fmt::ltr(Customer::text($service, 'name')) . (is_array($service['server'] ?? null) ? ' · ' . Fmt::e(Customer::text($service['server'], 'name')) : ''),
            ]);
        if ($words !== '') {
            $view->line(Fmt::e($words));
        }

        return $view->row(
            Button::to(Icons::ORDERS . ' سفارش ' . Fmt::id($number), 'orv', Nav::SHOW, $number),
            $service === null ? null : Button::to(Icons::SERVICES . ' سرویس', 'sbv', Nav::SHOW, Customer::int($service, 'id')),
        )
            ->row(Button::to(Words::add('سفارش دیگر'), self::ID, 'new', $id))
            ->back(CustomerScreen::ID, Nav::SHOW, $id);
    }
}
