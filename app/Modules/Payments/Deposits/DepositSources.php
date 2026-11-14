<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits;

use App\Modules\Payments\Deposits\Contracts\DepositSource;
use App\Modules\Payments\Deposits\Sources\ForApp;

/** The phone apps whose forwarded bank SMS the shop takes (Contracts\DepositSource), by their key: ForApp. */
final class DepositSources
{
    /** @var array<string, DepositSource> */
    private readonly array $sources;

    public function __construct(ForApp $forApp)
    {
        $this->sources = [$forApp->key() => $forApp];
    }

    public function find(string $key): ?DepositSource
    {
        return $this->sources[$key] ?? null;
    }

    /** @return list<DepositSource> */
    public function all(): array
    {
        return array_values($this->sources);
    }
}
