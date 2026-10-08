<?php

declare(strict_types=1);

namespace App\Modules\Providers\Contracts;

use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\InboundInfo;
use App\Modules\Providers\DTO\PanelStatus;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;

/**
 * What every panel connector's client (3x-ui, PasarGuard, …) offers the rest of the shop. One instance talks to one
 * server's panel: its connector builds it (PanelDriver::connect()) and ProviderRegistry::forServer() keeps it a while, so
 * a session or token it signed in for serves the calls that follow.
 *
 * Clients are addressed by their panel-wide name (ClientSpec::$name on creation, the same string afterwards); inbounds
 * by the opaque key the driver reports on InboundInfo. What the customer gets is the subscription link the panel serves
 * for the client (ClientInfo::$subscriptionUrl) and nothing else.
 *
 * Every method throws a ProviderException (or a subclass) on failure; a client that is not there is a
 * NotFoundException where a return value cannot say so.
 */
interface ProviderInterface
{
    /**
     * Verify credentials and reachability.
     *
     * @throws ProviderException
     */
    public function testConnection(): void;

    /**
     * Host health for the servers screen.
     *
     * @throws UnsupportedOperationException when the panel reports no host statistics
     * @throws ProviderException
     */
    public function status(): PanelStatus;

    /**
     * Empty for a driver without inbounds (PanelDriver::capabilities()).
     *
     * @return list<InboundInfo>
     * @throws ProviderException
     */
    public function listInbounds(): array;

    /**
     * Whether the panel hands out subscription links right now — the one thing a customer gets, so a panel without them
     * cannot be sold.
     *
     * @throws ProviderException
     */
    public function servesSubscriptions(): bool;

    /**
     * Create a client attached to the given inbounds — every key at once, so the customer's one link carries them all.
     * Empty only for a driver without inbounds. Returns the client as the panel has it.
     *
     * @param list<string> $inboundKeys
     * @throws ProviderException
     */
    public function createClient(array $inboundKeys, ClientSpec $spec): ClientInfo;

    /**
     * Replace the limits and expiry of the client `$spec->name`, switched on; the panel keeps its credentials. Returns
     * the client as the panel has it afterwards, its counters included.
     *
     * @throws NotFoundException
     * @throws ProviderException
     */
    public function updateClient(ClientSpec $spec): ClientInfo;

    /**
     * Give the client fresh credentials and a fresh subscription link, so every link and config handed out so far stops
     * working; limits, expiry, counters and attachments stay. Returns the client as the panel has it afterwards.
     *
     * @throws UnsupportedOperationException when the panel cannot (PanelDriver::capabilities())
     * @throws NotFoundException
     * @throws ProviderException
     */
    public function rotateClientCredentials(string $name): ClientInfo;

    /**
     * @throws NotFoundException
     * @throws ProviderException
     */
    public function setClientEnabled(string $name, bool $enabled): void;

    /**
     * Take the client off the panel.
     *
     * @throws NotFoundException when the panel has no such client (gone already — callers treat it as done)
     * @throws ProviderException
     */
    public function deleteClient(string $name): void;

    /**
     * The client with its live counters, or null when the panel no longer has it. Whether it is connected right now
     * (ClientInfo::$online) is answered only when asked for (`$presence`: the customer's own screen) — a panel may need
     * another call for it.
     *
     * @throws ProviderException
     */
    public function findClient(string $name, bool $presence = false): ?ClientInfo;

    /**
     * Every client on the panel as findClient() answers it, presence aside — what the shop's periodic sync reads: one
     * call for a whole server instead of one per service.
     *
     * @return list<ClientInfo>
     * @throws UnsupportedOperationException when the panel cannot list its clients at once (the sync then asks for each)
     * @throws ProviderException
     */
    public function listClients(): array;

    /** @throws ProviderException */
    public function resetClientTraffic(string $name): void;
}
