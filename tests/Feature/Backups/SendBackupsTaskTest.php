<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Modules\Backups\Backups;
use App\Modules\Backups\BackupScope;
use App\Modules\Backups\Tasks\SendBackupsTask;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Reports\Topic;
use Tests\DatabaseTestCase;
use Tests\Support\FakeTelegram;

/**
 * The backup sent to the report group's «پشتیبان‌گیری» topic: the main shop's group gets the whole installation's, an
 * agent's group its own shop's alone, from its own bot; nothing goes without a group or with the topic switched off; a
 * file Telegram refused is said in the panel, and no made file is left behind. A run out of time begins none, and a
 * shop that missed a run goes before one whose backup went; every run prunes the backups' folder.
 */
final class SendBackupsTaskTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->customer();
    }

    public function testTheMainShopsGroupGetsTheWholeInstallationsBackupInItsTopic(): void
    {
        $threads = $this->reportGroup();

        $this->service(SendBackupsTask::class)->run();

        self::assertSame(['sendDocument'], $this->telegram()->calls());
        $params = $this->telegram()->params(0);
        self::assertSame([(string) self::REPORT_GROUP, (string) $threads[Topic::Backups->value]], [$params['chat_id'], $params['message_thread_id']]);
        self::assertStringContainsString('پشتیبان کل نصب', $params['caption']);
        self::assertSame('installation', $this->header($this->telegram()->files(0)['document'])['scope']);
        self::assertStringEndsWith('.json.gz', $params['document']);

        $auto = $this->service(Backups::class)->status(BackupScope::installation())['auto'];
        self::assertNotNull($auto['last_sent_at']);
        self::assertNull($auto['last_problem']);
        self::assertSame([], glob(sys_get_temp_dir() . '/amobot-tests-' . getmypid() . '-*/backups/made/*') ?: [], 'the made file is gone');
    }

    public function testAnAgentsGroupGetsTheirShopsAloneFromTheirBot(): void
    {
        $shop = $this->agentBot();
        $threads = CurrentBot::run($shop, function (): array {
            $this->customer(['telegram_id' => 601]);

            return $this->reportGroup(-1009876543210);
        });

        CurrentBot::run($shop, fn() => $this->service(SendBackupsTask::class)->run());

        self::assertSame(['sendDocument'], $this->telegram()->calls());
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0));
        $params = $this->telegram()->params(0);
        self::assertSame(['-1009876543210', (string) $threads[Topic::Backups->value]], [$params['chat_id'], $params['message_thread_id']]);
        self::assertStringContainsString('پشتیبان فروشگاه', $params['caption']);
        $header = $this->header($this->telegram()->files(0)['document']);
        self::assertSame(['shop', $shop->id], [$header['scope'], $header['shop']['id']]);
    }

    public function testNothingGoesWithoutAGroupOrWithTheTopicOff(): void
    {
        $this->service(SendBackupsTask::class)->run();
        self::assertSame([], $this->telegram()->calls(), 'no group');

        $this->reportGroup();
        $this->service(Backups::class)->sendToGroup(false);
        $this->service(SendBackupsTask::class)->run();
        self::assertSame([], $this->telegram()->calls(), 'the topic is off');
        self::assertNull($this->service(Backups::class)->status(BackupScope::installation())['auto']['last_sent_at']);
    }

    public function testARefusedFileIsSaidInThePanel(): void
    {
        $this->reportGroup();
        $this->telegram()->on('sendDocument', static fn(): mixed => FakeTelegram::error(400, 'Bad Request: file is too big'));

        $this->service(SendBackupsTask::class)->run();

        $auto = $this->service(Backups::class)->status(BackupScope::installation())['auto'];
        self::assertNotNull($auto['last_sent_at']);
        self::assertStringContainsString('file is too big', (string) $auto['last_problem']);

        $this->telegram()->on('sendDocument', static fn(): mixed => ['message_id' => 900]);
        $this->service(SendBackupsTask::class)->run();
        self::assertNull($this->service(Backups::class)->status(BackupScope::installation())['auto']['last_problem'], 'the next one went');
    }

    /** A run whose time is over begins no backup: the shop waits for its turn, and its backup goes at the next run. */
    public function testARunOutOfTimeBeginsNoBackupAndTheShopGoesAtTheNext(): void
    {
        $this->reportGroup();

        $this->withTheTurnOver(fn() => $this->service(SendBackupsTask::class)->run());
        self::assertSame([], $this->telegram()->calls(), 'the turn is over');
        self::assertSame([Bot::MAIN => 0], $this->begun(), 'it waits for its turn, as one never begun');

        $this->service(SendBackupsTask::class)->run();
        self::assertSame(['sendDocument'], $this->telegram()->calls());
        self::assertEqualsWithDelta(time(), $this->begun()[Bot::MAIN], 5);
    }

    /**
     * A shop whose backup went at the last run gives its turn while another's missed one — a run short of time, or
     * stopped half-way, left it out —: the ones waiting longest go first, so no shop misses out run after run.
     */
    public function testAShopWhoseBackupWentGivesItsTurnToOneThatMissedARun(): void
    {
        $this->reportGroup();
        $shop = $this->agentBot();
        CurrentBot::run($shop, fn() => $this->reportGroup(-1009876543210));
        $this->service(Settings::class)->set(SendBackupsTask::BEGUN, [Bot::MAIN => time() - 86400, $shop->id => time() - 2 * 86400], Bot::MAIN);

        $this->service(SendBackupsTask::class)->run();
        self::assertSame([], $this->telegram()->calls(), 'the main shop went a day ago; the agent\'s missed a run');

        CurrentBot::run($shop, fn() => $this->service(SendBackupsTask::class)->run());
        self::assertSame(['sendDocument'], $this->telegram()->calls());
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0));

        $this->service(SendBackupsTask::class)->run();
        self::assertSame(['sendDocument', 'sendDocument'], $this->telegram()->calls(), 'no one waits now: the main shop\'s goes');
    }

    /** Every run prunes the backups' folder — every shop's made files past their time, a write's half-made part —, with no group. */
    public function testEveryRunPrunesTheBackupsFolderWithOrWithoutAGroup(): void
    {
        $folder = (string) $this->app()->container()->get('backups.path');
        $old = ["{$folder}/made/amobot-backup-installation-old.json.gz", "{$folder}/kept/shop-7/amobot-backup-x-before-restore.json.gz.part"];
        $fresh = "{$folder}/made/amobot-backup-installation-new.json.gz";
        foreach ([...$old, $fresh] as $file) {
            @mkdir(dirname($file), 0o777, true);
            file_put_contents($file, 'x');
        }
        foreach ($old as $file) {
            touch($file, time() - 2 * 3600);
        }

        $this->service(SendBackupsTask::class)->run();

        self::assertSame([], $this->telegram()->calls(), 'no group: nothing sent');
        self::assertSame([false, false, true], array_map('is_file', [...$old, $fresh]));
    }

    /** @return array<int, int> When each shop's backup was last begun (SendBackupsTask::BEGUN) */
    private function begun(): array
    {
        return (array) $this->service(Settings::class)->get(SendBackupsTask::BEGUN, [], Bot::MAIN);
    }

    /** @return array<string, mixed> The header line of a backup file */
    private function header(string $file): array
    {
        $lines = explode("\n", (string) gzdecode($file));

        return json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    }
}
