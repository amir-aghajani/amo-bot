<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Database\Drivers\DatabaseDriver;
use App\Core\Database\Drivers\ProbeFailedException;
use App\Core\Database\Drivers\ProbeResult;
use App\Core\Drivers\Descriptor;
use Illuminate\Database\Schema\Builder;

/**
 * A real driver whose probe the test answers from a queue instead of a database server: TestCase puts one in the
 * registry for every database driver as the app boots, each passing everything to the real driver until a test answers
 * its probes (probedDatabase()) — the installer's and the settings screen's tests drive the driver's own form and
 * config.php settings, and decide what the probe says, and which PHP extensions it needs. Every probe answered is
 * recorded with the settings it was asked about. Back to the real driver's answers after every test (reset()).
 */
final class ProbedDriver implements DatabaseDriver
{
    /** @var list<ProbeResult|ProbeFailedException> Next answers, in order; a database with no tables once they run out */
    public array $answers = [];

    /** A database that does not answer at all: every probe fails with this, whatever is queued. */
    public ?ProbeFailedException $down = null;

    /** @var list<array<string, mixed>> */
    public array $probed = [];

    /** @var list<string>|null The PHP extensions it needs in place of the real driver's (one PHP lacks, say); null keeps them */
    public ?array $extensions = null;

    /** Offered by the installer or not, whatever the real driver says; null keeps it. */
    public ?bool $installable = null;

    /** Whether the test answers the probes: until it does, each goes to the real driver. */
    private bool $answering = false;

    public function __construct(private readonly DatabaseDriver $driver) {}

    /** From now on the test answers its probes. */
    public function answer(): self
    {
        $this->answering = true;

        return $this;
    }

    /** The real driver's answers again, nothing queued or recorded. */
    public function reset(): void
    {
        $this->answering = false;
        $this->answers = [];
        $this->down = null;
        $this->probed = [];
        $this->extensions = null;
        $this->installable = null;
    }

    public function key(): string
    {
        return $this->driver->key();
    }

    public function describe(): Descriptor
    {
        $real = $this->driver->describe();

        return $this->installable === null ? $real : new Descriptor($real->key, $real->label, $real->description, $real->form, $real->notes, [self::INSTALLABLE => $this->installable] + $real->traits);
    }

    public function extensions(): array
    {
        return $this->extensions ?? $this->driver->extensions();
    }

    public function connection(array $config): array
    {
        return $this->driver->connection($config);
    }

    public function probe(array $config): ProbeResult
    {
        if (!$this->answering) {
            return $this->driver->probe($config);
        }
        $this->probed[] = $config;
        if ($this->down !== null) {
            throw $this->down;
        }
        $answer = array_shift($this->answers) ?? new ProbeResult('MariaDB 10.4.32', 0);
        if ($answer instanceof ProbeFailedException) {
            throw $answer;
        }

        return $answer;
    }

    public function localDate(string $column, int $offset): string
    {
        return $this->driver->localDate($column, $offset);
    }

    public function releaseNames(Builder $schema, string $table): void
    {
        $this->driver->releaseNames($schema, $table);
    }
}
