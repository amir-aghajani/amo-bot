<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Mail\Mailer;
use App\Core\Mail\MailFailedException;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\DTO\MergeOffer;
use App\Modules\Accounts\Enums\WayIn;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Mail\EmailRemoved;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Bots\Services\Bots;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A signed-in customer's ways into their account on the shop's website — a Telegram account, a Google account, an email
 * with its password (Enums\WayIn) — added and taken away. A way in is proven the way it signs in
 * (TelegramSignIn::account(), GoogleSignIn::account() — the customer's own redirect state, a proof that does not hold a
 * 422 on its field —, an emailed code — EmailSignIn::sendLinkCode(), proveLink()) before anything is said of it; then it
 * is this account's already (nothing to do), the account has another of its kind (refused: that one goes first), it is
 * nobody's (it is this account's from now on — a Telegram account with its profile, a Google account with the address
 * Google speaks for when the account has none and it is nobody's), or it is another account's of the shop — a merge
 * offered (MergeOffers), the other account described for the customer to judge; never to an account reached by its
 * address alone — an emailed code, Google speaking for it — whose password sign-in asks a second step, which the address
 * is no way past. Taking one away leaves at least one: an email goes with its password and two-factor sign-in (and the
 * address is told, EmailRemoved: it hears nothing of the account any more), a Telegram account with its handle (the bot
 * knows that Telegram account as a newcomer from its next message). Every write is one conditional UPDATE on the slot it
 * fills or empties, so two at once never leave an account with none, nor a way in on two accounts; every one is told the
 * customer, on every door the account has (CustomerNotifier). What changes the ways in asks a recent sign-in of the
 * session (Http\RecentSignInMiddleware).
 */
final class Identities
{
    public function __construct(
        private readonly TelegramSignIn $telegram,
        private readonly GoogleSignIn $google,
        private readonly EmailSignIn $email,
        private readonly MergeOffers $offers,
        private readonly CustomerNotifier $notifier,
        private readonly Mailer $mailer,
        private readonly Bots $bots,
    ) {}

    /**
     * A Telegram account added to the customer's — as its sign-in proves one: the popup's id_token, or the redirect's
     * code (with a state the customer asked for from this very session: POST /me/telegram/authorize).
     *
     * @param array<string, mixed> $input {id_token, nonce} or {code, state, code_verifier}
     * @throws AccountRefusedException 422 the account has another Telegram account; a merge the rules refuse
     * @throws SignInRefusedException 422 Telegram sign-in off
     * @throws ValidationException 422 a field missing, or on the proof's field: it does not hold
     * @throws TooManyAttemptsException 429
     * @throws TelegramUnreachableException 502
     */
    public function linkTelegram(SignInSite $site, ServerRequestInterface $request, Customer $customer, array $input): User|MergeOffer
    {
        $user = $customer->user;
        $account = $this->telegram->account($site, $request, $input, $customer);

        for ($try = 1; ; $try++) {
            if ($user->telegram_id === $account->id) {
                return $user;
            }
            if ($user->telegram_id !== null) {
                throw AccountRefusedException::otherTelegram();
            }
            $other = User::query()->where('telegram_id', $account->id)->first();
            if ($other !== null) {
                return $this->offers->offer($user, $other, ['telegram_id' => $account->id]);
            }
            if ($this->fill($user, WayIn::Telegram, ['telegram_id' => $account->id, 'bot_blocked' => false] + $account->profile, $try)) {
                return $this->added($user->refresh(), WayIn::Telegram);
            }
            $user->refresh();
        }
    }

    /**
     * A Google account added to the customer's, as Google's sign-in proves one: the account another of the shop's signs
     * in with — or, the address Google speaks for being another account's, that account — is a merge offered.
     *
     * @param array<string, mixed> $input {id_token, nonce}
     * @throws AccountRefusedException 422 the account has another Google account; a merge the rules refuse
     * @throws SignInRefusedException 422 Google sign-in off; 502 Google out of reach
     * @throws ValidationException 422 a field missing, or on `id_token`: the proof does not hold
     * @throws TooManyAttemptsException 429
     */
    public function linkGoogle(SignInSite $site, ServerRequestInterface $request, User $user, array $input): User|MergeOffer
    {
        $account = $this->google->account($site, $request, $input, $user);

        for ($try = 1; ; $try++) {
            if ($user->google_sub === $account->sub) {
                return $user;
            }
            if ($user->google_sub !== null) {
                throw AccountRefusedException::otherGoogle();
            }
            $other = User::query()->where('google_sub', $account->sub)->first();
            if ($other !== null) {
                return $this->offers->offer($user, $other, ['google_sub' => $account->sub], ['email' => $account->email]);
            }
            $owner = $account->email === null || $account->email === $user->email ? null : User::query()->where('email', $account->email)->first();
            if ($owner !== null) {
                return $this->offers->offer($user, $owner, ['email' => (string) $account->email], ['google_sub' => $account->sub], byAddress: true);
            }
            $email = $user->email === null && $account->email !== null ? ['email' => $account->email] : [];
            if ($this->fill($user, WayIn::Google, ['google_sub' => $account->sub] + $email, $try)) {
                return $this->added($user->refresh(), WayIn::Google);
            }
            $user->refresh();
        }
    }

