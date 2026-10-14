<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Subscriptions\Services\SubscriptionActions;
use App\Modules\Subscriptions\Services\SubscriptionDirectory;
use App\Modules\Users\Services\UserDirectory;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The subscriptions screen (SubscriptionDirectory) and the modal's operations (SubscriptionActions, by the request's
 * principal): sync from the panel, extend, switch off or on, move to another server, hand to another customer
 * (ServiceTransfers), delete. An operation answers the
 * fresh row (a delete: 204), or a refusal: 422 the state or a field, 403 a shop admin's own service, 409 another change
 * in flight, 502 a panel (under `errors.panel`, or a move's `errors.previous`/`errors.target`).
 */
final class SubscriptionsController extends ApiController
{
    private const RELATIONS = ['user', 'plan', 'server'];

    public function __construct(
        private readonly SubscriptionDirectory $subscriptions,
        private readonly SubscriptionActions $actions,
        private readonly ServiceTransfers $transfers,
    ) {}

    /** GET /subscriptions?search=&status=&server=&user=&sort=&dir=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->subscriptions->search($this->pageRequest($request))->toArray('subscriptions'));
    }

    /**
     * GET /subscriptions/{id} — one service as the list shows it: the modal keeps it fresh while it is open
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->present($response, $this->load(Subscription::class, $args, self::RELATIONS));
    }

    /**
     * POST /subscriptions/{id}/sync — the panel's numbers again
     *
     * @param array<string, string> $args
     */
    public function sync(Request $request, Response $response, array $args): Response
    {
        $subscription = $this->load(Subscription::class, $args, self::RELATIONS);
        $this->actions->sync($subscription, Principal::of($request)->actor());

        return $this->present($response, $subscription);
    }

    /**
     * POST /subscriptions/{id}/extend — {days, traffic_gb, note?, notify?}: days and traffic on top of what the service
     * has, under the name of whoever gave them; the note goes to the customer, who hears it unless `notify` is off
     *
     * @param array<string, string> $args
     */
    public function extend(Request $request, Response $response, array $args): Response
    {
        $subscription = $this->load(Subscription::class, $args, self::RELATIONS);
        $this->actions->extend($subscription, Principal::of($request)->actor(), $this->input($request));

        return $this->present($response, $subscription);
    }

    /**
     * POST /subscriptions/{id}/disable — {note?}: the note goes to the customer
     *
     * @param array<string, string> $args
     */
    public function disable(Request $request, Response $response, array $args): Response
    {
        $note = Input::note($this->input($request), 'note');
        $subscription = $this->load(Subscription::class, $args, self::RELATIONS);
        $this->actions->disable($subscription, Principal::of($request)->actor(), $note);

        return $this->present($response, $subscription);
    }

    /**
     * POST /subscriptions/{id}/enable
     *
     * @param array<string, string> $args
     */
    public function enable(Request $request, Response $response, array $args): Response
    {
        $subscription = $this->load(Subscription::class, $args, self::RELATIONS);
        $this->actions->enable($subscription, Principal::of($request)->actor());

        return $this->present($response, $subscription);
    }

    /**
     * POST /subscriptions/{id}/move — {server_id, leave_previous?}: the customer gets the new link. One service per
     * request: the screen moves a selection one by one and shows how each went.
     *
     * @param array<string, string> $args
     */
    public function move(Request $request, Response $response, array $args): Response
    {
        $input = $this->input($request);
        $subscription = $this->load(Subscription::class, $args, self::RELATIONS);
        $this->actions->move($subscription, Principal::of($request)->actor(), Input::integer($input, 'server_id'), Input::truthy($input['leave_previous'] ?? false));

        return $this->present($response, $subscription);
    }

    /**
     * GET /subscriptions/{id}/transfer?telegram_id= — before a transfer: the shop's customer with that Telegram id who may
     * receive the service (ServiceTransfers::recipient()), or the transfer's refusal; nothing changed
     *
     * @param array<string, string> $args
     */
    public function transferRecipient(Request $request, Response $response, array $args): Response
    {
        $subscription = $this->load(Subscription::class, $args, self::RELATIONS);
        $recipient = $this->transfers->recipient($subscription, $request->getQueryParams()['telegram_id'] ?? null, Principal::of($request)->actor());

        return $this->json($response, ['recipient' => UserDirectory::presentRef($recipient)]);
    }

    /**
     * POST /subscriptions/{id}/transfer — {telegram_id}: the service handed to the shop's customer with that Telegram id
     * (ServiceTransfers), «تمدید خودکار» off; both customers hear of it. Answers the fresh row, its new customer on it.
     *
     * @param array<string, string> $args
     */
    public function transfer(Request $request, Response $response, array $args): Response
    {
        $input = $this->input($request);
        $subscription = $this->load(Subscription::class, $args, self::RELATIONS);
        $this->transfers->transferById($subscription, $input['telegram_id'] ?? null, Principal::of($request)->actor());

        return $this->present($response, $subscription);
    }

    /**
     * POST /subscriptions/{id}/delete — {note?, leave_panel?}: the service is gone for good (204); the note goes to the
     * customer, when they hear at all; leave_panel deletes it without contacting its panel (out of reach), whose client
     * then stays there
     *
     * @param array<string, string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $input = $this->input($request);
        $note = Input::note($input, 'note');
        $this->actions->delete($this->load(Subscription::class, $args, self::RELATIONS), Principal::of($request)->actor(), $note, Input::truthy($input['leave_panel'] ?? false));

        return $this->noContent($response);
    }

    private function present(Response $response, Subscription $subscription): Response
    {
        return $this->json($response, ['subscription' => $this->subscriptions->present($subscription)]);
    }
}
