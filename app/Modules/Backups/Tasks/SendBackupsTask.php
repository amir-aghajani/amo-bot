<?php

declare(strict_types=1);

namespace App\Modules\Backups\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Scheduler;
use App\Core\Scheduling\Shops;
use App\Core\Scheduling\Task;
use App\Modules\Backups\Backups;
use App\Modules\Backups\BackupScope;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Reports\ReportGroupState;
use App\Modules\Telegram\Reports\ReportSettings;
use App\Modules\Telegram\Reports\ReportTopics;
use App\Modules\Telegram\Reports\Topic;
use App\Support\Persian;
use GuzzleHttp\Psr7\LazyOpenStream;
use Psr\Log\LoggerInterface;

/**
 * The shop's backup sent to its report group's «پشتیبان‌گیری» topic (once a day by default, the owner's «زمان‌بندی کارها»),
 * in every shop whose group is connected and whose topic is on: the main shop's group gets the whole installation's —
 * every shop, the servers, the agents' terms —, an agent's group its own shop's alone. The file goes up from the disk as
 * it is read, never whole in memory; one Telegram would not take (larger than a bot may send) is said in a line
 * instead, to download from the panel. When it went, or what kept it, is kept for the panel (Backups::status()).
 *
 * A run short of time starts no backup once its turn is over (Budget); a shop left out so — or one whose turn never
 * came, the run having stopped half-way — goes first at the next run: a shop whose backup went at the last one gives
 * its turn while another missed one (BEGUN), so no shop misses out run after run. Every run prunes the backups'
 * folder too (Backups::prune()), whether or not any shop sends.
 */
final class SendBackupsTask implements Task
{
    /** The largest file a bot may send. */
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** The main shop's setting of when each shop's backup was last begun, by shop id (a unix time; 0: never). */
    public const BEGUN = 'backups.begun';

    public function __construct(
        private readonly Backups $backups,
        private readonly ReportSettings $reports,
        private readonly ReportGroupState $group,
        private readonly ReportTopics $topics,
        private readonly BotApi $api,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly Budget $budget,
        private readonly Scheduler $scheduler,
        private readonly Shops $shops,
    ) {}

    public function run(): void
    {
        $this->backups->prune();
        $shop = CurrentBot::id();
        $chatId = $this->group->sendingTo();
        if ($chatId === null || !$this->reports->enabled(Topic::Backups)) {
            $this->begin($shop, null);

            return;
        }
        $begun = $this->begun();
        if ($this->budget->seconds() <= 0.0 || $this->givesWay($shop, $begun)) {
            $this->begin($shop, $begun[$shop] ?? 0);

            return;
        }
        $this->begin($shop, time());

        $scope = CurrentBot::isMain() ? BackupScope::installation() : BackupScope::shop($shop);
        $made = $this->backups->make($scope);
        $file = null;
        try {
            $thread = $this->topics->threadFor(Topic::Backups);
            if ($thread === null) {
                return;
            }
            $size = (int) filesize($made['path']);
            $words = self::words($scope, $size, array_sum($made['rows']));
            if ($size > self::MAX_BYTES) {
                $this->api->sendMessage($chatId, $words . "\n\n⚠️ فایل از حدی که تلگرام از ربات می‌گیرد بزرگ‌تر است؛ آن را از پنل، «تنظیمات پنل › پشتیبان‌گیری»، بگیرید.", ['message_thread_id' => $thread]);
            } else {
                $file = new LazyOpenStream($made['path'], 'rb');
                $this->api->sendDocument($chatId, $file, $made['name'], ['message_thread_id' => $thread, 'caption' => $words]);
            }
            $this->settings->set(Backups::LAST_SENT, ['at' => now()->toIso8601String(), 'problem' => null]);
        } catch (TelegramApiException $e) {
            $this->settings->set(Backups::LAST_SENT, ['at' => now()->toIso8601String(), 'problem' => $e->getMessage()]);
            $this->logger->warning('The backup could not be sent to the report group: {message}', ['message' => $e->getMessage()]);
        } finally {
            $file?->close();
            @unlink($made['path']);
        }
    }

    /**
     * Whether the shop gives its turn to another: its own backup was begun at the last run, another's has missed a run
     * since its last — so the ones waiting longest go first while a run cannot take them all.
     *
     * @param array<int, int> $begun
     */
    private function givesWay(int $shop, array $begun): bool
    {
        // A run later than one interval and a half after a shop's last: it missed the run between.
        $missed = fn(int $at): bool => time() - $at >= intdiv($this->scheduler->interval(self::class) * 3, 2);
        if ($missed($begun[$shop] ?? 0)) {
            return false;
        }
        foreach ($begun as $other => $at) {
            if ($other !== $shop && $missed($at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * When each shop's backup was last begun, of the shops the scheduler runs now (BEGUN).
     *
     * @return array<int, int>
     */
    private function begun(): array
    {
        $kept = $this->settings->get(self::BEGUN, [], Bot::MAIN);
        $shops = array_flip($this->shops->ids());
        $begun = [];
        foreach (is_array($kept) ? $kept : [] as $shop => $at) {
            if (isset($shops[(int) $shop]) && is_int($at)) {
                $begun[(int) $shop] = $at;
            }
        }

        return $begun;
    }

    /** Keep when the shop's backup was last begun; null: the shop sends none, and waits for no turn. */
    private function begin(int $shop, ?int $at): void
    {
        $begun = $this->begun();
        if (($begun[$shop] ?? null) === $at) {
            return;
        }
        if ($at === null) {
            unset($begun[$shop]);
        } else {
            $begun[$shop] = $at;
        }
        // By id, from the main shop's (1): never a list, which JSON would keep without its ids.
        $this->settings->set(self::BEGUN, $begun, Bot::MAIN);
    }

    /** The file's caption: what it is of, when, its size and rows, and where it is restored. */
    private static function words(BackupScope $scope, int $size, int $rows): string
    {
        $of = $scope->isShop() ? 'پشتیبان فروشگاه' : 'پشتیبان کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها';

        return '🗄 <b>' . $of . '</b>' . "\n"
            . '📅 ' . Persian::date(now(), withTime: true) . "\n"
            . '📦 ' . Messages::bytes($size) . ' · ' . Persian::number($rows) . ' ردیف' . "\n\n"
            . 'برای بازگردانی، این فایل را در پنل، «تنظیمات پنل › پشتیبان‌گیری»، بارگذاری کنید.';
    }
}
