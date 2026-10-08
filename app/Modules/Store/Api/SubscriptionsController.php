<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Database\PageRequest;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Store\Services\CustomerSubscriptions;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Exceptions\ServiceNotReadException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Input;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's services on the shop's website (Store\Services\CustomerSubscriptions): the list, one of them
 * — another customer's is a 404 —, read from its panel now, its «تمدید خودکار», a new link.
 */
final class SubscriptionsController extends ApiController
{
    public function __construct(private readonly CustomerSubscriptions $subscriptions) {}

    /** GET /subscriptions — ?status=&page=, newest first. */
    public function index(Request $request, Response $response): Response
    {
        $page = $this->subscriptions->page(Customer::of($request)->user, PageRequest::fromQuery($request->getQueryParams()));

        return $this->json($response, $page->toArray('subscriptions'));
    }

    /**
     * GET /subscriptions/{id} — as the shop last saw it on its panel.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->answer($response, $this->own($request, $args));
    }

    /**
     * POST /subscriptions/{id}/refresh — read from its panel now, with whether it is connected.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ServiceNotReadException 502 the panel could not be read
     */
    public function refresh(Request $request, Response $response, array $args): Response
    {
        $subscription = $this->own($request, $args);

        return $this->answer($response, $subscription, $this->subscriptions->refresh($subscription));
    }

    /**
     * PATCH /subscriptions/{id} — {auto_renew}: its «تمدید خودکار», on or off.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422 on `auto_renew`: no switch
     * @throws AutoRenewUnavailableException 422 on `auto_renew`: not offered
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $subscription = $this->own($request, $args);
        $this->subscriptions->autoRenew($subscription, $this->switch($request, 'auto_renew'));

        return $this->answer($response, $subscription);
    }

    /**
     * POST /subscriptions/{id}/rotate-link — a new link, every device on the old one dropped.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422 on `status`
     * @throws PanelFailedException 502
     * @throws ServiceBusyException 409
     */
    public function rotateLink(Request $request, Response $response, array $args): Response
    {
        $subscription = $this->own($request, $args);
        $this->subscriptions->rotateLink($subscription);

        return $this->answer($response, $subscription);
    }

    /**
     * The service the route names, the customer's own.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    private function own(Request $request, array $args): Subscription
    {
        return $this->subscriptions->find(Customer::of($request)->user, Input::integerOf($args['id'] ?? null) ?? 0);
    }

    private function answer(Response $response, Subscription $subscription, ?ClientInfo $client = null): Response
    {
        return $this->json($response, ['subscription' => $this->subscriptions->present($subscription, $client)]);
    }
}
