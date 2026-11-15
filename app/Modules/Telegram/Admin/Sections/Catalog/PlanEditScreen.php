<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Auth\StaffGrant;
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
use App\Support\Persian;
use App\Support\Unit;

/**
 * The plan form (the panel's «افزودن پلن» / «ویرایش» dialog) as a wizard: a new plan asks what it must have field by
 * field — name, category, price, traffic, term, devices, servers — then the optional description and the first-purchase
 * and agent prices («⏭ رد شدن»); then, and for an edit at once, a summary in the form's four tabs with «✏️» on every field
 * — the checkout note, the bot button's colour and width, the badge, the premium emoji, the switch, the servers
 * (PlanServersScreen), the extras prices (PlanExtrasScreen) — and one save of the whole draft. A save the panel refuses
 * keeps the draft and opens the first tab with something to fix, each refusal over its field.
 *
 * The draft (PlanDraft) is the chat's: `id` (0: new), `d`, `seq` (the step of a new plan's questions, null after),
 * `tab`, `err` (the last refusal's messages by field), `title` (the name the plan had).
 *
 * An edit starts from the plan as the panel lists it at that tap (it has no route for one plan), never from a list read
 * before.
 *
 * Actions: `new`, `edit` (id), `sum` (tab), `f` (field, page of its choices: edit it), `v` (field, value: a picked one),
 * `x` (field: emptied), `skip` (field), `next`, `tog` (1|0: the switch set), `save`, `drop` (asks), `gone` (drops the
 * draft).
 */
final class PlanEditScreen extends BaseScreen
{
    public const ID = 'ple';

    /** What a new plan is asked, in order; the summary has the rest. */
    private const SEQUENCE = ['name', 'category_id', 'price', 'traffic_gb', 'duration_hours', 'ip_limit', 'servers', 'description', 'first_purchase_price', 'agent_price'];

    /** The fields that may be left blank. */
    private const OPTIONAL = ['description', 'checkout_note', 'first_purchase_price', 'agent_price', 'emoji_id'];

    /** Each tab's fields as the summary offers them. */
    private const FIELDS = [
        'details' => ['name', 'emoji_id', 'category_id', 'description', 'checkout_note', 'button_style', 'button_row', 'badge', 'is_active'],
        'quota' => ['price', 'first_purchase_price', 'agent_price', 'traffic_gb', 'duration_hours', 'ip_limit'],
        'servers' => ['servers'],
        'extras' => ['extras'],
    ];

    /** Each field's emoji on its button. */
    private const ICONS = [
        'name' => '✏️', 'emoji_id' => '✨', 'category_id' => '🗂', 'description' => '📝', 'checkout_note' => '🧾', 'button_style' => '🎨',
        'button_row' => '↔️', 'badge' => '🏷', 'is_active' => '🛒', 'price' => '💰', 'first_purchase_price' => '🥇', 'agent_price' => '🤝',
        'traffic_gb' => '📶', 'duration_hours' => '⏳', 'ip_limit' => '👥', 'servers' => '🖥', 'extras' => '➕',
    ];

