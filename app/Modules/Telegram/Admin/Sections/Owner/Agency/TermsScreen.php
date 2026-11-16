<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Owner\Words as OwnerWords;
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
 * An agent's terms as a card (the panel's AgencyTermsFields and SellingTermsFields): the level they are on, the credit
 * their wallet may go below zero by, when their agency ends and the traffic they buy each Jalali month at least — each
 * changed by a tap or a typed answer, then one save: approving a request (AgencyApproveScreen) or changing an agent
 * (AgentTermsScreen). Its one way out, as a form's: «⬅️ بازگشت» while the terms are as they started, else «🗑 کنار
 * گذاشتن», asked first.
 *
 * State (the screen's): `for` (the request's or the agent's id), `level_id`, `credit`, `expires` (a moment, or null for
 * none), `minimum`, `selling` (whether the end and the minimum are set here: an agent without a shop has none), `was`
 * (the terms as the card first showed them: what a change is measured against).
 *
 * Actions: `lv` (the levels), `lvp` (level id), `cr` (asks the credit), `crz` (no credit), `ex` (asks the end), `exn`
 * (no end), `exd` (days from now), `mn` (asks the minimum), `mnz` (no minimum), `save`, `quit` (the way out — an older
 * window's «⬅️ بازگشت» too —: changed terms asked first), `gone` (the terms dropped).
 */
abstract class TermsScreen extends BaseScreen
{
    /** A credit and a monthly minimum of 0, as the terms say them (the agency's rules too: AgencySettingsScreen). */
    public const NO_CREDIT = 'بدون اعتبار';

    public const NO_MINIMUM = 'بدون کف خرید';

    /** The ends a tap sets, in days from now. */
    private const DAYS = [30, 90, 180, 365];

    /** A credit or a minimum typed as no sum of Toman. */
    private const NOT_TOMAN = 'مبلغ را به تومان بنویسید، مثل ۵۰۰۰۰۰ یا «۵۰۰ هزار تومان».';

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
                return $admin->ask((new Prompt('اعتبار (تومان)', $this->screen(), 'cr', [], 'موجودی کیف پول نماینده تا این اندازه منفی می‌شود.', Button::to(Words::CANCEL, $this->screen())))
                    ->current((int) ($state['credit'] ?? 0) > 0 ? Fmt::digits((string) $state['credit']) : self::NO_CREDIT, (int) ($state['credit'] ?? 0) > 0)
                    ->example('۵۰۰۰۰۰')
                    ->limits('۰ یا بیشتر؛ ۰ یعنی فقط با موجودی کیف پول')
                    ->choices(Button::to(self::NO_CREDIT, $this->screen(), 'crz')));
            case 'ex':
                return $admin->ask((new Prompt('پایان نمایندگی', $this->screen(), 'ex', [], 'روزهای مانده از امروز، یا روز پایان به تاریخ شمسی. بعد از آن فروش رباتش متوقف می‌شود.', Button::to(Words::CANCEL, $this->screen())))
                    ->current(Fmt::plain(AgencyEnd::words(self::moment($state))), false)
                    ->example('۳۰ یا ۱۴۰۵/۰۷/۲۰')
                    ->choices(Button::to(AgencyEnd::NONE, $this->screen(), 'exn'), ...array_map(fn(int $days): Button => Button::to(Fmt::number($days) . ' روز دیگر', $this->screen(), 'exd', $days), self::DAYS)));
            case 'mn':
                return $admin->ask((new Prompt('کف خرید ماهانه (تومان)', $this->screen(), 'mn', [], 'خرید حجم در هر ماه شمسی؛ کمتر از آن، فروش ربات از ماه بعد متوقف می‌شود.', Button::to(Words::CANCEL, $this->screen())))
                    ->current((int) ($state['minimum'] ?? 0) > 0 ? Fmt::digits((string) $state['minimum']) : self::NO_MINIMUM, (int) ($state['minimum'] ?? 0) > 0)
                    ->example('۱۰۰۰۰۰۰')
                    ->limits('۰ یا بیشتر؛ ۰ یعنی بدون کف خرید')
                    ->choices(Button::to(self::NO_MINIMUM, $this->screen(), 'mnz')));
            case 'save':
                return $this->save($admin, $state);
            case 'quit':
            case 'gone':
                if ($action === 'quit' && self::edited($state)) {
                    return OwnerWords::discard($admin, $this->screen(), false);
                }
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
            'cr' => ['credit' => (string) ($answer->amount(Unit::Toman) ?? throw new ApiRefusal(422, self::NOT_TOMAN))],
            'mn' => ['minimum' => (string) ($answer->amount(Unit::Toman) ?? throw new ApiRefusal(422, self::NOT_TOMAN))],
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
        if (!is_array($state['was'] ?? null)) {
            // The card first shown, as its start left it: what a change is measured against.
            $state['was'] = self::terms($state);
            $admin->remember($this->screen(), ['was' => $state['was']]);
        }
        $view = View::make($this->title($state), ...AgencyScreen::path());
        $this->intro($view, $admin, $state);
        $level = $this->level($admin, (int) ($state['level_id'] ?? 0));
        $selling = Json::on($state, 'selling');
        $view->card([
            'سطح' => $level === null ? '⚠️ انتخاب نشده' : Fmt::e((string) ($level['name'] ?? '')) . ' · هر گیگابایت ' . Fmt::money((string) ($level['price_per_gb'] ?? '0')),
            'اعتبار' => (int) ($state['credit'] ?? 0) > 0 ? Fmt::money($state['credit']) : 'بدون اعتبار: فقط با موجودی کیف پول',
            'پایان نمایندگی' => $selling ? AgencyEnd::words(self::moment($state)) : null,
            'کف خرید ماهانه' => $selling ? self::minimum($state) : null,
        ]);
        if ($this->note($state) !== '') {
            $view->note($this->note($state));
        }
        $view->tiles(
            Button::to(Words::edit('سطح'), $this->screen(), 'lv')->styled($level === null ? 'primary' : null),
            Button::to(Words::edit('اعتبار'), $this->screen(), 'cr'),
            $selling ? Button::to(Words::edit('پایان نمایندگی'), $this->screen(), 'ex') : null,
            $selling ? Button::to(Words::edit('کف خرید ماهانه'), $this->screen(), 'mn') : null,
        );

        return OwnerWords::wayOut($view, $this->screen(), self::edited($state), Button::to($this->saveLabel($state), $this->screen(), 'save')->success());
    }

    /**
     * The terms a card edits, as a change to them is measured.
     *
     * @param array<string, mixed> $state
     * @return array<string, string|null>
     */
    private static function terms(array $state): array
    {
        return [
            'level_id' => (string) ($state['level_id'] ?? ''),
            'credit' => (string) ($state['credit'] ?? '0'),
            'expires' => is_scalar($state['expires'] ?? null) ? (string) $state['expires'] : null,
            'minimum' => (string) ($state['minimum'] ?? '0'),
        ];
    }

    /**
     * Whether the terms are no longer as the card first showed them: its way out asks first.
     *
     * @param array<string, mixed> $state
     */
    private static function edited(array $state): bool
    {
        return is_array($state['was'] ?? null) && $state['was'] !== self::terms($state);
    }

    /**
     * The levels to pick among, each with its price per GB.
     *
     * @param array<string, mixed> $state
     */
    private function levels(Admin $admin, array $state): View
    {
        $view = View::make('🏷 سطح', ...[...AgencyScreen::path(), Fmt::bare($this->title($state))])
            ->line('قیمتی که نماینده هر گیگابایت حجم رباتش را با آن می‌خرد.');
        $buttons = [];
        foreach ($this->all($admin) as $level) {
            $chosen = (int) ($level['id'] ?? 0) === (int) ($state['level_id'] ?? 0);
            $buttons[] = Button::to(($chosen ? Words::PICKED : '') . (string) ($level['name'] ?? '') . ' · هر گیگابایت ' . Fmt::plain(Fmt::money((string) ($level['price_per_gb'] ?? '0'))), $this->screen(), 'lvp', (int) ($level['id'] ?? 0));
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
        return (int) ($state['minimum'] ?? 0) > 0 ? Fmt::money((string) $state['minimum']) : self::NO_MINIMUM;
    }
}
