<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Database\Schema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Brings a database to database/schema.php after an edit — the step migrations would be, before the first release.
 */
#[AsCommand(name: 'db:rebuild', description: 'Make every table again from database/schema.php, carrying the rows over')]
final class DbRebuildCommand extends Command
{
    public function __construct(private readonly Schema $schema)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln([
            'Every table is made again from database/schema.php and its rows copied back: a column the schema dropped goes with its values.',
            'Stop the bot first — its requests fail while the tables are being made.',
        ]);
        if (!$input->getOption('force') && !$io->confirm('Rebuild the database?', false)) {
            $io->writeln('Nothing done.');

            return Command::FAILURE;
        }

        try {
            $tables = $this->schema->rebuild();
        } catch (\Throwable $e) {
            $io->error(['The rebuild stopped: ' . $e->getMessage(), 'Fix database/schema.php if it is the cause and run db:rebuild again: it picks up where this one stopped, and the old rows wait in the <table>' . Schema::ASIDE . ' tables until then.']);

            return Command::FAILURE;
        }

        foreach ($tables as $table) {
            $changes = array_filter([
                $table['added'] !== [] ? 'new: ' . implode(', ', $table['added']) : null,
                $table['dropped'] !== [] ? 'dropped: ' . implode(', ', $table['dropped']) : null,
            ]);
            $io->writeln(sprintf(
                '  %s: %s%s',
                $table['table'],
                match ($table['rows']) {
                    null => 'new table',
                    1 => '1 row',
                    default => $table['rows'] . ' rows',
                },
                $changes === [] ? '' : ' (' . implode('; ', $changes) . ')',
            ));
        }
        $io->success('The database follows database/schema.php.');

        return Command::SUCCESS;
    }
}
