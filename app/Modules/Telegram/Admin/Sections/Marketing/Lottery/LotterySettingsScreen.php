<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Lottery;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\BotCard;
use App\Modules\Telegram\Admin\Sections\Marketing\ProgramSwitch;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Persian;

/**
 * «⚙️ تنظیمات قرعه‌کشی» (the panel's «قرعه‌کشی شبانه» card, `lottery`), reached from the draws' list: the switch, the hour,
 * the winners, the prize — each kind's amount its own field, a plan prize's plan said with it and picked among the
 * shop's («✏️ پلن جایزه») —, how entries count and, in the main shop, whether agents take part. The hour, the winners and
 * the prize's amount are held while the draw is off, as the panel holds them. A plan prize chosen while no plan is kept
 * asks for the plan first, and saves both at once (the group refuses a plan prize without its plan).
 *
 * Actions besides the card's: `on` (ProgramSwitch: the draw turned on from its list), `pl` (page: the plans to pick
 * from), `pp` (plan id: the plan prize saved with its plan).
 */
final class LotterySettingsScreen extends BotCard
{
    use ProgramSwitch;

    public const ID = 'lts';

    /** A prize given to one of the winner's services. */
    private const ON_A_SERVICE = 'به جدیدترین سرویس فعال برنده اضافه می‌شود؛ برنده‌ای که سرویس فعالی ندارد کنار می‌رود و نفر بعدی برنده می‌شود.';

    /** The prize kinds, in the panel's order. */
    private const PRIZES = ['wallet' => 'شارژ کیف پول', 'traffic' => 'حجم', 'days' => 'زمان', 'plan' => 'سرویس رایگان'];

    /** @var array<int, string>|null The shop's plans, read once an update, for the plan prize's name */
    private ?array $plans = null;

    public function show(Admin $admin, array $args): View
    {
        $this->plans = null;

        return parent::show($admin, $args);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $this->plans = null;
        $this->here = $admin;
        if ($action === 'pl') {
            return $this->planPick($admin, self::id($args));
        }
        if ($action === 'pp') {
            $values = $this->source()->load($admin);
            $values['lottery_prize'] = 'plan';
            $values = $this->source()->save($admin, $values, 'lottery_plan_id', self::id($args));
            $admin->notice('✅ «پلن جایزه» ذخیره شد');

            return $this->card($admin, $values);
        }
        $prize = array_search('lottery_prize', array_map(static fn(FieldDef $field): string => $field->name, $this->fields()), true);
        if ($action === 'c' && self::id($args) === $prize && (array_keys(self::PRIZES)[self::id($args, 1)] ?? null) === 'plan' && !is_int($this->source()->load($admin)['lottery_plan_id'] ?? null)) {
            return $this->planPick($admin, 1);
        }

        return parent::act($admin, $action, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $this->plans = null;

        return parent::input($admin, $action, $args, $answer);
    }

    protected function group(): string
    {
        return 'lottery';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return Marketing::settings(Marketing::LOTTERY);
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::LOTTERY);
    }

    protected function parent(): string
    {
        return LotteryDrawsScreen::ID;
    }

    protected function intro(): string
    {
        return 'هر شب در ساعتی که تعیین می‌کنید، از میان مشتری‌هایی که از قرعه‌کشی قبلی تا همان لحظه خرید کرده‌اند قرعه کشیده می‌شود (حداکثر ۴۸ ساعت گذشته؛ اولین قرعه‌کشی ۲۴ ساعت گذشته)؛ جایزه همان لحظه به برنده‌ها داده می‌شود و در تلگرام یا با ایمیل خبردار می‌شوند. مدیرهای ربات و مشتری‌های مسدود هیچ‌وقت شرکت داده نمی‌شوند.' . "\n\n"
            . 'خرید، تمدید و حجم، زمان و کاربر اضافه شانس می‌آورد، اگر پرداخت شده و برگشت نخورده باشد؛ شارژ کیف پول و سرویس هدیه نه. ساعت، برنده‌ها و جایزه تا روشن شدن همان‌طور می‌مانند.'
            . ($this->main() ? "\n\n" . '«نماینده‌ها شرکت داده نشوند»: خریدهای نماینده‌ها از ربات اصلی در قرعه‌کشی حساب نمی‌شود.' : '');
    }

    protected function fields(): array
    {
        $on = ['lottery_enabled', [true]];
        $fields = [
            FieldDef::toggle('lottery_enabled', 'قرعه‌کشی شبانه فعال باشد'),
            FieldDef::number('lottery_hour', 'ساعت قرعه‌کشی', '', 'به وقت منطقه زمانی فروشگاه؛ شانس‌ها خریدهای پرداخت‌شده از قرعه‌کشی قبلی تا همین ساعت است، پس با عوض کردن ساعت هیچ خریدی جا نمی‌ماند یا دو بار حساب نمی‌شود.')->example('۲۱')->when(...$on),
            FieldDef::number('lottery_winners', 'تعداد برنده‌ها', '', 'هر مشتری حداکثر یک بار برنده می‌شود.')->example('۳')->when(...$on),
            FieldDef::choice('lottery_prize', 'جایزه هر برنده', self::PRIZES),
            FieldDef::money('lottery_wallet', 'مبلغ جایزه', 'به کیف پول برنده اضافه می‌شود.')->when('lottery_prize', ['wallet'])->when(...$on),
            FieldDef::number('lottery_traffic_gb', 'حجم جایزه', 'گیگابایت', self::ON_A_SERVICE)->when('lottery_prize', ['traffic'])->when(...$on),
            FieldDef::number('lottery_days', 'زمان جایزه', 'روز', self::ON_A_SERVICE)->when('lottery_prize', ['days'])->when(...$on),
            FieldDef::choice('lottery_entries', 'شانس شرکت‌کننده‌ها', ['order' => 'هر خرید یک شانس', 'buyer' => 'هر خریدار یک شانس'], 'هر خرید یک شانس: مشتری‌ای که سه خرید کرده سه برابر کسی که یک خرید کرده شانس دارد. هر خریدار یک شانس: همه خریدارها شانس برابر دارند، هر چند بار که خرید کرده باشند.'),
        ];
        if ($this->main()) {
            $fields[] = FieldDef::toggle('lottery_exclude_agents', 'نماینده‌ها شرکت داده نشوند');
        }

        return $this->described($fields);
    }

