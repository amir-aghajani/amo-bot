<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Scheduling\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point. Add to crontab:  * * * * * php /path/to/bin/console schedule:run >> /dev/null 2>&1
 */
#[AsCommand(name: 'schedule:run', description: 'Run the scheduled tasks that are due')]
final class ScheduleRunCommand extends Command
{
    public function __construct(private readonly Scheduler $scheduler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Run every task regardless of its interval');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ran = $this->scheduler->run((bool) $input->getOption('force'));

        if ($ran === []) {
            $io->writeln('No tasks due.', OutputInterface::VERBOSITY_VERBOSE);
        } else {
            foreach ($ran as $task) {
                $io->writeln("  <info>✔</info> {$task}");
            }
        }

        return Command::SUCCESS;
    }
}
