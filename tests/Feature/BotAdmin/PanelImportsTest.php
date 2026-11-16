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
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words;
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
        $berlin = $this->reviewed();
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

        // A switch that goes with another: held off while that one is off, given back as it was once it is on again.
        $plans = $this->switchOf('پلن‌ها');
        $plansOn = $this->switchOf('پلن‌ها روشن بیایند');
        $this->tapAs(Nav::to('ppic', 't', $plansOn, 1));
        self::assertTrue($this->choices()['plans_on'] ?? false);
        $this->tapAs(Nav::to('ppic', 't', $plans, 0));
        self::assertSame([false, false], [$this->choices()['plans'] ?? null, $this->choices()['plans_on'] ?? null], 'no plans, none to come on');
        self::assertContains('🔒 پلن‌ها روشن بیایند', $this->buttonLabels($this->windowCall()), 'held while «پلن‌ها» is off');
        $this->tapAs(Nav::to('ppic', 't', $plans, 1));
        self::assertSame([true, true], [$this->choices()['plans'] ?? null, $this->choices()['plans_on'] ?? null], 'given back as it was');

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

    public function testAPanelsServersArePagedPastOneKeyboard(): void
    {
        $berlin = $this->reviewed();
        $last = $berlin;
        for ($n = 1; $n < 100; $n++) {
            $last = $this->fakeServer("Node {$n}");
        }

        $this->tapAs(Nav::to('ppip', 'p', 0));
        self::assertLessThanOrEqual(100, count(array_merge(...$this->inlineKeyboard(0))), 'within the keyboard\'s 100 buttons');
        self::assertContains(Nav::to('ppip', 's', 0, $berlin->id), $this->callbacks(0), 'the first page, from its button of before');
        self::assertContains(Nav::to('ppip', 'p', 0, 2), $this->callbacks(0), 'the next page');
        self::assertContains(Nav::to('ppip', 'k', 0), $this->callbacks(0), 'left behind, on every page');

        $this->tapAs(Nav::to('ppip', 'p', 0, 3));
        self::assertContains(Nav::to('ppip', 's', 0, $last->id), $this->callbacks(0), 'the last servers on the last page');
        self::assertNotContains(Nav::to('ppip', 's', 0, $berlin->id), $this->callbacks(0));
        self::assertContains(Nav::to('ppip', 'k', 0), $this->callbacks(0));
        $this->tapAs(Nav::to('ppip', 's', 0, $last->id));
        self::assertSame(['action' => 'server', 'server_id' => $last->id], $this->choices()['panels']['de'] ?? null, 'picked from a later page');

        $this->tapAs(Nav::to('ppip', 'p', 0));
        self::assertContains(Nav::to('ppip', 's', 0, $last->id), $this->callbacks(0), 'opened again at the server picked');
        self::assertContains(Words::PICKED . 'سرور «Node 99»', $this->buttonLabels(0));
    }

    public function testImportsAreTheOwnersAlone(): void
    {
        $bot = $this->agentBot();
        $this->sendTo($bot, $this->tap(Nav::to('ppi'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
    }

    /**
     * The other bot's backup — one panel, «DE» on the fake connector, its customer and service — sent here and read to
     * its review; the shop's server on the same connector and address returned.
     */
    private function reviewed(): Server
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

        return $berlin;
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

    /** The place of the switch labelled `$label` among «چه چیزهایی منتقل شود»'s, as its button carries it. */
    private function switchOf(string $label): int
    {
        $this->tapAs(Nav::to('ppic'));
        foreach (array_merge(...$this->inlineKeyboard($this->windowCall())) as $button) {
            if (preg_replace('/^\S+ /u', '', (string) ($button['text'] ?? '')) === $label && preg_match('/^a:ppic:t:(\d+):/', (string) ($button['callback_data'] ?? ''), $match) === 1) {
                return (int) $match[1];
            }
        }

        self::fail("No switch «{$label}».");
    }

    /** @return array<string, mixed> The import's choices as saved */
    private function choices(): array
    {
        $choices = Import::query()->sole()->choices;

        return is_array($choices) ? $choices : [];
    }
}
