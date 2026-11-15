<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Drivers\Registry;
use App\Modules\Admin\Api\ImportsController;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Sources\Stager;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Models\User;
use GuzzleHttp\Psr7\Response;
use Tests\BotTestCase;
use Tests\Fakes\FakeMigrator;
use Tests\Fakes\FakeProvider;
use Tests\Feature\Imports\Api\ImportsApiTest;
use Tests\Unit\Imports\Sources\SqliteDialect;

/**
 * «🔀 انتقال از ربات دیگر» in the bot (Telegram\Admin\Sections\Panel\Imports), on the imports' fake bot as their API
 * tests run it: its backup sent here as a file and read a step a tap to its review, a panel's services sent to a server
 * and a switch flipped — each saved at once —, started on a yes and written a step a tap to its report, which is then
 * deleted on a yes. The owner's alone.
 */
final class PanelImportsTest extends BotTestCase
{
    private const OWNER = 7001;

    /** How many taps a loop makes at most before the test gives up on it. */
    private const MOST_TAPS = 20;

    private FakeMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrator = new FakeMigrator();
        $folder = $this->scratchDir();
        $container = $this->app()->container();
        $stager = $container->make(Stager::class, ['folder' => $folder, 'dialect' => new SqliteDialect()]);
        $this->swap(Stager::class, $stager);
        $this->swap(Imports::class, $container->make(Imports::class, ['migrators' => new Registry([$this->migrator]), 'stager' => $stager, 'folder' => $folder]), ImportsController::class);

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
    }

    public function testABackupSentHereIsReadReviewedAndWrittenATapAtATime(): void
    {
        $this->fakePanel();
        $berlin = $this->sellingServer('Berlin', ['base_url' => 'https://berlin.test']);
        FakeProvider::put($berlin, new ClientInfo(name: 'ali_1', enabled: true, subscriptionUrl: 'https://berlin.test/sub/ali_1'));
        $this->migrator->panels = [new ImportedPanel('de', 'DE', 'x-ui_single', 'https://berlin.test', FakeProvider::driver(), null, ['base_url' => 'https://berlin.test', 'api_token' => 't'], 1)];
        $this->migrator->customers = [new ImportedCustomer('1', 501, 'ali', balance: '25000')];
        $this->migrator->services = [new ImportedService('s1', '1', 'de', 'ali_1')];

        $this->tapAs(Nav::to('ppi'));
        self::assertStringContainsString('هنوز انتقالی انجام نشده است', $this->said()[0]);
        $this->tapAs(Nav::to('ppis'));
        self::assertStringContainsString(FakeMigrator::LABEL, $this->said()[0], 'the bots it reads');
        self::assertContains(Nav::to('ppid'), $this->callbacks(0), 'or its database');

        $this->tapAs(Nav::to('ppis', 'up'));
        $this->telegram()->reply(['file_path' => 'documents/backup.sql']);
        $this->telegram()->raw(new Response(200, [], ImportsApiTest::DUMP));
        $this->send($this->message(null, ['document' => ['file_id' => 'dump-1', 'file_name' => 'backup.sql', 'mime_type' => 'application/sql']], self::OWNER));
        self::assertStringContainsString('در حال خواندن', implode("\n", $this->said()), 'uploaded through POST /imports, its reading begun');

        $this->tapUntil('آنچه در ربات قبلی پیدا شد');
        $id = Import::query()->sole()->id;
        self::assertContains(Nav::to('ppip'), $this->window());

        $this->tapAs(Nav::to('ppip', 'p', 0));
        self::assertContains(Nav::to('ppip', 's', 0, $berlin->id), $this->callbacks(0), 'a server on the same connector');
        $this->tapAs(Nav::to('ppip', 'k', 0));
        self::assertSame('skip', $this->choices()['panels']['de']['action'] ?? null, 'saved at once');
        $this->tapAs(Nav::to('ppip', 's', 0, $berlin->id));
        self::assertSame(['action' => 'server', 'server_id' => $berlin->id], $this->choices()['panels']['de'] ?? null);

        $this->tapAs(Nav::to('ppic'));
        self::assertStringContainsString('شماره موبایل‌ها', $this->said()[0]);
        $this->tapAs(Nav::to('ppic', 't', 2));
        self::assertFalse($this->choices()['phones'] ?? true, 'a switch flipped, saved at once');

        $this->tapAs(Nav::to('ppi', 'start'));
        self::assertContains(Nav::to('ppi', 'go'), $this->callbacks(0), 'asked first');
        $this->tapAs(Nav::to('ppi', 'go'));
        $this->tapUntil('انتقال تمام شد');
        self::assertSame('25000.00', User::query()->where('telegram_id', 501)->sole()->balance(), 'written');
        self::assertStringContainsString('مشتری‌ها', implode("\n", $this->said()), 'what it did of each kind');

        $this->tapAs(Nav::to('ppio', 'del', $id));
        self::assertContains(Nav::to('ppio', 'gone', $id), $this->callbacks(0));
        $this->tapAs(Nav::to('ppio', 'gone', $id));
        self::assertSame(0, Import::query()->count(), 'its record deleted');
    }

    public function testImportsAreTheOwnersAlone(): void
    {
        $bot = $this->agentBot();
        $this->sendTo($bot, $this->tap(Nav::to('ppi'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
    }

    /** «▶️ ادامه» tapped until the window says `$words`. */
    private function tapUntil(string $words): void
    {
        for ($taps = 0; $taps < self::MOST_TAPS; $taps++) {
            if (str_contains(implode("\n", $this->said()), $words)) {
                return;
            }
            $this->tapAs(Nav::to('ppi', 'step'));
        }

        self::fail("The window never said «{$words}».");
    }

    /**
     * The buttons the window shows now, whichever call put them there.
     *
     * @return list<string>
     */
    private function window(): array
    {
        return array_merge(...array_map($this->callbacks(...), array_keys($this->calls())));
    }

    /** @return array<string, mixed> The import's choices as saved */
    private function choices(): array
    {
        $choices = Import::query()->sole()->choices;

        return is_array($choices) ? $choices : [];
    }

    private function tapAs(string $data): void
    {
        $this->send($this->tap($data, chat: self::OWNER));
    }

    /** The account 7001 linked as the owner. */
    private function owner(): User
    {
        $owner = User::query()->where('telegram_id', self::OWNER)->first() ?? $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);

        return $owner;
    }
}
