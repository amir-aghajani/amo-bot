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
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Psr\Log\LoggerInterface;

/**
 * The buttons under a request to become an agent in the report group — «✅ تایید» and «❌ رد» — for the main bot's admins
 * only (GroupButtons::admin()): the requests are the main bot's, and only its group carries these buttons. A press
 * that reaches an agent's bot — callback data a modified client wrote under one of that bot's messages — decides
 * nothing, or an agent, the admin of their own bot, could approve anyone on any level. «تایید» asks which level, one
 * button per level (the program's default credit comes with it; the agents page sets another); «رد» rejects without a
 * note (the agents page takes one). They decide through AgencyActions as the page does — a request decided there, or
 * here a moment earlier, is not decided again — which tells the customer. A decided request loses its buttons: here at
 * once, and by its verdict's report. An admin's own request keeps them, for another admin (ActorRefusedException).
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
        private readonly BotApi $api,
        private readonly GroupButtons $groupButtons,
        private readonly AgencyActions $agency,
        private readonly AgencyLevels $levels,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The two buttons under a request that waits — «تایید» on a Persian reader's right.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function buttons(int $requestId): array
    {
        return InlineKeyboard::make()
            ->row(InlineKeyboard::callback('❌ رد', CallbackData::build(self::PREFIX, self::REJECT, $requestId), 'danger'), InlineKeyboard::callback('✅ تایید', CallbackData::build(self::PREFIX, self::APPROVE, $requestId), 'success'))
            ->build()['inline_keyboard'];
    }

    /** A press on one of the buttons, from a group. */
    public function handle(Update $update): void
    {
        $callbackId = (string) $update->callbackId();
        $args = $update->callbackArgs(self::PREFIX) ?? [];
        $action = $args[0] ?? '';
        $shaped = in_array($action, [self::APPROVE, self::REJECT, self::BACK], true) ? count($args) === 2 : ($action === self::LEVEL && count($args) === 3);
        if (!$shaped || !CurrentBot::isMain()) {
            $this->api->answerCallbackQuery($callbackId);

            return;
        }

        $admin = GroupButtons::admin($update->from());
        if ($admin === null) {
            $this->api->answerCallbackQuery($callbackId, self::NOT_ADMIN, alert: true);

            return;
        }

        $chatId = (int) $update->chatId();
        $messageId = (int) $update->messageId();
        $request = AgencyRequest::query()->with(['user', 'level'])->find((int) $args[1]);
        if ($request === null || $request->status !== AgencyRequestStatus::Pending) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $request === null ? self::NOT_FOUND : AgencyActions::decided($request), alert: true);

            return;
        }

        match ($action) {
            self::LEVEL => $this->approve($callbackId, $chatId, $messageId, $request, (int) $args[2], $admin),
            self::REJECT => $this->reject($callbackId, $chatId, $messageId, $request, $admin),
            self::APPROVE => $this->pickLevel($callbackId, $chatId, $messageId, $request),
            default => $this->showButtons($callbackId, $chatId, $messageId, self::buttons($request->id)),
        };
    }

    /** «تایید» opened: one button per level, in the admin's order, and back. */
    private function pickLevel(string $callbackId, int $chatId, int $messageId, AgencyRequest $request): void
    {
        $levels = $this->levels->ordered();
        if ($levels->isEmpty()) {
            $this->api->answerCallbackQuery($callbackId, self::NO_LEVELS, alert: true);

            return;
        }

        $buttons = $levels->map(static fn(AgencyLevel $level): array => InlineKeyboard::callback(
            sprintf('🏅 %s · %s', $level->name, Money::format($level->price_per_gb)),
            CallbackData::build(self::PREFIX, self::LEVEL, $request->id, $level->id),
            'success',
        ))->values()->all();
        $back = InlineKeyboard::callback('⬅️ بازگشت', CallbackData::build(self::PREFIX, self::BACK, $request->id));

        $this->showButtons($callbackId, $chatId, $messageId, InlineKeyboard::make()->grid($buttons, 2)->row($back)->build()['inline_keyboard']);
    }

    /** @param list<list<array<string, mixed>>> $rows */
    private function showButtons(string $callbackId, int $chatId, int $messageId, array $rows): void
    {
        $this->groupButtons->set($chatId, $messageId, $rows);
        $this->api->answerCallbackQuery($callbackId);
    }

    private function approve(string $callbackId, int $chatId, int $messageId, AgencyRequest $request, int $levelId, User $admin): void
    {
        $level = AgencyLevel::query()->find($levelId);
        if ($level === null) {
            $this->groupButtons->set($chatId, $messageId, self::buttons($request->id));
            $this->api->answerCallbackQuery($callbackId, self::LEVEL_GONE, alert: true);

            return;
        }

        try {
            $this->agency->approve($request, Actor::groupAdmin($admin), $level, null);
        } catch (ActorRefusedException $e) {
            // A request of the admin's own: its buttons stay, for another admin.
            $this->api->answerCallbackQuery($callbackId, $e->getMessage(), alert: true);

            return;
        } catch (ValidationException $e) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $e->getMessage(), alert: true);

            return;
        }

        $this->groupButtons->set($chatId, $messageId, null);
        $this->api->answerCallbackQuery($callbackId, sprintf(self::APPROVED, $level->name));
        $this->logger->info('Agency request {id} approved from the report group by {reviewer}', ['id' => $request->id, 'reviewer' => $request->reviewer]);
    }

    private function reject(string $callbackId, int $chatId, int $messageId, AgencyRequest $request, User $admin): void
    {
        try {
            $this->agency->reject($request, Actor::groupAdmin($admin), null);
        } catch (ValidationException $e) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $e->getMessage(), alert: true);

            return;
        }

        $this->groupButtons->set($chatId, $messageId, null);
        $this->api->answerCallbackQuery($callbackId, self::REJECTED);
        $this->logger->info('Agency request {id} rejected from the report group by {reviewer}', ['id' => $request->id, 'reviewer' => $request->reviewer]);
    }
}
