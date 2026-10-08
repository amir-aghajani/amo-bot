<?php

declare(strict_types=1);

namespace Tests\Feature\Updates;

use App\Core\Database\Upgrades;
use App\Core\Installation;
use App\Core\Scheduling\Budget;
use App\Core\Support\Sleeper;
use App\Modules\Updates\AppFolders;
use App\Modules\Updates\Controllers\UpdateController;
use App\Modules\Updates\Disk;
use App\Modules\Updates\GitHub;
use App\Modules\Updates\Maintenance;
use App\Modules\Updates\ReleaseKey;
use App\Modules\Updates\Releases;
use App\Modules\Updates\Updater;
use App\Modules\Updates\Workspace;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Illuminate\Database\Connection;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeGitHub;
use Tests\Support\FakeRelease;

/**
 * The owner's update, played on a shop of the test's own — never this repository: an installed 0.1.0 in a scratch folder
 * (its config.php and storage/ the shop's own), the updater's folder and its flag in that storage/, a release key made
 * for the test (release(): FakeRelease), and GitHub (FakeGitHub) the transport of the app's outgoing client. The update
 * screen's API runs on an Updater made over them; the owner is signed in.
 */
abstract class UpdateTestCase extends HttpTestCase
{
    /** The installed shop's folder. */
    protected string $folder;

    /** Its storage/updates — the updater's own. */
    protected string $work;

    /** The flag that holds the shop while an update installs. */
    protected string $flag;

    /** The router's table. */
    protected string $routes;

    /** The scheduler's lock. */
    protected string $schedulerLock;

    protected FakeGitHub $gitHub;

    private ?FakeRelease $release = null;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scratchDir();
        $this->folder = "{$root}/shop";
        $this->work = "{$this->folder}/storage/updates";
        $this->flag = "{$this->folder}/storage/updating.flag";
        $this->routes = "{$this->folder}/storage/cache/routes";
        $this->schedulerLock = "{$this->folder}/storage/cache/schedule.json.lock";
        $this->keyFile = "{$root}/release-key.pub";
        $this->place($this->folder, [
            ...FakeRelease::files('0.1.0'),
            'config.php' => "<?php return ['APP_NAME' => 'The shop'];",
            'storage/uploads/receipts/7.jpg' => 'a customer\'s receipt',
            'storage/cache/routes/routes-old.php' => '<?php return [];',
        ]);

        $this->gitHub = new FakeGitHub();
        $this->transport()->setHandler($this->gitHub);
        $this->swap(Updater::class, $this->updater(), UpdateController::class);
        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->transport()->setHandler(static fn(RequestInterface $request): PromiseInterface => new RejectedPromise(new ConnectException('No network in the tests.', $request)));

        parent::tearDown();
    }

    /** The key the test's releases are signed with, the shop's release key from the first time it is asked for. */
    protected function release(): FakeRelease
    {
        if ($this->release === null) {
            $this->release = new FakeRelease();
            file_put_contents($this->keyFile, $this->release->keyFile());
        }

        return $this->release;
    }

    /** What the shop's file `$path` holds. */
    protected function shopFile(string $path): string
    {
        return (string) file_get_contents("{$this->folder}/{$path}");
    }

    /**
     * Files written under `$folder`, by their path in it.
     *
     * @param array<string, string> $files
     */
    protected function place(string $folder, array $files): void
    {
        foreach ($files as $path => $content) {
            Disk::folder(dirname("{$folder}/{$path}"));
            file_put_contents("{$folder}/{$path}", $content);
        }
    }

    /**
     * The update screen once its run is at `$until`: a step taken after another, each answered with a 200.
     *
     * @return array<string, mixed>
     */
    protected function stepUntil(string $until): array
    {
        for ($steps = 0; $steps < 50; $steps++) {
            $response = $this->postJson('/api/admin/system/update/step');
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $update = $this->screen($response);
            if (($update['run']['step'] ?? null) === $until) {
                return $update;
            }
        }

        self::fail("The update never reached {$until}.");
    }

    /**
     * The update screen an answer carries.
     *
     * @return array<string, mixed>
     */
    protected function screen(ResponseInterface $response): array
    {
        $update = $this->decode($response)['update'] ?? null;

        return is_array($update) ? $update : [];
    }

    /** The Updater of the test's shop: its folders, its key — the app's own services for the rest. */
    private function updater(): Updater
    {
        $workspace = new Workspace($this->work);

        return new Updater(
            $this->service(Releases::class),
            $this->service(GitHub::class),
            new ReleaseKey($this->keyFile),
            $workspace,
            new AppFolders($this->folder, $workspace->previous()),
            new Maintenance($this->flag),
            $this->service(Upgrades::class),
            $this->service(Installation::class),
            $this->service(Connection::class),
            $this->service(Budget::class),
            $this->service(Sleeper::class),
            $this->service(LoggerInterface::class),
            $this->folder,
            $this->schedulerLock,
            $this->routes,
        );
    }

    /** @return HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface> */
    private function transport(): HandlerStack
    {
        $transport = $this->app()->container()->get('http.transport');
        assert($transport instanceof HandlerStack);

        return $transport;
    }
}
