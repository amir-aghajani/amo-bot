<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Store\Presenters\SubscriptionPresenter;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Exceptions\ServiceNotReadException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\RenewalSettings;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * A customer's own services on the website (another's is a 404), and what they do with one, through the same services and
 * rules as the bot's service screen: read it from its panel now, switch «تمدید خودکار», get a new link. What each may do is
 * judged for a whole page at once, never a query per service. What asks a panel is rate-limited per customer: REFRESHES
 * reads per REFRESH_WINDOW, ROTATIONS new links per ROTATE_WINDOW.
 */
final class CustomerSubscriptions
{
    /** What a service shows of its own, loaded with it. */
    private const RELATIONS = ['plan', 'server'];

    /** The refusal of a new link the panel did not give, followed by what happened in a word. */
    public const NOT_ROTATED = 'لینک این سرویس عوض نشد: ';

    /** Services a customer reads from their panels (refresh() — a few panel requests each) in REFRESH_WINDOW seconds. */
    public const REFRESHES = 10;
    public const REFRESH_WINDOW = 60;

    /** New links (rotateLink()) a customer asks for in ROTATE_WINDOW seconds. */
    public const ROTATIONS = 5;
    public const ROTATE_WINDOW = 3600;

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly AutoRenewal $autoRenewal,
        private readonly CustomerRenewal $renewal,
        private readonly CustomerExtras $extras,
        private readonly RenewalSettings $renewalSettings,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /** A page of the customer's services, newest first, narrowed to a `status` when the query names one. */
    public function page(User $customer, PageRequest $request): Page
    {
        $query = self::of($customer)->latest('id');
        $status = $request->enum('status', SubscriptionStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return Page::fetchTogether($query->with(self::RELATIONS), $request, $this->presentAll(...));
    }

    /**
     * One of the customer's services; another's is a 404.
     *
     * @throws ModelNotFoundException 404
     */
    public function find(User $customer, int $id): Subscription
    {
        return self::of($customer)->with(self::RELATIONS)->findOrFail($id);
    }

    /**
     * The service as the website shows it; `$client` is what its panel was just asked (refresh()).
     *
     * @return array<string, mixed>
     */
    public function present(Subscription $subscription, ?ClientInfo $client = null): array
    {
        return $this->presentAll(new Collection([$subscription]), $client === null ? [] : [$subscription->id => $client])[0];
    }

    /**
     * Read the service from its panel now, with whether it is connected (ProvisioningService::look()). The row is updated;
     * null when the panel no longer has the client (the row is then marked deleted).
     *
     * @throws ServiceNotReadException 502 when the panel could not be read (out of reach, or backing off after a failure)
     * @throws TooManyAttemptsException 429 its customer read REFRESHES in the window
     */
    public function refresh(Subscription $subscription): ?ClientInfo
    {
        $this->limiter->enforce([['subscriptions|refresh|' . $subscription->user_id, self::REFRESHES, self::REFRESH_WINDOW]], 'اطلاعات سرویس را زیاد به‌روز کرده‌اید');

        return $this->provisioning->look($subscription);
    }

    /**
     * Switch its «تمدید خودکار» on or off, while it is offered (AutoRenewal::set()).
     *
     * @throws AutoRenewUnavailableException 422 on `auto_renew`
     */
    public function autoRenew(Subscription $subscription, bool $on): void
    {
        $this->autoRenewal->set($subscription, $on);
    }

    /**
     * «تغییر لینک»: a new link, every device on the old one dropped (ProvisioningService::rotateLink()). The bot says
     * nothing — the customer did it here. A panel failure is a 502 with its summary, never the owner's diagnosis.
     *
     * @throws ValidationException 422 on `status`: it does not run, or its panel cannot give it a new link
     * @throws PanelFailedException 502
     * @throws ServiceBusyException 409 another change of it under way
     * @throws TooManyAttemptsException 429 its customer asked for ROTATIONS in the window
     */
    public function rotateLink(Subscription $subscription): void
    {
        $this->limiter->enforce([['subscriptions|rotate|' . $subscription->user_id, self::ROTATIONS, self::ROTATE_WINDOW]], 'لینک سرویس را زیاد عوض کرده‌اید');
        try {
            $this->provisioning->rotateLink($subscription);
        } catch (ProviderException $e) {
            $this->logger->warning('Could not rotate the link of subscription {id} on {server} for its website: {message}', ['id' => $subscription->id, 'server' => $subscription->server->name, 'message' => $e->getMessage()]);

            throw new PanelFailedException(self::NOT_ROTATED . ProviderErrorPresenter::summary($e), $e);
        }
    }

    /**
     * Services as the website shows them: what each may do is judged for them all at once (the wallet, an agent's
     * traffic, the extras' prices); `$clients` is what their panels were just asked, by id.
     *
     * @param Collection<int, Subscription> $subscriptions Read with RELATIONS
     * @param array<int, ClientInfo> $clients
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $subscriptions, array $clients = []): array
    {
        $offered = $this->autoRenewal->offeredForAll($subscriptions);
        $days = $this->renewalSettings->autoRenewDays();
        $plans = $this->renewal->plansFor($subscriptions);
        $extras = $this->extras->offersFor($subscriptions);

        return array_values($subscriptions->map(fn(Subscription $subscription): array => SubscriptionPresenter::present(
            $subscription,
            autoRenewOffered: $offered[$subscription->id],
            autoRenewDays: $days,
            renewable: $plans[$subscription->id] !== null,
            extras: $extras[$subscription->id],
            linkRotation: $this->provisioning->rotatable($subscription),
            client: $clients[$subscription->id] ?? null,
        ))->all());
    }

    /** @return Builder<Subscription> The customer's services, in the current shop. */
    private static function of(User $customer): Builder
    {
        return Subscription::query()->where('user_id', $customer->id);
    }
}
