<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Agency\Exceptions\TrafficShortException;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\DTO\Gift;
use App\Modules\Subscriptions\Enums\SubscriptionAction;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\MoveException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Models\Subscription;
use Psr\Log\LoggerInterface;

/**
 * The subscriptions screen's operations on one service — both panels, and the website's admin API: read the panel
 * again, add days and traffic, switch off or on, move to another server, delete. Each is allowed by the service's state
 * (allowed()) and by who asks (the Actor), done on the panel by ProvisioningService, and told to the customer here (a
 * switch always; an extension unless the admin says not to; a move with its new link; a delete only of a service that
 * still ran; a sync never).
 *
 * Rules by who asks:
 * - A panel's failure is a 502 worded for the reader: the owner gets the diagnosis, anyone else the summary.
 * - Leaving a client on an unreachable panel (a move or delete without contacting it) is the owner's call in the main
 *   shop; elsewhere — an agent's shop, or the website's admins — only while that panel is in backoff (mayLeave()).
 * - Traffic added to a service of an agent's shop comes out of the agent's pool (extend()).
 * - A shop admin never changes their own service (notTheirOwn()).
 * - Panel work from an agent's shop or the website's admins is rate-limited (PANEL_WORK per PANEL_WORK_WINDOW, per
 *   shop); the owner in the main shop is not.
 */
final class SubscriptionActions
{
    /** Refusal of mayLeave(): the panel answers, so its client may not be left on it. */
    public const LEAVE_REFUSED = 'پنل سرور «%s» در دسترس است؛ کلاینت این سرویس فقط وقتی روی پنل می‌ماند که پنل جواب ندهد.';

    /**
     * Panel operations a shop may run from this screen per PANEL_WORK_WINDOW seconds (the owner in the main shop is not
     * counted): enough for a move batch, never a flood that would put a panel out of reach for every shop.
     */
    public const PANEL_WORK = 60;
    public const PANEL_WORK_WINDOW = 60;

    /** The refusal past PANEL_WORK; TooManyAttemptsException::wait() adds the wait. */
    public const TOO_MUCH_PANEL_WORK = 'درخواست زیادی به پنل سرورها فرستاده‌اید';

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly CustomerNotifier $notifier,
        private readonly TrafficPool $pool,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Read the client again from its panel (counters, quota, term, link); a client the panel no longer has marks the
     * service deleted.
     *
     * @throws ValidationException|PanelFailedException|TooManyAttemptsException
     */
    public function sync(Subscription $subscription, Actor $actor): void
    {
        $this->allow($subscription, SubscriptionAction::Sync);
        $this->panelWork($subscription, $actor);
        $this->onPanel($subscription, $actor, fn() => $this->provisioning->inspect($subscription));
    }

    /**
     * «افزایش زمان و حجم»: days and traffic on top of what the service has — exactly what a grant gives
     * (ProvisioningService::grant()) — checked against the panel right now: a service the panel ended or switched off
     * takes nothing. In an agent's shop the traffic is drawn from the agent's pool before any panel is asked (refused
     * under `traffic_gb` when short) and given back if the extension fails (TrafficPool::extending()); days cost
     * nothing. The customer is notified, with the note, unless `notify` is off.
     *
     * @param array<string, mixed> $input {days, traffic_gb, note?, notify?}
     * @throws ActorRefusedException 403 a shop admin's own service
     * @throws ValidationException|PanelFailedException|ServiceBusyException|TooManyAttemptsException
     */
    public function extend(Subscription $subscription, Actor $actor, array $input): void
    {
        self::notTheirOwn($subscription, $actor);
        $this->allow($subscription, SubscriptionAction::Extend);
        $gift = Gift::fromInput($input, 'note');
        self::fits($subscription, $gift);
        $this->panelWork($subscription, $actor);

        $give = fn() => $this->onPanel($subscription, $actor, function () use ($subscription, $gift): void {
            // The grant adds to what the panel says right now, so the service must still take it after the read.
            $client = $this->provisioning->inspect($subscription);
            $this->allow($subscription, SubscriptionAction::Extend);
            // grant() sends the client switched on: one switched off on the panel itself is left alone.
            if ($client?->enabled !== true) {
                throw ValidationException::on('status', 'این سرویس روی پنل غیرفعال شده است؛ زمان و حجم فقط به سرویس فعال اضافه می‌شود.');
            }
            self::fits($subscription, $gift);
            $this->provisioning->grant($subscription, $gift->days, $gift->bytes);
        });
        if ($subscription->shop()->isMain() || $gift->bytes === 0) {
            $give();
        } else {
            try {
                $this->pool->extending($subscription, $actor, $gift->bytes, $give);
            } catch (TrafficShortException $e) {
                throw ValidationException::on('traffic_gb', $e->getMessage());
            }
        }

        $this->logger->info('Subscription {id} ({name}) extended by {reviewer}: {days} days and {bytes} bytes', [
            'id' => $subscription->id,
            'name' => $subscription->remote_name,
            'reviewer' => $actor->reviewer,
            'days' => $gift->days,
            'bytes' => $gift->bytes,
        ]);
        if ($gift->notify) {
            $this->notifier->serviceGranted($subscription, $gift->days, $gift->bytes, $gift->note);
        }
    }

