<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Admin\Services\CustomerProfile;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerGroups;
use App\Modules\Users\Services\UserActions;
use App\Modules\Users\Services\UserDirectory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The users table — the customers, their status and role, the admin's groups they are in, and their wallet — and one
 * customer's page, with what support does to their account on the shop's website: its two-factor sign-in turned off,
 * its devices signed out. What is decided is UserActions', by the request's principal (a panel's, or one of the shop's
 * admins on its website — the bot's admin role is the panels' alone).
 */
final class UsersController extends ApiController
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly UserActions $actions,
        private readonly CustomerGroups $groups,
        private readonly CustomerProfile $profile,
    ) {}

    /** GET /users?search=&status=&role=&group=&sort=&dir=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->users->search(PageRequest::fromQuery($request->getQueryParams()))->toArray('users'));
    }

    /**
     * GET /users/{id} — the customer's page: their row, their referral facts and an agent's agency (CustomerProfile)
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $this->profile->present($this->load(User::class, $args)));
    }

    /**
     * PATCH /users/{id} — {status}: banned, or not any more
     *
     * @param array<string, string> $args
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        $user = $this->actions->setStatus($this->load(User::class, $args), Principal::of($request)->actor(), $this->input($request));

        return $this->json($response, ['user' => $this->users->presentWithCounts($user)]);
    }

    /**
     * PUT /users/{id}/role — {role}: the bot's admin, or a customer again
     *
     * @param array<string, string> $args
     */
    public function role(Request $request, Response $response, array $args): Response
    {
        $user = $this->actions->setRole($this->load(User::class, $args), Principal::of($request)->actor(), $this->input($request));

        return $this->json($response, ['user' => $this->users->presentWithCounts($user)]);
    }

    /**
     * PUT /users/{id}/groups — {group_ids: [..]}: the admin's groups the customer is in, every one listed and no other
     *
     * @param array<string, string> $args
     */
    public function groups(Request $request, Response $response, array $args): Response
    {
        $user = $this->load(User::class, $args);
        $this->groups->assign($user, $this->input($request)['group_ids'] ?? null);

        return $this->json($response, ['user' => $this->users->presentWithCounts($user)]);
    }

    /**
     * POST /users/{id}/two-factor/disable — support turns the customer's two-factor sign-in on the website off (the
     * phone it was on lost): the website account as it stands now.
     *
     * @param array<string, string> $args
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's
     * @throws AccountRefusedException 422 it is not on
     */
    public function disableTwoFactor(Request $request, Response $response, array $args): Response
    {
        $user = $this->load(User::class, $args);
        $this->actions->disableTwoFactor($user, Principal::of($request)->actor());

        return $this->json($response, ['account' => $this->profile->account($user)]);
    }

    /**
     * POST /users/{id}/sessions/end — support signs the customer out of the website on every device.
     *
     * @param array<string, string> $args
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's
     */
    public function endSessions(Request $request, Response $response, array $args): Response
    {
        $this->actions->endSessions($this->load(User::class, $args), Principal::of($request)->actor());

        return $this->noContent($response);
    }

    /**
     * GET /users/{id}/wallet — the row and its ledger
     *
     * @param array<string, string> $args
     */
    public function wallet(Request $request, Response $response, array $args): Response
    {
        $user = $this->load(User::class, $args);

        return $this->json($response, ['user' => $this->users->presentWithCounts($user), 'transactions' => $this->users->ledger($user)]);
    }

    /**
     * POST /users/{id}/wallet — {type: credit|debit, amount, description?}
     *
     * @param array<string, string> $args
     * @throws ActorRefusedException 403 one of the shop's admins' own wallet
     */
    public function adjustWallet(Request $request, Response $response, array $args): Response
    {
        $user = $this->load(User::class, $args);
        $transaction = $this->actions->adjustWallet($user, Principal::of($request)->actor(), $this->input($request));

        return $this->json($response, [
            'user' => $this->users->presentWithCounts($user),
            'transaction' => $this->users->presentTransaction($transaction),
            'transactions' => $this->users->ledger($user),
        ], 201);
    }
}
