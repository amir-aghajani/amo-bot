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
use App\Modules\Store\Exceptions\QrCardUnavailableException;
use App\Modules\Store\Presenters\SubscriptionPresenter;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Exceptions\ServiceClaimRefusedException;
use App\Modules\Subscriptions\Exceptions\ServiceNotReadException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\RenewalSettings;
use App\Modules\Subscriptions\Services\ServiceClaims;
use App\Modules\Subscriptions\Services\ServiceCleanup;
use App\Modules\Telegram\Qr\QrCard;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Text;
use App\Support\Validation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * A customer's own services on the website (another's is a 404), and what they do with one, through the same services and
 * rules as the bot's service screen: read it from its panel now, switch «تمدید خودکار», name it, get a new link or its QR
 * card; and a service added by its link (ServiceClaims). What each may do is judged for a whole page at once, never a
 * query per service. What asks a panel is rate-limited per customer: REFRESHES reads per REFRESH_WINDOW, ROTATIONS new
 * links per ROTATE_WINDOW (and a link sent, ServiceClaims::TRIES per its window) — and so are the QR cards drawn,
 * QR_CARDS per QR_WINDOW.
 */
final class CustomerSubscriptions
{
    /** What a service shows of its own, and its customer (whose renewal prices AutoRenewal judges), loaded with it. */
    private const RELATIONS = ['plan', 'server', 'user'];

    /** The refusal of a new link the panel did not give, followed by what happened in a word. */
    public const NOT_ROTATED = 'لینک این سرویس عوض نشد: ';

    /** Services a customer reads from their panels (refresh() — a few panel requests each) in REFRESH_WINDOW seconds. */
    public const REFRESHES = 10;
    public const REFRESH_WINDOW = 60;

    /** New links (rotateLink()) a customer asks for in ROTATE_WINDOW seconds. */
    public const ROTATIONS = 5;
    public const ROTATE_WINDOW = 3600;

    /** QR cards (qrCard() — a picture drawn anew each time) a customer asks for in QR_WINDOW seconds. */
    public const QR_CARDS = 30;
    public const QR_WINDOW = 60;

    /** The longest text a link is sent in (claim()): a subscription link or a config, and a few words around it. */
    public const LINK_MAX = 2000;
    public const LINK_TOO_LONG = 'این متن برای یک لینک سرویس بیش از حد بلند است؛ فقط لینک را بفرستید.';

    /** The longest name a customer gives a service of theirs («نام دلخواه»), in characters: its column's room. */
    public const LABEL_MAX = 32;

    public const NOTHING_TO_CHANGE = 'چیزی برای تغییر فرستاده نشد؛ تمدید خودکار یا نام دلخواه سرویس را بفرستید.';
    public const LABEL_NOT_TEXT = 'نام دلخواه را به صورت متن بفرستید.';
    public const LABEL_CONTROL = 'نام دلخواه باید یک خط متن باشد، بدون کاراکترهای کنترلی.';

