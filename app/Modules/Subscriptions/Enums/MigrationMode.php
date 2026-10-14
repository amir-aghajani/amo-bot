<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/**
 * How a server's services are moved (Services\ServerMigrations): «مهاجرت» while the source's panel answers — each client
 * read there, made on the target with what it has left, deleted there —, or «نجات» when it does not — made on the
 * target from the shop's own last copy, the old client left there and written down for removal once it answers.
 */
enum MigrationMode: string
{
    case Migrate = 'migrate';
    case Rescue = 'rescue';

    /** The mode's name, as the screens and the report group say it. */
    public function label(): string
    {
        return match ($this) {
            self::Migrate => 'مهاجرت',
            self::Rescue => 'نجات',
        };
    }
}
