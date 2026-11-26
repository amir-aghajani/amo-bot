<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

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
use App\Support\Persian;
use App\Support\Unit;

/**
 * «تغییر گروهی قیمت» (the panel's bulk price change): several plans' prices raised or lowered at once by a percent or
 * an amount, rounded — the plans, the direction, the kind, the value, the rounding and which prices moved, each set
 * here —, previewed first (each plan's prices before and after and why one cannot take it, a page of plans at a time),
 * and applied exactly as previewed: any change to the choices drops the preview, the apply carries the digest of the
 * preview it is drawn under (a window left from another preview, or an older version's bare apply, makes nothing and
 * shows the preview in force), and the change goes with the prices the preview showed, which the API makes it on or
 * refuses (a plan's prices moved since, the change sent twice). Refused, the preview stays with why under it, to be
 * previewed again.
 *
 * Actions: `new`, `plans` (page), `tg` (plan id, page), `all` (page), `dir` (increase|decrease), `mode`
 * (percent|amount), `val` (asks), `rnd` (step), `pr` (price field), `pv` (preview), `pg` (preview page), `ok` (the
 * preview's digest: apply).
 */
final class PlanPricesScreen extends BaseScreen
{
    public const ID = 'plp';

    public const TITLE = '💹 تغییر گروهی قیمت';

    /** The preview: its button and its section. */
    private const PREVIEW = '👁 پیش‌نمایش';

    /** The apply's button. */
    private const APPLY = '✔️ اعمال تغییر';

    private const DIRECTIONS = ['increase' => '⬆️ افزایش', 'decrease' => '⬇️ کاهش'];
    private const MODES = ['percent' => '٪ درصد', 'amount' => '💰 مبلغ ثابت'];
    private const ROUNDINGS = [1, 100, 1000, 10000];

    /** The prices a change may move: each the words a plan's form calls it by. */
    private const PRICES = ['price' => 'قیمت', 'first_purchase_price' => 'قیمت خرید اول', 'agent_price' => 'قیمت نماینده'];

    /** A refused change's fields in the screen's words (a plan's refusal names the plan itself). */
    private const REFUSED = ['value' => 'مقدار', 'prices' => 'قیمت‌ها', 'direction' => 'جهت تغییر', 'mode' => 'نوع تغییر', 'round_to' => 'گرد کردن'];

    /** The most of a refusal's lines the window shows. */
    private const REFUSED_MOST = 12;

