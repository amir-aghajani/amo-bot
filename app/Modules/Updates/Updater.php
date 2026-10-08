<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Application;
use App\Core\Database\UpgradeFailedException;
use App\Core\Database\Upgrades;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Json;
use App\Core\Installation;
use App\Core\Logging\Redact;
use App\Core\Scheduling\Budget;
use App\Core\Support\FileLock;
use App\Core\Support\Files;
use App\Core\Support\Sleeper;
use App\Modules\Updates\Exceptions\UpdateRefusedException;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\BlueprintState;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Fluent;
use Psr\Log\LoggerInterface;
use Slim\ResponseEmitter;

/**
 * The owner's one-press update, a request a step — a shared host gives a request half a minute or so and keeps nothing
 * running between two: the release's files fetched and its manifest checked against the release key (Download), the zip
 * unpacked a slice at a time (Extract), the host checked — its PHP, its extensions, that PHP may swap the app's folders
 * (Preflight) —, then in one request the install: the shop held (Maintenance), the app's paths swapped for the release's
 * (AppFolders), the database brought along (Core\Database\Upgrades), the compiled code and the router's table forgotten,
 * the version recorded (Installation), the shop open again. Anything after the swap that fails takes the swap back and
 * says why; an install a crash cut short is finished by the next step, by whichever code is in place then. The version
 * an install replaced stays aside until the next update installs, so a regretted one is taken back (rollback()) — while
 * it changed nothing of the database. Files someone put in place by hand, whose database waits for its upgrades, are a
 * run of their own: an install with nothing to swap (UpdateRun::upgrading()). One request works a run at a time.
 */
final class Updater
{
    public const NO_KEY = 'این نسخه کلید امضای نسخه‌ها را ندارد و نمی‌تواند نسخه تازه را بررسی کند؛ دستی به‌روز کنید (راهنمای Upgrading).';

    public const NO_SODIUM = 'افزونه sodium روی PHP این هاست فعال نیست و بدون آن امضای نسخه تازه بررسی نمی‌شود؛ از پشتیبانی هاست بخواهید فعالش کند، یا دستی به‌روز کنید (راهنمای Upgrading).';

    public const NO_ZIP = 'افزونه zip روی PHP این هاست فعال نیست و بدون آن بسته نسخه تازه باز نمی‌شود؛ از پشتیبانی هاست بخواهید فعالش کند، یا دستی به‌روز کنید (راهنمای Upgrading).';

    public const CHECKOUT = 'این فروشگاه از یک کپی git اجرا می‌شود؛ آن را با git به‌روز کنید.';

    public const NOT_LATEST = 'این نسخه تازه‌ترین نسخه نیست؛ دوباره بررسی کنید.';

    public const UNDER_WAY = 'یک به‌روزرسانی در جریان است؛ آن را ادامه دهید یا لغو کنید.';

    public const NO_RUN = 'به‌روزرسانی‌ای در جریان نیست.';

    public const SCHEDULER_BUSY = 'کارهای زمان‌بندی‌شده هنوز در حال اجرا هستند؛ یک دقیقه دیگر دوباره امتحان کنید.';

    public const CANNOT_CANCEL = 'نصب شروع شده و دیگر لغو نمی‌شود؛ آن را ادامه دهید.';

    public const NOT_DONE = 'به‌روزرسانی تمام‌شده‌ای نیست که برگردانده شود.';

    public const DATABASE_CHANGED = 'این به‌روزرسانی دیتابیس را هم تغییر داده است؛ برگشت به نسخه قبلی فقط با نسخه پشتیبان دیتابیس ممکن است (راهنمای Upgrading).';

    public const NOTHING_KEPT = 'نسخه قبلی دیگر نگه داشته نشده است.';

    public const ZIP_MISMATCH = 'فایل دانلودشده با امضای نسخه جور نیست و نصب نمی‌شود؛ دوباره امتحان کنید.';

    public const NOT_COMPLETE = 'بسته این نسخه کامل نیست؛ نصب نمی‌شود.';

