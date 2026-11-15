<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Core\Database\Transitions;
use App\Core\Drivers\Registry;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\Encrypter;
use App\Core\Support\FileLock;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Enums\ImportStage;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportProblem;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Sources\RowWriter;
use App\Modules\Imports\Sources\Stager;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Writers\Run;
use App\Modules\Imports\Writers\ServerWriter;
use App\Support\Text;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * «انتقال از ربات دیگر»: an import's life, as the owner's panel and the console drive it — always into the main shop,
 * one open at a time. Begun from a backup file or a database connection (the Stager keeps either), it is read a step
 * at a time into its staged copy; recognised as its bot's and surveyed, it waits for the owner's choices (Choices);
 * started, it is written a step at a time (Importer); done, failed or cancelled, its staged rows and files go — they hold
 * the panels' passwords — while its record, tally and problems stay for its report. One step at a time per import: a
 * lock file beside its folder, which a second request finds held and answers the import as it stands.
 */
final class Imports
{
    /** Seconds one request's step works for. */
    public const STEP_SECONDS = 15;

    /** How long an open import nobody touches is kept before prune() cancels it. */
    public const IDLE_DAYS = 7;

    /** Why an import stopped when its staged copy is gone from under it. */
    public const STAGED_GONE = 'داده‌های خوانده‌شده ربات قبلی دیگر در دسترس نیست؛ انتقال را از نو شروع کنید.';

    /** Why an import stopped when the other bot's data could not be made into what it brings (the log has why). */
    public const BROKEN = 'داده‌های ربات قبلی در این مرحله خوانده نشد و انتقال نمی‌تواند ادامه پیدا کند؛ جزئیات در لاگ برنامه است.';

    /** Why an import stopped when this installation no longer has its bot's migrator. */
    public const NO_MIGRATOR = 'این نسخه دیگر انتقال از این ربات را ندارد.';

    /** How many tables a refusal names before «…». */
    private const FEW = 3;

    /** The longest reviewer name kept. */
    private const REVIEWER_MAX = 64;

    /** @param Registry<Migrator> $migrators */
    public function __construct(
        private readonly Registry $migrators,
        private readonly Stager $stager,
        private readonly Importer $importer,
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
        /** The imports' folder (storage/imports): the Stager's per import, and the locks beside them. */
        private readonly string $folder,
    ) {}

    /** @return list<Migrator> The bots an import reads, in the order they are registered. */
    public function migrators(): array
    {
        return $this->migrators->all();
    }

    /** The import under way (reading, in review, or importing); null for none. */
    public function current(): ?Import
    {
        return Import::query()->whereIn('phase', self::open())->latest('id')->first();
    }

    /** @return list<Import> The imports that ended, newest first. */
    public function history(int $limit = 10): array
    {
        return array_values(Import::query()->whereNotIn('phase', self::open())->latest('id')->limit($limit)->get()->all());
    }

    /** @throws ModelNotFoundException a 404 */
    public function find(int $id): Import
    {
        return Import::query()->findOrFail($id);
    }

    /**
     * An import of the other bot's backup file, kept to be read (Stager::keepUpload()). Refused as a whole — nothing left
     * behind — when the file is.
     *
     * @throws ValidationException under `migrator` (no such bot), `import` (another one is open) or `file`
     */
    public function fromBackup(string $migrator, ?UploadedFileInterface $file, ?string $reviewer): Import
    {
        return $this->begin($migrator, ImportSource::Backup, $reviewer, function (Import $import) use ($file): void {
            $this->stager->keepUpload($import, $file);
        });
    }

    /**
     * An import read off the other bot's database, its connection tried at once and kept encrypted (Stager::keepConnection()).
     *
     * @param array<string, mixed> $connection {host, port, database, username, password}
     * @throws ValidationException under `migrator`, `import` or the connection's field at fault
     */
    public function fromDatabase(string $migrator, array $connection, ?string $reviewer): Import
    {
        return $this->begin($migrator, ImportSource::Database, $reviewer, function (Import $import) use ($connection): void {
            $this->stager->keepConnection($import, $connection);
        });
    }

