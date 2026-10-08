<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\ScheduleRunCommand;
use App\Console\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

use function DI\factory;

/**
 * bin/console: every command of app/Console/Commands under the name and the words its #[AsCommand] gives it — each made
 * only when it runs, so the minute's `schedule:run` does not build the poller, the bot's dispatcher and the rest.
 */
final class KernelTest extends TestCase
{
    /** @var list<class-string<Command>> The commands the container was asked to make, in order */
    private array $made = [];

    public function testEveryCommandIsListedUnderItsNameAndNoneIsMadeForIt(): void
    {
        $this->recordMaking();
        $console = $this->app()->console();
        self::assertSame($console, $this->app()->console(), 'built once a process');

        $console->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(Command::SUCCESS, $console->run(new ArrayInput(['command' => 'list']), $output));

        $listed = $output->fetch();
        foreach (self::commands() as $command) {
            self::assertTrue($console->has($command->name), $command->name);
            self::assertMatchesRegularExpression('/^\s+' . preg_quote($command->name, '/') . '\s+' . preg_quote((string) $command->description, '/') . '$/m', $listed);
        }
        self::assertSame([], $this->made, 'listing them made none');
    }

    public function testACommandIsMadeWhenItRunsAndNoOtherWithIt(): void
    {
        $this->recordMaking();
        $console = Kernel::create($this->app());
        $console->setAutoExit(false);

        $output = new BufferedOutput();
        self::assertSame(Command::SUCCESS, $console->run(new ArrayInput(['command' => 'schedule:run']), $output));

        self::assertSame([ScheduleRunCommand::class], $this->made);
        self::assertSame('ran', $output->fetch());
    }

    /** Every command the container makes from now on is noted, and stands in for the real one: it only says it ran. */
    private function recordMaking(): void
    {
        foreach (array_keys(self::commands()) as $class) {
            $this->swap($class, factory(function () use ($class): Command {
                $this->made[] = $class;

                return new class extends Command {
                    protected function execute(InputInterface $input, OutputInterface $output): int
                    {
                        $output->write('ran');

                        return Command::SUCCESS;
                    }
                };
            }));
        }
    }

    /** @return array<class-string<Command>, AsCommand> Every command class of app/Console/Commands, with its attribute */
    private static function commands(): array
    {
        $commands = [];
        foreach (glob(dirname(__DIR__, 3) . '/app/Console/Commands/*.php') ?: [] as $file) {
            $class = 'App\\Console\\Commands\\' . basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Command::class)) {
                continue;
            }
            $attribute = $reflection->getAttributes(AsCommand::class)[0] ?? self::fail("{$class} has no #[AsCommand]: bin/console cannot list it.");
            $commands[$class] = $attribute->newInstance();
        }
        self::assertNotEmpty($commands);

        return $commands;
    }
}
