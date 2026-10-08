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
 * What the subscriptions screen's modal does with a service — in either panel, or on the shop's website (its admins):
 * read the panel again, give it days and traffic, switch the client off or back on, move it to another server, delete it
 * for good — each allowed by the service's state (allowed()) and by who asks (the Actor), worked on the panel by
 * ProvisioningService, and told to the customer here: a switch always, days and traffic unless the admin says not to, a
 * move with its new link, a delete only when it took away a service that still ran (tidying away an ended one is quiet),
 * a read never. A panel that fails is a 502 in words for whoever reads the screen — the owner's diagnosis, the summary
 * for an agent and for the shop's admins on its website (a move's too: MoveException::worded()). A move or a delete that leaves the client on
 * its panel (the panel out of reach) is the owner's call in the main shop; in an agent's shop, and for the shop's admins
 * on its website, it waits until the shop has seen that panel fail (mayLeave()); the traffic given to a service of an
 * agent's shop is the agent's (extend()); and one of the shop's admins — its customer too — never changes their own
 * service, reading its panel again aside (notTheirOwn()). The panels are the owner's: what an agent's shop asks of them
 * from this screen, and what the shop's admins ask from its website, is held to PANEL_WORK operations in
 * PANEL_WORK_WINDOW (a 429 with the wait) — the shop's count, the owner's too while they have an agent's shop open.
 */
final class SubscriptionActions
{
    /** An agent's shop — or the shop's admins on its website — asked to leave a client on a panel that answers (mayLeave()). */
    public const LEAVE_REFUSED = 'پنل سرور «%s» در دسترس است؛ کلاینت این سرویس فقط وقتی روی پنل می‌ماند که پنل جواب ندهد.';

    /**
     * The operations a shop runs on the panels from this screen — a service read again, given days and traffic, switched,
     * moved, deleted — in PANEL_WORK_WINDOW seconds, but the owner's own in the main bot's shop: a move batch's pace,
     * never a flood of a panel that would put it out of reach for every shop.
     */
    public const PANEL_WORK = 60;
    public const PANEL_WORK_WINDOW = 60;

    /** The refusal past PANEL_WORK, in the panel's reader's words: TooManyAttemptsException::wait() adds the wait. */
    public const TOO_MUCH_PANEL_WORK = 'درخواست زیادی به پنل سرورها فرستاده‌اید';

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly CustomerNotifier $notifier,
        private readonly TrafficPool $pool,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Read the client again from its panel: counters, quota, term and link — or, when the panel no longer has it, the
     * service marked deleted.
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
     * «افزایش زمان و حجم»: days and traffic on top of what the service has — exactly what a grant gives it
     * (ProvisioningService::grant()) —, from what its panel says right now: a service the panel has ended, or switched
     * off itself, takes nothing. In an agent's shop the traffic is the agent's: drawn from their bot's pool before any
     * panel is asked — refused under `traffic_gb` when the pool is short — and given back when the extension does not go
     * through (TrafficPool::extending()); the days cost nothing. Logged under whoever gave it; the customer hears it,
     * with the admin's note, unless `notify` is off.
     *
     * @param array<string, mixed> $input {days, traffic_gb, note?, notify?}
     * @throws ActorRefusedException 403 one of the shop's admins' own service
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
            // The grant adds to what the panel says right now — and the service it describes must still take it.
            $client = $this->provisioning->inspect($subscription);
            $this->allow($subscription, SubscriptionAction::Extend);
            // ProvisioningService::grant() sends the client switched on: one switched off on the panel itself is left so.
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
     * Switch the client off; the customer hears it, with the admin's note.
     *
     * @throws ActorRefusedException 403 one of the shop's admins' own service
     * @throws ValidationException|PanelFailedException|ServiceBusyException|TooManyAttemptsException
     */
    public function disable(Subscription $subscription, Actor $actor, ?string $note): void
    {
        self::notTheirOwn($subscription, $actor);
        $this->allow($subscription, SubscriptionAction::Disable);
        $this->panelWork($subscription, $actor);
        if ($this->onPanel($subscription, $actor, fn(): bool => $this->provisioning->setEnabled($subscription, false))) {
            $this->notifier->serviceDisabled($subscription, $note);
        }
    }