    /**
     * Switch the client off; the customer is notified, with the admin's note.
     *
     * @throws ActorRefusedException 403 a shop admin's own service
     * @throws ValidationException|PanelFailedException|ServiceBusyException|TooManyAttemptsException
     */
    public function disable(Subscription $subscription, Actor $actor, ?string $note): void
    {
        $this->switchClient($subscription, $actor, false, $note);
    }

    /**
     * Switch the client back on; the customer is notified.
     *
     * @throws ActorRefusedException 403 a shop admin's own service
     * @throws ValidationException|PanelFailedException|ServiceBusyException|TooManyAttemptsException
     */
    public function enable(Subscription $subscription, Actor $actor): void
    {
        $this->switchClient($subscription, $actor, true);
    }

    /**
     * Move the service to another server with what is left of it (ProvisioningService::move()); the customer gets the new
     * link. A refusal under `server_id` is about the target (no service can go there, so a batch stops); one under
     * `status` is about this service alone (a batch moves on) — except LEAVE_REFUSED, which is about the previous server.
     *
     * @param bool $leavePrevious do not contact the previous server (out of reach): its client stays there
     * @throws ActorRefusedException 403 a shop admin's own service (a client left behind would be a second one)
     * @throws ValidationException|NoServerAvailableException|MoveException|ServiceBusyException|TooManyAttemptsException
     */
    public function move(Subscription $subscription, Actor $actor, ?int $serverId, bool $leavePrevious): void
    {
        self::notTheirOwn($subscription, $actor);
        $this->allow($subscription, SubscriptionAction::Move);
        if ($leavePrevious) {
            $this->mayLeave($subscription, $actor);
        }
        $target = Server::query()->find($serverId ?? 0) ?? throw ValidationException::on('server_id', 'سرور مقصد را انتخاب کنید.');

        $this->panelWork($subscription, $actor);
        try {
            $this->provisioning->move($subscription, $target, $leavePrevious);
        } catch (MoveException $e) {
            throw $e->worded(static fn(ProviderException $panel): string => self::panelWords($actor, $panel));
        }
        $this->notifier->serviceMoved($subscription);
    }

    /**
     * Delete the service for good: its client off the panel and its row gone (ProvisioningService::delete()). The
     * customer is notified only when a running service was taken away.
     *
     * @param bool $leavePanel do not contact the panel (out of reach): the row goes and the client stays on the panel
     *                         for the admin to remove
     * @throws ActorRefusedException 403 a shop admin's own service
     * @throws ValidationException|PanelFailedException|ServiceBusyException|TooManyAttemptsException
     */
    public function delete(Subscription $subscription, Actor $actor, ?string $note, bool $leavePanel): void
    {
        self::notTheirOwn($subscription, $actor);
        if ($leavePanel) {
            $this->mayLeave($subscription, $actor);
        }
        $this->panelWork($subscription, $actor);
        $running = $subscription->isActive();
        if ($this->onPanel($subscription, $actor, fn(): bool => $this->provisioning->delete($subscription, $leavePanel)) && $running) {
            $this->notifier->serviceDeleted($subscription, $note);
        }
    }

    /**
     * What the modal may do with the service right now, by its state:
     * - sync: anything whose client the panel still had last time;
     * - extend: an active one (running, or waiting for its first connection) that can take days (it ends some day) or
     *   traffic (it has a quota);
     * - disable, move: an active one;
     * - enable: one switched off here — an ended one needs a renewal, not a switch;
     * - delete: anything, including one whose client is gone from the panel.
     *
     * @return array<string, bool> By SubscriptionAction value
     */
    public function allowed(Subscription $subscription): array
    {
        $actions = [];
        foreach (SubscriptionAction::cases() as $action) {
            $actions[$action->value] = match ($action) {
                SubscriptionAction::Sync => $subscription->status !== SubscriptionStatus::Deleted,
                SubscriptionAction::Extend => $subscription->isActive() && ($subscription->hasTerm() || $subscription->traffic_limit_bytes > 0),
                SubscriptionAction::Disable, SubscriptionAction::Move => $subscription->isActive(),
                SubscriptionAction::Enable => $subscription->status === SubscriptionStatus::Disabled,
                SubscriptionAction::Delete => true,
            };
        }

        return $actions;
    }

    /**
     * Switch the client off or on; the customer is notified only when this call made the switch (not when another tab just
     * did).
     *
     * @throws ActorRefusedException|ValidationException|PanelFailedException|ServiceBusyException|TooManyAttemptsException
     */
    private function switchClient(Subscription $subscription, Actor $actor, bool $enabled, ?string $note = null): void
    {
        self::notTheirOwn($subscription, $actor);
        $this->allow($subscription, $enabled ? SubscriptionAction::Enable : SubscriptionAction::Disable);
        $this->panelWork($subscription, $actor);
        if (!$this->onPanel($subscription, $actor, fn(): bool => $this->provisioning->setEnabled($subscription, $enabled))) {
            return;
        }
        $enabled ? $this->notifier->serviceEnabled($subscription) : $this->notifier->serviceDisabled($subscription, $note);
    }

