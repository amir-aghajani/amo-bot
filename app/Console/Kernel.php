<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\BotInfoCommand;
use App\Console\Commands\BotPollCommand;
use App\Console\Commands\BotWebhookDeleteCommand;
use App\Console\Commands\BotWebhookSetCommand;
use App\Console\Commands\DbRebuildCommand;
use App\Console\Commands\PanelProbeCommand;
use App\Console\Commands\ScheduleRunCommand;
use App\Core\Application;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;

/**
 * Builds the `bin/console` application. Register new commands in the list below.
 *
 * Each command is made only when it runs — its name and description are read off its #[AsCommand] — so the minute's
 * `schedule:run` does not build the poller, the bot's dispatcher and the rest along with it.
 */
final class Kernel
{
    /** @var list<class-string<Command>> */
    private const COMMANDS = [
        DbRebuildCommand::class,
        BotInfoCommand::class,
        BotPollCommand::class,
        BotWebhookSetCommand::class,
        BotWebhookDeleteCommand::class,
        PanelProbeCommand::class,
        ScheduleRunCommand::class,
    ];

    public static function create(Application $app): ConsoleApplication
    {
        $container = $app->container();
        $factories = [];
        foreach (self::COMMANDS as $class) {
            $command = ((new \ReflectionClass($class))->getAttributes(AsCommand::class)[0] ?? throw new \LogicException("{$class} has no #[AsCommand]."))->newInstance();
            $factories[$command->name] = static fn(): Command => new LazyCommand($command->name, [], (string) $command->description, false, static fn(): Command => $container->get($class));
        }

        $console = new ConsoleApplication(Application::NAME, Application::VERSION);
        $console->setCommandLoader(new FactoryCommandLoader($factories));

        return $console;
    }
}
