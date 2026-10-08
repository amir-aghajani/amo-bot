<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Kernel;
use Slim\Interfaces\RouteInterface;
use Symfony\Component\Console\Command\Command;
use Tests\DatabaseTestCase;

/**
 * Whatever the framework builds from the container on its own is buildable: the controller of every HTTP route (Slim
 * resolves it only when a request reaches it, after the guards — a route no test calls through would show a broken
 * constructor to its first customer), every class routes/bot.php wires (its handlers, gates and the report group's
 * handlers), and every console command (made only when it runs).
 */
final class ContainerWiringTest extends DatabaseTestCase
{
    public function testEveryRoutesControllerIsBuiltWithTheActionItNames(): void
    {
        $routes = $this->app()->http()->getRouteCollector()->getRoutes();
        self::assertNotEmpty($routes);

        foreach ($routes as $route) {
            /** @var RouteInterface $route */
            $callable = $route->getCallable();
            if ($callable instanceof \Closure) {
                continue; // routes/web.php's redirect: nothing to build
            }
            [$class, $method] = is_array($callable) ? $callable : [$callable, '__invoke'];
            $where = implode('|', $route->getMethods()) . ' ' . $route->getPattern();
            self::assertIsString($class, $where);

            $controller = $this->built($class);
            self::assertIsObject($controller, $where);
            self::assertTrue(method_exists($controller, $method), "{$where}: {$class}::{$method}()");
        }
    }

    public function testEveryClassTheBotsRoutesWireIsBuilt(): void
    {
        preg_match_all('/^use (App\\\\[\w\\\\]+);/m', (string) file_get_contents($this->app()->basePath('routes/bot.php')), $imports);
        $built = 0;
        foreach ($imports[1] as $class) {
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                continue;
            }
            self::assertInstanceOf($class, $this->built($class));
            $built++;
        }

        self::assertGreaterThan(15, $built, 'the handlers, the gates and the report group\'s handlers');
    }

    public function testEveryConsoleCommandIsBuilt(): void
    {
        $commands = (new \ReflectionClassConstant(Kernel::class, 'COMMANDS'))->getValue();
        self::assertIsArray($commands);
        self::assertNotEmpty($commands);

        foreach ($commands as $class) {
            self::assertInstanceOf(Command::class, $this->built($class), $class);
        }
    }

    /** What the container builds under a name, as the framework asks it for one. */
    private function built(string $id): mixed
    {
        return $this->app()->container()->get($id);
    }
}
