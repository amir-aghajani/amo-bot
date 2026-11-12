<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\Models\Bot;

/**
 * What a backup is of: the whole installation — every shop, the servers, the agents' terms: the owner's, from their
 * panel in the main shop — or one shop — an agent's, from their panel or from the owner's in that shop —, its own rows
 * alone (BackupTables).
 */
final readonly class BackupScope
{
    private function __construct(
        /** The shop; null for the whole installation */
        public ?int $shop,
    ) {}

    public static function installation(): self
    {
        return new self(null);
    }

    /** One agent's shop. The main shop is backed up and restored only with the whole installation: the owner's rows name its own. */
    public static function shop(int $bot): self
    {
        if ($bot === Bot::MAIN) {
            throw new \LogicException('The main shop is backed up with the whole installation.');
        }

        return new self($bot);
    }

    /** The scope a panel works in: the owner in the main shop backs up the whole installation; in any other shop, that shop. */
    public static function for(Principal $principal): self
    {
        return $principal->kind === PrincipalKind::Owner && $principal->shop->id === Bot::MAIN ? self::installation() : self::shop($principal->shop->id);
    }

    public function isShop(): bool
    {
        return $this->shop !== null;
    }

    /** The scope's word in a file's header and name. */
    public function kind(): string
    {
        return $this->isShop() ? 'shop' : 'installation';
    }

    public function equals(self $other): bool
    {
        return $this->shop === $other->shop;
    }
}