    /** Seconds the zip may take to come down. */
    private const DOWNLOAD_SECONDS = 240;

    /** The most the manifest and its signature may weigh. */
    private const MANIFEST_BYTES = 65536;
    private const SIGNATURE_BYTES = 1024;

    /** What the disk must have free for a release, as times its zip: the zip, the release unpacked, and room. */
    private const ROOM = 4;

    /** How long an install or a rollback waits for a scheduler run under way to end. */
    private const SCHEDULER_WAIT_SECONDS = 60;

    /** What every release unpacked has, whatever else it brings: its packages' loader, its boot, its front door, its version. */
    private const RELEASE_FILES = ['vendor/autoload.php', 'bootstrap/app.php', 'public/index.php', 'app/Core/Application.php'];

    /**
     * What the request that swaps the app's files still runs once they are swapped — the rest of the install, a rollback,
     * and the answer —, loaded before: a class loaded afterwards would come from the other version's files.
     */
    private const LOADED_BEFORE_THE_SWAP = [
        AppFolders::class, Disk::class, UpdateRun::class, UpdateStep::class, Release::class, Maintenance::class,
        UpdateRefusedException::class, ValidationException::class, Installation::class, Upgrades::class,
        UpgradeFailedException::class, Redact::class, FileLock::class, Json::class, ResponseEmitter::class,
        Blueprint::class, BlueprintState::class, ColumnDefinition::class, ForeignIdColumnDefinition::class,
        ForeignKeyDefinition::class, IndexDefinition::class, Fluent::class,
    ];

    public function __construct(
        private readonly Releases $releases,
        private readonly GitHub $gitHub,
        private readonly ReleaseKey $key,
        private readonly Workspace $workspace,
        private readonly AppFolders $folders,
        private readonly Maintenance $maintenance,
        private readonly Upgrades $upgrades,
        private readonly Installation $installation,
        private readonly Connection $db,
        private readonly Budget $budget,
        private readonly Sleeper $sleeper,
        private readonly LoggerInterface $logger,
        /** The app's folder (Application::basePath()): its database/upgrades are the ones that run. */
        private readonly string $app,
        /** The scheduler's lock (Core\Scheduling\Scheduler): held, no scheduled run starts. */
        private readonly string $schedulerLock,
        /** The router's table (Core\Http\RouteCache's folder). */
        private readonly string $routes,
    ) {}

    /**
     * The update screen: the version the shop runs, the newest release and when it was read, whether it is newer — and
     * what keeps the shop from installing it itself —, and the run under way, or the last one.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->answer($this->releases->latest(), $this->run());
    }

    /**
     * The newest release read from GitHub now — the owner's «بررسی دوباره».
     *
     * @return array<string, mixed>
     * @throws UpdateRefusedException when GitHub does not answer
     */
    public function check(): array
    {
        $this->releases->check();

        return $this->status();
    }

    /**
     * An update to `$version`, the newest release, begun: its first step the next request's.
     *
     * @return array<string, mixed>
     * @throws ValidationException when `$version` is not the newest release, newer than the shop's
     * @throws UpdateRefusedException when an update is under way, or the shop cannot update itself
     */
    public function start(string $version): array
    {
        $lock = $this->workspace->lock();
        try {
            if ($this->run()?->step->isUnderWay()) {
                throw UpdateRefusedException::busy(self::UNDER_WAY);
            }
            $release = $this->releases->latest()['release'];
            if ($release === null || $release->version !== $version || !$release->isNewer()) {
                throw ValidationException::on('version', self::NOT_LATEST);
            }
            $blocker = $this->blocker($release);
            if ($blocker !== null) {
                throw UpdateRefusedException::refused($blocker);
            }
            $this->workspace->save(UpdateRun::begin($version, Application::VERSION));
            $this->logger->info('The owner began updating AmoBot {from} to {version}.', ['from' => Application::VERSION, 'version' => $version]);
        } finally {
            $lock->release();
        }

        return $this->status();
    }

