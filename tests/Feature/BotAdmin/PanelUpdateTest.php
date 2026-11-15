<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Application;
use App\Core\Database\Upgrades;
use App\Core\Installation;
use App\Core\Scheduling\Budget;
use App\Core\Support\Sleeper;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Updates\AppFolders;
use App\Modules\Updates\Controllers\UpdateController;
use App\Modules\Updates\Disk;
use App\Modules\Updates\GitHub;
use App\Modules\Updates\Maintenance;
use App\Modules\Updates\ReleaseKey;
use App\Modules\Updates\Releases;
use App\Modules\Updates\Updater;
use App\Modules\Updates\Workspace;
use App\Modules\Users\Models\User;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Log\LoggerInterface;
use Tests\BotTestCase;
use Tests\Support\FakeGitHub;
use Tests\Support\FakeRelease;

/**
 * «⬆️ به‌روزرسانی» in the bot (Telegram\Admin\Sections\Panel\UpdateScreen), played on a shop of the test's own as the
 * update's API tests play it (Feature\Updates\UpdateTestCase): the newest release and its notes, the update asked first,
 * then taken a step a tap — each through POST /system/update/step — until it is installed.
 */
#[RequiresPhpExtension('zip')]
final class PanelUpdateTest extends BotTestCase
{
    private const OWNER = 7001;

    private string $folder;

    private string $keyFile;

    private string $next;

    private FakeGitHub $gitHub;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scratchDir();
        $this->folder = "{$root}/shop";
        $this->keyFile = "{$root}/release-key.pub";
        [$major, $minor] = array_map(intval(...), explode('.', Application::VERSION));
        $this->next = "{$major}." . ($minor + 1) . '.0';
        $this->service(Installation::class)->markInstalled();
        foreach ([...FakeRelease::files(Application::VERSION), 'config.php' => "<?php return ['APP_NAME' => 'The shop'];"] as $path => $content) {
            Disk::folder(dirname("{$this->folder}/{$path}"));
            file_put_contents("{$this->folder}/{$path}", $content);
        }
        $this->gitHub = $this->outgoingFake(FakeGitHub::class);
        $workspace = new Workspace("{$this->folder}/storage/updates");
        $this->swap(Updater::class, new Updater(
            $this->service(Releases::class),
            $this->service(GitHub::class),
            new ReleaseKey($this->keyFile),
            $workspace,
            new AppFolders($this->folder, $workspace->previous()),
            new Maintenance("{$this->folder}/storage/updating.flag"),
            $this->service(Upgrades::class),
            $this->service(Installation::class),
            $this->service(Connection::class),
            $this->service(Budget::class),
            $this->service(Sleeper::class),
            $this->service(LoggerInterface::class),
            $this->folder,
            "{$this->folder}/storage/cache/schedule.json.lock",
            "{$this->folder}/storage/cache/routes",
        ), UpdateController::class);

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
    }

    public function testTheNewestReleaseIsInstalledAStepATapAfterAYes(): void
    {
        $release = new FakeRelease();
        file_put_contents($this->keyFile, $release->keyFile());
        $release->publish($this->gitHub, $this->next, null, 'Faster checkouts.');

        $this->tapAs(Nav::to('ppu'));
        self::assertStringContainsString($this->next, $this->said()[0], 'the newest release');
        self::assertStringContainsString('Faster checkouts.', $this->said()[0], 'and its notes');
        self::assertContains(Nav::to('ppu', 'start'), $this->callbacks(0));

        $this->tapAs(Nav::to('ppu', 'start'));
        self::assertStringContainsString('پشتیبان بگیرید', $this->said()[0], 'asked first, in the panel\'s words');
        self::assertContains(Nav::to('ppu', 'go'), $this->callbacks(0));

        $this->tapAs(Nav::to('ppu', 'go'));
        self::assertStringContainsString('دانلود و بررسی امضا', implode("\n", $this->said()), 'begun, its steps shown');
        for ($taps = 0; $taps < 20 && !str_contains(implode("\n", $this->said()), 'به‌روز شد'); $taps++) {
            self::assertContains(Nav::to('ppu', 'step'), array_merge(...array_map($this->callbacks(...), array_keys($this->calls()))), 'a step to take');
            $this->tapAs(Nav::to('ppu', 'step'));
        }
        self::assertStringContainsString('به‌روز شد', implode("\n", $this->said()), 'installed');
        self::assertSame($this->next, (string) file_get_contents("{$this->folder}/app/marker.txt"), 'the release in the app\'s place');
    }

    public function testTheUpdateIsTheOwnersAlone(): void
    {
        $bot = $this->agentBot();
        $this->sendTo($bot, $this->tap(Nav::to('ppu'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
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