    /**
     * Refuse the action unless the service's state allows it, worded by that state (most often changed elsewhere a moment
     * ago: switched off, ended, gone from its panel).
     *
     * @throws ValidationException 422 on `status`
     */
    private function allow(Subscription $subscription, SubscriptionAction $action): void
    {
        if ($this->allowed($subscription)[$action->value]) {
            return;
        }

        throw ValidationException::on('status', match (true) {
            $subscription->status === SubscriptionStatus::Deleted => 'کلاینت این سرویس دیگر روی پنل نیست؛ فقط می‌شود حذفش کرد.',
            $subscription->status === SubscriptionStatus::Expired => 'این سرویس منقضی شده است و تمدید لازم دارد.',
            $subscription->isActive() => $action === SubscriptionAction::Enable
                ? 'این سرویس فعال است.'
                : 'این سرویس نه تاریخ پایان دارد نه سقف حجم؛ چیزی به آن اضافه نمی‌شود.',
            default => 'این سرویس غیرفعال شده است' . match ($action) {
                SubscriptionAction::Move => '؛ فقط سرویس فعال به سرور دیگری منتقل می‌شود.',
                SubscriptionAction::Extend => '؛ زمان و حجم فقط به سرویس فعال اضافه می‌شود.',
                default => '.',
            },
        });
    }

    /**
     * Refuse a shop admin (who is also a customer) changing their own service: another admin or a panel decides that.
     * Reading its panel again decides nothing, so sync() does not ask.
     *
     * @throws ActorRefusedException
     */
    private static function notTheirOwn(Subscription $subscription, Actor $actor): void
    {
        if ($actor->is($subscription->user_id)) {
            throw ActorRefusedException::ownService();
        }
    }

    /**
     * Refuse days for a service that never ends and traffic for an unlimited one, each under its field.
     *
     * @throws ValidationException
     */
    private static function fits(Subscription $subscription, Gift $gift): void
    {
        $errors = [];
        if ($gift->days > 0 && !$subscription->hasTerm()) {
            $errors['days'][] = 'این سرویس تاریخ پایان ندارد؛ روزی به آن اضافه نمی‌شود.';
        }
        if ($gift->bytes > 0 && $subscription->traffic_limit_bytes === 0) {
            $errors['traffic_gb'][] = 'حجم این سرویس نامحدود است؛ حجمی به آن اضافه نمی‌شود.';
        }
        ValidationException::ifAny($errors);
    }

    /**
     * Whether the service's client may be left on its panel (a move or delete that does not contact it): the owner's call
     * in the main shop; otherwise only while that panel is in backoff (the screen's first, failed try records that). A
     * client left on a panel that answers would be a second working service nobody paid for.
     *
     * @throws ValidationException 422 on `status`
     */
    private function mayLeave(Subscription $subscription, Actor $actor): void
    {
        if (self::ownersOwnShop($subscription, $actor) || $subscription->server->isBackingOff()) {
            return;
        }

        throw ValidationException::on('status', sprintf(self::LEAVE_REFUSED, $subscription->server->name));
    }

    /**
     * Count one more panel operation against the shop's limit (PANEL_WORK per PANEL_WORK_WINDOW); the owner in the main
     * shop is not counted.
     *
     * @throws TooManyAttemptsException 429 with the wait
     */
    private function panelWork(Subscription $subscription, Actor $actor): void
    {
        if (self::ownersOwnShop($subscription, $actor)) {
            return;
        }

        $this->limiter->enforce([['subscriptions|panel|' . $subscription->shop()->id, self::PANEL_WORK, self::PANEL_WORK_WINDOW]], self::TOO_MUCH_PANEL_WORK);
    }

    /**
     * Run one operation's panel work; a panel that fails it is a 502 worded for the reader (panelWords()).
     *
     * @template T
     * @param \Closure(): T $work
     * @param-immediately-invoked-callable $work
     * @return T
     * @throws PanelFailedException
     */
    private function onPanel(Subscription $subscription, Actor $actor, \Closure $work): mixed
    {
        try {
            return $work();
        } catch (ProviderException $e) {
            throw new PanelFailedException("پنل «{$subscription->server->name}»: " . self::panelWords($actor, $e), $e);
        }
    }

    /**
     * A panel's failure for whoever asked: the owner, who runs the servers, gets the diagnosis (whichever shop is open);
     * an agent or a website admin gets the summary — never the panel's address or answers.
     */
    private static function panelWords(Actor $actor, ProviderException $e): string
    {
        return $actor->isOwner() ? ProviderErrorPresenter::describe($e) : ProviderErrorPresenter::summary($e);
    }

    /** The owner working in the main shop: the limits and the leave rule for everyone else do not apply. */
    private static function ownersOwnShop(Subscription $subscription, Actor $actor): bool
    {
        return $subscription->shop()->isMain() && !$actor->isStaff();
    }
}