    /**
     * The run's next step taken — or the part of it a request has time for.
     *
     * @return array<string, mixed>
     * @throws UpdateRefusedException when the step is refused: said, and kept with the run for the screen
     */
    public function step(): array
    {
        $lock = $this->workspace->lock();
        try {
            $run = $this->run();
            if ($run === null || !$run->step->isUnderWay()) {
                throw UpdateRefusedException::refused(self::NO_RUN);
            }
            // Read before the step: once an install swapped the app's files, nothing more of them is loaded.
            $latest = $this->releases->latest();
            try {
                $run = match ($run->step) {
                    UpdateStep::Download => $this->download($run),
                    UpdateStep::Extract => $this->extract($run),
                    UpdateStep::Preflight => $this->preflight($run),
                    default => $this->install($run),
                };
            } catch (UpdateRefusedException $e) {
                $this->logger->warning('Updating AmoBot to {version} was refused at {step}: {message}', ['version' => $run->version, 'step' => $run->step->value, 'message' => $e->getMessage()]);
                // The run as its step last saved it: an install's records what it did before it was refused.
                $this->workspace->save(($this->workspace->run() ?? $run)->refused($e->getMessage()));

                throw $e;
            }
            $this->workspace->save($run);
        } finally {
            $lock->release();
        }

        return $this->answer($latest, $run);
    }

    /**
     * The run given up before its install began: what it fetched and unpacked goes.
     *
     * @return array<string, mixed>
     * @throws UpdateRefusedException when there is none, or its install began
     */
    public function cancel(): array
    {
        $lock = $this->workspace->lock();
        try {
            $run = $this->workspace->run();
            if ($run === null || !$run->isCancellable()) {
                throw UpdateRefusedException::refused($run !== null && $run->step->isUnderWay() ? self::CANNOT_CANCEL : self::NO_RUN);
            }
            $this->workspace->forget();
            $this->discardDownloads();
            $this->logger->info('The owner cancelled updating AmoBot to {version}.', ['version' => $run->version]);
        } finally {
            $lock->release();
        }

        return $this->status();
    }

    /**
     * The update installed last taken back: the version it replaced in its place again — while that is kept, and the
     * update changed nothing of the database (which only a backup takes back).
     *
     * @return array<string, mixed>
     * @throws UpdateRefusedException when it may not be taken back, or could not be
     */
    public function rollback(): array
    {
        $lock = $this->workspace->lock();
        try {
            $run = $this->workspace->run();
            if ($run === null || $run->step !== UpdateStep::Done) {
                throw UpdateRefusedException::refused(self::NOT_DONE);
            }
            if ($run->upgraded !== []) {
                throw UpdateRefusedException::refused(self::DATABASE_CHANGED);
            }
            if ($run->paths === [] || !$this->folders->kept()) {
                throw UpdateRefusedException::refused(self::NOTHING_KEPT);
            }

            $latest = $this->releases->latest();
            $scheduler = $this->waitForScheduler();
            try {
                $this->loadWhatTheSwapNeeds();
                $this->logger->info('Taking AmoBot {version} back to {from}.', ['version' => $run->version, 'from' => $run->from]);
                $this->holdTheShop();
                $release = $this->workspace->release($run->version);
                try {
                    $this->folders->restore($release, $run->paths);
                    $this->installation->moveTo($run->from);
                } catch (\Throwable $e) {
                    $this->logger->error('Taking AmoBot {version} back failed: {message}', ['version' => $run->version, 'message' => $e->getMessage(), 'exception' => $e]);
                    $this->putBack(fn() => $this->folders->install($release, $run->paths), $run->version);
                    $this->forgetCompiledCode();
                    $this->maintenance->release();

                    throw UpdateRefusedException::refused(sprintf('برگشت به نسخه قبلی انجام نشد (%s)؛ فروشگاه روی نسخه %s ماند.', self::reason($e), $run->version));
                }
                $this->forgetCompiledCode();
                $this->maintenance->release();
                $run = $run->over(UpdateStep::RolledBack);
                $this->workspace->save($run);
                $this->logger->info('The owner took AmoBot {version} back to {from}.', ['version' => $run->version, 'from' => $run->from]);
                $this->discardDownloads();
            } finally {
                $scheduler?->release();
            }
        } finally {
            $lock->release();
        }

        return $this->answer($latest, $run);
    }