    /**
     * An email for the customer's account, with the password chosen to sign in by it: its code sent. How long it waits.
     *
     * @param array<string, mixed> $input {email, password}
     * @throws AccountRefusedException 422 the account has an email
     * @throws SignInRefusedException 503 the shop's email does not go out
     * @throws ValidationException 422 under the fields
     * @throws TooManyAttemptsException 429
     * @throws MailFailedException 502
     */
    public function sendEmailCode(SignInSite $site, ServerRequestInterface $request, User $user, array $input): int
    {
        return $this->email->sendLinkCode($site, $request, $user, $input);
    }

    /**
     * The email added once its code is typed back: the account's from now on, with its password — or, another account's
     * of the shop, a merge offered, the address and its password carried for the account that stays.
     *
     * @param array<string, mixed> $input {email, code}
     * @throws AccountRefusedException 422 the account has an email; the address is an account's that asks a second step; a merge the rules refuse
     * @throws ValidationException 422 on `email`, on `code`
     * @throws TooManyAttemptsException 429
     */
    public function verifyEmail(ServerRequestInterface $request, User $user, array $input): User|MergeOffer
    {
        $proven = $this->email->proveLink($request, $user, $input);

        for ($try = 1; ; $try++) {
            if ($user->email !== null) {
                throw AccountRefusedException::hasEmail();
            }
            $other = User::query()->where('email', $proven['email'])->first();
            if ($other !== null) {
                return $this->offers->offer($user, $other, ['email' => $proven['email']], $proven, byAddress: true);
            }
            if ($this->fill($user, WayIn::Email, $proven, $try)) {
                return $this->added($user->refresh(), WayIn::Email);
            }
            $user->refresh();
        }
    }

    /**
     * A way in taken off the customer's account — never its last: an email with its password and two-factor sign-in (the
     * address told it is no way in any more), a Telegram account with its handle, a Google account. The customer is told.
     *
     * @throws AccountRefusedException 422 the account has none of that kind, or it is the last way in
     */
    public function remove(User $user, WayIn $kind): User
    {
        $column = $kind->column();
        $changes = match ($kind) {
            WayIn::Telegram => ['telegram_id' => null, 'username' => null, 'bot_blocked' => false],
            WayIn::Google => ['google_sub' => null],
            WayIn::Email => ['email' => null, 'password_hash' => null] + array_fill_keys(TwoFactor::COLUMNS, null),
        };
        $others = array_values(array_diff(WayIn::columns(), [$column]));
        $address = $user->email;

        $removed = User::query()->whereKey($user->id)->whereNotNull($column)
            ->where(static fn(Builder $left) => $left->whereNotNull($others[0])->orWhereNotNull($others[1]))
            ->update($changes);
        $user->refresh();
        if ($removed !== 1) {
            throw $user->getAttribute($column) === null ? AccountRefusedException::notLinked() : AccountRefusedException::lastWayIn();
        }

        $this->notifier->wayInRemoved($user, $kind->label());
        if ($kind === WayIn::Email && $address !== null) {
            $this->tellAddress($user, $address);
        }

        return $user;
    }

    /**
     * Fill the account's empty slot of `$kind` with a way in (`$values`): one conditional UPDATE while it is empty. False
     * when it did not — the slot filled meanwhile, or (the first time) the way in taken by another account in the same
     * moment: the caller looks again.
     *
     * @param array<string, mixed> $values
     */
    private function fill(User $user, WayIn $kind, array $values, int $try): bool
    {
        try {
            return User::query()->whereKey($user->id)->whereNull($kind->column())->update($values) === 1;
        } catch (UniqueConstraintViolationException $e) {
            if ($try > 1) {
                throw $e;
            }

            return false;
        }
    }

    /** The account a way in of `$kind` was added to, as it stands now: the customer told, on every door it has — the new one too. */
    private function added(User $user, WayIn $kind): User
    {
        $this->notifier->wayInAdded($user, $kind->label());

        return $user;
    }

    /** The address taken off the account is told — where the account's notices no longer reach it; a mail that did not go is the Mailer's to log. */
    private function tellAddress(User $user, string $address): void
    {
        if (!$this->mailer->ready()) {
            return;
        }

        try {
            $this->mailer->send(EmailRemoved::message($address, $this->bots->name($user->shop())));
        } catch (MailFailedException) {
            // The way in is gone whatever the mail server said: the account's own notice is kept on its website.
        }
    }
}