    /**
     * What a name may not hold: a control character (a line break or a tab among them), a line or paragraph separator, and
     * the marks that turn the direction of the text after them — never the zero-width non-joiner Persian is written with.
     */
    private const LABEL_REFUSED = '/[\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly ServiceClaims $claims,
        private readonly AutoRenewal $autoRenewal,
        private readonly CustomerRenewal $renewal,
        private readonly CustomerExtras $extras,
        private readonly RenewalSettings $renewalSettings,
        private readonly ServiceCleanup $cleanup,
        private readonly QrCard $qr,
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
     * «افزودن سرویس با لینک»: the service the links in `link` are for added to the customer's account, as the bot adds
     * it (ServiceClaims::claim()); read again as find() reads one.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `link`: longer than LINK_MAX
     * @throws ServiceClaimRefusedException 422 / 409 / 502 on `link`
     * @throws TooManyAttemptsException 429
     */
    public function claim(User $customer, array $input): Subscription
    {
        $text = Input::text($input, 'link');
        if (mb_strlen($text) > self::LINK_MAX) {
            throw ValidationException::on('link', self::LINK_TOO_LONG);
        }

        return $this->find($customer, $this->claims->claim($customer, $text)->id);
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
     * Change what the customer sends of the service, one of the two at least, both judged before either is changed: its
     * «تمدید خودکار» on or off, while it is offered (AutoRenewal::set()), and `label`, their own name for it — trimmed,
     * LABEL_MAX characters at most, cleared when blank or null.
     *
     * @param array<string, mixed> $input {auto_renew?, label?}
     * @throws ValidationException 422: neither sent; on `auto_renew`: no switch; on `label`: no text, too long, or a
     *                             control character in it
     * @throws AutoRenewUnavailableException 422 on `auto_renew`
     * @throws ModelNotFoundException 404: handed to another customer meanwhile
     */
    public function update(Subscription $subscription, array $input): void
    {
        $switches = array_key_exists('auto_renew', $input);
        $names = array_key_exists('label', $input);
        if (!$switches && !$names) {
            throw new ValidationException([], self::NOTHING_TO_CHANGE);
        }
        if ($switches && !Input::isBoolean($input['auto_renew'])) {
            throw ValidationException::on('auto_renew', Validation::NOT_A_SWITCH);
        }
        $label = $names ? self::label($input) : null;

        if ($switches) {
            $this->autoRenewal->set($subscription, Input::truthy($input['auto_renew']));
        }
        if ($names) {
            // Written while the service is still theirs: one handed on meanwhile keeps its new owner's name.
            if ($subscription->newModelQuery()->whereKey($subscription->id)->where('user_id', $subscription->user_id)->update(['label' => $label]) !== 1) {
                throw (new ModelNotFoundException())->setModel(Subscription::class, [$subscription->id]);
            }
            $subscription->forceFill(['label' => $label])->syncOriginalAttribute('label');
        }
    }

    /**
     * The service's QR card, as the bot sends it with a delivery (QrCard::render()): its link as a code on the shop's
     * background, a JPEG.
     *
     * @throws ModelNotFoundException 404: it has no link (gone from its panel)
     * @throws TooManyAttemptsException 429 its customer asked for QR_CARDS in the window
     * @throws QrCardUnavailableException 503: the server cannot draw it
     */
    public function qrCard(Subscription $subscription): string
    {
        if ($subscription->status === SubscriptionStatus::Deleted || $subscription->subscription_url === '') {
            throw (new ModelNotFoundException())->setModel(Subscription::class, [$subscription->id]);
        }
        $this->limiter->enforce([['subscriptions|qr|' . $subscription->user_id, self::QR_CARDS, self::QR_WINDOW]], 'تصویر QR سرویس را زیاد خواسته‌اید');

        return $this->qr->render($subscription->subscription_url) ?? throw new QrCardUnavailableException();
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
     * traffic, the extras' prices), and when each goes by the shop's rules (read once); `$clients` is what their panels
     * were just asked, by id.
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
        $pickable = $this->renewal->pickable($subscriptions);
        $extras = $this->extras->offersFor($subscriptions);

        return array_values($subscriptions->map(fn(Subscription $subscription): array => SubscriptionPresenter::present(
            $subscription,
            autoRenewOffered: $offered[$subscription->id],
            autoRenewDays: $days,
            renewable: $plans[$subscription->id] !== null,
            picksPlan: $pickable[$subscription->id],
            renewalWindow: $this->renewal->window($subscription),
            extras: $extras[$subscription->id],
            linkRotation: $this->provisioning->rotatable($subscription),
            removesAt: $this->cleanup->removesAt($subscription),
            client: $clients[$subscription->id] ?? null,
        ))->all());
    }

    /**
     * The `label` sent: null to clear it (null, blank, or nothing a reader would see).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `label`
     */
    private static function label(array $input): ?string
    {
        if ($input['label'] === null) {
            return null;
        }
        if (!is_string($input['label'])) {
            throw ValidationException::on('label', self::LABEL_NOT_TEXT);
        }

        $label = Input::text($input, 'label');
        if (preg_match(self::LABEL_REFUSED, $label) === 1) {
            throw ValidationException::on('label', self::LABEL_CONTROL);
        }
        if (mb_strlen($label) > self::LABEL_MAX) {
            throw ValidationException::on('label', Validation::tooLong('نام دلخواه', self::LABEL_MAX));
        }

        return Text::blank($label) ? null : $label;
    }

    /** @return Builder<Subscription> The customer's services, in the current shop. */
    private static function of(User $customer): Builder
    {
        return Subscription::query()->where('user_id', $customer->id);
    }
}
