<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Providers\Services\ServerHealth;
use App\Modules\Providers\Services\ServerReadiness;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Users\Models\User;
use App\Support\Text;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * The services stage: each service of the other bot found by its client's name on the server its panel maps to, and
 * taken into the shop as the panel has it (ProvisioningService::adopt()) — its link, traffic, end and switch the
 * panel's; nothing on the panel is changed, not even the client's comment. A server's clients are read once a step
 * (listClients(), or one by one from a panel that cannot list them), its contact recorded on the server; a panel that
 * does not answer is asked again the next few steps (the step stops before its service), then leaves its services for
 * a later import of the same data to pick up.
 *
 * A service with a plan comes as one sold from it; one without, as a claimed one (`claimed_at`), which a renewal gives a
 * plan. A client the shop keeps already stays its customer's — linked when that is this customer.
 */
final class ServiceWriter
{
    private const KIND = 'services';

    /** How many steps in a row a silent panel is asked again before its services are given up on (panel_unreachable). */
    private const TRIES = 3;

    /** The longest «نام دلخواه» the shop keeps. */
    private const LABEL_MAX = 32;

    /**
     * This step's reading of each server's clients, by server id: its clients by name and when it answered, null clients
     * for a panel asked one by one, or the failure that keeps it unanswered for the rest of the step.
     *
     * @var array<int, array{clients: array<string, ClientInfo>|null, observed: Carbon}|ProviderException>
     */
    private array $read = [];

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly ProviderRegistry $providers,
        private readonly ServerHealth $health,
        private readonly Links $links,
    ) {}

    /** A new step reads every panel afresh. */
    public function forget(): void
    {
        $this->read = [];
    }

    public function write(Run $run, ImportedService $service): void
    {
        $key = $service->key;
        $reason = match (true) {
            $service->reseller !== null && !$run->choices->on('resellers') => ProblemReason::Reseller,
            !$run->choices->on($service->test ? 'tests' : 'services') => ProblemReason::NotChosen,
            trim($service->remoteName) === '' => ProblemReason::Unreadable,
            default => null,
        };
        if ($reason !== null) {
            $run->skipped(self::KIND, $key, $reason);

            return;
        }
        $server = $run->server($service->panelKey);
        if ($server === null) {
            $run->skipped(self::KIND, $key, ProblemReason::PanelSkipped);

            return;
        }
        if ($run->broughtBefore(Links::SERVICE, self::KIND, $key, Subscription::class)) {
            return;
        }

        $where = Run::isolate($service->remoteName) . ' — ' . $server->name;
        $customer = $this->links->customer($run->import, $service->customerKey);
        $user = $customer === null ? null : User::query()->find($customer);
        if ($user === null) {
            // A customer brought and deleted by the owner since takes their services with them (brought once).
            $run->skipped(self::KIND, $key, $this->links->brought($run->import, Links::CUSTOMER, $service->customerKey) ? ProblemReason::Removed : ProblemReason::NoCustomer, $where);

            return;
        }
        if (!$this->providers->has($server->driver)) {
            $run->skipped(self::KIND, $key, ProblemReason::PanelUnreachable, $where . ': ' . ServerReadiness::NO_CONNECTOR);

            return;
        }

        try {
            [$client, $observed] = $this->client($run, $server, $service->remoteName);
        } catch (ProviderException $e) {
            $run->skipped(self::KIND, $key, ProblemReason::PanelUnreachable, $where . ': ' . ProviderErrorPresenter::describe($e));

            return;
        }
        if ($client === null || $client->subscriptionUrl === null) {
            $run->skipped(self::KIND, $key, $client === null ? ProblemReason::NotOnPanel : ProblemReason::NoLink, $where);

            return;
        }
        if ($this->kept($run, $server, $client, $user, $key, $where)) {
            return;
        }

        $plan = $service->planKey === null ? null : $this->links->target($run->import, Links::PLAN, $service->planKey);
        $plan = $plan !== null && Plan::query()->whereKey($plan)->exists() ? $plan : null;
        $label = trim((string) $service->label);
        $carried = ['is_test' => $service->test, 'label' => $label === '' ? null : Text::fit($label, self::LABEL_MAX)]
            // Sold from a plan, it is no claimed service: renewed from its plan as any other.
            + ($plan === null ? [] : ['plan_id' => $plan, 'claimed_at' => null]);
        try {
            $this->provisioning->adopt($user, $server, $client, $observed, fn(Subscription $adopted) => $this->links->add($run->import, Links::SERVICE, $key, $adopted->id), $carried);
        } catch (UniqueConstraintViolationException $e) {
            // The shop took the client in the same moment (its customer claimed it by its link, say).
            if (!$this->kept($run, $server, $client, $user, $key, $where)) {
                throw $e;
            }

            return;
        }
        $run->created(self::KIND);
    }

    /**
     * Whether the shop keeps the client already — in any shop: this customer's service, now linked; anyone else's, left
     * theirs (kept_already).
     *
     * @phpstan-impure It reads the shop as it is now: asked again after a clash, it may answer otherwise.
     */
    private function kept(Run $run, Server $server, ClientInfo $client, User $user, string $key, string $where): bool
    {
        $kept = Subscription::acrossShops()->where('server_id', $server->id)->where('remote_name', $client->name)->first();
        if ($kept === null) {
            return false;
        }
        if ((int) $kept->user_id === $user->id && (int) $kept->bot_id === (int) $user->bot_id) {
            $this->links->add($run->import, Links::SERVICE, $key, $kept->id);
            $run->linked(self::KIND);
        } else {
            $run->skipped(self::KIND, $key, ProblemReason::KeptAlready, $where . ' (#' . $kept->id . ')');
        }

        return true;
    }

    /**
     * The client of that name on the server's panel, and when the panel said so; null when it has none.
     *
     * @return array{ClientInfo|null, Carbon}
     * @throws ProviderException the panel did not answer (this step's first failure, for each service after it)
     * @throws TryAgain it did not, and has steps left to answer in (silent())
     */
    private function client(Run $run, Server $server, string $name): array
    {
        $read = $this->read[$server->id] ??= $this->list($run, $server);
        if ($read instanceof ProviderException) {
            throw $read;
        }
        // The listing's client — unless it leaves the device limit unsaid (Rebecca's) on a panel that keeps one: that one is
        // read on its own, so it comes with the limit it has.
        $listed = $read['clients'] === null ? null : $read['clients'][$name] ?? null;
        if ($read['clients'] !== null && ($listed === null || $listed->ipLimit !== null || !$this->providers->capabilities($server->driver)->ipLimit)) {
            return [$listed, $read['observed']];
        }

        $observed = now();
        try {
            $client = $this->providers->forServer($server)->findClient($name);
        } catch (ProviderException $e) {
            // The listing answered for the client this step: a read of its device limit that fails leaves it as listed —
            // never a silence, which the next step's listing would clear, the same read failing again for good.
            if ($listed !== null) {
                return [$listed, $read['observed']];
            }
            $this->health->contacted($server, $e);
            if ($e->unavailable()) {
                $this->silent($run, $server, $e);
                $this->read[$server->id] = $e;
            }

            throw $e;
        }
        $this->health->contacted($server, null);
        // It answered: its silences in a row are over, as a listing's answer ends them (list()).
        $run->silent($server->id, answered: true);

        return [$client, $observed];
    }

    /**
     * The server's clients by name, read at once; null clients for a panel that cannot list them; the failure of one
     * that did not answer.
     *
     * @return array{clients: array<string, ClientInfo>|null, observed: Carbon}|ProviderException
     * @throws TryAgain it did not answer, and has steps left to answer in (silent())
     */
    private function list(Run $run, Server $server): array|ProviderException
    {
        $observed = now();
        try {
            $clients = [];
            foreach ($this->providers->forServer($server)->listClients() as $client) {
                $clients[$client->name] = $client;
            }
        } catch (UnsupportedOperationException) {
            return ['clients' => null, 'observed' => $observed];
        } catch (ProviderException $e) {
            $this->health->contacted($server, $e);
            $this->silent($run, $server, $e);

            return $e;
        }
        $this->health->contacted($server, null);
        $run->silent($server->id, answered: true);

        return ['clients' => $clients, 'observed' => $observed];
    }

    /**
     * A panel that did not answer is asked again next step, before its services are given up on: up to TRIES steps in a
     * row (kept in the import's tally, so a step cut short keeps count).
     *
     * @throws TryAgain while it has steps left
     */
    private function silent(Run $run, Server $server, ProviderException $e): void
    {
        if ($run->silences($server->id) < self::TRIES) {
            $run->silent($server->id);

            throw new TryAgain($e->getMessage(), 0, $e);
        }
    }
}
