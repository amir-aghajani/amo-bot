<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bots\BotOutcome;
use App\Modules\Telegram\Services\BotWebhooks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Takes the shop off webhooks, back to polling: BotWebhooks::disable(), which the owner's panel runs too. */
#[AsCommand(name: 'bot:webhook:delete', description: 'Remove every bot\'s webhook (bot:poll does it too as it starts)')]
final class BotWebhookDeleteCommand extends EveryBotCommand
{
    public function __construct(private readonly BotWebhooks $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('drop-pending', null, InputOption::VALUE_NONE, 'Discard queued updates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outcomes = $this->webhooks->disable((bool) $input->getOption('drop-pending'));

        return self::report(new SymfonyStyle($input, $output), $outcomes, static fn(BotOutcome $outcome): string => self::name($outcome->bot) . ': webhook removed.') ? Command::SUCCESS : Command::FAILURE;
    }
}
