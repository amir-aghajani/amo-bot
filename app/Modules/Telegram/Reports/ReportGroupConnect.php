<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Bots\Services\Bots;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;

/**
 * `/start@<bot> reports_<code>` in a group — what the panel's connect link makes Telegram send, or the admin types. With
 * the code outstanding, in a supergroup with topics where the bot may manage them, the group becomes the report group
 * (ReportGroup::adopt()); otherwise the group (and the panel screen) is told why not. A /start for another bot, or
 * without a code, is ignored.
 */
final class ReportGroupConnect implements GroupHandler
{
    private const INVALID_CODE = 'این لینک اتصال معتبر نیست یا وقتش تمام شده است. از پنل مدیریت، «تنظیمات ربات ← گروه گزارش‌ها»، لینک تازه بسازید.';
    private const REFUSED = "⛔️ این گروه به ربات وصل نشد.\n%s\nبعد دوباره لینک اتصال را بزنید.";
    private const CONNECTED = "✅ این گروه، گروه گزارش‌های فروشگاه شد. گزارش‌ها از این به بعد در این تاپیک‌ها می‌آید:\n%s\n\nروشن یا خاموش کردن هر بخش: پنل مدیریت ← تنظیمات ربات ← گروه گزارش‌ها.";
    private const NOT_YET_MADE = "\n\n⚠️ تاپیک %s هنوز ساخته نشد؛ با اولین گزارشش ساخته می‌شود.";

    public function __construct(
        private readonly ReportGroup $group,
        private readonly ReportGroupState $state,
        private readonly GroupButtons $groupButtons,
        private readonly Bots $bots,
    ) {}

    public function handle(Update $update): void
    {
        $payload = $this->addressedToUs($update) ? $update->commandArgument() : null;
        if ($payload !== null && str_starts_with($payload, ReportGroup::PAYLOAD)) {
            $this->connect($update, substr($payload, strlen(ReportGroup::PAYLOAD)));
        }
    }

    /** The group offered with a code: connected when it qualifies, told why not otherwise. */
    private function connect(Update $update, string $code): void
    {
        $chat = (array) $update->chat();
        $title = trim((string) ($chat['title'] ?? ''));

        if (!$this->state->matches($code)) {
            $this->reply($update, self::INVALID_CODE);

            return;
        }

        $refusal = $this->group->refusal($chat);
        if ($refusal !== null) {
            $this->state->recordAttempt($title, $refusal);
            $this->reply($update, sprintf(self::REFUSED, $refusal->message()));

            return;
        }

        // Of two messages carrying the code (two taps, two webhook requests at once), one connects.
        if (!$this->state->claim($code)) {
            $this->reply($update, self::INVALID_CODE);

            return;
        }

        $missing = $this->group->adopt((int) $chat['id'], $title);
        $text = sprintf(self::CONNECTED, implode("\n", array_map(static fn(Topic $topic): string => '• ' . $topic->title(), ReportSettings::topics())));
        if ($missing !== []) {
            $text .= sprintf(self::NOT_YET_MADE, implode('، ', array_map(static fn(Topic $topic): string => '«' . $topic->title() . '»', $missing)));
        }
        $this->reply($update, $text);
    }

    /** `/start` with no "@bot", or "@" this bot: in a group with several bots, another's command is not ours. */
    private function addressedToUs(Update $update): bool
    {
        $to = $update->commandTarget();
        $bot = $this->bots->username();

        return $to === null || $bot === '' || strcasecmp($to, $bot) === 0;
    }

    /** Answer under the message that carried the code, in its topic. */
    private function reply(Update $update, string $text): void
    {
        $this->groupButtons->sayUnder((int) $update->chatId(), $update->message() ?? [], $text);
    }
}
