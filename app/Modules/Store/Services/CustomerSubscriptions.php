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
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\RenewalSettings;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * A customer's services on the shop's website — their own only, a service of anyone else's not there at all —, and
 * what they do with one there, as the bot's service screen does it (the same services, the same rules): read it from its
 * panel now, switch its «تمدید خودکار», give it a new link. What each may do is judged for a whole page at once — the
 * wallet asked once, an agent's traffic read once —, never a query a service. What asks its panel is held per customer
 * (Core\Security\RateLimiter: a 429 with the wait): REFRESHES reads in REFRESH_WINDOW, ROTATIONS new links in
 * ROTATE_WINDOW.
 */
final class CustomerSubscriptions
{
    /** What a service shows of its own, loaded with it. */
    private const RELATIONS = ['plan', 'server'];

    /** A new link the panel did not give, in the customer's words — then what happened, in a word. */
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
        private readonly RenewalSettings $renewalSettings,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /** The customer's services, newest first — those in one state (`status`, a SubscriptionStatus), or every one —, a page of them. */
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
     * One of the customer's services: another's is not there, as one that never was.
     *
     * @throws ModelNotFoundException 404
     */
    public function find(User $customer, int $id): Subscription
    {
        return self::of($customer)->with(self::RELATIONS)->findOrFail($id);
    }

    /**
     * The service as the website shows it; with `$client`, what its panel was just asked (refresh()).
     *
     * @return array<string, mixed>
     */
    public function present(Subscription $subscription, ?ClientInfo $client = null): array
    {
        return $this->presentAll(new Collection([$subscription]), $client === null ? [] : [$subscription->id => $client])[0];
    }

    /**
     * The service read from its panel now, with whether it is connected (ProvisioningService::look() — the bot's
     * «🔄 به‌روزرسانی اطلاعات»): the row up to date — marked deleted when the panel no longer has the client (null then).
     *
     * @throws ServiceNotReadException 502 when the panel could not be read: out of reach, or left alone a while after it
     *                                 failed — the row's copy is no answer
     * @throws TooManyAttemptsException 429 its customer read REFRESHES in the window
     */
    public function refresh(Subscription $subscription): ?ClientInfo
    {
        $this->throttle(['subscriptions|refresh|' . $subscription->user_id, self::REFRESHES, self::REFRESH_WINDOW], 'اطلاعات سرویس را زیاد به‌روز کرده‌اید');

        return $this->provisioning->look($subscription);
    }

    /**
     * Its «تمدید خودکار», on or off — while it is offered (AutoRenewal::set()).
     *
     * @throws AutoRenewUnavailableException 422 on `auto_renew`
     */
    public function autoRenew(Subscription $subscription, bool $on): void
    {
        $this->autoRenewal->set($subscription, $on);
    }

    /**
     * «تغییر لینک»: a new link for the service, every device on the old one dropped (ProvisioningService::rotateLink()).
     * The customer did it on their website: the bot does not tell them. A panel that fails it is a 502 in the customer's
     * words — what happened, in a word, never the owner's diagnosis of the panel (ProviderErrorPresenter::summary()).
     *
     * @throws ValidationException 422 on `status`: it does not run, or its panel cannot give it a new link
     * @throws PanelFailedException 502
     * @throws ServiceBusyException 409 another change of it under way
     * @throws TooManyAttemptsException 429 its customer asked for ROTATIONS in the window
     */
    public function rotateLink(Subscription $subscription): void
    {
        $this->throttle(['subscriptions|rotate|' . $subscription->user_id, self::ROTATIONS, self::ROTATE_WINDOW], 'لینک سرویس را زیاد عوض کرده‌اید');
        try {
            $this->provisioning->rotateLink($subscription);
        } catch (ProviderException $e) {
            $this->logger->warning('Could not rotate the link of subscription {id} on {server} for its website: {message}', ['id' => $subscription->id, 'server' => $subscription->server->name, 'message' => $e->getMessage()]);

            throw new PanelFailedException(self::NOT_ROTATED . ProviderErrorPresenter::summary($e), $e);
        }
    }

    /**
     * Services as the website shows them, in their order: what each may do judged for them all at once (the wallet,
     * an agent's traffic); `$clients` what their panels were just asked, by id (refresh()).
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

        return array_values($subscriptions->map(fn(Subscription $subscription): array => SubscriptionPresenter::present(
            $subscription,
            autoRenewOffered: $offered[$subscription->id],
            autoRenewDays: $days,
            renewable: $plans[$subscription->id] !== null,
            linkRotation: $this->provisioning->rotatable($subscription),
            client: $clients[$subscription->id] ?? null,
        ))->all());
    }

    /**
     * One more of something the customer asks of a panel, held to its window: `[key, max attempts, seconds]`.
     *
     * @param array{string, int, int} $window
     * @throws TooManyAttemptsException 429, `$what` and the wait
     */
    private function throttle(array $window, string $what): void
    {
        $wait = $this->limiter->attempt([$window]);
        if ($wait > 0) {
            throw TooManyAttemptsException::wait($what, $wait);
        }
    }

    /** @return Builder<Subscription> The customer's services — in the shop the request is worked in. */
    private static function of(User $customer): Builder
    {
        return Subscription::query()->where('user_id', $customer->id);
    }
}
