<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Auth\Actor;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Store\Presenters\RecipientPresenter;
use App\Modules\Store\Services\CustomerSubscriptions;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Exceptions\ServiceClaimRefusedException;
use App\Modules\Subscriptions\Exceptions\ServiceNotReadException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's services (Store\Services\CustomerSubscriptions): the list, one of them (another customer's is a
 * 404), read from its panel now, its «تمدید خودکار», a new link, handed to another customer (ServiceTransfers, as the
 * bot does it) — and one added by its link.
 */
final class SubscriptionsController extends ApiController
{
    public function __construct(
        private readonly CustomerSubscriptions $subscriptions,
        private readonly ServiceTransfers $transfers,
    ) {}

    /** GET /subscriptions — ?status=&page=, newest first. */
    public function index(Request $request, Response $response): Response
    {
        $page = $this->subscriptions->page(Customer::of($request)->user, $this->pageRequest($request));

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
     * POST /subscriptions/claim — {link}: «افزودن سرویس با لینک», the service its link is for added to the customer's
     * account; a 201 with it.
     *
     * @throws ValidationException 422 on `link`: too long
     * @throws ServiceClaimRefusedException 422 / 409 / 502 on `link`
     * @throws TooManyAttemptsException 429
     */
    public function claim(Request $request, Response $response): Response
    {
        $subscription = $this->subscriptions->claim(Customer::of($request)->user, $this->input($request));

        return $this->json($response, ['subscription' => $this->subscriptions->present($subscription)], 201);
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
     * GET /subscriptions/{id}/transfer?telegram_id= — «انتقال سرویس», before it is done: who the shop's customer with that
     * Telegram id is, so the website asks the customer to confirm them (ServiceTransfers::recipient()).
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422 on `status` or `telegram_id`
     * @throws TooManyAttemptsException 429
     */
    public function transferRecipient(Request $request, Response $response, array $args): Response
    {
        $customer = Customer::of($request)->user;
        $recipient = $this->transfers->recipient($this->own($request, $args), $request->getQueryParams()['telegram_id'] ?? null, Actor::customer($customer));

        return $this->json($response, ['recipient' => RecipientPresenter::present($recipient)]);
    }

    /**
     * POST /subscriptions/{id}/transfer — {telegram_id}: «انتقال سرویس», the service handed to the shop's customer with
     * that Telegram id (ServiceTransfers::transferById()); it is theirs from now on, so the answer is who received it.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422 on `status` or `telegram_id`
     * @throws TooManyAttemptsException 429
     */
    public function transfer(Request $request, Response $response, array $args): Response
    {
        $customer = Customer::of($request)->user;
        $recipient = $this->transfers->transferById($this->own($request, $args), $this->input($request)['telegram_id'] ?? null, Actor::customer($customer));

        return $this->json($response, ['recipient' => RecipientPresenter::present($recipient)]);
    }

    /**
     * The customer's own service the route names.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    private function own(Request $request, array $args): Subscription
    {
        return $this->subscriptions->find(Customer::of($request)->user, $this->routeId($args));
    }

    private function answer(Response $response, Subscription $subscription, ?ClientInfo $client = null): Response
    {
        return $this->json($response, ['subscription' => $this->subscriptions->present($subscription, $client)]);
    }
}
