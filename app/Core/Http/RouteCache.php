<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Application;
use App\Core\Support\Files;
use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector as FastRouteCollector;
use FastRoute\RouteParser\Std;
use Slim\Interfaces\RouteCollectorInterface;

/**
 * The router's compiled table kept in a file (storage/cache/routes): Slim would otherwise compile every route's pattern
 * on each request. The file is named after the version, the prefix and each route file's time and size, so any change
 * makes a new one; old ones go after KEEP_OLD_SECONDS. Written atomically; one that does not read back is dropped; with
 * no writable folder nothing is kept.
 */
final class RouteCache
{
    /** How long a table made from earlier routes is kept beside the current one. */
    public const KEEP_OLD_SECONDS = 3600;

    /** @param string|null $directory Where the table is kept (the container's `routes.cache`); null keeps none */
    public function __construct(private readonly ?string $directory) {}

    /**
     * Hand the router its table from the file, made from its routes when there is none yet.
     *
     * @param list<string> $sources The files the routes are made from
     */
    public function apply(RouteCollectorInterface $routes, array $sources): void
    {
        if ($this->directory === null) {
            return;
        }

        $file = $this->directory . '/routes-' . self::key($routes->getBasePath(), $sources) . '.php';
        if (is_file($file) ? !self::readable($file) : !$this->write($file, $routes)) {
            return;
        }

        $routes->setCacheFile($file);
    }

    /** @param list<string> $sources */
    private static function key(string $basePath, array $sources): string
    {
        $made = [Application::VERSION, $basePath];
        foreach ($sources as $source) {
            $made[] = $source . ' ' . (Files::modifiedAt($source) ?? 0) . ' ' . (is_file($source) ? (int) filesize($source) : 0);
        }

        return hash('xxh128', implode("\n", $made));
    }

    /** Whether the file holds a table; one that does not is deleted, for the next request to make again. */
    private static function readable(string $file): bool
    {
        if (is_array(Files::load($file))) {
            return true;
        }
        Files::delete($file);

        return false;
    }

    /** The table made as Slim makes it (its parser, generator and route identifiers) and kept; false when it cannot be. */
    private function write(string $file, RouteCollectorInterface $routes): bool
    {
        $table = new FastRouteCollector(new Std(), new GroupCountBased());
        foreach ($routes->getRoutes() as $route) {
            $table->addRoute($route->getMethods(), $routes->getBasePath() . $route->getPattern(), $route->getIdentifier());
        }

        try {
            Files::writeAtomically($file, '<?php return ' . var_export($table->getData(), true) . ";\n");
        } catch (\RuntimeException) {
            return false;
        }

        // The tables of earlier routes, versions or prefixes — once nobody can be reading one: a request that started
        // before an upgrade may still be about to.
        foreach (glob($this->directory . '/routes-*.php') ?: [] as $old) {
            if ($old !== $file && (Files::modifiedAt($old) ?? 0) < time() - self::KEEP_OLD_SECONDS) {
                Files::delete($old);
            }
        }

        return true;
    }
}