    /**
     * Download: the release's manifest and its signature fetched and checked against the release key, then the zip,
     * checked against the manifest. What an earlier run left goes first — but the version the last update replaced,
     * kept until this one installs.
     */
    private function download(UpdateRun $run): UpdateRun
    {
        if (!$this->workspace->clear(['previous'], $this->budget->deadline())) {
            return $run;
        }
        foreach ([Release::MANIFEST => self::MANIFEST_BYTES, Release::SIGNATURE => self::SIGNATURE_BYTES] as $name => $bytes) {
            $this->gitHub->download($run->version, $name, $this->workspace->download($name), $bytes, self::DOWNLOAD_SECONDS);
        }
        $manifest = $this->manifest($run->version);

        $room = $manifest->size * self::ROOM;
        $free = $this->workspace->freeSpace();
        if ($free !== null && $free < $room) {
            throw UpdateRefusedException::refused(sprintf('فضای خالی هاست برای نسخه تازه کافی نیست (دست‌کم %d مگابایت لازم است)؛ جا باز کنید و دوباره امتحان کنید.', (int) ceil($room / 1048576)));
        }

        $zip = $this->workspace->download($manifest->file);
        $this->gitHub->download($run->version, $manifest->file, $zip, $manifest->size, self::DOWNLOAD_SECONDS);
        $this->checkZip($zip, $manifest);

        return $run->at(UpdateStep::Extract);
    }

    /**
     * Extract: the zip — its bytes the manifest's again, every entry where it may go (Package::check()) — unpacked into
     * the release's folder a slice at a time; once all of it is, the release checked whole.
     */
    private function extract(UpdateRun $run): UpdateRun
    {
        $manifest = $this->manifest($run->version);
        $zip = $this->workspace->download($manifest->file);
        $package = new Package($zip, $manifest);
        if ($run->entry === 0) {
            $this->checkZip($zip, $manifest);
            $package->check();
            // What an earlier try unpacked.
            if (!$this->workspace->clear(['previous', 'download'], $this->budget->deadline())) {
                return $run;
            }
        }

        $release = $this->workspace->release($run->version);
        $next = $package->extract($release, $run->entry, $this->budget->deadline());
        if ($next < $package->count()) {
            return $run->unpacked($next, intdiv($next * 100, $package->count()));
        }

        $version = preg_match("/const VERSION = '([^']+)'/", Files::read("{$release}/app/Core/Application.php") ?? '', $code) === 1 ? $code[1] : null;
        $missing = array_filter([...$manifest->paths, ...self::RELEASE_FILES], static fn(string $path): bool => !file_exists("{$release}/{$path}"));
        if ($missing !== [] || $version !== $manifest->version) {
            throw UpdateRefusedException::refused(self::NOT_COMPLETE);
        }

        return $run->at(UpdateStep::Preflight);
    }

    /**
     * Preflight: what the release needs of the host — its PHP, its extensions — and whether PHP may swap the app's
     * paths: each its own to change, and a folder moving between the updater's and the app's on one file system. The
     * version the last update replaced goes now: this one's install replaces it.
     */
    private function preflight(UpdateRun $run): UpdateRun
    {
        $manifest = $this->manifest($run->version);
        $blocker = $this->hostBlocker($manifest->paths);
        if ($blocker !== null) {
            throw UpdateRefusedException::refused($blocker);
        }
        if (version_compare(PHP_VERSION, $manifest->php, '<')) {
            throw UpdateRefusedException::refused(sprintf('نسخه %s دست‌کم PHP %s می‌خواهد و PHP این هاست %s است؛ نسخه PHP را از تنظیمات هاست بالا ببرید.', $run->version, $manifest->php, PHP_VERSION));
        }
        $missing = array_values(array_filter($manifest->extensions, static fn(string $extension): bool => !extension_loaded($extension)));
        if ($missing !== []) {
            throw UpdateRefusedException::refused(sprintf('نسخه %s افزونه‌های %s را می‌خواهد که روی PHP این هاست فعال نیست؛ از پشتیبانی هاست بخواهید فعالشان کند.', $run->version, implode('، ', $missing)));
        }

        if (!$this->workspace->clear(['download', $run->version], $this->budget->deadline())) {
            return $run;
        }
        if (!$this->folders->renames()) {
            throw UpdateRefusedException::refused('PHP نمی‌تواند پوشه‌های برنامه را جابه‌جا کند: پوشه storage روی دیسک دیگری است، یا اجازه نوشتن در پوشه برنامه نیست؛ دستی به‌روز کنید (راهنمای Upgrading).');
        }

        return $run->at(UpdateStep::Install);
    }

