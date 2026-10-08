<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionActions;
use App\Modules\Subscriptions\Services\SubscriptionDirectory;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The subscriptions screen (SubscriptionDirectory) and what its modal can do (SubscriptionActions, by the request's
 * principal): list, read the panel again, give a service days and traffic, switch a client off or back on, move it to
 * another server, delete it for good. Every operation answers with the fresh row — a delete with nothing — or with why
 * it was refused: the state, a field or the target server (422), who asks (403: one of the shop's admins' own service),
 * another change of the service in flight (409), a panel (502, under `errors.panel`, or a move's `errors.previous` /
 * `errors.target`).
 */
final class SubscriptionsController extends ApiController
{
    private const RELATIONS = ['user', 'plan', 'server'];

    public function __construct(
        private readonly SubscriptionDirectory $subscriptions,
        private readonly SubscriptionActions $actions,
    ) {}

    /** GET /subscriptions?search=&status=&server=&user=&sort=&dir=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->subscriptions->search(PageRequest::fromQuery($request->getQueryParams()))->toArray('subscriptions'));
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
