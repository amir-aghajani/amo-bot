<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\Unit;

/**
 * An agent's terms as a card (the panel's AgencyTermsFields and SellingTermsFields): the level they are on, the credit
 * their wallet may go below zero by, when their agency ends and the traffic they buy each Jalali month at least — each
 * changed by a tap or a typed answer, then one save: approving a request (AgencyApproveScreen) or changing an agent
 * (AgentTermsScreen).
 *
 * State (the screen's): `for` (the request's or the agent's id), `level_id`, `credit`, `expires` (a moment, or null for
 * none), `minimum`, `selling` (whether the end and the minimum are set here: an agent without a shop has none).
 *
 * Actions: `lv` (the levels), `lvp` (level id), `cr` (asks the credit), `crz` (no credit), `ex` (asks the end), `exn`
 * (no end), `exd` (days from now), `mn` (asks the minimum), `mnz` (no minimum), `save`, `quit`.
 */
abstract class TermsScreen extends BaseScreen
{
    /** The ends a tap sets, in days from now. */
    private const DAYS = [30, 90, 180, 365];

    /** The screen's id, as Screens registers it. */
    abstract protected function screen(): string;

    /** @param array<string, mixed> $state */
    abstract protected function title(array $state): string;

    /**
     * Lines over the terms: the request, or the agent.
     *
     * @param array<string, mixed> $state
     */
    abstract protected function intro(View $view, Admin $admin, array $state): void;

    /** @param array<string, mixed> $state */
    abstract protected function saveLabel(array $state): string;

    /**
     * Save the terms — one API call — and what to show after.
     *
     * @param array<string, mixed> $state
     * @throws ApiRefusal
     */
    abstract protected function save(Admin $admin, array $state): View;

    /**
     * Where the card is left for.
     *
     * @param array<string, mixed> $state
     */
    abstract protected function leave(Admin $admin, array $state): View;

    /**
     * A note under the terms (what the customer is sent).
     *
     * @param array<string, mixed> $state
     */
    protected function note(array $state): string
    {
        return '';
    }

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $state = $admin->state($this->screen());

        return isset($state['for']) ? $this->card($admin, $state) : $this->leave($admin, $state);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $state = $admin->state($this->screen());
        if (!isset($state['for'])) {
            return $this->start($admin, $action, $args);
        }
        $changes = match ($action) {
            'lvp' => ['level_id' => (string) self::id($args)],
            'crz' => ['credit' => '0'],
            'exn' => ['expires' => null],
            'exd' => ['expires' => AgencyEnd::in(max(1, self::id($args)))],
            'mnz' => ['minimum' => '0'],
            default => null,
        };
        if ($changes !== null) {
            $admin->remember($this->screen(), $changes);

            return $this->card($admin, $admin->state($this->screen()));
        }

        switch ($action) {
            case 'lv':
                return $this->levels($admin, $state);
            case 'cr':
                return $admin->ask((new Prompt('اعتبار (تومان)', $this->screen(), 'cr', [], 'موجودی کیف پول تا این اندازه منفی می‌شود. بدون اعتبار: فقط با موجودی کیف پول.' . "\n" . 'الان: ' . Fmt::money($state['credit'] ?? '0')))
                    ->choices(Button::to('بدون اعتبار', $this->screen(), 'crz')));
            case 'ex':
                return $admin->ask((new Prompt('پایان نمایندگی', $this->screen(), 'ex', [], 'روزهای مانده از امروز را بنویسید (مثل ۳۰)، یا روز پایان را به تاریخ شمسی (مثل ۱۴۰۵/۰۷/۲۰). بعد از آن فروش رباتش متوقف می‌شود.' . "\n" . 'الان: ' . AgencyEnd::words(self::moment($state))))
                    ->choices(Button::to('بدون پایان', $this->screen(), 'exn'), ...array_map(fn(int $days): Button => Button::to(Fmt::number($days) . ' روز دیگر', $this->screen(), 'exd', $days), self::DAYS)));
            case 'mn':
                return $admin->ask((new Prompt('کف خرید ماهانه (تومان)', $this->screen(), 'mn', [], 'خرید حجم در هر ماه شمسی؛ کمتر از آن، فروش ربات از ماه بعد متوقف می‌شود. ۰: بدون کف خرید.' . "\n" . 'الان: ' . self::minimum($state)))
                    ->choices(Button::to('بدون کف خرید', $this->screen(), 'mnz')));
            case 'save':
                return $this->save($admin, $state);
            case 'quit':
                $admin->forget($this->screen());

                return $this->leave($admin, $state);
        }