    /** The badge choice's «بدون برچسب», the colour's «پیش‌فرض»: a button's data holds no blank value. */
    private const NONE = 'none';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->holds(StaffGrant::Catalog);
    }

    public function show(Admin $admin, array $args): View
    {
        if (self::draft($admin) === null) {
            return $admin->open(PlansScreen::ID);
        }
        // Shown (a question let go of, say), it is the summary: no more questions follow.
        $admin->remember(self::ID, ['seq' => null]);

        return $this->summary($admin);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $field = (string) ($args[0] ?? '');
        if ($action === 'new') {
            $admin->forget(self::ID);
            $admin->remember(self::ID, ['id' => 0, 'd' => PlanDraft::fresh(), 'seq' => null, 'tab' => 'details', 'err' => [], 'title' => '']);

            return $this->step($admin, 0);
        }
        if ($action === 'edit') {
            $id = self::id($args);
            foreach (PlansScreen::plans($admin) as $plan) {
                if ((int) $plan['id'] === $id) {
                    $admin->forget(self::ID);
                    $admin->remember(self::ID, ['id' => $id, 'd' => PlanDraft::of($plan), 'seq' => null, 'tab' => 'details', 'err' => [], 'title' => (string) $plan['name']]);

                    return $this->summary($admin);
                }
            }

            return $admin->open(PlansScreen::ID);
        }
        $draft = self::draft($admin);
        if ($draft === null) {
            return $admin->open(PlansScreen::ID);
        }

        switch ($action) {
            case 'sum':
                $admin->remember(self::ID, ['seq' => null] + (isset(PlanDraft::TABS[$field]) ? ['tab' => $field] : []));

                return $this->summary($admin);
            case 'f':
                return $this->field($admin, $field, $draft, self::page($args, 1));
            case 'v':
                $value = self::picked($field, (string) ($args[1] ?? ''));
                if ($value === false) {
                    break;
                }
                self::put($admin, [$field => $value], $field);

                return $this->after($admin, $field);
            case 'x':
                if (!in_array($field, self::OPTIONAL, true)) {
                    break;
                }
                self::put($admin, [$field => in_array($field, ['description', 'checkout_note'], true) ? '' : null], $field);

                return $this->after($admin, $field);
            case 'skip':
            case 'next':
                return $this->step($admin, (int) ($admin->state(self::ID)['seq'] ?? count(self::SEQUENCE)) + 1);
            case 'tog':
                if (in_array($field, ['1', '0'], true)) {
                    self::put($admin, ['is_active' => $field === '1'], 'is_active');
                }

                return $this->summary($admin);
            case 'save':
                return $this->save($admin, $draft);
            case 'drop':
                return $admin->confirm(
                    'این پیش‌نویس کنار گذاشته شود؟',
                    Button::to('🗑 بله، کنار گذاشته شود', self::ID, 'gone'),
                    Button::to('✖️ انصراف', self::ID, 'sum'),
                    'آنچه این‌جا نوشته شده ذخیره نمی‌شود.',
                );
            case 'gone':
                $id = (int) ($admin->state(self::ID)['id'] ?? 0);
                $admin->forget(self::ID);

                return $id > 0 ? $admin->open(PlanScreen::ID, 'show', [$id]) : $admin->open(PlansScreen::ID);
        }

        return $this->summary($admin);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $field = (string) ($args[0] ?? '');
        $draft = self::draft($admin);
        if ($action !== 'f' || $draft === null) {
            return $this->show($admin, []);
        }
        $changes = match ($field) {
            'name' => ['name' => $answer->text(), 'emoji_id' => CatalogFmt::nameEmoji($answer, $answer->text(), is_string($draft['emoji_id'] ?? null) ? $draft['emoji_id'] : null)],
            'emoji_id' => self::premium($answer, (string) ($draft['name'] ?? '')),
            'description', 'checkout_note' => [$field => $answer->text()],
            'price', 'first_purchase_price', 'agent_price' => [$field => (string) ($answer->amount(Unit::Toman) ?? throw ApiRefusal::typed($field, 'مبلغ را به تومان و بدون اعشار بنویسید، مثل ۱۲۰۰۰۰ یا «۱۲۰ هزار تومان»؛ برای رایگان ۰.'))],
            'traffic_gb' => ['traffic_gb' => self::gigabytes($answer) ?? throw ApiRefusal::typed($field, 'حجم را به گیگابایت بنویسید، مثل ۳۰ یا ۰.۵؛ ۰ یعنی نامحدود.')],
            'duration_hours' => ['duration_hours' => self::hours($answer) ?? throw ApiRefusal::typed($field, 'مدت را به روز بنویسید، مثل ۳۰، یا به ساعت، مثل «۱۲ ساعت»؛ ۰ یعنی نامحدود.')],
            'ip_limit' => ['ip_limit' => self::unlimited($answer) ?? $answer->amount(Unit::Device) ?? throw ApiRefusal::typed($field, 'تعداد کاربر را با یک عدد بنویسید؛ ۰ یعنی نامحدود.')],
            default => null,
        };
        if ($changes === null) {
            return $this->summary($admin);
        }
        self::put($admin, $changes, $field);

        return $this->after($admin, $field);
    }

    /**
     * The draft being made or edited; null with none.
     *
     * @return array<string, mixed>|null
     */
    public static function draft(Admin $admin): ?array
    {
        $draft = $admin->state(self::ID)['d'] ?? null;

        return is_array($draft) ? $draft : null;
    }

    /**
     * `$changes` into the draft; the refusal of `$field` gone with them (it is being fixed).
     *
     * @param array<string, mixed> $changes
     */
    public static function put(Admin $admin, array $changes, string $field): void
    {
        $state = $admin->state(self::ID);
        $errors = Json::part($state, 'err');
        foreach (array_keys($errors) as $refused) {
            if (explode('.', (string) $refused)[0] === $field) {
                unset($errors[$refused]);
            }
        }
        $admin->remember(self::ID, ['d' => array_replace(self::draft($admin) ?? PlanDraft::fresh(), $changes), 'err' => $errors]);
    }

    /** Whether the draft is a new plan's, still on its questions: the servers' screen goes on to the next. */
    public static function asking(Admin $admin): bool
    {
        return ($admin->state(self::ID)['seq'] ?? null) !== null;
    }

    /**
     * The form's choices: the categories and every server with its inbounds and why it cannot sell now.
     *
     * @return array{categories: list<array<string, mixed>>, servers: list<array<string, mixed>>}
     */
    public static function options(Admin $admin): array
    {
        $options = $admin->api->get('/plans/options');

        return [
            'categories' => Json::rows($options, 'categories'),
            'servers' => Json::rows($options, 'servers'),
        ];
    }

    /** After a field is set: the next question of a new plan's, else the summary on the field's tab. */
    private function after(Admin $admin, string $field): View
    {
        $seq = $admin->state(self::ID)['seq'] ?? null;
        if ($seq !== null) {
            return $this->step($admin, (int) $seq + 1);
        }
        $admin->remember(self::ID, ['tab' => PlanDraft::tab($field)]);

        return $this->summary($admin);
    }

    /** A new plan's question `$index`; past the last, the summary. */
    private function step(Admin $admin, int $index): View
    {
        $field = self::SEQUENCE[$index] ?? null;
        if ($field === null) {
            $admin->remember(self::ID, ['seq' => null, 'tab' => 'details']);

            return $this->summary($admin);
        }
        $admin->remember(self::ID, ['seq' => $index]);
        if ($field === 'category_id' && self::options($admin)['categories'] === []) {
            return $this->step($admin, $index + 1);
        }

        return $this->field($admin, $field, self::draft($admin) ?? PlanDraft::fresh());
    }

    /**
     * Editing one field: a question, a choice (page `$page` of its choices), or its own screen; the switch is the
     * summary's button.
     *
     * @param array<string, mixed> $draft
     */
    private function field(Admin $admin, string $field, array $draft, int $page = 1): View
    {
        $asking = self::asking($admin);
        $new = (int) ($admin->state(self::ID)['id'] ?? 0) === 0;
        switch ($field) {
            case 'servers':
                return $admin->open(PlanServersScreen::ID);
            case 'extras':
                return $admin->open(PlanExtrasScreen::ID);
            case 'is_active':
                return $this->summary($admin);
            case 'category_id':
            case 'button_style':
            case 'button_row':
            case 'badge':
                return $this->choose($admin, $field, $draft, $page);
        }
        if (!isset(PlanDraft::LABELS[$field])) {
            return $this->summary($admin);
        }

        $agentShop = !$admin->seat->shop()->isMain();
        [$question, $hint, $choices] = match ($field) {
            'name' => ['نام پلن', 'همان‌طور که مشتری در ربات می‌بیند؛ مثلا «یک‌ماهه ۳۰ گیگ». نامی که با ایموجی پرمیوم شروع شود، در ربات پرمیوم دیده می‌شود.', []],
            'emoji_id' => ['ایموجی پرمیوم نام', 'یک ایموجی پرمیوم بفرستید: جای ایموجی اول نام می‌نشیند و ربات آن را پرمیوم نشان می‌دهد؛ روی دکمه به شکل آیکون. فقط وقتی دیده می‌شود که حسابی که ربات را در ' . Fmt::ltr('@BotFather') . ' ساخته تلگرام پرمیوم داشته باشد؛ مینی‌اپ و وب‌سایت همان ایموجی معمولی را نشان می‌دهند.', []],
            'description' => ['توضیحات', 'زیر نام پلن نمایش داده می‌شود؛ مثلا برای چه کسی مناسب است.', []],
            'checkout_note' => ['یادداشت پیش‌فاکتور', 'زیر پیش‌فاکتور خرید و تمدید این پلن در ربات نشان داده می‌شود؛ حداکثر ۵۰۰ نویسه.', []],
            'price' => ['قیمت (تومان)', 'قیمت عادی پلن، برای هر خرید و تمدید؛ ۰ یعنی رایگان.', [Button::to('🆓 رایگان', self::ID, 'v', 'price', '0')]],
            'first_purchase_price' => ['قیمت خرید اول (تومان)', 'مشتری‌ای که هنوز سرویسی از فروشگاه نخریده، اولین بار این پلن را با این قیمت می‌خرد. باید کمتر از قیمت پلن باشد؛ ۰ یعنی خرید اول رایگان. تمدید همیشه با قیمت عادی است.', []],
            'agent_price' => ['قیمت نماینده (تومان)', 'نماینده‌ها و مشتری‌های گروه‌های همکار این قیمت را می‌پردازند، در خرید و تمدید؛ باید کمتر از قیمت باشد. خالی یعنی ندارد.', []],
            'traffic_gb' => ['حجم (گیگابایت)', $agentShop ? 'الزامی؛ هر فروش و تمدید این پلن همین حجم را از حجم نمایندگی کم می‌کند.' : '۰ یعنی نامحدود. حجم و مدت روی پنل اعمال می‌شوند و با پایان هر کدام اشتراک منقضی می‌شود.', [
                ...array_map(static fn(int $gb): Button => Button::to(Persian::number($gb) . ' گیگ', self::ID, 'v', 'traffic_gb', (string) $gb), [10, 30, 50, 100]),
                ...($agentShop ? [] : [Button::to('♾ نامحدود', self::ID, 'v', 'traffic_gb', '0')]),
            ]],
            'duration_hours' => ['مدت اعتبار', 'به روز بنویسید، یا برای پلن ساعتی با «ساعت» (مثل «۱۲ ساعت»)؛ ۰ یعنی نامحدود. روزها از اولین اتصال مشتری شمرده می‌شوند.', [
                ...array_map(static fn(int $days): Button => Button::to(Persian::number($days) . ' روز', self::ID, 'v', 'duration_hours', (string) ($days * 24)), [7, 30, 60, 90]),
                Button::to('♾ نامحدود', self::ID, 'v', 'duration_hours', '0'),
            ]],
            'ip_limit' => ['تعداد کاربر', 'چند اتصال هم‌زمان مجاز است؛ ۰ یعنی نامحدود.', [
                ...array_map(static fn(int $count): Button => Button::to(Persian::number($count) . ' کاربر', self::ID, 'v', 'ip_limit', (string) $count), [1, 2, 3, 5]),
                Button::to('♾ نامحدود', self::ID, 'v', 'ip_limit', '0'),
            ]],
            default => [PlanDraft::LABELS[$field], '', []],
        };

        // The premium emoji's hint carries its own markup (an isolated @BotFather).
        $lines = [$field === 'emoji_id' ? $hint : Fmt::e($hint)];
        $current = $draft[$field] ?? null;
        $set = $current !== null && $current !== '';
        if ($set || !$asking) {
            $lines[] = 'الان: ' . self::display($field, $draft, null);
        }
        $index = array_search($field, self::SEQUENCE, true);
        if ($asking && $index !== false) {
            $lines[] = '<i>گام ' . Persian::number($index + 1) . ' از ' . Persian::number(count(self::SEQUENCE)) . '</i>';
        }
        $cancel = $asking && $new && $field === self::SEQUENCE[0] ? Button::to('✖️ لغو', self::ID, 'gone') : Button::to('✖️ لغو', self::ID, 'sum');
        $prompt = new Prompt($question, self::ID, 'f', [$field], implode("\n", $lines), $cancel);
        if (in_array($field, self::OPTIONAL, true)) {
            if ($asking) {
                $choices[] = Button::to('⏭ رد شدن', self::ID, 'skip', $field);
                $choices[] = Button::to('📋 خلاصه و ساختن', self::ID, 'sum');
            }
            if ($set) {
                $choices[] = Button::to($field === 'emoji_id' ? '🚫 برداشتن ایموجی پرمیوم' : '🗑 خالی کردن', self::ID, 'x', $field);
            }
        }

        return $admin->ask($prompt->choices(...$choices));
    }

    /**
     * A field picked among its choices: the category (many go by pages), the button's colour and width, the badge.
     *
     * @param array<string, mixed> $draft
     */
    private function choose(Admin $admin, string $field, array $draft, int $page): View
    {
        $current = $draft[$field] ?? null;
        $view = View::make(self::ICONS[$field] . ' ' . PlanDraft::LABELS[$field], 'مدیریت', 'فروشگاه', 'پلن‌ها');
        $options = [];
        switch ($field) {
            case 'category_id':
                $categories = self::options($admin)['categories'];
                $view->line($categories === []
                    ? 'هنوز دسته‌ای ندارید؛ از بخش «دسته‌بندی‌ها» بسازید.'
                    : 'تا وقتی دسته فعالی هست، مشتری در ربات اول دسته را انتخاب می‌کند؛ پلن بی‌دسته زیر «سایر پلن‌ها» می‌آید.');
                $options[0] = 'بدون دسته';
                foreach ($categories as $category) {
                    $options[(int) $category['id']] = (string) $category['name'] . ((bool) ($category['is_active'] ?? true) ? '' : ' (غیرفعال)');
                }
                $current = (int) ($current ?? 0);
                break;
            case 'button_style':
                $view->line('رنگ دکمه پلن در لیست پلن‌های ربات. دکمه پلن‌های پشت سر هم با پهنای یکسان، در یک ردیف کنار هم می‌آیند.');
                foreach (CatalogFmt::STYLES as $style => $label) {
                    $options[$style === '' ? self::NONE : $style] = $label;
                }
                $current ??= self::NONE;
                break;
            case 'button_row':
                $view->line('چند دکمه در ردیف دکمه این پلن در لیست پلن‌های ربات می‌آیند. دکمه پلن‌های پشت سر هم با پهنای یکسان، در یک ردیف کنار هم می‌آیند.');
                $options = CatalogFmt::ROWS;
                $current = (int) ($current ?? 1);
                break;
            case 'badge':
                $view->line('روی کارت پلن در مینی‌اپ و وب‌سایت؛ مثلا پلنی که بیشتر از همه فروخته می‌شود.');
                $options = [self::NONE => 'بدون برچسب'] + CatalogFmt::BADGES;
                $current ??= self::NONE;
                break;
        }
        $buttons = [];
        foreach ($options as $value => $label) {
            $buttons[] = Button::to(((string) $value === (string) $current ? '● ' : '') . $label, self::ID, 'v', $field, (string) $value);
        }
        $view->paged(count($buttons) > 4 ? 2 : 1, $buttons, $page, static fn(int $at): array => [self::ID, 'f', [$field, $at]]);
        if (self::asking($admin)) {
            $view->row(Button::to('📋 خلاصه و ساختن', self::ID, 'sum'));
        }

        return $view->back(self::ID, 'sum');
    }

    /** The summary: every field in the form's tabs, the shown tab's fields to edit, and the one save. */
    private function summary(Admin $admin): View
    {
        $state = $admin->state(self::ID);
        $draft = self::draft($admin) ?? PlanDraft::fresh();
        $id = (int) ($state['id'] ?? 0);
        $tab = isset(PlanDraft::TABS[(string) ($state['tab'] ?? '')]) ? (string) $state['tab'] : 'details';
        $errors = Json::part($state, 'err');
        $options = self::options($admin);

        $view = View::make($id > 0 ? '✏️ ویرایش «' . (string) ($state['title'] ?? '') . '»' : '📦 پلن تازه', 'مدیریت', 'فروشگاه', 'پلن‌ها');
        foreach ($errors as $field => $messages) {
            foreach (is_array($messages) ? $messages : [] as $message) {
                $view->line('⚠️ ' . Fmt::e((PlanDraft::LABELS[explode('.', (string) $field)[0]] ?? (string) $field) . ': ' . (is_string($message) ? $message : '')));
            }
        }
        if ($errors !== []) {
            $view->line();
        }
        foreach (self::FIELDS as $section => $fields) {
            $view->line('<b>' . Fmt::e(PlanDraft::TABS[$section]) . '</b>');
            if ($section === 'servers') {
                $view->lines(...PlanServersScreen::lines($admin, $draft, $options['servers']));
            } elseif ($section === 'extras') {
                $view->line(is_array($draft['extras'] ?? null) ? 'قیمت‌های این پلن' : 'قیمت‌های فروشگاه');
            } else {
                foreach ($fields as $field) {
                    $view->line(Fmt::e(PlanDraft::LABELS[$field]) . ': ' . self::display($field, $draft, $options));
                }
            }
            $view->line();
        }
        $view->note($id > 0
            ? 'تغییرات روی خریدهای بعدی و تمدیدها اثر می‌گذارد؛ اشتراک‌های فعلی تا تمدید بعدی با شرایط قبلی ادامه می‌دهند.'
            : 'بسته‌ای که مشتری می‌خرد: حجم، مدت، تعداد کاربر و قیمت.');

        $chips = [];
        foreach (PlanDraft::TABS as $section => $label) {
            $refused = array_filter(array_keys($errors), static fn(int|string $field): bool => PlanDraft::tab((string) $field) === $section) !== [];
            $chips[] = Button::to(($section === $tab ? '● ' : '') . $label . ($refused ? ' ⚠️' : ''), self::ID, 'sum', $section);
        }
        $view->grid(2, ...$chips);
        $buttons = [];
        foreach (self::FIELDS[$tab] as $field) {
            $value = match ($field) {
                'servers' => Persian::number(count(Json::part($draft, 'servers'))) . ' سرور',
                'extras' => is_array($draft['extras'] ?? null) ? 'قیمت‌های این پلن' : 'قیمت‌های فروشگاه',
                default => Fmt::plain(self::display($field, $draft, $options)),
            };
            // The switch carries what it sets: a tap twice, or on a stale window, sets it the same.
            $buttons[] = $field === 'is_active'
                ? Button::to(self::ICONS[$field] . ' ' . PlanDraft::LABELS[$field] . ': ' . $value, self::ID, 'tog', (bool) ($draft['is_active'] ?? true) ? 0 : 1)
                : Button::to(self::ICONS[$field] . ' ' . PlanDraft::LABELS[$field] . ': ' . $value, self::ID, 'f', $field);
        }
        $view->grid(1, ...$buttons)
            ->row(Button::to($id > 0 ? '💾 ذخیره تغییرات' : '✔️ ساختن', self::ID, 'save'))
            ->row(Button::to('🗑 کنار گذاشتن', self::ID, 'drop'));

        return $id > 0 ? $view->back(PlanScreen::ID, 'show', $id) : $view->back(PlansScreen::ID);
    }

    /**
     * Save the whole draft in one request; refused, the summary on the first tab with something to fix.
     *
     * @param array<string, mixed> $draft
     */
    private function save(Admin $admin, array $draft): View
    {
        $id = (int) ($admin->state(self::ID)['id'] ?? 0);
        try {
            $answer = $id > 0 ? $admin->api->put("/plans/{$id}", PlanDraft::request($draft)) : $admin->api->post('/plans', PlanDraft::request($draft));
        } catch (ApiRefusal $refusal) {
            if ($refusal->status !== 422 || $refusal->errors === []) {
                throw $refusal;
            }
            $refused = array_map(static fn(int|string $field): string => PlanDraft::tab((string) $field), array_keys($refusal->errors));
            $first = array_values(array_filter(array_keys(PlanDraft::TABS), static fn(string $tab): bool => in_array($tab, $refused, true)))[0] ?? 'details';
            $admin->remember(self::ID, ['err' => $refusal->errors, 'tab' => $first, 'seq' => null]);
            $admin->alert('ذخیره نشد؛ موارد ⚠️ را درست کنید.');

            return $this->summary($admin);
        }
        $saved = (array) ($answer['plan'] ?? []);
        $admin->forget(self::ID);
        $admin->notice($id > 0 ? '✅ پلن ذخیره شد' : '✅ پلن اضافه شد');

        return $admin->open(PlanScreen::ID, 'show', [(int) ($saved['id'] ?? $id)]);
    }

    /**
     * A field's value as the summary says it (HTML).
     *
     * @param array<string, mixed> $draft
     * @param array{categories: list<array<string, mixed>>, servers: list<array<string, mixed>>}|null $options
     */
    private static function display(string $field, array $draft, ?array $options): string
    {
        $value = $draft[$field] ?? null;
        $text = is_scalar($value) ? (string) $value : '';

        return match ($field) {
            'name', 'description', 'checkout_note' => $text === '' ? Fmt::NONE : Fmt::e(Fmt::cut($text, 80)),
            'emoji_id' => $text === '' ? 'ندارد' : '✨ دارد',
            'category_id' => (int) $value === 0 ? 'بدون دسته' : Fmt::e(self::categoryName((int) $value, $options)),
            'button_style' => Fmt::e(CatalogFmt::STYLES[$text] ?? CatalogFmt::STYLES['']),
            'button_row' => Fmt::e(CatalogFmt::ROWS[(int) $value] ?? CatalogFmt::ROWS[1]),
            'badge' => Fmt::e(CatalogFmt::BADGES[$text] ?? 'بدون برچسب'),
            'is_active' => Fmt::onOff((bool) ($value ?? true)),
            'price' => CatalogFmt::price($value === null ? null : $text),
            'first_purchase_price', 'agent_price' => $value === null ? 'ندارد' : CatalogFmt::price($text),
            'traffic_gb' => $value === null ? Fmt::NONE : CatalogFmt::traffic($text),
            'duration_hours' => Fmt::hours((int) $value),
            'ip_limit' => CatalogFmt::devices((int) $value),
            default => Fmt::e($text),
        };
    }

    /** @param array{categories: list<array<string, mixed>>, servers: list<array<string, mixed>>}|null $options */
    private static function categoryName(int $id, ?array $options): string
    {
        foreach ($options['categories'] ?? [] as $category) {
            if ((int) $category['id'] === $id) {
                return (string) $category['name'] . ((bool) ($category['is_active'] ?? true) ? '' : ' (غیرفعال)');
            }
        }

        return 'دسته ' . Persian::digits((string) $id);
    }

    /** A value picked at a tap, as the draft keeps it; false for one that is none of the field's. */
    private static function picked(string $field, string $value): mixed
    {
        return match ($field) {
            'category_id', 'duration_hours', 'ip_limit' => ctype_digit($value) ? (int) $value : false,
            'button_row' => isset(CatalogFmt::ROWS[(int) $value]) ? (int) $value : false,
            'button_style' => $value === self::NONE ? null : (isset(CatalogFmt::STYLES[$value]) ? $value : false),
            'badge' => $value === self::NONE ? null : (isset(CatalogFmt::BADGES[$value]) ? $value : false),
            'price', 'traffic_gb' => ctype_digit($value) ? $value : false,
            default => false,
        };
    }

    /**
     * The premium emoji sent, as the name's leading one.
     *
     * @return array{name: string, emoji_id: string}
     */
    private static function premium(Answer $answer, string $name): array
    {
        $premium = CatalogFmt::premium($answer) ?? throw ApiRefusal::typed('emoji_id', 'یک ایموجی پرمیوم بفرستید؛ ایموجی معمولی پرمیوم نیست.');

        return ['name' => trim(CatalogFmt::withLeading($name, $premium['emoji'])), 'emoji_id' => $premium['id']];
    }

    /** Gigabytes typed: «۳۰», «۳۰ گیگ», «۰.۵», «نامحدود» (0); null for anything else. */
    private static function gigabytes(Answer $answer): ?string
    {
        $whole = self::unlimited($answer) ?? $answer->amount(Unit::Gigabyte);

        return $whole !== null ? (string) $whole : $answer->decimal();
    }

    /** A term typed in days («۳۰», «۳۰ روز») or hours («۱۲ ساعت»), as hours; «نامحدود» is 0; null for anything else. */
    private static function hours(Answer $answer): ?int
    {
        if (preg_match('/^(\d{1,6})\s*(?:ساعته|ساعت|h|hours?)$/iu', $answer->latin(), $match) === 1) {
            return (int) $match[1];
        }
        $days = self::unlimited($answer) ?? $answer->amount(Unit::Day);

        return $days === null ? null : $days * 24;
    }

    /** 0 for «نامحدود» typed; null otherwise. */
    private static function unlimited(Answer $answer): ?int
    {
        return $answer->text() === 'نامحدود' ? 0 : null;
    }
}
