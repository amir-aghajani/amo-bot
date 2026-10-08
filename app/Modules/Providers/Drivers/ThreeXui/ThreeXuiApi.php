<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui;

use App\Modules\Providers\Drivers\ThreeXui\DTO\ClientPayload;
use App\Modules\Providers\Drivers\ThreeXui\DTO\ClientRecord;
use App\Modules\Providers\Drivers\ThreeXui\DTO\Inbound;
use App\Modules\Providers\Drivers\ThreeXui\DTO\PanelSettings;
use App\Modules\Providers\Drivers\ThreeXui\DTO\ServerStatus;
use App\Modules\Providers\Drivers\ThreeXui\DTO\Traffic;
use App\Modules\Providers\Drivers\ThreeXui\Support\Raw;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;

/**
 * The 3x-ui v3 endpoints the shop calls, and only those — each held to the panel's own description by
 * ThreeXuiContractTest — in 3x-ui's terms: clients are first-class rows addressed by email, attached to any number of
 * inbounds (by id); a client the panel does not have is a NotFoundException however the endpoint words it.
 */
final class ThreeXuiApi
{
    private const CLIENTS = '/panel/api/clients';

    public function __construct(private readonly ThreeXuiClient $client) {}

    /**
     * GET /panel/api/inbounds/options — the cheapest call that needs an admin's rights, so a token that only monitors
     * fails here instead of at the first sale.
     *
     * @throws ProviderException
     */
    public function inboundOptions(): void
    {
        $this->client->get('/panel/api/inbounds/options');
    }

    /**
     * GET /panel/api/inbounds/list/slim — every inbound, its clients stripped to their email and switch: no client
     * secret crosses the wire.
     *
     * @return list<Inbound>
     * @throws ProviderException
     */
    public function inbounds(): array
    {
        return array_map(Inbound::fromArray(...), Raw::objectsOf($this->client->get('/panel/api/inbounds/list/slim')));
    }

    /** @throws ProviderException */
    public function status(): ServerStatus
    {
        return ServerStatus::fromArray(Raw::objectOf($this->client->get('/panel/api/server/status')));
    }

    /** @throws ProviderException */
    public function settings(): PanelSettings
    {
        return new PanelSettings(Raw::objectOf($this->client->post('/panel/api/setting/all')));
    }

    /**
     * GET /clients/list — every client, each with its traffic block.
     *
     * @return list<ClientRecord>
     * @throws ProviderException
     */
    public function clients(): array
    {
        return array_map(ClientRecord::fromArray(...), Raw::objectsOf($this->client->get(self::CLIENTS . '/list')));
    }

    /**
     * GET /clients/get/{email} — the full row, secrets included, or null when the panel has none. The panel wraps it
     * (`{client: {…the row…}, inboundIds, externalLinks, …}`), unlike /list's flat rows; a flat answer reads too.
     *
     * @throws ProviderException
     */
    public function client(string $email): ?ClientRecord
    {
        try {
            $raw = Raw::objectOf($this->client->get(self::CLIENTS . '/get/' . rawurlencode($email)));
        } catch (ThreeXuiApiException $e) {
            if (self::isNotFound($e)) {
                return null;
            }

            throw $e;
        }

        $row = Raw::nullableArray($raw, 'client') ?? $raw;

        return $row !== [] ? ClientRecord::fromArray($row) : null;
    }

    /**
     * GET /clients/traffic/{email} — the counters of a row that came without them; null when the panel keeps none.
     *
     * @throws ProviderException
     */
    public function traffic(string $email): ?Traffic
    {
        try {
            $raw = Raw::objectOf($this->client->get(self::CLIENTS . '/traffic/' . rawurlencode($email)));
        } catch (ThreeXuiApiException $e) {
            if (self::isNotFound($e)) {
                return null;
            }

            throw $e;
        }

        return $raw !== [] ? Traffic::fromArray($raw) : null;
    }

