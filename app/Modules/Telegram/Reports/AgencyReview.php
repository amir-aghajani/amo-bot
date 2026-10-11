<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencyLevels;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Psr\Log\LoggerInterface;

/**
 * «✅ تایید» (then a level) and «❌ رد» under an agency request in the main bot's report group, for its bot admins only.
 * A press reaching an agent's bot decides nothing: callback data is the client's to write, and an agent is their own
 * bot's admin. Decisions go through AgencyActions, as the agents page's do; an admin's own request is another admin's.
 */
final class AgencyReview implements GroupHandler
{
    /** What the buttons' callback data starts with: `ag:<action>:<request id>[:<level id>]`. */
    public const PREFIX = 'ag:';

    private const APPROVE = 'ok';
    private const LEVEL = 'lv';
    private const REJECT = 'no';
    private const BACK = 'bk';

    private const NOT_ADMIN = 'فقط مدیرهای ربات می‌توانند درخواست نمایندگی را تایید یا رد کنند؛ نقش «مدیر ربات» در صفحه کاربران پنل داده می‌شود.';
    private const NOT_FOUND = 'این درخواست پیدا نشد.';
    private const NO_LEVELS = 'هنوز سطح نمایندگی تعریف نشده است؛ در پنل، صفحه «نمایندگان»، بخش «سطح‌ها» یکی بسازید.';
    private const LEVEL_GONE = 'این سطح دیگر وجود ندارد؛ دوباره «تایید» را بزنید.';
    private const APPROVED = '✅ نماینده شد، با سطح «%s».';
    private const REJECTED = '❌ درخواست رد شد.';

    public function __construct(
        private readonly GroupButtons $groupButtons,
        private readonly AgencyActions $agency,
        private readonly AgencyLevels $levels,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The two buttons under a waiting request, «تایید» on a Persian reader's right.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function buttons(int $requestId): array
    {
        return InlineKeyboard::make()
            ->row(InlineKeyboard::callback('❌ رد', CallbackData::build(self::PREFIX, self::REJECT, $requestId), 'danger'), InlineKeyboard::callback('✅ تایید', CallbackData::build(self::PREFIX, self::APPROVE, $requestId), 'success'))
            ->build()['inline_keyboard'];
    }

    /** A press on one of the buttons. */
    public function handle(Update $update): void
    {
        $args = $update->callbackArgs(self::PREFIX) ?? [];
        $action = $args[0] ?? '';
        $shaped = in_array($action, [self::APPROVE, self::REJECT, self::BACK], true) ? count($args) === 2 : ($action === self::LEVEL && count($args) === 3);
        if (!$shaped || !CurrentBot::isMain()) {
            $this->groupButtons->answer($update);

            return;
        }

        $admin = $this->groupButtons->presser($update, self::NOT_ADMIN);
        if ($admin === null) {
            return;
        }

        $request = AgencyRequest::query()->with(['user', 'level'])->find((int) $args[1]);
        if ($request === null || $request->status !== AgencyRequestStatus::Pending) {
            $this->groupButtons->dismiss($update, $request === null ? self::NOT_FOUND : AgencyActions::decided($request));

            return;
        }

        match ($action) {
            self::LEVEL => $this->approve($update, $request, (int) $args[2], $admin),
            self::REJECT => $this->reject($update, $request, $admin),
            self::APPROVE => $this->pickLevel($update, $request),
            default => $this->showButtons($update, self::buttons($request->id)),
        };
    }

    /** «تایید» opened: a button per level, in the admin's order, and «بازگشت». */
    private function pickLevel(Update $update, AgencyRequest $request): void
    {
        $levels = $this->levels->ordered();
        if ($levels->isEmpty()) {
            $this->groupButtons->answer($update, self::NO_LEVELS, alert: true);

            return;
        }

        $buttons = $levels->map(static fn(AgencyLevel $level): array => InlineKeyboard::callback(
            sprintf('🏅 %s · %s', $level->name, Money::format($level->price_per_gb)),
            CallbackData::build(self::PREFIX, self::LEVEL, $request->id, $level->id),
            'success',
        ))->values()->all();
        $back = InlineKeyboard::callback('⬅️ بازگشت', CallbackData::build(self::PREFIX, self::BACK, $request->id));

        $this->showButtons($update, InlineKeyboard::make()->grid($buttons, 2)->row($back)->build()['inline_keyboard']);
    }

    /** @param list<list<array<string, mixed>>> $rows */
    private function showButtons(Update $update, array $rows): void
    {
        $this->groupButtons->buttons($update, $rows);
        $this->groupButtons->answer($update);
    }

    private function approve(Update $update, AgencyRequest $request, int $levelId, User $admin): void
    {
        $level = AgencyLevel::query()->find($levelId);
        if ($level === null) {
            $this->groupButtons->buttons($update, self::buttons($request->id));
            $this->groupButtons->answer($update, self::LEVEL_GONE, alert: true);

            return;
        }

        try {
            $this->agency->approve($request, Actor::groupAdmin($admin), $level, null);
        } catch (ActorRefusedException $e) {
            // The admin's own request: the buttons stay for another admin.
            $this->groupButtons->answer($update, $e->getMessage(), alert: true);

            return;
        } catch (ValidationException $e) {
            $this->groupButtons->dismiss($update, $e->getMessage());

            return;
        }

        $this->groupButtons->buttons($update, null);
        $this->groupButtons->answer($update, sprintf(self::APPROVED, $level->name));
        $this->logger->info('Agency request {id} approved from the report group by {reviewer}', ['id' => $request->id, 'reviewer' => $request->reviewer]);
    }

    private function reject(Update $update, AgencyRequest $request, User $admin): void
    {
        try {
            $this->agency->reject($request, Actor::groupAdmin($admin), null);
        } catch (ValidationException $e) {
            $this->groupButtons->dismiss($update, $e->getMessage());

            return;
        }

        $this->groupButtons->buttons($update, null);
        $this->groupButtons->answer($update, self::REJECTED);
        $this->logger->info('Agency request {id} rejected from the report group by {reviewer}', ['id' => $request->id, 'reviewer' => $request->reviewer]);
    }
}
