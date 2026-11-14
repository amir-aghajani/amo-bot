<?php

declare(strict_types=1);

namespace App\Modules\Imports\Records;

/**
 * A VPN panel the other bot sold on (Survey::$panels): the owner maps it to one of this shop's servers, has it added as a
 * new one, or leaves its services behind. Its services are found on the server it maps to by their client names.
 */
final class ImportedPanel
{
    /** @param array<string, mixed> $connection */
    public function __construct(
        /** What the other bot's services name it by (its own panel name or id): ImportedService::$panelKey. */
        public readonly string $key,
        public readonly string $name,
        /** The other bot's word for its kind ('marzban', 'x-ui_single' …), shown as it is. */
        public readonly string $type,
        /** The panel's address, for the owner to recognise it and the core to suggest a server on the same one. */
        public readonly ?string $url,
        /** The panel connector of this shop that speaks to it (PanelDriver::key()); null when none does. */
        public readonly ?string $driver,
        /** Why none does, in the owner's words, when `$driver` is null. */
        public readonly ?string $unsupported,
        /**
         * The server form's values that add it as a server of this shop (`driver`'s form, as ServerService takes them:
         * `base_url`, `username`, `password`, `api_token` …) — its secrets in it, so it never leaves the server; empty
         * when it cannot be added.
         */
        public readonly array $connection,
        /** Its services the import would bring (their clients still on it). */
        public readonly int $services,
        /** The other bot sold on it: one it had closed to new sales comes as a server switched off. */
        public readonly bool $active = true,
        /** The most services the other bot sold on it (servers.capacity); null for no limit. */
        public readonly ?int $capacity = null,
    ) {}

    /** Whether the core can add it as a server of this shop. */
    public function addable(): bool
    {
        return $this->driver !== null && $this->connection !== [];
    }
}
