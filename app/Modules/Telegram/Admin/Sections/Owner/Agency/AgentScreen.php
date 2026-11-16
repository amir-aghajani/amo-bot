<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;
use Illuminate\Support\Carbon;

/**
 * One agent — opened by their customer id (Nav::to('agv', 'show', $userId)), read fresh from their customer page's
 * `agency` in the main shop (the panel's row, its menu and AgentModal; there is no agents' route for one): their
 * bot and its state, its traffic and what is hidden from their shop, their level, wallet and credit, what their bot sold,
 * their terms of selling and the owner's word on them (selling stopped, an end near, a month short), and the row's menu:
 * «✏️ سطح، اعتبار و شرایط فروش» (the main one), «📦 حجم ربات», «🙈 پنهان از فروشگاهش», «🏪 باز کردن فروشگاه» and their
 * bot's link, «▶️ ادامه فروش» / «⏸ توقف فروش» and «🚫 لغو نمایندگی» (red) — the stop and the end each asked first:
 * «✍️ … با توضیح» (a note the agent reads, then done), «⏭ … بدون توضیح» (done) or «✖️ انصراف».
 *
 * Actions: `sus` (id: asks), `susw` (id: asks the note), `susn` (id: stops without a note), `res` (id), `rev` (id:
 * asks), `revw` (id: asks the note), `revn` (id: ends without a note).
 */
final class AgentScreen extends BaseScreen
{
    public const ID = 'agv';

    /** Why an agent's selling is stopped (SELLING_SUSPENSION). */
    private const SUSPENSIONS = [
        'expired' => 'تاریخ نمایندگی گذشته است',
        'minimum' => 'خرید حجم ماه قبل به کف خرید ماهانه نرسید',
        'manual' => 'فروشگاه اصلی فروش را متوقف کرده است',
    ];

    /** What a shop whose selling is stopped takes no more of (SUSPENDED_SALES). */
    private const SUSPENDED_SALES = 'خرید، تمدید، حجم، زمان و کاربر اضافه، شارژ کیف پول و اکانت تست';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $row = self::load($admin, self::id($args));