    /**
     * Switch it back on; the customer hears it.
     *
     * @throws ActorRefusedException 403 one of the shop's admins' own service — switched off by another's decision
     * @throws ValidationException|PanelFailedException|ServiceBusyException|TooManyAttemptsException
     */
    public function enable(Subscription $subscription, Actor $actor): void
    {
        self::notTheirOwn($subscription, $actor);
        $this->allow($subscription, SubscriptionAction::Enable);
        $this->panelWork($subscription, $actor);
        if ($this->onPanel($subscription, $actor, fn(): bool => $this->provisioning->setEnabled($subscription, true))) {
            $this->notifier->serviceEnabled($subscription);
        }
    }

    /**
     * Move the service to another server with what is left of it (ProvisioningService::move()); the customer gets the new
     * link. A refusal under `server_id` is about the target (no service can go there); one under `status` is about this
     * service alone (not active, or already there), so a batch moves on to the next one — but for LEAVE_REFUSED, which is
     * about the previous server (an agent may not leave a client on a panel that answers), so a batch from it stops.
     *
     * @param bool $leavePrevious do not contact the previous server (out of reach): its client stays there
     * @throws ActorRefusedException 403 one of the shop's admins' own service — a client left behind would be a second one
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
     * customer hears it only when it took away a service that still ran.
     *
     * @param bool $leavePanel do not contact the panel (out of reach): the service is deleted here and its client stays on
     *                         the panel for the admin to remove
     * @throws ActorRefusedException 403 one of the shop's admins' own service
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
     * - sync: anything whose client the panel still had last time (read the panel again);
     * - extend: an active one — running, or waiting for its first connection — that can take days (it ends some day)
     *   or traffic (it has a quota);
     * - disable: an active one;
     * - enable: one switched off here — an ended one needs time or traffic (a renewal), not a switch;
     * - move: an active one — what is left of it goes to the new server;
     * - delete: anything, one whose client is gone from the panel too (its row is all that is left).
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
     * @throws ValidationException when the service's state does not allow the action — worded by that state, which most
     *                             often was decided elsewhere a moment ago (switched off, ended, gone from its panel)
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
     * One of the shop's admins — who is its customer too — asking to change their own service: a decision about
     * themselves, another admin's (or the panels') to make. Reading its panel again decides nothing.
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
     * Days and traffic the service cannot take, refused under their fields: days when it never ends, traffic when it has
     * no quota — a grant would give it nothing of either.
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
     * Whether the service's client may be left on its panel — a move or a delete that does not contact the server: at the
     * owner's word in the main bot's shop; in an agent's shop, and for the shop's admins on its website, only while that
     * panel is out of reach (Server::isBackingOff(): the shop has just failed to reach it — the screen's first try records
     * it). Neither ever keeps a working client on a panel that answers: a second service on the shop's servers that nobody
     * paid for.
     *
     * @throws ValidationException 422 on `status`
     */
    private function mayLeave(Subscription $subscription, Actor $actor): void
    {
        if (($subscription->shop()->isMain() && !$actor->isStaff()) || $subscription->server->isBackingOff()) {
            return;
        }

        throw ValidationException::on('status', sprintf(self::LEAVE_REFUSED, $subscription->server->name));
    }

    /**
     * One more operation a shop runs on the panels (PANEL_WORK in PANEL_WORK_WINDOW, the shop's count); the owner works
     * their own shop as they please — its admins on its website do not.
     *
     * @throws TooManyAttemptsException 429 with the wait
     */
    private function panelWork(Subscription $subscription, Actor $actor): void
    {
        $shop = $subscription->shop();
        if ($shop->isMain() && !$actor->isStaff()) {
            return;
        }

        $wait = $this->limiter->attempt([['subscriptions|panel|' . $shop->id, self::PANEL_WORK, self::PANEL_WORK_WINDOW]]);
        if ($wait > 0) {
            throw TooManyAttemptsException::wait(self::TOO_MUCH_PANEL_WORK, $wait);
        }
    }

    /**
     * The panel work of one operation: a panel that fails it is a 502 in words for whoever reads the screen (panelWords()).
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
     * A panel's failure for whoever asked — the one who reads the answer: the owner, who runs the servers, the diagnosis
     * (whichever shop they have open); an agent, or one of the shop's admins on its website, what happened in a word —
     * never the panel's address or its answers.
     */
    private static function panelWords(Actor $actor, ProviderException $e): string
    {
        return $actor->isOwner() ? ProviderErrorPresenter::describe($e) : ProviderErrorPresenter::summary($e);
    }
}
