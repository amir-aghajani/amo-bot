<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Security\RateLimiter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Subscriptions\Enums\ClaimRefusal;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Exceptions\ServiceClaimRefusedException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * «افزودن سرویس با لینک»: a customer adds to their account a service support made on a panel by hand, by sending its
 * subscription link or one of its configs — in the bot or on the website. The shop's panels are asked whose it is
 * (ProvisioningService::findByLink()), one after another, the first that has it winning, and the client becomes the
 * customer's service as its panel has it (ProvisioningService::adopt()), with no plan until a renewal gives it one
 * (CustomerRenewal). It changes owner as a transferred service does (ProvisioningService::newOwner(), then relabel(): its
 * client on the panel takes the customer's Telegram id and comment). The first to send it gets it: a client the shop
 * keeps already — any customer's, in any shop — is refused, and the report group hears of every claim
 * (ShopReports::serviceClaimed()), so support can remove a wrong one from the shop (its client left on the panel) for
 * the right customer to claim. The one exception is the customer's own service the shop lists as gone from its panel
 * while its panel has the client again: the stale record goes, and the client is claimed afresh, keeping the record's
 * plan and «تمدید خودکار» (and a free test staying one).
 *
 * The main shop's alone, while its admin switches it on (ServiceClaimSettings). A customer has TRIES within
 * WINDOW_SECONDS, since a try reads the panels one after another until one has the client.
 */
final class ServiceClaims
{
    /** Links a customer may send within the window. */
    public const TRIES = 10;
    public const WINDOW_SECONDS = 600;

    private const TOO_MANY = 'تلاش برای افزودن سرویس زیاد بود';

    public function __construct(
        private readonly ServiceClaimSettings $settings,
        private readonly ProvisioningService $provisioning,
        private readonly ProviderRegistry $providers,
        private readonly ShopReports $reports,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the shop takes services by their link now. */
    public function offered(): bool
    {
        return CurrentBot::isMain() && $this->settings->enabled();
    }

    /**
     * Add the service the links in `$text` are for (ClientLink::find()) to the customer's account.
     *
     * @throws ServiceClaimRefusedException
     * @throws TooManyAttemptsException 429
     */
    public function claim(User $user, string $text): Subscription
    {
        if (!$this->offered()) {
            throw ServiceClaimRefusedException::because(ClaimRefusal::Off);
        }
        $link = ClientLink::find($text) ?? throw ServiceClaimRefusedException::because(ClaimRefusal::NotALink);
        $this->limiter->enforce([['claim|' . $user->id, self::TRIES, self::WINDOW_SECONDS]], self::TOO_MANY);

        ['server' => $server, 'client' => $client, 'observed' => $observed] = $this->find($link);
        if ($client->subscriptionUrl === null) {
            throw ServiceClaimRefusedException::because(ClaimRefusal::NoLink);
        }
        $kept = self::kept($server, $client->name);
        $carried = [];
        if ($kept !== null) {
            if (!$this->clearedStale($user, $kept)) {
                throw self::refusal($user, $kept);
            }
            // What the customer had of it comes back with it — a free test stays one.
            $carried = ['plan_id' => $kept->plan_id, 'auto_renew' => $kept->auto_renew, 'is_test' => $kept->is_test];
        }

        try {
            $claimed = $this->provisioning->adopt($user, $server, $client, $observed, fn(Subscription $claimed) => $this->reports->serviceClaimed($claimed), $carried);
        } catch (UniqueConstraintViolationException $e) {
            // Claimed in the same moment by another tap, or another customer.
            $kept = self::kept($server, $client->name) ?? throw $e;

            throw self::refusal($user, $kept);
        }
        $this->provisioning->relabel($claimed);

        return $claimed;
    }

    /**
     * The first of the shop's panels, in the servers' order, with a client the link is for, and when it answered. A panel
     * in backoff is not asked and one that fails is logged; either is passed over, and the link is then "not found" only
     * if every panel answered. A server whose connector is not installed cannot be asked at all, and is passed over.
     *
     * @return array{server: Server, client: ClientInfo, observed: Carbon}
     * @throws ServiceClaimRefusedException
     */
    private function find(ClientLink $link): array
    {
        $unanswered = false;
        foreach (Server::query()->orderBy('sort')->orderBy('id')->get() as $server) {
            if (!$this->providers->has($server->driver)) {
                continue;
            }
            if ($server->isBackingOff()) {
                $unanswered = true;

                continue;
            }

            $observed = now();
            try {
                $client = $this->provisioning->findByLink($server, $link);
            } catch (ProviderException $e) {
                $this->logger->warning('Could not look for a service sent by its link on {server}: {message}', ['server' => $server->name, 'message' => $e->getMessage()]);
                $unanswered = true;

                continue;
            }
            if ($client !== null) {
                return ['server' => $server, 'client' => $client, 'observed' => $observed];
            }
        }

        throw ServiceClaimRefusedException::because($unanswered ? ClaimRefusal::Unavailable : ClaimRefusal::NotFound);
    }

    /** The service the shop keeps for the client, in any shop. */
    private static function kept(Server $server, string $name): ?Subscription
    {
        return Subscription::acrossShops()->where('server_id', $server->id)->where('remote_name', $name)->first();
    }

    /**
     * The customer's own service the shop lists as gone from its panel (`deleted`) while its panel has a client of that
     * name again: its stale record removed — nothing on the panel touched —, so the client is claimed afresh. True when
     * it was. Anyone else's such record is left for support to judge.
     *
     * @throws ServiceClaimRefusedException unavailable while another change holds the record
     */
    private function clearedStale(User $user, Subscription $kept): bool
    {
        if ($kept->status !== SubscriptionStatus::Deleted || !self::owns($user, $kept)) {
            return false;
        }

        try {
            $this->provisioning->delete($kept, leavePanel: true);
        } catch (ModelNotFoundException) {
            // Deleted meanwhile.
        } catch (ServiceBusyException) {
            throw ServiceClaimRefusedException::because(ClaimRefusal::Unavailable);
        }

        return true;
    }

    /** Why a client the shop keeps is not added: it is the customer's own already, or someone else's. */
    private static function refusal(User $user, Subscription $kept): ServiceClaimRefusedException
    {
        return self::owns($user, $kept) ? ServiceClaimRefusedException::yours($kept) : ServiceClaimRefusedException::because(ClaimRefusal::Taken);
    }

    /** Whether the service is the customer's, in this shop. */
    private static function owns(User $user, Subscription $service): bool
    {
        return (int) $service->user_id === $user->id && (int) $service->bot_id === CurrentBot::id();
    }
}
