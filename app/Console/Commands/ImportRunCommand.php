<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\Sleeper;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Models\Import;
use App\Support\Input;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An import from another bot worked from a shell, for an owner who has one (the panel works it a request at a time
 * without): read through to its review, or written through to its end, a step at a time with its progress printed after
 * each. While another step holds the import (the panel's, another import:run) it waits for it, a while. The review —
 * what to bring, where each panel's services go — is the panel's.
 */
#[AsCommand(name: 'import:run', description: 'Work an import from another bot through to its review or its end (the panel does it a step a request)')]
final class ImportRunCommand extends Command
{
    /** Seconds to wait for another step holding the import before trying again. */
    public const WAIT_SECONDS = 5;

    /** How many times it waits before giving up on that step: a few minutes. */
    public const WAITS = 60;

    /** @param (\Closure(): Budget)|null $budget How long each step works: Imports::STEP_SECONDS, or a test's few rows */
    public function __construct(
        private readonly Imports $imports,
        private readonly Sleeper $sleeper,
        private readonly ?\Closure $budget = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The import\'s number (its `id` in GET /api/admin/imports)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = Input::integerOf($input->getArgument('id')) ?? 0;
        try {
            $import = $this->imports->find($id);
        } catch (ModelNotFoundException) {
            $io->error("No import #{$id}.");

            return Command::INVALID;
        }

        $waits = 0;
        while (self::stepping($import)) {
            if ($this->imports->advance($import, $this->budget === null ? Budget::seconds(Imports::STEP_SECONDS) : ($this->budget)())) {
                $waits = 0;
                if (self::stepping($import)) {
                    $io->writeln('  ' . self::progress($this->imports->present($import)));
                }

                continue;
            }
            if ($waits === self::WAITS) {
                $io->warning(sprintf('Another step of import #%d is still running after %d minutes. Run this again once it has finished.', $id, intdiv(self::WAITS * self::WAIT_SECONDS, 60)));

                return Command::FAILURE;
            }
            if ($waits++ === 0) {
                $io->writeln("  Another step of import #{$id} is running (the panel's, or another import:run): waiting for it …");
            }
            $this->sleeper->sleep(self::WAIT_SECONDS);
        }

        if ($import->phase === ImportPhase::Review) {
            // Read and surveyed: the owner's choices come next, in the panel.
            $io->success("Import #{$id} is read. Review it in the owner's panel (Settings, «انتقال از ربات دیگر») and start it there; the panel then writes it, or run import:run {$id} again.");

            return Command::SUCCESS;
        }
        if ($import->phase === ImportPhase::Done) {
            self::report($io, $this->imports->present($import));

            return Command::SUCCESS;
        }

        $import->phase === ImportPhase::Failed ? $io->error($import->error ?? 'The import failed.') : $io->warning("Import #{$id} was cancelled.");

        return Command::FAILURE;
    }

    /**
     * A written import's report: what each kind came to, and its problems by reason.
     *
     * @param array<string, mixed> $state Imports::present()
     */
    private static function report(SymfonyStyle $io, array $state): void
    {
        /** @var array<string, array{created: int, linked: int, skipped: int}> $counts */
        $counts = $state['counts'] ?? [];
        if ($counts !== []) {
            $io->table(['Kind', 'Created', 'Linked', 'Skipped'], array_map(
                static fn(string $kind, array $count): array => [$kind, $count['created'], $count['linked'], $count['skipped']],
                array_keys($counts),
                $counts,
            ));
        }

        /** @var array{total: int, by_reason: list<array{reason: string, label: string, count: int}>} $problems */
        $problems = $state['problems'];
        foreach ($problems['by_reason'] as $reason) {
            $io->writeln("  {$reason['reason']}: {$reason['count']} ({$reason['label']})");
        }
        $io->success(sprintf('Import #%d is done%s. Its report is in the owner\'s panel.', $state['id'], $problems['total'] > 0 ? ", with {$problems['total']} problem(s)" : ''));
    }

    /** Whether the import goes on by steps: reading, or importing. */
    private static function stepping(Import $import): bool
    {
        return $import->phase === ImportPhase::Reading || $import->phase === ImportPhase::Importing;
    }

    /**
     * Where a step left the import, in a line: reading, the bytes or rows read; importing, the stage and its things
     * written.
     *
     * @param array<string, mixed> $state Imports::present()
     */
    private static function progress(array $state): string
    {
        /** @var array{stage: string|null, done: int, total: int|null} $progress */
        $progress = $state['progress'];
        $where = $progress['stage'] === null ? 'Read' : "Importing {$progress['stage']}:";

        return sprintf('%s %d%s', $where, $progress['done'], $progress['total'] === null ? '' : " of {$progress['total']}");
    }
}