    /** The hour as a clock says it; a plan prize with its plan's name. */
    protected function value(FieldDef $field, array $values): string
    {
        if ($field->name === 'lottery_hour' && is_numeric($values['lottery_hour'] ?? null)) {
            return self::clock((int) $values['lottery_hour']);
        }
        if ($field->name === 'lottery_prize' && ($values['lottery_prize'] ?? null) === 'plan') {
            return Fmt::e(self::PRIZES['plan'] . ' «' . $this->planName($values) . '»');
        }

        return parent::value($field, $values);
    }

    /** The plan prize's plan: «✏️ پلن جایزه», shown as the amounts are (a plan prize, the draw on). */
    protected function extra(View $view, Admin $admin, array $values): void
    {
        if (($values['lottery_prize'] ?? null) === 'plan' && ($values['lottery_enabled'] ?? false) === true) {
            $view->row(Button::to(Words::edit('پلن جایزه'), self::ID, 'pl'));
        }
    }

    /**
     * The prize as it stands, in words: «۵۰٬۰۰۰ تومان شارژ کیف پول», «۱۰ گیگابایت حجم», «سرویس رایگان «یک‌ماهه»».
     *
     * @param array<string, mixed> $values The bot's settings
     * @param array<int, string> $plans The shop's plans by id (Marketing::plans())
     */
    public static function prize(array $values, array $plans): string
    {
        $id = is_int($values['lottery_plan_id'] ?? null) ? $values['lottery_plan_id'] : 0;

        return match ($values['lottery_prize'] ?? null) {
            'wallet' => Fmt::money(is_numeric($values['lottery_wallet'] ?? null) ? (string) $values['lottery_wallet'] : '0') . ' شارژ کیف پول',
            'traffic' => Fmt::number((int) ($values['lottery_traffic_gb'] ?? 0)) . ' گیگابایت حجم',
            'days' => Fmt::number((int) ($values['lottery_days'] ?? 0)) . ' روز زمان',
            'plan' => self::PRIZES['plan'] . ' «' . ($plans[$id] ?? ($id > 0 ? 'پلن حذف‌شده' : Fmt::NONE)) . '»',
            default => Fmt::NONE,
        };
    }

    /** The draw's hour as a clock says it: «۲۱:۰۰». */
    public static function clock(int $hour): string
    {
        return Persian::digits(sprintf('%02d:00', $hour));
    }

    /** Whether the shop worked is the main one: only there may agents be left out. */
    private function main(): bool
    {
        return $this->here?->seat->shop()->isMain() ?? true;
    }

    /**
     * The plan prize's plan by name, «پلن حذف‌شده» for one gone, «—» for none.
     *
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    private function planName(array $values): string
    {
        $id = is_int($values['lottery_plan_id'] ?? null) ? $values['lottery_plan_id'] : 0;
        if ($id === 0 || $this->here === null) {
            return Fmt::NONE;
        }
        $this->plans ??= Marketing::plans($this->here);

        return $this->plans[$id] ?? 'پلن حذف‌شده';
    }

    /**
     * Which plan a plan prize gives: picked — View::CHOICES_MOST a page, page `$page` (0: the one with the plan kept),
     * the one kept marked ✅ —, saved with the prize.
     *
     * @throws ApiRefusal
     */
    private function planPick(Admin $admin, int $page): View
    {
        $view = View::make('پلن جایزه', ...[...$this->path(), Fmt::bare($this->title())])
            ->line('یک سرویس رایگان از این پلن برای برنده ساخته و در ربات فرستاده می‌شود.');
        $kept = $this->source()->load($admin)['lottery_plan_id'] ?? null;
        $buttons = [];
        $at = 0;
        foreach (Marketing::giftPlans($admin) as $id => $name) {
            $at = $id === $kept ? count($buttons) : $at;
            $buttons[] = Button::to(($id === $kept ? Words::PICKED : '') . $name, self::ID, 'pp', $id);
        }
        if ($buttons === []) {
            $view->line()->line('<i>فروشگاه هنوز پلنی ندارد.</i>');
        }

        return $view->paged(1, $buttons, $page > 0 ? $page : intdiv($at, View::CHOICES_MOST) + 1, static fn(int $to): array => [self::ID, 'pl', [$to]])->back(self::ID, self::VIEW);
    }
}