    public function show(Admin $admin, array $args): View
    {
        $plans = PlansScreen::plans($admin);
        $state = $admin->state(self::ID);
        if (!isset($state['dir'])) {
            $state = self::reset($admin, $plans);
        }
        $ids = array_map(intval(...), (array) ($state['ids'] ?? []));
        $percent = ($state['mode'] ?? 'percent') === 'percent';
        $value = (string) ($state['value'] ?? '');
        $round = (int) ($state['round'] ?? 1000);
        $prices = array_map(strval(...), (array) ($state['prices'] ?? []));

        $view = View::make(self::TITLE, ...PlanEditScreen::under())
            ->card([
                'پلن‌ها' => count($ids) === count($plans) ? 'همه (' . Fmt::number(count($plans)) . ')' : Fmt::number(count($ids)) . ' از ' . Fmt::number(count($plans)),
                'جهت تغییر' => Fmt::e(self::DIRECTIONS[(string) $state['dir']] ?? ''),
                $percent ? 'درصد تغییر' : 'مبلغ تغییر' => $value === '' ? '' : ($percent ? Fmt::percent($value) : Fmt::money($value)),
                'گرد کردن به' => self::rounding($round),
                'قیمت‌هایی که تغییر می‌کنند' => $prices === [] ? 'هیچ‌کدام' : Fmt::e(implode('، ', array_map(static fn(string $field): string => self::PRICES[$field] ?? $field, $prices))),
            ]);

        $preview = is_array($state['pv'] ?? null) ? $state['pv'] : null;
        [$changed, $blocked] = $preview === null ? [0, 0] : self::tally($preview);
        $refused = array_values(array_filter((array) ($state['rf'] ?? []), is_string(...)));
        [$page, $pages] = [1, 1];
        if ($preview === null) {
            $view->note('پیش از اعمال، «' . self::PREVIEW . '» را بزنید تا قیمت تازه هر پلن را ببینید.');
        } else {
            [$rows, $page, $pages] = View::slice(self::problemsFirst($preview), (int) ($state['pg'] ?? 1));
            $view->section(self::PREVIEW)->lines(...self::previewLines($preview, $rows, $changed, $blocked));
        }
        if ($refused !== []) {
            $view->section('⛔️ اعمال نشد');
            foreach (array_slice($refused, 0, self::REFUSED_MOST) as $line) {
                $view->line('• ' . Fmt::e(Fmt::cut($line, 300)));
            }
            if (count($refused) > self::REFUSED_MOST) {
                $view->line('و ' . Persian::number(count($refused) - self::REFUSED_MOST) . ' مورد دیگر.');
            }
            $view->line('هیچ قیمتی تغییر نکرد. پیش‌نمایش را دوباره بگیرید تا قیمت‌های الان هر پلن را ببینید.');
        }

        // The preview's pages under its lines, then what it is made of — each choice marked —, then the preview and the apply.
        $view->pager($page, $pages, self::ID, 'pg');
        $view->row(Button::to(Words::edit('پلن‌ها'), self::ID, 'plans', 1), Button::to(Words::edit($percent ? 'درصد تغییر' : 'مبلغ تغییر'), self::ID, 'val'));
        $view->row(...array_map(static fn(string $dir): Button => Button::to(($state['dir'] === $dir ? Words::PICKED : '') . self::DIRECTIONS[$dir], self::ID, 'dir', $dir), array_keys(self::DIRECTIONS)));
        $view->row(...array_map(static fn(string $mode): Button => Button::to((($state['mode'] ?? 'percent') === $mode ? Words::PICKED : '') . self::MODES[$mode], self::ID, 'mode', $mode), array_keys(self::MODES)));
        $view->grid(2, ...array_map(static fn(int $step): Button => Button::to(($round === $step ? Words::PICKED : '') . ($step === 1 ? 'بدون گرد کردن' : 'گرد به ' . Fmt::plain(Fmt::money($step))), self::ID, 'rnd', $step), self::ROUNDINGS));
        $view->grid(3, ...array_map(static fn(string $field): Button => Button::to(Words::tick(in_array($field, $prices, true)) . self::PRICES[$field], self::ID, 'pr', $field), array_keys(self::PRICES)));
        $ready = $preview !== null && $refused === [] && $blocked === 0 && $changed > 0;
        $view->row(
            // The apply names the preview it is drawn under: a window left from another preview applies nothing.
            $ready ? Button::to(self::APPLY, self::ID, 'ok', self::digest($preview))->success() : null,
            $preview === null || $refused !== [] ? Button::to($refused !== [] ? '🔄 پیش‌نمایش دوباره' : self::PREVIEW, self::ID, 'pv')->primary() : Button::to(self::PREVIEW, self::ID, 'pv'),
        );

        return $view->help('قیمت چند پلن را یک‌جا بالا یا پایین ببرید؛ اول پیش‌نمایش را ببینید، بعد اعمال کنید. هر تغییری در انتخاب‌ها پیش‌نمایش را پاک می‌کند، و تغییر درست با همان قیمت‌هایی اعمال می‌شود که پیش‌نمایش نشان داد. پلنی که قیمت خرید اول یا قیمت نماینده ندارد، بعد از تغییر هم ندارد.')
            ->back(PlansScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $arg = (string) ($args[0] ?? '');
        $plans = PlansScreen::plans($admin);
        if ($action === 'new' || !isset($admin->state(self::ID)['dir'])) {
            self::reset($admin, $plans);
            if ($action === 'new') {
                return $this->show($admin, []);
            }
        }
        $state = $admin->state(self::ID);
        $ids = array_map(intval(...), (array) ($state['ids'] ?? []));
        $all = array_map(static fn(array $plan): int => (int) $plan['id'], $plans);
        switch ($action) {
            case 'plans':
                return $this->picker($admin, $plans, self::page($args));
            case 'tg':
                $id = self::id($args);
                self::change($admin, ['ids' => array_values(array_filter($all, static fn(int $planId): bool => $planId === $id ? !in_array($id, $ids, true) : in_array($planId, $ids, true)))]);

                return $this->picker($admin, $plans, self::page($args, 1));
            case 'all':
                self::change($admin, ['ids' => count($ids) === count($all) ? [] : $all]);

                return $this->picker($admin, $plans, self::page($args));
            case 'dir':
                if (isset(self::DIRECTIONS[$arg])) {
                    self::change($admin, ['dir' => $arg]);
                }
                break;
            case 'mode':
                if (isset(self::MODES[$arg]) && $arg !== ($state['mode'] ?? null)) {
                    self::change($admin, ['mode' => $arg, 'value' => '']);
                }
                break;
            case 'rnd':
                if (in_array((int) $arg, self::ROUNDINGS, true)) {
                    self::change($admin, ['round' => (int) $arg]);
                }
                break;
            case 'pr':
                if (isset(self::PRICES[$arg])) {
                    $prices = array_map(strval(...), (array) ($state['prices'] ?? []));
                    $next = in_array($arg, $prices, true) ? array_diff($prices, [$arg]) : [...$prices, $arg];
                    self::change($admin, ['prices' => array_values(array_intersect(array_keys(self::PRICES), $next))]);
                }
                break;
            case 'val':
                $percent = ($state['mode'] ?? 'percent') === 'percent';
                $value = (string) ($state['value'] ?? '');

                return $admin->ask((new Prompt(
                    $percent ? 'درصد تغییر' : 'مبلغ تغییر (تومان)',
                    self::ID,
                    'val',
                    [],
                    Fmt::e($percent ? 'اعشار هم می‌شود.' : 'به تومان و بدون اعشار.'),
                    Button::to(Words::CANCEL, self::ID),
                ))->current($value === '' ? null : Fmt::digits($value))
                    ->example($percent ? '۱۰' : '۵۰۰۰')
                    ->limits($percent ? 'تا ۱۰۰۰ درصد؛ در کاهش تا ۱۰۰' : 'بیش از صفر'));
            case 'pv':
                $body = self::body($state);
                $changes = Json::rows($admin->api->post('/plans/prices/preview', $body), 'changes');
                $admin->remember(self::ID, ['pv' => ['body' => $body, 'changes' => $changes], 'pg' => 1, 'rf' => []]);
                break;
            case 'pg':
                $admin->remember(self::ID, ['pg' => self::page($args)]);
                break;
            case 'ok':
                $preview = is_array($state['pv'] ?? null) ? $state['pv'] : null;
                if ($preview === null || (array) ($state['rf'] ?? []) !== []) {
                    break;
                }
                [$changed, $blocked] = self::tally($preview);
                if ($blocked > 0 || $changed === 0) {
                    break;
                }
                if ($arg !== self::digest($preview)) {
                    // Tapped under another preview (a window left from before, an older version's button): nothing is
                    // made; the preview in force is shown, to apply from it.
                    $admin->alert('پیش‌نمایش عوض شده است؛ پیش‌نمایش الان را ببینید و اگر درست است، دوباره «' . self::APPLY . '» را بزنید.');
                    break;
                }
                try {
                    $admin->api->post('/plans/prices', self::made($preview));
                } catch (ApiRefusal $refusal) {
                    if ($refusal->status !== 422) {
                        throw $refusal;
                    }
                    // The preview stays — what the owner saw —, why it was not made under it, every line.
                    $admin->remember(self::ID, ['rf' => self::refusals($refusal)]);
                    $admin->notice('⛔️ اعمال نشد');
                    break;
                }
                $admin->forget(self::ID);
                $admin->notice('✅ قیمت ' . Persian::number($changed) . ' پلن تغییر کرد');

                return $admin->open(PlansScreen::ID);
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action !== 'val') {
            return $this->show($admin, []);
        }
        $percent = ($admin->state(self::ID)['mode'] ?? 'percent') === 'percent';
        $value = $percent ? $answer->decimal() : $answer->amount(Unit::Toman);
        if ($value === null) {
            throw new ApiRefusal(422, $percent ? 'درصد را با یک عدد بنویسید، مثل ۱۰ یا ۷.۵.' : 'مبلغ را به تومان و بدون اعشار بنویسید، مثل ۵۰۰۰.');
        }
        self::change($admin, ['value' => (string) $value]);

        return $this->show($admin, []);
    }

    /**
     * The plans the change reaches, ticked among the shop's.
     *
     * @param list<array<string, mixed>> $plans
     */
    private function picker(Admin $admin, array $plans, int $page): View
    {
        $ids = array_map(intval(...), (array) ($admin->state(self::ID)['ids'] ?? []));
        [$rows, $page, $pages] = View::slice($plans, $page);
        $view = View::make(PlansScreen::TITLE, ...PlanEditScreen::under(Fmt::bare(self::TITLE)))
            ->total(count($plans), 'پلن', Fmt::number(count($ids)) . ' انتخاب‌شده')
            ->note('پلن‌هایی را که قیمتشان تغییر می‌کند علامت بزنید.');
        foreach ($rows as $plan) {
            $view->row(Button::to(Words::tick(in_array((int) $plan['id'], $ids, true)) . Fmt::cut((string) $plan['name'], 26) . ' · ' . Fmt::plain(Fmt::money(is_scalar($plan['price'] ?? null) ? (string) $plan['price'] : '0')), self::ID, 'tg', (int) $plan['id'], $page));
        }
        $view->row(Button::to(Words::tick(count($ids) === count($plans)) . 'همه پلن‌ها', self::ID, 'all', $page));
        $view->pager($page, $pages, self::ID, 'plans');

        return $view->back(self::ID);
    }

    /**
     * The panel's choices to start from: every plan, a raise by a percent, rounded to 1,000 Toman, the price alone.
     *
     * @param list<array<string, mixed>> $plans
     * @return array<string, mixed>
     */
    private static function reset(Admin $admin, array $plans): array
    {
        $admin->forget(self::ID);
        $state = ['ids' => array_map(static fn(array $plan): int => (int) $plan['id'], $plans), 'dir' => 'increase', 'mode' => 'percent', 'value' => '', 'round' => 1000, 'prices' => ['price'], 'pv' => null, 'pg' => 1, 'rf' => []];
        $admin->remember(self::ID, $state);

        return $state;
    }

    /**
     * A choice changed: the preview showed another change, and goes with what was said of it.
     *
     * @param array<string, mixed> $changes
     */
    private static function change(Admin $admin, array $changes): void
    {
        $admin->remember(self::ID, $changes + ['pv' => null, 'pg' => 1, 'rf' => []]);
    }

    /**
     * The change as the panel's request carries it.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function body(array $state): array
    {
        return [
            'plan_ids' => array_map(intval(...), (array) ($state['ids'] ?? [])),
            'direction' => (string) ($state['dir'] ?? 'increase'),
            'mode' => (string) ($state['mode'] ?? 'percent'),
            'value' => (string) ($state['value'] ?? ''),
            'round_to' => (int) ($state['round'] ?? 1000),
            'prices' => array_values(array_map(strval(...), (array) ($state['prices'] ?? []))),
        ];
    }

    /**
     * The change as it is made: the previewed request, and each plan's prices as the preview showed them — the API makes
     * it on those or refuses it (a plan changed since, the change sent twice).
     *
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private static function made(array $preview): array
    {
        $previewed = [];
        foreach ((array) ($preview['changes'] ?? []) as $change) {
            if (is_array($change)) {
                $previewed[] = ['id' => (int) ($change['id'] ?? 0), 'before' => (array) ($change['before'] ?? [])];
            }
        }

        return (array) ($preview['body'] ?? []) + ['previewed' => $previewed];
    }

    /**
     * What names a preview — its request and every plan's prices before and after it —, short, for the apply drawn under
     * it to carry: the apply goes only with the preview the window showed.
     *
     * @param array<string, mixed> $preview
     */
    private static function digest(array $preview): string
    {
        return hash('crc32b', (string) json_encode([$preview['body'] ?? [], $preview['changes'] ?? []]));
    }

    /**
     * Why a change was not made, a line each: each plan's (named in it), each field's under its words, or the general one.
     *
     * @return list<string>
     */
    private static function refusals(ApiRefusal $refusal): array
    {
        $lines = [];
        foreach ($refusal->errors as $field => $messages) {
            foreach ($messages as $message) {
                $lines[] = (isset(self::REFUSED[$field]) ? self::REFUSED[$field] . ': ' : '') . $message;
            }
        }

        return $lines === [] ? [$refusal->getMessage()] : $lines;
    }

    /**
     * How many plans' prices the preview moves, and how many cannot take it.
     *
     * @param array<string, mixed> $preview
     * @return array{0: int, 1: int}
     */
    private static function tally(array $preview): array
    {
        $changed = $blocked = 0;
        foreach ((array) ($preview['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }
            if (($change['problem'] ?? null) !== null) {
                $blocked++;
                continue;
            }
            foreach (array_keys(self::PRICES) as $field) {
                if ((($change['before'] ?? [])[$field] ?? null) !== (($change['after'] ?? [])[$field] ?? null)) {
                    $changed++;
                    break;
                }
            }
        }

        return [$changed, $blocked];
    }

    /**
     * The preview's plans, those that cannot take the change first: why nothing can be applied is on its first page.
     *
     * @param array<string, mixed> $preview
     * @return list<array<string, mixed>>
     */
    private static function problemsFirst(array $preview): array
    {
        $changes = Json::rows($preview, 'changes');
        $blocked = array_filter($changes, static fn(array $change): bool => ($change['problem'] ?? null) !== null);

        return [...array_values($blocked), ...array_values(array_diff_key($changes, $blocked))];
    }

    /**
     * The preview: how many plans it moves and how many cannot take it, then page `$page`'s plans — each plan's prices
     * before and after (the amount alone when it stays, a dash for none) and why it cannot take the change.
     *
     * @param array<string, mixed> $preview
     * @param list<array<string, mixed>> $rows The page's plans
     * @return list<string>
     */
    private static function previewLines(array $preview, array $rows, int $changed, int $blocked): array
    {
        $lines = [$changed === 0 && $blocked === 0
            ? 'با این تغییر قیمت هیچ پلنی عوض نمی‌شود.'
            : ($blocked > 0 ? '⛔️ ' : '') . 'قیمت ' . Persian::number($changed) . ' پلن تغییر می‌کند' . ($blocked > 0 ? '؛ ' . Persian::number($blocked) . ' پلن این تغییر را نمی‌پذیرد و تا درست نشود، هیچ قیمتی تغییر نمی‌کند.' : '.'),
        ];
        $columns = array_map(strval(...), (array) (((array) ($preview['body'] ?? []))['prices'] ?? []));
        foreach ($rows as $change) {
            $name = '<b>' . Fmt::e(Fmt::cut((string) ($change['name'] ?? ''), 60)) . '</b>';
            $amounts = array_map(static fn(string $field): string => self::priceChange($change, $field), $columns);
            if (count($columns) === 1) {
                $lines[] = '• ' . $name . ': ' . $amounts[0];
            } else {
                $lines[] = '• ' . $name;
                foreach ($columns as $at => $field) {
                    $lines[] = '   ' . Fmt::e(self::PRICES[$field] ?? $field) . ': ' . $amounts[$at];
                }
            }
            if (is_string($change['problem'] ?? null)) {
                $lines[] = '   ⛔️ ' . Fmt::e($change['problem']);
            }
        }

        return $lines;
    }

    /**
     * One price of a plan as the change leaves it: before ← after, the amount alone when it stays, a dash for none.
     *
     * @param array<string, mixed> $change
     */
    private static function priceChange(array $change, string $field): string
    {
        $from = ($change['before'] ?? [])[$field] ?? null;
        $to = ($change['after'] ?? [])[$field] ?? null;

        return match (true) {
            !is_scalar($from) || !is_scalar($to) => Fmt::NONE,
            (string) $from === (string) $to => Fmt::money((string) $from),
            default => Fmt::money((string) $from) . ' ← <b>' . Fmt::money((string) $to) . '</b>',
        };
    }

    /** A rounding step in words: «بدون گرد کردن (۱ تومان)», «۱٬۰۰۰ تومان». */
    private static function rounding(int $step): string
    {
        return $step === 1 ? 'بدون گرد کردن (' . Fmt::money(1) . ')' : Fmt::money($step);
    }
}
