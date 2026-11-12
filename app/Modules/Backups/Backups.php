<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Core\Exceptions\ValidationException;
use App\Core\Scheduling\Scheduler;
use App\Core\Support\Files;
use App\Modules\Backups\Exceptions\BackupRefusedException;
use App\Modules\Backups\Tasks\SendBackupsTask;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Reports\ReportGroupState;
use App\Modules\Telegram\Reports\ReportSettings;
use App\Modules\Telegram\Reports\Topic;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * The shop's backups (storage/backups, never served as it is): one made now to download or send (make()); a file
 * uploaded, checked and described before it is restored (inspect()), then restored by its token (restore()) — the
 * scope's state just before kept first (the last KEEP of them, the older ones gone once a restore went through), to
 * download should the restore be the wrong one. Files made for a download, uploads not restored and their
 * descriptions, and files a write left half-made go after a while (prune(), also once a day from SendBackupsTask).
 */
final class Backups
{
    /** How long an uploaded file waits to be restored. */
    public const UPLOAD_MINUTES = 30;

    /** The states kept from before a restore, for each scope. */
    public const KEEP = 3;

    /** How long a file made for a download is kept. */
    private const MADE_MINUTES = 60;

    /** The shop's setting of when its backup last went to its report group, and what kept it: {at, problem}. */
    public const LAST_SENT = 'backups.last_sent';

    public function __construct(
        private readonly BackupWriter $writer,
        private readonly BackupReader $reader,
        private readonly Restorer $restorer,
        private readonly Settings $settings,
        /** storage/backups: never served as it is */
        private readonly string $folder,
        private readonly LoggerInterface $logger,
        private readonly ReportSettings $reports,
        private readonly ReportGroupState $group,
        private readonly Scheduler $scheduler,
    ) {}

    /**
     * What the panel shows of `$scope`'s backups: what they hold, their sending to the report group, the states kept from
     * before a restore, and what one upload here takes.
     *
     * @return array<string, mixed>
     */
    public function status(BackupScope $scope): array
    {
        $shop = $scope->shop === null ? null : Bot::query()->find($scope->shop);
        $last = $this->settings->get(self::LAST_SENT);

        return [
            'scope' => $scope->kind(),
            'shop' => $shop === null ? null : ['id' => $shop->id, 'username' => $shop->username, 'title' => $shop->title],
            'auto' => [
                'enabled' => $this->reports->enabled(Topic::Backups),
                'group' => $this->group->chatId() !== null,
                'every_minutes' => intdiv($this->scheduler->interval(SendBackupsTask::class), 60),
                'last_sent_at' => is_array($last) && is_string($last['at'] ?? null) ? $last['at'] : null,
                'last_problem' => is_array($last) && is_string($last['problem'] ?? null) ? $last['problem'] : null,
            ],
            'kept' => $this->kept($scope),
            'upload_max_bytes' => self::uploadLimit(),
        ];
    }

    /** Whether the shop's backup goes to its report group (its «پشتیبان‌گیری» topic's switch). */
    public function sendToGroup(bool $on): void
    {
        $this->settings->set('reports.topic.' . Topic::Backups->value, $on);
    }

    /**
     * A backup of `$scope` made now.
     *
     * @return array{path: string, name: string, rows: array<string, int>}
     */
    public function make(BackupScope $scope): array
    {
        $this->prune();
        $name = $this->fileName($scope, '');
        $path = $this->folder('made') . '/' . $name;
        $rows = $this->writer->write($scope, $path);

        return ['path' => $path, 'name' => $name, 'rows' => $rows];
    }

    /**
     * Check a file uploaded to restore into `$scope` and describe it — what it is of, when, what it holds, what a restore
     * leaves out — kept under a token its restore names.
     *
     * @return array<string, mixed>
     * @throws ValidationException (BackupRefusedException's words)
     */
    public function inspect(?UploadedFileInterface $upload, BackupScope $scope): array
    {
        $this->prune();
        if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            throw BackupRefusedException::because(BackupRefusedException::NO_FILE);
        }
        if (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw BackupRefusedException::because(sprintf(BackupRefusedException::TOO_LARGE, Messages::bytes(self::uploadLimit())));
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw BackupRefusedException::because(BackupRefusedException::NOT_A_BACKUP);
        }