    /**
     * One step: reading or writing on, as far as the budget lets (in the main shop, whoever asks), the import refreshed
     * as it then stands. True when it ran; false when another step held the import (its lock) and nothing was done — what
     * a caller stepping in a loop waits on before asking again. A step that ends the import lets go of its staged copy
     * and files.
     */
    public function advance(Import $import, Budget $budget): bool
    {
        $lock = FileLock::take($this->lockOf($import));
        if ($lock === null) {
            $import->refresh();

            return false;
        }

        try {
            CurrentBot::run($import->bot_id, function () use ($import, $budget): void {
                $import->refresh();
                match ($import->phase) {
                    ImportPhase::Reading => $this->read($import, $budget),
                    ImportPhase::Importing => $this->write($import, $budget),
                    default => null,
                };
            });
        } finally {
            $lock->release();
            $import->refresh();
            if (!$import->phase->open()) {
                $this->discard($import);
            }
        }

        return true;
    }

    /**
     * The owner's choices, checked against the survey and the shop's servers (Choices::check()) and saved.
     *
     * @param array<string, mixed> $choices
     * @throws ValidationException under `choices.…`, or `import` when it is not in review
     */
    public function choose(Import $import, array $choices): Import
    {
        $import->refresh();
        if ($import->phase !== ImportPhase::Review) {
            throw ImportRefusedException::on('import', ImportRefusedException::NOT_NOW);
        }
        $checked = Choices::check($choices, $import->survey ?? [])->toArray();
        // Only while it is in review: a start a moment ago keeps the choices it started on.
        if (Import::query()->whereKey($import->id)->where('phase', ImportPhase::Review->value)->update(['choices' => json_encode($checked, JSON_THROW_ON_ERROR), 'updated_at' => now()]) !== 1) {
            throw ImportRefusedException::on('import', ImportRefusedException::NOT_NOW);
        }

        return $import->refresh();
    }

    /**
     * From the review to writing, from the first stage: on the choices saved — checked again, a server chosen may be gone
     * since —, or what the review proposes when none were. Of two starts at once, one moves it.
     *
     * @throws ValidationException under `choices.…`, or `import` when it is not in review
     */
    public function start(Import $import): Import
    {
        $import->refresh();
        if ($import->phase !== ImportPhase::Review) {
            throw ImportRefusedException::on('import', ImportRefusedException::NOT_NOW);
        }
        $survey = $import->survey ?? [];
        $choices = $import->choices === null ? Choices::propose($survey) : Choices::check($import->choices, $survey);
        $moved = Transitions::move($import, 'phase', [ImportPhase::Review], ImportPhase::Importing, [
            'choices' => $choices->toArray(),
            'stage' => ImportStage::Servers,
            'cursor' => 0,
            'counts' => [],
            'started_at' => now(),
        ]);
        if (!$moved) {
            throw ImportRefusedException::on('import', ImportRefusedException::NOT_NOW);
        }
        $this->logger->info('Import {id} of {migrator} started', ['id' => $import->id, 'migrator' => $import->migrator]);

        return $import;
    }

    /**
     * An open import cancelled — its staged copy and files gone now, or by the step under way as it stops —; an ended
     * one's record deleted, its problems with it (what it brought stays linked: a later import still knows it).
     */
    public function cancel(Import $import): void
    {
        $import->refresh();
        if (!$import->phase->open()) {
            $this->discard($import);
            $import->delete();

            return;
        }
        if (Transitions::move($import, 'phase', self::open(), ImportPhase::Cancelled, ['finished_at' => now()])) {
            $this->logger->info('Import {id} of {migrator} cancelled', ['id' => $import->id, 'migrator' => $import->migrator]);
        }
        $lock = FileLock::take($this->lockOf($import));
        if ($lock !== null) {
            $lock->release();
            $this->discard($import->refresh());
        }
    }