        return $row === null ? $admin->open(AgentsScreen::ID) : $this->view($row);
    }

    /**
     * The agent `$id` (their customer id) as the API has them now — their customer page's `agency` (GET /users/{id}, in
     * the main shop whichever shop the owner works: the agents are its customers) —, kept for their sections' lines;
     * null for no agent.
     *
     * @return array<string, mixed>|null
     * @throws ApiRefusal but a 404 (no such customer)
     */
    public static function load(Admin $admin, int $id): ?array
    {
        try {
            $agency = $id > 0 ? self::main($admin)->get("/users/{$id}")['agency'] ?? null : null;
        } catch (ApiRefusal $refusal) {
            if ($refusal->status !== 404) {
                throw $refusal;
            }
            $agency = null;
        }

        return is_array($agency) ? self::keep($admin, $agency) : null;
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'sus':
            case 'rev':
                $row = self::load($admin, $id);
                if ($row === null) {
                    return $admin->open(AgentsScreen::ID);
                }
                $user = Words::user($row['user'] ?? null);
                $name = Fmt::e($user);

                return $action === 'sus'
                    ? Words::withNote(
                        $admin,
                        'فروش ' . $user . ' متوقف شود؟',
                        'در ربات ' . $name . ' تا وقتی خودتان «ادامه فروش» را بزنید ' . self::SUSPENDED_SALES . ' تازه انجام نمی‌شود؛ ربات، پنل و سرویس‌های فعلی مشتری‌هایش کار می‌کنند و خودش هم حجم می‌خرد. به او خبر داده می‌شود.',
                        Button::to('✍️ توقف با توضیح', self::ID, 'susw', $id)->danger(),
                        Button::to('⏭ توقف بدون توضیح', self::ID, 'susn', $id)->danger(),
                        Button::to(UiWords::CANCEL, self::ID, 'show', $id),
                    )
                    : Words::withNote(
                        $admin,
                        'نمایندگی ' . $user . ' لغو شود؟',
                        'ربات ' . $name . ' خاموش می‌شود و ورودش به پنل تمام می‌شود؛ اگر بدهکار باشد، بدهی‌اش می‌ماند. فروشگاه و حجمش نگه داشته می‌شود و با نماینده شدن دوباره برمی‌گردد. به او خبر داده می‌شود.',
                        Button::to('✍️ لغو با توضیح', self::ID, 'revw', $id)->danger(),
                        Button::to('⏭ لغو بدون توضیح', self::ID, 'revn', $id)->danger(),
                        Button::to(UiWords::CANCEL, self::ID, 'show', $id),
                    );
            case 'susw':
                return $admin->ask(new Prompt('توضیح توقف فروش', self::ID, 'sus', [$id], Words::NOTE_HINT, Button::to(UiWords::CANCEL, self::ID, 'show', $id)));
            case 'susn':
                return $this->suspend($admin, $id, '');
            case 'res':
                $agent = (array) ($admin->api->post("/agency/agents/{$id}/resume")['agent'] ?? []);
                $admin->notice('▶️ فروش نماینده دوباره باز شد');

                return $this->view(self::keep($admin, $agent));
            case 'revw':
                return $admin->ask(new Prompt('توضیح لغو نمایندگی', self::ID, 'rev', [$id], Words::NOTE_HINT, Button::to(UiWords::CANCEL, self::ID, 'show', $id)));
            case 'revn':
                return $this->revoke($admin, $id, '');
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        return match ($action) {
            'sus' => $this->suspend($admin, self::id($args), $answer->text()),
            'rev' => $this->revoke($admin, self::id($args), $answer->text()),
            default => $this->show($admin, $args),
        };
    }

    /**
     * The panels' API in the main shop, where the agency is (its agents are its customers), whichever shop is worked.
     */
    private static function main(Admin $admin): PanelBridge
    {
        return $admin->seat->shop()->isMain() ? $admin->api : $admin->api->for($admin->seat->in(CurrentBot::main()));
    }

    /**
     * The agent as last read (load(), or an answer kept): for a section's lines — never what an edit starts from; null
     * when it is not there.
     *
     * @return array<string, mixed>|null
     */
    public static function row(Admin $admin, int $id): ?array
    {
        return ListScreen::keptRow($admin, AgentsScreen::ID, $id);
    }

    /**
     * The agent as the API answered them, kept for the list's page too.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function keep(Admin $admin, array $row): array
    {
        $rows = (array) ($admin->state(AgentsScreen::ID)['rows'] ?? []);
        $rows[(int) ($row['id'] ?? 0)] = $row;
        $admin->remember(AgentsScreen::ID, ['rows' => $rows]);

        return $row;
    }

    /**
     * The owner's word on an agent's terms (the panel's ownerWord): their selling stopped and why, their end near, or
     * their month short; null while all is well.
     *
     * @param array<string, mixed> $agent
     */
    public static function concern(array $agent): ?string
    {
        $standing = is_array($agent['standing'] ?? null) ? $agent['standing'] : null;
        if ($standing === null) {
            return null;
        }
        $warn = (int) ($standing['warn_days'] ?? 0);
        if (is_array($standing['suspension'] ?? null)) {
            return '⛔️ فروش متوقف: ' . (self::SUSPENSIONS[(string) ($standing['suspension']['reason'] ?? '')] ?? '') . '. ربات، پنل و سرویس‌های فعلی مشتری‌هایش کار می‌کنند.';
        }
        $ends = Fmt::moment(is_string($standing['expires_at'] ?? null) ? $standing['expires_at'] : null);
        if ($ends !== null && self::daysUntil($ends) <= $warn) {
            return '⚠️ نمایندگی ' . Fmt::date($standing['expires_at']) . ' به پایان می‌رسد؛ بعد از آن فروش رباتش متوقف می‌شود. با «سطح، اعتبار و شرایط فروش» تمدیدش کنید.';
        }
        $month = Json::part($standing, 'month');
        $monthEnds = Fmt::moment(is_string($month['ends_at'] ?? null) ? $month['ends_at'] : null);
        if (self::short($standing) && $monthEnds !== null && self::daysUntil($monthEnds) <= $warn) {
            return '⚠️ کف ماهانه نرسیده: خرید حجم ' . Fmt::e((string) ($month['name'] ?? '')) . ': ' . self::month($standing) . '. اگر تا پایان ماه به کف نرسد، فروش رباتش متوقف می‌شود.';
        }

        return null;
    }

    private function suspend(Admin $admin, int $id, string $note): View
    {
        $agent = (array) ($admin->api->post("/agency/agents/{$id}/suspend", ['note' => $note])['agent'] ?? []);
        $admin->notice('⛔️ فروش نماینده متوقف شد');

        return $this->view(self::keep($admin, $agent));
    }

    private function revoke(Admin $admin, int $id, string $note): View
    {
        $admin->api->post("/agency/agents/{$id}/revoke", ['note' => $note]);
        $rows = (array) ($admin->state(AgentsScreen::ID)['rows'] ?? []);
        unset($rows[$id], $rows[(string) $id]);
        $admin->remember(AgentsScreen::ID, ['rows' => $rows]);
        $admin->notice('✅ نمایندگی لغو شد');

        return $admin->open(AgentsScreen::ID);
    }

    /** @param array<string, mixed> $agent */
    private function view(array $agent): View
    {
        $id = (int) ($agent['id'] ?? 0);
        $bot = is_array($agent['bot'] ?? null) ? $agent['bot'] : null;
        $standing = is_array($agent['standing'] ?? null) ? $agent['standing'] : null;
        $counts = Json::part($agent, 'counts');
        $hidden = Json::part($agent, 'hidden');
        $balance = (string) ($agent['balance'] ?? '0');

        $view = View::make(Icons::AGENTS . ' نماینده ' . Words::user($agent['user'] ?? null), ...[...AgencyScreen::path(), Fmt::bare(AgentsScreen::TITLE)]);
        $concern = self::concern($agent);
        if ($concern !== null) {
            $view->line($concern);
        }
        $view->card([
            'مشتری' => Words::who($agent['user'] ?? null) . (($agent['status'] ?? null) === 'banned' ? ' · ⛔️ مسدود' : ''),
            'ربات' => $bot === null ? 'هنوز ندارد' : self::bot($bot),
            'حجم باقی‌مانده' => $bot === null ? null : Fmt::bytes((int) ($bot['traffic_balance'] ?? 0)),
            'پنهان' => (int) ($hidden['servers'] ?? 0) > 0 || (int) ($hidden['plans'] ?? 0) > 0 ? Fmt::number((int) ($hidden['servers'] ?? 0)) . ' سرور، ' . Fmt::number((int) ($hidden['plans'] ?? 0)) . ' پلن' : null,
            'سطح' => Fmt::e((string) ($agent['level']['name'] ?? '')) . ' · هر گیگابایت ' . Fmt::money((string) ($agent['level']['price_per_gb'] ?? '0')),
            'موجودی کیف پول' => (str_starts_with($balance, '-') ? '🔻 ' : '') . Fmt::money($balance),
            'اعتبار' => (float) ($agent['credit_limit'] ?? 0) > 0 ? Fmt::money((string) $agent['credit_limit']) : 'ندارد',
            'مشتری‌های ربات' => Fmt::number((int) ($counts['customers'] ?? 0)),
            'سرویس‌های فروخته‌شده' => Fmt::number((int) ($counts['sold'] ?? 0)) . ' · ' . Fmt::number((int) ($counts['active'] ?? 0)) . ' فعال',
        ]);
        if ($standing !== null) {
            $month = Json::part($standing, 'month');
            $view->section('📋 شرایط فروش')->card([
                'پایان نمایندگی' => is_string($standing['expires_at'] ?? null) ? Fmt::date($standing['expires_at']) . ' (' . self::left($standing['expires_at']) . ')' : AgencyEnd::NONE,
                'خرید حجم ' . (string) ($month['name'] ?? '') => ($standing['monthly_minimum'] ?? null) === null
                    ? Fmt::money((string) ($month['purchased'] ?? '0')) . ' · بدون کف ماهانه'
                    : self::month($standing) . (Json::on($month, 'judged') ? '' : ' (این ماه سنجیده نمی‌شود)'),
                'فروش' => is_array($standing['suspension'] ?? null) ? 'متوقف: ' . Fmt::e(self::SUSPENSIONS[(string) ($standing['suspension']['reason'] ?? '')] ?? '') : 'باز',
            ]);
        }

        $suspension = is_array($standing['suspension'] ?? null) ? (string) ($standing['suspension']['reason'] ?? '') : null;
        $username = is_string($bot['username'] ?? null) && $bot['username'] !== '' ? $bot['username'] : null;
        $view->row(Button::to(UiWords::edit('سطح، اعتبار و شرایط فروش'), AgentTermsScreen::ID, 'edit', $id)->primary());
        if ($bot !== null) {
            $view->row(Button::to('📦 حجم ربات', AgentTrafficScreen::ID, 'show', $id), Button::to('🙈 پنهان از فروشگاهش', AgentHiddenScreen::ID, 'show', $id))
                ->row(
                    Button::to(Icons::SHOP_SWITCH . ' باز کردن فروشگاه', 'shop', 'pick', (int) ($bot['id'] ?? 0)),
                    $username !== null ? Button::url('🤖 ربات او', 'https://t.me/' . $username) : null,
                );
        }
        $view->row(
            $suspension !== null && $suspension !== 'expired' ? Button::to('▶️ ادامه فروش', self::ID, 'res', $id)->success() : null,
            $standing !== null && $suspension !== 'manual' ? Button::to('⏸ توقف فروش', self::ID, 'sus', $id)->danger() : null,
        );

        return $view->row(Button::to('🚫 لغو نمایندگی', self::ID, 'rev', $id)->danger())
            ->back(AgentsScreen::ID)
            ->refresh(self::ID, 'show', $id);
    }

    /**
     * An agent's bot by its @username, with how it stands (agentBotStatus).
     *
     * @param array<string, mixed> $bot
     */
    private static function bot(array $bot): string
    {
        $name = is_string($bot['username'] ?? null) ? Fmt::ltr('@' . $bot['username']) : 'بدون توکن';
        $state = match (true) {
            ($bot['status'] ?? null) === 'disabled' => UiWords::INACTIVE,
            ($bot['connected'] ?? false) !== true => '🟡 منتظر توکن',
            is_string($bot['problem'] ?? null) => '⚠️ مشکل دارد: ' . Fmt::e($bot['problem']),
            default => UiWords::ACTIVE,
        };

        return $name . ' · ' . $state;
    }

    /** @param array<string, mixed> $standing */
    private static function short(array $standing): bool
    {
        $month = Json::part($standing, 'month');

        return ($standing['monthly_minimum'] ?? null) !== null && Json::on($month, 'judged') && (float) ($month['purchased'] ?? 0) < (float) $standing['monthly_minimum'];
    }

    /**
     * «۲۵۰٬۰۰۰ تومان از ۵۰۰٬۰۰۰ تومان» — this month's purchases against the minimum.
     *
     * @param array<string, mixed> $standing
     */
    private static function month(array $standing): string
    {
        return Fmt::money((string) ($standing['month']['purchased'] ?? '0')) . ' از ' . Fmt::money((string) ($standing['monthly_minimum'] ?? '0'));
    }

    /** Whole days until a moment, a part of one counting as one; 0 once it passed. */
    private static function daysUntil(Carbon $moment): int
    {
        return max(0, (int) ceil(($moment->getTimestamp() - Carbon::now()->getTimestamp()) / 86_400));
    }

    /** «۵ روز مانده», «۳ ساعت مانده», «تمام شده». */
    private static function left(string $iso): string
    {
        $moment = Fmt::moment($iso);
        $seconds = $moment === null ? 0 : $moment->getTimestamp() - Carbon::now()->getTimestamp();

        return match (true) {
            $seconds <= 0 => 'تمام شده',
            $seconds < 86_400 => Fmt::number((int) ceil($seconds / 3600)) . ' ساعت مانده',
            default => Fmt::number((int) ceil($seconds / 86_400)) . ' روز مانده',
        };
    }
}