    /**
     * Install, in one request: the scheduler's run waited for and held off, the shop held, the app's paths swapped for
     * the release's, the database's upgrades run, the version recorded, the shop open again — or, anything after the
     * swap failing, the swap taken back. An install a crash cut short (its run installing) is finished from where it
     * stopped, without the checks it passed before it began.
     */
    private function install(UpdateRun $run): UpdateRun
    {
        $paths = $run->paths;
        if (!$run->installing) {
            $paths = $this->manifest($run->version)->paths;
            $blocker = $this->hostBlocker($paths);
            if ($blocker !== null) {
                throw UpdateRefusedException::refused($blocker);
            }
        }

        $scheduler = $this->waitForScheduler();
        try {
            $this->loadWhatTheSwapNeeds();
            // Said before the swap: the log's own classes are loaded from the files in place too.
            $this->logger->info('Installing AmoBot {version} in place of {from}.', ['version' => $run->version, 'from' => $run->from]);
            $this->holdTheShop();
            try {
                $run = $run->installing(true, $paths);
                $this->workspace->save($run);
            } catch (UpdateRefusedException $e) {
                $this->maintenance->release();

                throw $e;
            }
            $release = $this->workspace->release($run->version);
            try {
                $this->folders->install($release, $paths);
                $this->forgetCompiledCode();
                $this->upgrades->run("{$this->app}/database/upgrades", $this->installation->version(), $run->version, function (string $version) use (&$run): void {
                    $this->installation->moveTo($version);
                    $run = $run->upgraded($version);
                    $this->workspace->save($run);
                    $this->maintenance->hold();
                });
                $this->installation->moveTo($run->version);
            } catch (\Throwable $e) {
                throw $this->takeBack($run, $release, $e);
            }
            $this->maintenance->release();
            $this->logger->info('AmoBot {version} is installed in place of {from}.', ['version' => $run->version, 'from' => $run->from]);
            $run = $run->over(UpdateStep::Done);
            $this->workspace->save($run);
            $this->discardDownloads();

            return $run;
        } finally {
            $scheduler?->release();
        }
    }

    /**
     * An install that failed after its swap, taken back: the app's own paths in their place again — as far as that goes —
     * and the shop open. The refusal says what failed, and what the shop runs now.
     */
    private function takeBack(UpdateRun $run, string $release, \Throwable $failure): UpdateRefusedException
    {
        $this->logger->error('Installing AmoBot {version} failed: {message}', ['version' => $run->version, 'message' => $failure->getMessage(), 'exception' => $failure]);
        $restored = $this->putBack(fn() => $this->folders->restore($release, $run->paths), $run->version);
        $this->forgetCompiledCode();
        $this->maintenance->release();
        try {
            // Put back, the next try installs anew; not — or with nothing to put back (files put in place by hand) —, it
            // finishes this one.
            $this->workspace->save($run->installing(!$restored || $run->paths === [], $run->paths));
        } catch (UpdateRefusedException $e) {
            $this->logger->warning('The update\'s run was not kept: {message}', ['message' => $e->getMessage()]);
        }

        $what = $failure instanceof UpgradeFailedException
            ? sprintf('به‌روزرسانی دیتابیس به نسخه %s انجام نشد: %s.', $failure->version, self::reason($failure->getPrevious() ?? $failure))
            : sprintf('نصب نسخه %s انجام نشد: %s.', $run->version, self::reason($failure));
        $then = match (true) {
            !$restored => ' نسخه قبلی هم کامل برنگشت؛ پوشه‌های آن در storage/updates/previous است (راهنمای Upgrading).',
            $run->paths === [] => ' دوباره امتحان کنید.',
            default => sprintf(' فایل‌های برنامه به نسخه %s برگشت؛ دوباره امتحان کنید.', $run->from),
        };

        return UpdateRefusedException::refused($what . $then);
    }