    /**
     * What the import could not bring, a page at a time in the order it met them, narrowed to a reason and a kind of
     * thing — the reasons only counted (ProblemReason::counted()) have no rows here.
     *
     * @return array{problems: list<ImportProblem>, total: int}
     */
    public function problems(Import $import, ?string $reason, ?string $kind, int $page, int $perPage = 50): array
    {
        $query = $import->problems();
        if ($reason !== null) {
            $query->where('reason', $reason);
        }
        if ($kind !== null) {
            $query->where('kind', $kind);
        }
        $total = (clone $query)->count();
        $problems = $query->orderBy('id')->forPage(max(1, $page), max(1, $perPage))->get()->all();

        return ['problems' => array_values($problems), 'total' => $total];
    }

    /**
     * The import as the panels read it (ImportState): its phase and progress, the survey with the server each panel
     * likely is, the choices saved — or proposed while none are —, the tally, the problems by reason, and why it failed.
     *
     * @return array<string, mixed>
     */
    public function present(Import $import): array
    {
        $survey = $import->survey;
        $servers = $survey === null ? [] : Choices::servers();
        if ($survey !== null) {
            $survey['panels'] = array_map(static fn(array $panel): array => $panel + ['suggested_server_id' => Choices::suggestedServer($panel, $servers)], array_values(array_filter(is_array($survey['panels'] ?? null) ? $survey['panels'] : [], 'is_array')));
        }
        $proposed = $import->phase === ImportPhase::Review && $import->choices === null && $survey !== null ? Choices::propose($survey)->toArray() : null;

        return [
            'id' => $import->id,
            'migrator' => $import->migrator,
            'migrator_label' => $this->migrators->find($import->migrator)?->describe()->label ?? $import->migrator,
            'source' => $import->source->value,
            'file_name' => $import->file_name,
            'phase' => $import->phase->value,
            'progress' => $this->progress($import),
            'version' => $import->version,
            'survey' => $survey,
            'choices' => $import->choices === null ? null : Choices::of($import->choices)->toArray(),
            'proposed' => $proposed,
            'counts' => Run::tally($import->counts),
            'problems' => self::problemsByReason($import),
            'error' => $import->error,
            'reviewer' => $import->reviewer,
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
            'updated_at' => $import->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Open imports nobody touched for IDLE_DAYS, cancelled; every ended import's staged copy and files that are still
     * there (a cancel whose step was cut short), gone; and the folders and locks of imports whose record is gone.
     */
    public function prune(): void
    {
        foreach (Import::query()->whereIn('phase', self::open())->where('updated_at', '<', now()->subDays(self::IDLE_DAYS))->get() as $import) {
            $this->logger->info('Import {id} of {migrator} was left untouched; cancelled', ['id' => $import->id, 'migrator' => $import->migrator]);
            $this->cancel($import);
        }
        foreach (Import::query()->whereNotIn('phase', self::open())->get() as $import) {
            if ($import->panels !== null || $import->connection !== null || is_dir($this->folder . '/' . $import->id) || ImportRow::query()->where('import_id', $import->id)->exists()) {
                $this->discard($import);
            }
        }
        $this->pruneOrphans();
    }

    /**
     * The folders (the other bot's file, its panels' passwords) and locks of imports a restore of the whole installation
     * took away. Listed before their records are looked for: an import's record is always made before its folder and lock.
     */
    private function pruneOrphans(): void
    {
        $left = [];
        foreach (glob($this->folder . '/*') ?: [] as $path) {
            if (preg_match('/^(\d+)(?:\.lock)?$/D', basename($path), $id) === 1) {
                $left[(int) $id[1]] = true;
            }
        }
        if ($left === []) {
            return;
        }
        $kept = array_flip(array_map('intval', Import::query()->whereKey(array_keys($left))->pluck('id')->all()));
        foreach (array_keys(array_diff_key($left, $kept)) as $id) {
            $this->stager->discardFolder($id);
            @unlink($this->folder . '/' . $id . '.lock');
        }
    }

    /**
     * A new import, refused while another is open (one at a time — decided under a lock, so two at once cannot both
     * begin), its source then handed over: refused, the import is gone again.
     *
     * @param \Closure(Import): void $keep
     * @throws ValidationException
     */
    private function begin(string $key, ImportSource $source, ?string $reviewer, \Closure $keep): Import
    {
        $migrator = $this->migrators->find($key) ?? throw ImportRefusedException::on('migrator', ImportRefusedException::UNKNOWN_MIGRATOR);
        $lock = FileLock::wait($this->folder . '/begin.lock') ?? throw new \RuntimeException("The imports' folder {$this->folder} is not writable.");
        try {
            if ($this->current() !== null) {
                throw ImportRefusedException::on('import', ImportRefusedException::BUSY);
            }
            $import = Import::query()->create([
                'bot_id' => Bot::MAIN,
                'migrator' => $migrator->key(),
                'source' => $source,
                'phase' => ImportPhase::Reading,
                'reviewer' => $reviewer === null ? null : Text::fit($reviewer, self::REVIEWER_MAX),
            ]);
            // Its step's lock until its source is kept: another tab finds it open at once, but steps it only then.
            $held = FileLock::take($this->lockOf($import));
        } finally {
            $lock->release();
        }

        try {
            $keep($import);
        } catch (\Throwable $e) {
            $held?->release();
            $this->discard($import);
            $import->delete();

            throw $e;
        }
        $held?->release();
        $this->logger->info('Import {id} of {migrator} begun from its {source}', ['id' => $import->id, 'migrator' => $import->migrator, 'source' => $source->value]);

        return $import->refresh();
    }

    /**
     * Reading on (Stager::stage()); once everything is staged, the data recognised as its bot's — or the import failed,
     * in the owner's words —, surveyed for the review, and its panels' connections kept encrypted apart from the survey.
     */
    private function read(Import $import, Budget $budget): void
    {
        $migrator = $this->migrators->find($import->migrator);
        if ($migrator === null) {
            $this->fail($import, self::NO_MIGRATOR);

            return;
        }
        if ($import->reading === null) {
            // Its source is still being kept (begin()): nothing to read yet.
            return;
        }
        if ($this->stagedGone($import)) {
            $this->fail($import, self::STAGED_GONE);

            return;
        }
        try {
            $staged = $this->stager->stage($import, $migrator, $budget);
        } catch (ValidationException $e) {
            // The file or the database could not be read on: the owner's words of why.
            $this->fail($import, (string) (array_values($e->errors())[0][0] ?? $e->getMessage()));

            return;
        }
        if (!$staged) {
            return;
        }
        try {
            $schema = $this->stager->schema($import);
            $recognition = $migrator->recognize($schema);
            $survey = $recognition->ok ? $migrator->survey($this->stager->staged($import, $migrator)) : null;
            $instead = $survey === null ? $this->recognizedBy($schema, $migrator) : null;
        } catch (\Throwable $e) {
            $this->broken($import, $e);

            return;
        }
        if ($survey === null) {
            // A data-only export lacks what tells its tables apart: said as that, not as another bot's data.
            $unmapped = array_values(array_filter((array) ($import->reading['unmapped'] ?? []), 'is_string'));
            $label = Run::isolate($migrator->describe()->label);
            $this->fail($import, match (true) {
                $unmapped !== [] => sprintf(ImportRefusedException::NO_STRUCTURE, self::named($unmapped)),
                $instead !== null => sprintf(ImportRefusedException::ANOTHER_BOT, Run::isolate($instead), $label),
                $recognition->missing === [] => sprintf(ImportRefusedException::NOT_ITS, $label),
                default => sprintf(ImportRefusedException::NOT_THIS_BOT, $label, self::named($recognition->missing)),
            });

            return;
        }

        $connections = [];
        foreach ($survey->panels as $panel) {
            $connections[$panel->key] = ServerWriter::kept($panel);
        }
        $reviewed = Transitions::move($import, 'phase', [ImportPhase::Reading], ImportPhase::Review, [
            'survey' => $survey->toArray(),
            'panels' => $this->encrypter->encrypt(json_encode((object) $connections, JSON_THROW_ON_ERROR)),
            'version' => $survey->version ?? $recognition->version,
        ]);
        if ($reviewed) {
            // Read: its file (the whole old database, the bot's token and keys in it) and its connection are not needed again.
            $this->stager->readingDone($import);
        }
        $this->logger->info('Import {id} of {migrator} read: {panels} panels', ['id' => $import->id, 'migrator' => $import->migrator, 'panels' => count($survey->panels)]);
    }

    /** Writing on (Importer); once the last stage is written, done. */
    private function write(Import $import, Budget $budget): void
    {
        $migrator = $this->migrators->find($import->migrator);
        if ($migrator === null) {
            $this->fail($import, self::NO_MIGRATOR);

            return;
        }
        if ($this->stagedGone($import)) {
            $this->fail($import, self::STAGED_GONE);

            return;
        }
        try {
            $done = $this->importer->run($import, $migrator, $this->stager->staged($import, $migrator), $budget);
        } catch (\Throwable $e) {
            $this->broken($import, $e);

            return;
        }
        if ($done) {
            Transitions::move($import, 'phase', [ImportPhase::Importing], ImportPhase::Done, ['finished_at' => now()]);
            $this->logger->info('Import {id} of {migrator} done', ['id' => $import->id, 'migrator' => $import->migrator]);
        }
    }

    /**
     * The migrator could not make what it reads into records (its data is not as it expects): every step would stop at
     * the same place, so the import fails, its cause logged.
     */
    private function broken(Import $import, \Throwable $e): void
    {
        $this->logger->error('Import {id} of {migrator} could not go on: {message}', ['id' => $import->id, 'migrator' => $import->migrator, 'message' => $e->getMessage(), 'exception' => $e]);
        $this->fail($import, self::BROKEN);
    }

    /**
     * Whether the import's staged copy lost rows since they were staged — a restore of the database empties it —: fewer
     * rows than its reading says it staged, or, for an import that keeps no such count, none at all of data the survey
     * counted. Reading or writing on would then quietly leave out what is gone.
     */
    private function stagedGone(Import $import): bool
    {
        $staged = (int) ($import->reading[RowWriter::STAGED] ?? 0);
        if ($staged > 0) {
            return ImportRow::query()->where('import_id', $import->id)->count() < $staged;
        }

        return $import->phase === ImportPhase::Importing
            && array_sum(array_map('intval', (array) ($import->survey['counts'] ?? []))) > 0
            && !ImportRow::query()->where('import_id', $import->id)->exists();
    }

    /**
     * A few tables (or `table.column`) for a refusal, each set apart in the Persian line: the first FEW, then «…».
     *
     * @param list<string> $names
     */
    private static function named(array $names): string
    {
        return implode('، ', array_map(Run::isolate(...), array_slice($names, 0, self::FEW))) . (count($names) > self::FEW ? '، …' : '');
    }

    /**
     * The bot whose data the import read, when the one picked does not take it: another migrator's that does (Faoxima's
     * database told from MirzaBot's, its ancestor's, say); null for none.
     */
    private function recognizedBy(Schema $schema, Migrator $picked): ?string
    {
        foreach ($this->migrators->all() as $migrator) {
            if ($migrator->key() !== $picked->key() && $migrator->recognize($schema)->ok) {
                return $migrator->describe()->label;
            }
        }

        return null;
    }

    /** The import failed, in the owner's words — unless it left reading or importing meanwhile (cancelled). */
    private function fail(Import $import, string $error): void
    {
        if (Transitions::move($import, 'phase', [ImportPhase::Reading, ImportPhase::Importing], ImportPhase::Failed, ['error' => $error, 'finished_at' => now()])) {
            $this->logger->warning('Import {id} of {migrator} failed: {error}', ['id' => $import->id, 'migrator' => $import->migrator, 'error' => $error]);
        }
    }

    /**
     * Everything it kept of the other bot's secrets gone: its staged copy, files and kept connection (Stager::discard()),
     * its panels' connections, and its lock file.
     */
    private function discard(Import $import): void
    {
        $this->stager->discard($import);
        if ($import->panels !== null) {
            Import::query()->whereKey($import->id)->update(['panels' => null]);
            $import->forceFill(['panels' => null])->syncOriginal();
        }
        @unlink($this->lockOf($import));
    }

    private function lockOf(Import $import): string
    {
        return $this->folder . '/' . $import->id . '.lock';
    }

    /**
     * How far it got: the bytes or rows read while reading; the stage under way, how many things of it are written and
     * how many it has, while importing.
     *
     * @return array{stage: string|null, stage_label: string|null, done: int, total: int|null}
     */
    private function progress(Import $import): array
    {
        if ($import->phase === ImportPhase::Reading) {
            ['done' => $done, 'total' => $total] = $this->stager->progress($import);

            return ['stage' => null, 'stage_label' => null, 'done' => $done, 'total' => $total];
        }
        if ($import->phase !== ImportPhase::Importing || $import->stage === null) {
            return ['stage' => null, 'stage_label' => null, 'done' => 0, 'total' => null];
        }
        $done = Run::done($import->counts);
        // A survey that counted fewer than there were never shows more done than in all.
        return ['stage' => $import->stage->value, 'stage_label' => $import->stage->label(), 'done' => $done, 'total' => max(self::total($import->stage, $import->survey ?? []), $done)];
    }

    /**
     * How many things a stage goes through, as the survey counted them.
     *
     * @param array<string, mixed> $survey
     */
    private static function total(ImportStage $stage, array $survey): int
    {
        $counts = is_array($survey['counts'] ?? null) ? $survey['counts'] : [];
        $count = static fn(string ...$kinds): int => array_sum(array_map(static fn(string $kind): int => (int) ($counts[$kind] ?? 0), $kinds));

        return match ($stage) {
            ImportStage::Servers => count(is_array($survey['panels'] ?? null) ? $survey['panels'] : []),
            ImportStage::Groups => count(is_array($survey['groups'] ?? null) ? $survey['groups'] : []),
            ImportStage::Customers, ImportStage::Referrals => $count('customers'),
            ImportStage::Plans => $count('plans'),
            ImportStage::Services => $count('services', 'tests'),
            ImportStage::DiscountCodes => $count('discount_codes'),
            ImportStage::GiftCodes => $count('gift_codes'),
            ImportStage::Channels => $count('channels'),
            ImportStage::Cards => $count('cards'),
        };
    }

    /**
     * The problems by reason, in ProblemReason's order: the rows of import_problems counted, and the reasons only counted.
     *
     * @return array{total: int, by_reason: list<array{reason: string, label: string, count: int}>}
     */
    private static function problemsByReason(Import $import): array
    {
        $rows = $import->problems()->toBase()->selectRaw('reason, COUNT(*) AS aggregate')->groupBy('reason')->pluck('aggregate', 'reason')->all();
        $counted = Run::reasons($import->counts);
        $byReason = [];
        foreach (ProblemReason::cases() as $reason) {
            $count = (int) ($rows[$reason->value] ?? 0) + ($counted[$reason->value] ?? 0);
            if ($count > 0) {
                $byReason[] = ['reason' => $reason->value, 'label' => $reason->label(), 'count' => $count];
            }
        }

        return ['total' => array_sum(array_column($byReason, 'count')), 'by_reason' => $byReason];
    }

    /** @return list<ImportPhase> The phases of an import under way. */
    private static function open(): array
    {
        return array_values(array_filter(ImportPhase::cases(), static fn(ImportPhase $phase): bool => $phase->open()));
    }
}
