<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Services\BotLifecycle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'bot:info', description: 'Show every bot\'s identity and webhook status, as Telegram reports them')]
final class BotInfoCommand extends EveryBotCommand
{
    public function __construct(
        private readonly BotApi $api,
        private readonly Bots $bots,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->api->hasToken()) {
            $io->error(BotLifecycle::NO_TOKEN);

            return Command::FAILURE;
        }

        $outcomes = $this->bots->eachServing(function (Bot $bot) use ($io): void {
            [$me, $webhook] = [$this->api->getMe(), $this->api->getWebhookInfo()];

            $io->section(self::name($bot));
            $io->definitionList(
                ['Bot' => '@' . ($me['username'] ?? '?') . ' (' . ($me['first_name'] ?? '') . ')'],
                ['Id' => (string) ($me['id'] ?? '')],
                ['Webhook URL' => ($webhook['url'] ?? '') !== '' ? $webhook['url'] : '<comment>not set (polling mode)</comment>'],
                ['Pending updates' => (string) ($webhook['pending_update_count'] ?? 0)],
                ['Last error' => (string) ($webhook['last_error_message'] ?? '-')],
            );
        });

        return self::report($io, $outcomes) ? Command::SUCCESS : Command::FAILURE;
    }
}