    /**
     * `$work` — the app's paths put back where they were — done, or its failure logged: whether it went.
     *
     * @param \Closure(): void $work
     */
    private function putBack(\Closure $work, string $version): bool
    {
        try {
            $work();

            return true;
        } catch (\Throwable $e) {
            $this->logger->critical('Putting the app\'s files back after AmoBot {version} failed: {message} — the version they replaced is in storage/updates/previous.', ['version' => $version, 'message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }

    /**
     * The run under way or the last one; or, the shop's files newer than its database — put in place by hand, or an
     * install that stopped after its swap without its run (none kept) —, the upgrades they wait for.
     */
    private function run(): ?UpdateRun
    {
        $run = $this->workspace->run();
        $database = $this->installation->version();
        $finishing = $run !== null && $run->version === Application::VERSION && $run->step === UpdateStep::Install;

        return version_compare($database, Application::VERSION, '<') && !$finishing ? UpdateRun::upgrading(Application::VERSION, $database) : $run;
    }

    /**
     * @param array{release: Release|null, checked_at: int|null} $latest
     * @return array<string, mixed>
     */
    private function answer(array $latest, ?UpdateRun $run): array
    {
        $release = $latest['release'];
        // Under way, or just installed — by the code still answering, before the page reloads on the new one.
        $taken = $run !== null && ($run->step->isUnderWay() || ($run->step === UpdateStep::Done && $run->version === $release?->version));

        return [
            'current' => Application::VERSION,
            'latest' => $release?->present(),
            'checked_at' => $latest['checked_at'] === null ? null : gmdate(DATE_ATOM, $latest['checked_at']),
            'available' => $release !== null && $release->isNewer() && !$taken,
            'blocker' => $this->blocker($release),
            'run' => $run?->present($run->step === UpdateStep::Done && $run->upgraded === [] && $run->paths !== [] && $this->folders->kept()),
        ];
    }

    /** What keeps the shop from updating itself to `$release`: the host (hostBlocker()), or a release without its signed files. */
    private function blocker(?Release $release): ?string
    {
        return $this->hostBlocker(Manifest::ESSENTIAL)
            ?? ($release !== null && $release->isNewer() && !$release->isInstallable()
                ? sprintf('نسخه %s فایل‌های امضاشده‌ای را که به‌روزرسانی از داخل پنل لازم دارد ندارد؛ دستی به‌روز کنید (راهنمای Upgrading).', $release->version)
                : null);
    }

    /**
     * What keeps the shop from updating itself, whatever the release: no release key to check one with, PHP without the
     * extensions that check it and unpack it, a clone of the repository (git's to update), or `$paths` of the app PHP may
     * not change.
     *
     * @param list<string> $paths
     */
    private function hostBlocker(array $paths): ?string
    {
        $unchangeable = $this->folders->unchangeable($paths);

        return match (true) {
            !$this->key->exists() => self::NO_KEY,
            !function_exists('sodium_crypto_sign_verify_detached') => self::NO_SODIUM,
            !class_exists(\ZipArchive::class) => self::NO_ZIP,
            $this->folders->isCheckout() => self::CHECKOUT,
            $unchangeable !== null => sprintf(
                'PHP اجازه عوض کردن فایل‌های برنامه (%s) را ندارد؛ از پشتیبانی هاست بخواهید PHP را با حساب خود شما اجرا کند، یا دستی به‌روز کنید (راهنمای Upgrading).',
                $unchangeable === '.' ? 'پوشه برنامه' : $unchangeable,
            ),
            default => null,
        };
    }

    /**
     * The release's manifest as fetched — once the release key's signature over it verifies, and it is `$version`'s. A
     * manifest that is not goes, its signature with it: nothing unsigned is kept.
     *
     * @throws UpdateRefusedException
     */
    private function manifest(string $version): Manifest
    {
        try {
            return Manifest::verified(
                Files::read($this->workspace->download(Release::MANIFEST)) ?? '',
                Files::read($this->workspace->download(Release::SIGNATURE)) ?? '',
                $this->key,
                $version,
            );
        } catch (UpdateRefusedException $e) {
            $this->logger->warning('The manifest of AmoBot {version} was refused: {message}', ['version' => $version, 'message' => $e->getMessage()]);
            $this->discardDownloads();

            throw $e;
        }
    }

    /**
     * The zip as the manifest signed it: its size and its sha256 — or it goes.
     *
     * @throws UpdateRefusedException
     */
    private function checkZip(string $zip, Manifest $manifest): void
    {
        clearstatcache(true, $zip);
        if (!is_file($zip) || filesize($zip) !== $manifest->size || !hash_equals($manifest->sha256, (string) hash_file('sha256', $zip))) {
            Files::delete($zip);

            throw UpdateRefusedException::refused(self::ZIP_MISMATCH);
        }
    }

    /** What the run fetched and unpacked gone — as much as a moment allows: the rest is the next run's to clear. */
    private function discardDownloads(): void
    {
        try {
            $this->workspace->clear(['previous'], $this->budget->deadline());
        } catch (UpdateRefusedException $e) {
            $this->logger->warning('The update\'s downloads were not cleared: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * The scheduler's lock, once a run of it under way ends — so none starts while the app's files are swapped; null when
     * it keeps none (its folder not writable: it runs without, and so does this).
     *
     * @throws UpdateRefusedException when a run of it does not end in time
     */
    private function waitForScheduler(): ?FileLock
    {
        for ($waited = 0; ; $waited++) {
            try {
                $lock = FileLock::take($this->schedulerLock);
            } catch (\RuntimeException) {
                return null;
            }
            if ($lock !== null) {
                return $lock;
            }
            if ($waited >= self::SCHEDULER_WAIT_SECONDS) {
                throw UpdateRefusedException::busy(self::SCHEDULER_BUSY);
            }
            $this->sleeper->sleep(1);
        }
    }

    /**
     * The shop held while the app's files are swapped (Maintenance).
     *
     * @throws UpdateRefusedException when the flag cannot be written: nothing is swapped then
     */
    private function holdTheShop(): void
    {
        try {
            $this->maintenance->hold();
        } catch (\RuntimeException) {
            throw UpdateRefusedException::refused(Maintenance::UNWRITABLE);
        }
    }

    /** The classes the rest of this request runs once the app's files are swapped, loaded while they are its own. */
    private function loadWhatTheSwapNeeds(): void
    {
        foreach (self::LOADED_BEFORE_THE_SWAP as $class) {
            class_exists($class);
        }
        // The schema builder an upgrade gets, with its grammar.
        $this->db->getSchemaBuilder();
    }

    /** The code the swap replaced forgotten: the router's table made from it, and what the opcode cache compiled of it. */
    private function forgetCompiledCode(): void
    {
        clearstatcache(true);
        foreach (glob($this->routes . '/routes-*.php') ?: [] as $table) {
            Files::delete($table);
        }
        // A host may keep opcache's API to some scripts (opcache.restrict_api): this one is asked only where it may.
        $restricted = (string) ini_get('opcache.restrict_api');
        if (function_exists('opcache_reset') && ($restricted === '' || str_starts_with((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), $restricted))) {
            opcache_reset();
        }
    }

    /** What went wrong, in one line for the owner: no secret in it (Redact), no longer than a screen line or two. */
    private static function reason(\Throwable $e): string
    {
        $line = trim((string) strtok($e->getMessage(), "\n"));

        return mb_strimwidth(Redact::text($line !== '' ? $line : $e::class), 0, 300, '…');
    }
}