        $token = bin2hex(random_bytes(16));
        $path = $this->uploadPath($token);
        self::copy($upload, $path);

        try {
            $contents = $this->reader->read($path);
            self::hold($contents, $scope);
            $analysis = $this->restorer->analyse($path, $contents);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        $inspection = [
            'token' => $token,
            'scope' => $contents->scope->kind(),
            'shop' => $contents->shop,
            'made_at' => $contents->madeAt,
            'app_version' => $contents->appVersion,
            'tables' => array_map(static fn(string $table, int $rows): array => ['table' => $table, 'rows' => $rows], array_keys($contents->rows), array_values($contents->rows)),
            'rows' => $contents->total(),
            'warnings' => $analysis->warnings(),
            'expires_at' => now()->addMinutes(self::UPLOAD_MINUTES)->toIso8601String(),
        ];
        Files::writeAtomically($this->uploadPath($token, 'json'), (string) json_encode(['shop' => $scope->shop] + $inspection, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $inspection;
    }

    /**
     * Restore the file inspect() kept under `$token` into `$scope` — the scope's state now kept first.
     *
     * @return array{rows: array<string, int>, kept: string}
     * @throws ValidationException
     */
    public function restore(string $token, BackupScope $scope): array
    {
        $this->prune();
        $path = preg_match('/^[0-9a-f]{32}$/D', $token) === 1 ? $this->uploadPath($token) : '';
        $described = $path === '' ? null : json_decode((string) Files::read($this->uploadPath($token, 'json')), true);
        if (!is_file($path) || !is_array($described) || ($described['shop'] ?? null) !== $scope->shop) {
            throw BackupRefusedException::because(BackupRefusedException::EXPIRED);
        }

        // Read and held again: the file is what was checked, and the shop the one it was checked for.
        $contents = $this->reader->read($path);
        self::hold($contents, $scope);
        $analysis = $this->restorer->analyse($path, $contents);

        $kept = $this->keep($scope);
        try {
            $rows = $this->restorer->restore($path, $contents, $analysis);
        } finally {
            $this->settings->refresh();
        }
        // Only now: a restore that failed leaves every state kept before it, its own one more.
        $this->keepOnly($scope);
        @unlink($path);
        @unlink($this->uploadPath($token, 'json'));
        $this->logger->notice('A backup made {made} was restored into {scope}', ['made' => $contents->madeAt, 'scope' => $scope->shop === null ? 'the whole installation' : 'shop #' . $scope->shop]);

        return ['rows' => $rows, 'kept' => $kept];
    }

    /**
     * The states kept from before a restore of `$scope`, newest first.
     *
     * @return list<array{name: string, made_at: string, size: int}>
     */
    public function kept(BackupScope $scope): array
    {
        $kept = [];
        foreach (glob($this->folder('kept/' . self::scopeFolder($scope)) . '/*.json.gz') ?: [] as $path) {
            $kept[] = ['name' => basename($path), 'made_at' => date(DATE_ATOM, (int) filemtime($path)), 'size' => (int) filesize($path)];
        }
        usort($kept, static fn(array $a, array $b): int => strcmp($b['made_at'], $a['made_at']) ?: strcmp($b['name'], $a['name']));

        return $kept;
    }

    /** The path of a state kept from before a restore of `$scope`; null for a name that is none of them. */
    public function keptPath(BackupScope $scope, string $name): ?string
    {
        $path = $this->folder('kept/' . self::scopeFolder($scope)) . '/' . $name;

        return preg_match('/^[A-Za-z0-9_.-]+\.json\.gz$/D', $name) === 1 && is_file($path) ? $path : null;
    }

    /** What PHP takes in one upload here, bytes: the smaller of its two limits. */
    public static function uploadLimit(): int
    {
        $limits = array_filter([self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')], static fn(int $bytes): bool => $bytes > 0);

        return $limits === [] ? 0 : min($limits);
    }

    /** A backup file's name: what it is of — the installation, or the shop's bot — and when; `$kind` before the date. */
    public function fileName(BackupScope $scope, string $kind): string
    {
        $of = 'installation';
        if ($scope->shop !== null) {
            $username = Bot::query()->whereKey($scope->shop)->value('username');
            $of = is_string($username) && preg_match('/^[A-Za-z0-9_]+$/D', $username) === 1 ? $username : 'shop-' . $scope->shop;
        }

        return 'amobot-backup-' . $of . ($kind === '' ? '' : '-' . $kind) . '-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json.gz';
    }

    /**
     * Uploads not restored, files made for a download — every shop's — and what a write left half-made (BackupWriter's
     * `.part`), once their time is over.
     */
    public function prune(): void
    {
        $now = time();
        foreach (glob($this->folder('uploads') . '/*') ?: [] as $file) {
            if ($now - (int) filemtime($file) > self::UPLOAD_MINUTES * 60) {
                @unlink($file);
            }
        }
        foreach ([...glob($this->folder('made') . '/*') ?: [], ...glob($this->folder('kept') . '/*/*.part') ?: []] as $file) {
            if ($now - (int) filemtime($file) > self::MADE_MINUTES * 60) {
                @unlink($file);
            }
        }
    }

    /**
     * A file is restored into the scope it was made of: the whole installation's from the owner's panel in the main
     * shop, a shop's in that shop.
     *
     * @throws ValidationException
     */
    private static function hold(BackupContents $contents, BackupScope $scope): void
    {
        if (!$contents->scope->isShop() && $scope->isShop()) {
            throw BackupRefusedException::because(BackupRefusedException::WHOLE_ONLY);
        }
        if ($contents->scope->isShop() && !$scope->isShop()) {
            throw BackupRefusedException::because(BackupRefusedException::SHOP_ONLY);
        }
        if (!$contents->scope->equals($scope)) {
            $name = $contents->shop['username'] ?? null;

            throw BackupRefusedException::because(sprintf(BackupRefusedException::OTHER_SHOP, is_string($name) ? '@' . $name : '#' . $contents->scope->shop));
        }
    }

    /** The scope's state now, kept before a restore. */
    private function keep(BackupScope $scope): string
    {
        $name = $this->fileName($scope, 'before-restore');
        $this->writer->write($scope, $this->folder('kept/' . self::scopeFolder($scope)) . '/' . $name);

        return $name;
    }

    /** The newest KEEP states kept from before a restore of `$scope`; the older ones go. */
    private function keepOnly(BackupScope $scope): void
    {
        $folder = $this->folder('kept/' . self::scopeFolder($scope));
        foreach (array_slice($this->kept($scope), self::KEEP) as $old) {
            @unlink($folder . '/' . $old['name']);
        }
    }

    /**
     * An upload's bytes to `$path`, a piece at a time: its stream copied, which every host's PHP and every request a test
     * makes can do (moving it needs the upload's own temporary file).
     */
    private static function copy(UploadedFileInterface $upload, string $path): void
    {
        $stream = $upload->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException("The upload cannot be kept at {$path}.");
        }
        try {
            while (!$stream->eof()) {
                fwrite($out, $stream->read(1024 * 1024));
            }
        } finally {
            fclose($out);
        }
    }

    private static function scopeFolder(BackupScope $scope): string
    {
        return $scope->shop === null ? 'installation' : 'shop-' . $scope->shop;
    }

    private function uploadPath(string $token, string $extension = 'json.gz'): string
    {
        return $this->folder('uploads') . '/' . $token . '.' . $extension;
    }

    /** A folder of storage/backups, made when missing. */
    private function folder(string $name): string
    {
        $folder = $this->folder . '/' . $name;
        if (!is_dir($folder) && !@mkdir($folder, Files::folderMode(), true) && !is_dir($folder)) {
            throw new \RuntimeException("The folder {$folder} cannot be made.");
        }

        return $folder;
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