    /**
     * POST /clients/onlines — the emails connected within the panel's heartbeat window, on every node.
     *
     * @return list<string>
     * @throws ProviderException
     */
    public function onlines(): array
    {
        return Raw::stringsOf($this->client->post(self::CLIENTS . '/onlines'));
    }

    /**
     * POST /clients/add — make the client and attach it to the inbounds in one call; the secrets left out (uuid,
     * password, keys, the subscription id) are the panel's to mint.
     *
     * @param list<int> $inboundIds
     * @throws ProviderException
     */
    public function addClient(ClientPayload $client, array $inboundIds): void
    {
        $this->client->post(self::CLIENTS . '/add', ['client' => $client->toArray(), 'inboundIds' => $inboundIds]);
    }

    /**
     * POST /clients/update/{email} — replaces the row (send every field to keep: ClientPayload::fromRecord()); every
     * inbound it is on follows.
     *
     * @throws NotFoundException|ProviderException
     */
    public function updateClient(string $email, ClientPayload $client): void
    {
        $this->onClient($email, fn() => $this->client->post(self::CLIENTS . '/update/' . rawurlencode($email), $client->toArray()));
    }

    /**
     * POST /clients/del/{email} — off every inbound, its counters with it.
     *
     * @throws NotFoundException|ProviderException
     */
    public function deleteClient(string $email): void
    {
        $this->onClient($email, fn() => $this->client->post(self::CLIENTS . '/del/' . rawurlencode($email)));
    }

    /**
     * POST /clients/resetTraffic/{email} — its counters to zero (and switched on again, should the quota have stopped it).
     *
     * @throws NotFoundException|ProviderException
     */
    public function resetClientTraffic(string $email): void
    {
        $this->onClient($email, fn() => $this->client->post(self::CLIENTS . '/resetTraffic/' . rawurlencode($email)));
    }

    /**
     * POST /clients/bulkEnable or /bulkDisable for the one client — the panel has no single-client switch — whose answer
     * lists what it skipped, and why, instead of failing.
     *
     * @throws NotFoundException|ProviderException
     */
    public function setClientEnabled(string $email, bool $enabled): void
    {
        $path = self::CLIENTS . ($enabled ? '/bulkEnable' : '/bulkDisable');
        $answer = Raw::objectOf($this->client->post($path, ['emails' => [$email]]));

        $skipped = Raw::objectsOf($answer['skipped'] ?? null)[0] ?? null;
        $error = array_values(Raw::array($answer, 'errors'))[0] ?? null;
        $reason = match (true) {
            $skipped !== null => Raw::string($skipped, 'reason'),
            $error !== null => is_scalar($error) ? (string) $error : (string) json_encode($error),
            default => null,
        };
        if ($reason === null) {
            return;
        }

        throw self::saysNotFound($reason) ? self::missing($email) : new ThreeXuiApiException('POST', $path, 200, $reason);
    }

    /**
     * A write on one client, its "not found" — however the panel words it — a NotFoundException.
     *
     * @param \Closure(): mixed $call
     * @throws NotFoundException|ProviderException
     */
    private function onClient(string $email, \Closure $call): void
    {
        try {
            $call();
        } catch (ThreeXuiApiException $e) {
            throw self::isNotFound($e) ? self::missing($email, $e) : $e;
        }
    }

    private static function missing(string $email, ?ThreeXuiApiException $previous = null): NotFoundException
    {
        return new NotFoundException("3x-ui has no client \"{$email}\".", 0, $previous);
    }

    private static function isNotFound(ThreeXuiApiException $e): bool
    {
        return $e->httpStatus === 404 || self::saysNotFound($e->panelMessage);
    }

    /** The panel's wording for a row that is not there — in an envelope and in a bulk call's skip reasons alike. */
    private static function saysNotFound(string $message): bool
    {
        return preg_match('/not\s*found|no such|does not exist/i', $message) === 1;
    }
}
