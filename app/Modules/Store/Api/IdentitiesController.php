<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Core\Mail\MailFailedException;
use App\Core\Sms\SmsFailedException;
use App\Modules\Accounts\DTO\MergeOffer;
use App\Modules\Accounts\Enums\WayIn;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Services\AccountPresenter;
use App\Modules\Accounts\Services\Identities;
use App\Modules\Accounts\Services\MergeOffers;
use App\Modules\Accounts\Services\PhoneSignIn;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's ways in (Accounts\Services\Identities): one added (answered with the account, or a 202 merge
 * offer when it is another account's), one removed, an offered merge accepted — all behind a recent sign-in
 * (RecentSignInMiddleware). Telegram's redirect for this session begins at AuthController::authorize().
 */
final class IdentitiesController extends ApiController
{
    public function __construct(
        private readonly Identities $identities,
        private readonly MergeOffers $offers,
    ) {}

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
        return $this->linked($response, $this->identities->linkGoogle(StoreMiddleware::website($request), $request, Customer::of($request), $this->input($request)));
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
        return $this->json($response, ['expires_in' => $this->identities->sendEmailCode(StoreMiddleware::door($request), $request, Customer::of($request)->user, $this->input($request))], 202);
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
     * POST /me/identities/phone — {phone}: a code texted to the number (202).
     *
     * @throws AccountRefusedException 422 the account has a number
     * @throws SignInRefusedException 422 sign-in by SMS off, 503 the SMS budget spent
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     * @throws SmsFailedException 502
     */
    public function phone(Request $request, Response $response): Response
    {
        return $this->json($response, PhoneSignIn::sent($this->identities->sendPhoneCode(StoreMiddleware::website($request), $request, Customer::of($request)->user, $this->input($request))), 202);
    }

    /**
     * POST /me/identities/phone/verify — {phone, code}.
     *
     * @throws AccountRefusedException 422
     * @throws SignInRefusedException 422 sign-in by SMS off
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     */
    public function verifyPhone(Request $request, Response $response): Response
    {
        return $this->linked($response, $this->identities->verifyPhone(StoreMiddleware::website($request), $request, Customer::of($request)->user, $this->input($request)));
    }

    /** What taking the Telegram account off from inside the mini app answers. */
    public const TELEGRAM_STAYS = 'حساب تلگرام از داخل مینی‌اپ جدا نمی‌شود؛ مینی‌اپ و ربات شما را با همین حساب می‌شناسند. این کار را از وب‌سایت فروشگاه انجام دهید.';

    /**
     * DELETE /me/identities/{telegram|google|email|phone} — the Telegram account not through the mini app: the app and
     * the bot know the customer by it, so their next launch would be a stranger's new account. A mobile number is a way
     * in that stays only where a sign-in by SMS opens this very account by it (Identities::remove()).
     *
     * @param array<string, string> $args
     * @throws AccountRefusedException 422 none of that kind, or the last way in
     * @throws ValidationException 422 on `kind`: the Telegram account, through the mini app
     */
    public function remove(Request $request, Response $response, array $args): Response
    {
        $kind = WayIn::from($args['kind'] ?? '');
        if ($kind === WayIn::Telegram && StoreMiddleware::door($request) instanceof MiniApp) {
            throw ValidationException::on('kind', self::TELEGRAM_STAYS);
        }

        return $this->customer($response, $this->identities->remove(StoreMiddleware::door($request), Customer::of($request)->user, $kind));
    }

    /**
     * POST /me/merge — {token}: the two accounts made one; answers the survivor.
     *
     * @throws AccountRefusedException 422 the ticket no longer holds, or the merge is refused now
     */
    public function merge(Request $request, Response $response): Response
    {
        $customer = Customer::of($request);

        return $this->customer($response, $this->offers->accept($customer->user, $customer->session, Input::text($this->input($request), 'token')));
    }

    /** A way in added (the account), or another account's (a 202 merge offer). */
    private function linked(Response $response, User|MergeOffer $outcome): Response
    {
        return $outcome instanceof MergeOffer ? $this->json($response, $outcome->present(), 202) : $this->customer($response, $outcome);
    }

    private function customer(Response $response, User $user): Response
    {
        return $this->json($response, ['customer' => AccountPresenter::present($user)]);
    }
}