        return $this->start($admin, $action, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $state = $admin->state($this->screen());
        if (!isset($state['for'])) {
            return $this->leave($admin, $state);
        }
        $changes = match ($action) {
            'cr' => ['credit' => (string) ($answer->amount(Unit::Toman) ?? throw new ApiRefusal(422, 'مبلغ را به تومان بنویسید، مثل ۵۰۰۰۰۰ یا «۵۰۰ هزار تومان».'))],
            'mn' => ['minimum' => (string) ($answer->amount(Unit::Toman) ?? throw new ApiRefusal(422, 'مبلغ را به تومان بنویسید، مثل ۵۰۰۰۰۰ یا «۵۰۰ هزار تومان».'))],
            'ex' => ['expires' => AgencyEnd::typed($answer->text()) ?? throw new ApiRefusal(422, 'روزهای مانده را به عدد بنویسید (مثل ۳۰)، یا تاریخ شمسی را مثل ۱۴۰۵/۰۷/۲۰.')],
            default => [],
        };
        $admin->remember($this->screen(), $changes);

        return $this->card($admin, $admin->state($this->screen()));
    }

    /**
     * An action while no card is open: the subclass's own start (`new`, `edit`).
     *
     * @param list<string> $args
     * @throws ApiRefusal
     */
    protected function start(Admin $admin, string $action, array $args): View
    {
        return $this->leave($admin, []);
    }

    /**
     * The terms as they stand, a button each, and the save.
     *
     * @param array<string, mixed> $state
     */
    protected function card(Admin $admin, array $state): View
    {
        $view = View::make($this->title($state), 'مدیریت', 'نمایندگان');
        $this->intro($view, $admin, $state);
        $level = $this->level($admin, (int) ($state['level_id'] ?? 0));
        $levelWords = $level === null ? 'انتخاب سطح' : (string) ($level['name'] ?? '') . ' · هر گیگابایت ' . Fmt::plain(Fmt::money((string) ($level['price_per_gb'] ?? '0')));
        $selling = Json::on($state, 'selling');
        $view->line()
            ->line('• سطح: ' . Fmt::e($levelWords))
            ->line('• اعتبار: ' . ((int) ($state['credit'] ?? 0) > 0 ? Fmt::money($state['credit']) : 'بدون اعتبار: فقط با موجودی کیف پول'));
        if ($selling) {
            $view->line('• پایان نمایندگی: ' . AgencyEnd::words(self::moment($state)))
                ->line('• کف خرید ماهانه: ' . self::minimum($state));
        }
        if ($this->note($state) !== '') {
            $view->note($this->note($state));
        }
        $view->row(Button::to('🏷 سطح: ' . $levelWords, $this->screen(), 'lv'))
            ->row(Button::to('💳 اعتبار: ' . ((int) ($state['credit'] ?? 0) > 0 ? Fmt::plain(Fmt::money($state['credit'])) : 'ندارد'), $this->screen(), 'cr'));
        if ($selling) {
            $view->row(Button::to('📅 پایان: ' . Fmt::plain(AgencyEnd::words(self::moment($state))), $this->screen(), 'ex'))
                ->row(Button::to('📉 کف خرید ماهانه: ' . Fmt::plain(self::minimum($state)), $this->screen(), 'mn'));
        }

        return $view->row(Button::to($this->saveLabel($state), $this->screen(), 'save'), Button::to('✖️ انصراف', $this->screen(), 'quit'))
            ->back($this->screen(), 'quit');
    }

    /**
     * The levels to pick among, each with its price per GB.
     *
     * @param array<string, mixed> $state
     */
    private function levels(Admin $admin, array $state): View
    {
        $view = View::make('🏷 سطح', 'مدیریت', 'نمایندگان', $this->title($state))
            ->line('قیمتی که نماینده هر گیگابایت حجم رباتش را با آن می‌خرد.');
        $buttons = [];
        foreach ($this->all($admin) as $level) {
            $chosen = (int) ($level['id'] ?? 0) === (int) ($state['level_id'] ?? 0);
            $buttons[] = Button::to(($chosen ? '● ' : '') . (string) ($level['name'] ?? '') . ' · هر گیگابایت ' . Fmt::plain(Fmt::money((string) ($level['price_per_gb'] ?? '0'))), $this->screen(), 'lvp', (int) ($level['id'] ?? 0));
        }
        if ($buttons === []) {
            $view->line('هنوز سطحی ندارید؛ از بخش «سطح‌ها» بسازید.');
        }

        return $view->grid(1, ...$buttons)->back($this->screen());
    }

    /**
     * The levels, in the bot's order.
     *
     * @return list<array<string, mixed>>
     */
    protected function all(Admin $admin): array
    {
        return Json::rows($admin->api->get('/agency/levels'), 'levels');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function level(Admin $admin, int $id): ?array
    {
        foreach ($this->all($admin) as $level) {
            if ((int) ($level['id'] ?? 0) === $id) {
                return $level;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $state */
    protected static function moment(array $state): ?string
    {
        return is_string($state['expires'] ?? null) && $state['expires'] !== '' ? $state['expires'] : null;
    }

    /**
     * Money the API answered («500000.00») as a draft holds it: whole Toman.
     */
    protected static function toman(mixed $amount): string
    {
        return is_numeric($amount) ? (string) (int) round((float) $amount) : '0';
    }

    /** @param array<string, mixed> $state */
    private static function minimum(array $state): string
    {
        return (int) ($state['minimum'] ?? 0) > 0 ? Fmt::money((string) $state['minimum']) : 'بدون کف خرید';
    }
}
