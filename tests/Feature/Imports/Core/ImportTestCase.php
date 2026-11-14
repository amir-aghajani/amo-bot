<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Core\Drivers\Registry;
use App\Core\Security\Encrypter;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportProblem;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Writers\Run;
use App\Modules\Imports\Writers\ServerWriter;
use Tests\DatabaseTestCase;
use Tests\Fakes\ArrayStaged;
use Tests\Fakes\FakeMigrator;
use Tests\Fakes\FakeProvider;

/**
 * What the core's tests share: the fake migrator (FakeMigrator) as the one bot an import reads (the imports' folder the
 * run's own, as every file the app writes in a test), and imports made in review from the migrator's survey — as reading would have left
 * them —, brought in with choices, step by step or at once.
 */
abstract class ImportTestCase extends DatabaseTestCase
{
    protected FakeMigrator $migrator;

    /** The imports' folder (storage/imports in a shop): the run's own. */
    protected string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrator = new FakeMigrator();
        $this->folder = (string) $this->app()->container()->get('imports.path');
        $this->swap('import.migrators', new Registry([$this->migrator]), Imports::class);
    }

    protected function imports(): Imports
    {
        return $this->service(Imports::class);
    }

    /** An import of the fake bot in review: its survey the migrator's, its panels' connections kept encrypted. */
    protected function reviewed(): Import
    {
        $survey = $this->migrator->survey(new ArrayStaged([]));
        $connections = [];
        foreach ($survey->panels as $panel) {
            $connections[$panel->key] = ServerWriter::kept($panel);
        }

        $import = Import::query()->create([
            'bot_id' => 1,
            'migrator' => FakeMigrator::KEY,
            'source' => ImportSource::Backup,
            'phase' => ImportPhase::Review,
            'file_name' => 'backup.sql',
            'survey' => $survey->toArray(),
            'panels' => $this->service(Encrypter::class)->encrypt((string) json_encode((object) $connections)),
            'version' => $survey->version,
        ]);
        // Its staged copy, which the fake migrator does not read: what an import that read something has.
        ImportRow::query()->create(['import_id' => $import->id, 'table_name' => FakeMigrator::TABLE, 'data' => '{"id":"1","name":"Ali","secret":null}']);

        return $import;
    }

    /**
     * The fake bot's data brought in at once: an import in review, `$choices` saved (on Choices' defaults, each panel
     * skipped unless named), started, and stepped through with no budget.
     *
     * @param array<string, mixed> $choices
     */
    protected function importAll(array $choices = [], ?Budget $budget = null): Import
    {
        $import = $this->started($choices);

        return $this->step($import, $budget ?? Budget::unlimited());
    }

    /** One step, as the panel's «continue» takes it (Imports::advance()): the import as it then stands. */
    protected function step(Import $import, Budget $budget): Import
    {
        $this->imports()->advance($import, $budget);

        return $import;
    }

    /**
     * An import in review, `$choices` saved and started — nothing written yet.
     *
     * @param array<string, mixed> $choices
     */
    protected function started(array $choices = []): Import
    {
        $import = $this->reviewed();
        $panels = [];
        foreach ($this->migrator->panels as $panel) {
            $panels[$panel->key] = ['action' => Choices::SKIP];
        }
        $named = is_array($choices['panels'] ?? null) ? $choices['panels'] : [];
        $this->imports()->choose($import, ['panels' => $named + $panels] + $choices);

        return $this->imports()->start($import);
    }

    /**
     * A panel of the fake bot on the fake connector, its services found on the server it maps to.
     *
     * @param array<string, mixed> $connection
     */
    protected static function fakePanelOf(string $key, string $name = 'آلمان', int $services = 1, array $connection = [], ?string $url = null): ImportedPanel
    {
        return new ImportedPanel($key, $name, 'x-ui_single', $url ?? 'https://' . $key . '.test', FakeProvider::driver(), null, $connection, $services);
    }

    /** @return array<string, array{created: int, linked: int, skipped: int}> */
    protected static function counts(Import $import): array
    {
        return Run::tally($import->counts) ?? [];
    }

    /** @return list<array{kind: string, key: string, reason: string, detail: string|null}> The import's problem rows, in order. */
    protected static function problemRows(Import $import): array
    {
        return array_values(ImportProblem::query()->where('import_id', $import->id)->orderBy('id')->get()->map(static fn(ImportProblem $problem): array => [
            'kind' => $problem->kind,
            'key' => $problem->source_key,
            'reason' => $problem->reason->value,
            'detail' => $problem->detail,
        ])->all());
    }
}
