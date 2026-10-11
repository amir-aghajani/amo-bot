<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Reports\ShopReports;
use Illuminate\Database\ConnectionInterface;

/**
 * What the shop knows of a panel's health: when it last talked to it and why that failed (`last_checked_at`,
 * `last_error`, worded for the owner), shown on the server's page and the dashboard and read by Server::isBackingOff().
 * A panel that had answered and stops — or answers again — is reported to the main bot's report group (a server's first
 * contact is not). Each change is a compare-and-swap on the row, so two processes noticing it report it once, written in
 * one transaction with its report: a report the database refuses takes the change back, to be noticed again next time.
 */
final class ServerHealth
{
    public function __construct(
        private readonly ShopReports $reports,
        private readonly ConnectionInterface $db,
    ) {}

    /** Record a contact that read the panel as a whole (the admin's check, the sync): whatever it found. */
    public function record(Server $server, ?string $error): void
    {
        $error === null ? $this->answered($server) : $this->failed($server, $error);
    }

    /**
     * Record any other contact (a customer's screen, a renewal, a grant), writing only what changes the picture: a panel
     * that could not be worked with (ProviderException::unavailable()), or one that answers again. A failure about the
     * one thing asked means the panel answered.
     */
    public function contacted(Server $server, ?ProviderException $failure): void
    {
        if ($failure !== null && $failure->unavailable()) {
            $this->failed($server, ProviderErrorPresenter::describe($failure));
        } elseif ($server->last_error !== null) {
            $this->answered($server);
        }
    }

    private function answered(Server $server): void
    {
        $now = now();
        $this->db->transaction(function () use ($server, $now): void {
            if (Server::query()->whereKey($server->id)->whereNotNull('last_error')->update(['last_error' => null, 'last_checked_at' => $now]) === 1) {
                $this->reports->serverUp($server);
            } else {
                Server::query()->whereKey($server->id)->update(['last_checked_at' => $now]);
            }
        });
        $server->forceFill(['last_error' => null, 'last_checked_at' => $now])->syncOriginalAttributes(['last_error', 'last_checked_at']);
    }

    private function failed(Server $server, string $error): void
    {
        $now = now();
        $this->db->transaction(function () use ($server, $error, $now): void {
            // Down after it had answered — not the first contact, which has nothing to compare with.
            if (Server::query()->whereKey($server->id)->whereNull('last_error')->whereNotNull('last_checked_at')->update(['last_error' => $error, 'last_checked_at' => $now]) === 1) {
                $this->reports->serverDown($server, $error);
            } else {
                Server::query()->whereKey($server->id)->update(['last_error' => $error, 'last_checked_at' => $now]);
            }
        });
        $server->forceFill(['last_error' => $error, 'last_checked_at' => $now])->syncOriginalAttributes(['last_error', 'last_checked_at']);
    }
}
