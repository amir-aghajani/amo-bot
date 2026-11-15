<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits;

use App\Modules\Payments\Deposits\Contracts\DepositSource;
use App\Modules\Payments\Deposits\Sources\ForApp;

/**
 * The phone apps whose forwarded bank SMS the shared deposit webhook takes with the shop's key (Contracts\DepositSource),
 * by their key: ForApp. Rikhtam, the other source of deposits, posts to an address of its own with a key of its own
 * (Payments\Controllers\RikhtamWebhookController).
 */
final class DepositSources
{
    /** @var array<string, DepositSource> */
    private readonly array $sources;

    public function __construct(ForApp $forApp)
    {
        $this->sources = [$forApp->key() => $forApp];
    }

    /** The app the shared webhook takes under that key (Controllers\DepositWebhookController); null for none. */
    public function find(string $key): ?DepositSource
    {
        return $this->sources[$key] ?? null;
    }

    /** @return list<DepositSource> The apps the shared webhook takes, each given the shop's key by its owner. */
    public function all(): array
    {
        return array_values($this->sources);
    }
}
