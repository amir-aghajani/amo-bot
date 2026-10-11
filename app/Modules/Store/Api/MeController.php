<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\AccountNames;
use App\Modules\Accounts\Services\AccountPresenter;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Accounts\Services\Reauthentication;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Presenters\StaffPresenter;
use App\Modules\Store\Services\CustomerNotifications;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's own account (`/me`): who they are and their names, their password, a way in proven again, the
 * devices they are signed in on, and signing out.
 */
final class MeController extends ApiController
{
    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly AccountNames $names,
        private readonly EmailSignIn $email,
        private readonly Reauthentication $reauthentication,
        private readonly CustomerNotifications $notifications,
        private readonly Loyalty $loyalty,
    ) {}

    /** GET /me — the account, its unread notices, what the admin API lets them do (`staff`) and their loyalty tier. */
    public function show(Request $request, Response $response): Response
    {
        return $this->account($request, $response);
    }

    /**
     * PATCH /me — {first_name?, last_name?}: the names sent; answered as GET /me.
     *
     * @throws ValidationException 422 under the field: too long, a blank first name, or an account whose names are Telegram's
     */
    public function update(Request $request, Response $response): Response
    {
        $this->names->rename(Customer::of($request)->user, $this->input($request));

        return $this->account($request, $response);
    }

    /**
     * POST /me/reauthenticate — a way into this account proven again (see Reauthentication); the session is recent for
     * the seconds answered.
     *
     * @throws ValidationException 422 under the fields: a proof that does not hold, or is another account's
     * @throws SignInRefusedException 422 a way in the website has not set up; 502 Google out of reach
     * @throws TooManyAttemptsException 429
     * @throws TelegramUnreachableException 502
     */
    public function reauthenticate(Request $request, Response $response): Response
    {
        $this->reauthentication->prove(StoreMiddleware::website($request), $request, Customer::of($request), $this->input($request));

        return $this->json($response, ['expires_in' => CustomerSessions::RECENT_SECONDS]);
    }

    /**
     * PUT /me/password — {current_password?, password}: set or changed; every other session ends.
     *
     * @throws AccountRefusedException 422 no email on the account
     * @throws ValidationException 422 under the fields, the current password wrong too
     * @throws TooManyAttemptsException 429
     */
    public function password(Request $request, Response $response): Response
    {
        $customer = Customer::of($request);
        $user = $this->email->changePassword($customer->user, $customer->session, $request, $this->input($request));

        return $this->json($response, ['customer' => AccountPresenter::present($user)]);
    }

    /** GET /me/sessions — newest first, the request's own marked `current`. */
    public function sessions(Request $request, Response $response): Response
    {
        $customer = Customer::of($request);
        $sessions = $this->sessions->list($customer->user)
            ->map(static fn(CustomerSession $session): array => CustomerSessions::present($session, $customer->session))
            ->values()
            ->all();

        return $this->json($response, ['sessions' => $sessions]);
    }

    /**
     * DELETE /me/sessions/{id} — one of theirs ended, this one included; another customer's is a 404.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function endSession(Request $request, Response $response, array $args): Response
    {
        $this->sessions->end(Customer::of($request)->user, $this->routeId($args));

        return $this->noContent($response);
    }

    /** POST /auth/logout — this device signed out. */
    public function logout(Request $request, Response $response): Response
    {
        $this->sessions->close(Customer::of($request)->session);

        return $this->noContent($response);
    }

    /** What GET /me and PATCH /me answer. */
    private function account(Request $request, Response $response): Response
    {
        $customer = Customer::of($request);

        return $this->json($response, [
            'customer' => AccountPresenter::present($customer->user),
            'unread_notifications' => $this->notifications->unread($customer->user),
            'staff' => StaffPresenter::present(StoreMiddleware::website($request), $customer),
            'loyalty' => $this->loyalty->standing($customer->user),
        ]);
    }
}
