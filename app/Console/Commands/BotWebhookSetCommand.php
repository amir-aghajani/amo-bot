<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Config\Repository as Config;
use App\Modules\Bots\BotOutcome;
use App\Modules\Telegram\Exceptions\WebhookAddressException;
use App\Modules\Telegram\Services\BotWebhooks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Puts the shop on webhooks: every bot's registered at its address under APP_URL (Http\Urls) — BotWebhooks::enable(),
 * which the owner's panel runs too. An agent's bot Telegram refuses gets the reason on its row, as the poller gives it.
 */
#[AsCommand(name: 'bot:webhook:set', description: 'Register every bot\'s webhook (the main one at APP_URL/webhooks/telegram/{secret})')]
final class BotWebhookSetCommand extends EveryBotCommand
{
    public function __construct(
        private readonly BotWebhooks $webhooks,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Use this public base URL instead of APP_URL')
            ->addOption('drop-pending', null, InputOption::VALUE_NONE, 'Discard updates queued while the webhook was down');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hadSecret = (string) $this->config->get('telegram.webhook_secret', '') !== '';
        $baseUrl = is_string($input->getOption('url')) && $input->getOption('url') !== '' ? $input->getOption('url') : null;

        try {
            $outcomes = $this->webhooks->enable($baseUrl, (bool) $input->getOption('drop-pending'));
        } catch (WebhookAddressException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($outcomes as $outcome) {
            if ($outcome->bot->isMain() && $outcome->failure === null && !$hadSecret) {
                $io->note('Generated TELEGRAM_WEBHOOK_SECRET and saved it to config.php');
            }
        }

        return self::report($io, $outcomes, static fn(BotOutcome $outcome): string => sprintf('%s: webhook set to %s', self::name($outcome->bot), $outcome->result)) ? Command::SUCCESS : Command::FAILURE;
    }
}
