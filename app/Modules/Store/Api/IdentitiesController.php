<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Core\Mail\MailFailedException;
use App\Modules\Accounts\DTO\MergeOffer;
use App\Modules\Accounts\Enums\WayIn;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Exceptions\SignInsBusyException;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Services\AccountPresenter;
use App\Modules\Accounts\Services\Identities;
use App\Modules\Accounts\Services\MergeOffers;
use App\Modules\Accounts\Services\TelegramSignIn;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's ways in (Accounts\Services\Identities): a Telegram account, a Google account or an email added
 * — the customer's account as it stands, or, the way in being another account's of the shop, a merge offered (202) —,
 * one taken away, and a merge offered taken (Accounts\Services\MergeOffers): the account that stays, this request's
 * session its own. And Telegram's redirect sign-in begun for the customer themselves — a state theirs alone, which
 * adding their Telegram account and proving it again take (Accounts\Services\TelegramSignIn::authorize()).
 */
final class IdentitiesController extends ApiController
{
    public function __construct(
        private readonly Identities $identities,
        private readonly MergeOffers $offers,
        private readonly TelegramSignIn $telegram,
    ) {}

    /**
     * POST /me/telegram/authorize — {redirect_uri, code_challenge}: where to send the browser to prove their Telegram
     * account, back to the site — the PKCE challenge of the verifier the site keeps; the state this session's own: no
     * other browser's request spends it, nor another device of theirs.
     *
     * @throws SignInRefusedException 422 Telegram sign-in, or its redirect flow, not set up
     * @throws ValidationException 422 on `redirect_uri`, on `code_challenge`
     * @throws TooManyAttemptsException 429
     * @throws SignInsBusyException 503
     */
    public function authorize(Request $request, Response $response): Response
    {
        $input = $this->input($request);
        $url = $this->telegram->authorize(StoreMiddleware::website($request), $request, Input::text($input, 'redirect_uri'), Input::text($input, 'code_challenge'), Customer::of($request));

        return $this->json($response, ['url' => $url]);
    }

    /**
     * POST /me/identities/telegram — {id_token, nonce} or {code, state, code_verifier}.
     *
     * @throws AccountRefusedException 422
     * @throws SignInRefusedException 422
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     * @throws TelegramUnreachableException 502
     */
    public function telegram(Request $request, Response $response): Response
    {
        return $this->linked($response, $this->identities->linkTelegram(StoreMiddleware::website($request), $request, Customer::of($request), $this->input($request)));
    }

    /**
     * POST /me/identities/google — {id_token, nonce}.
     *
     * @throws AccountRefusedException 422
     * @throws SignInRefusedException 422, 502
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     */
    public function google(Request $request, Response $response): Response
    {
        return $this->linked($response, $this->identities->linkGoogle(StoreMiddleware::website($request), $request, Customer::of($request)->user, $this->input($request)));
    }

    /**
     * POST /me/identities/email — {email, password}: a code to the address (202).
     *
     * @throws AccountRefusedException 422 the account has an email
     * @throws SignInRefusedException 503
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     * @throws MailFailedException 502
     */
    public function email(Request $request, Response $response): Response
    {
        return $this->json($response, ['expires_in' => $this->identities->sendEmailCode(StoreMiddleware::website($request), $request, Customer::of($request)->user, $this->input($request))], 202);
    }

    /**
     * POST /me/identities/email/verify — {email, code}.
     *
     * @throws AccountRefusedException 422
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     */
    public function verifyEmail(Request $request, Response $response): Response
    {
        return $this->linked($response, $this->identities->verifyEmail($request, Customer::of($request)->user, $this->input($request)));
    }

    /**
     * DELETE /me/identities/{telegram|google|email}
     *
     * @param array<string, string> $args
     * @throws AccountRefusedException 422 none of that kind, or the last way in
     */
    public function remove(Request $request, Response $response, array $args): Response
    {
        return $this->customer($response, $this->identities->remove(Customer::of($request)->user, WayIn::from($args['kind'] ?? '')));
    }

    /**
     * POST /me/merge — {token}: the two accounts one; the account that stays.
     *
     * @throws AccountRefusedException 422 the ticket no longer holds, or the merge the rules refuse now
     */
    public function merge(Request $request, Response $response): Response
    {
        $customer = Customer::of($request);

        return $this->customer($response, $this->offers->accept($customer->user, $customer->session, Input::text($this->input($request), 'token')));
    }

    /** A way in added (the customer's account) or another account's (the merge offered, a 202). */
    private function linked(Response $response, User|MergeOffer $outcome): Response
    {
        return $outcome instanceof MergeOffer ? $this->json($response, $outcome->present(), 202) : $this->customer($response, $outcome);
    }

    private function customer(Response $response, User $user): Response
    {
        return $this->json($response, ['customer' => AccountPresenter::present($user)]);
    }
}
