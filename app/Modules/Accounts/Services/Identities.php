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
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A signed-in customer's ways into their account (Enums\WayIn: Telegram, Google, an email with its password), added and
 * taken away. A way in is proven the way it signs in before anything is said about it; then it is already this
 * account's, refused (the account has another of its kind), filled into the empty slot, or — another account of the
 * shop holds it — offered as a merge (MergeOffers).
 *
 * Every write is one conditional UPDATE on the slot it fills or empties, so two requests at once never leave an account
 * with no way in, nor a way in on two accounts. The customer is notified of every change on every channel they have, and
 * a Telegram account linked or taken off is what their services' clients on the panels name from then on
 * (ProvisioningService::relabelServicesOf()). The routes ask a recent sign-in of the session
 * (Http\RecentSignInMiddleware).
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
        private readonly ProvisioningService $provisioning,
    ) {}

    /**
     * Add the Telegram account a sign-in proof proves (the popup's id_token, or the redirect's code with a state this
     * very session asked for) — with its profile, which is Telegram's from then on.
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
        $account = $this->telegram->account($site, $request, $input, $customer);

        return $this->link($customer->user, WayIn::Telegram, function (User $user) use ($account): User|MergeOffer|array {
            if ($user->telegram_id === $account->id) {
                return $user;
            }
            if ($user->telegram_id !== null) {
                throw AccountRefusedException::otherTelegram();
            }
            $other = User::query()->where('telegram_id', $account->id)->first();

            return $other !== null
                ? $this->offers->offer($user, $other, ['telegram_id' => $account->id])
                : ['telegram_id' => $account->id, 'bot_blocked' => false] + $account->profile;
        });
    }

    /**
     * Add the Google account its id_token proves — and the address Google speaks for, when the account has none. Another
     * account signing in with that Google account, or holding that address, is offered as a merge.
     *
     * @param array<string, mixed> $input {id_token, nonce}
     * @throws AccountRefusedException 422 the account has another Google account; a merge the rules refuse
     * @throws SignInRefusedException 422 Google sign-in off; 502 Google out of reach
     * @throws ValidationException 422 a field missing, or on `id_token`: the proof does not hold
     * @throws TooManyAttemptsException 429
     */
    public function linkGoogle(SignInSite $site, ServerRequestInterface $request, Customer $customer, array $input): User|MergeOffer
    {
        $account = $this->google->account($site, $request, $input, $customer);

        return $this->link($customer->user, WayIn::Google, function (User $user) use ($account): User|MergeOffer|array {
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

            return ['google_sub' => $account->sub] + ($user->email === null && $account->email !== null ? ['email' => $account->email] : []);
        });
    }

    /**
     * Send the code that proves an email the customer adds to their account (with the password they chose for it). How
     * long it waits.
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
     * Add the email once its code is typed back, with its password — or, another account holding the address, offer a
     * merge that carries both to the account that stays.
     *
     * @param array<string, mixed> $input {email, code}
     * @throws AccountRefusedException 422 the account has an email; the address's account asks a second step; a merge the rules refuse
     * @throws ValidationException 422 on `email`, on `code`
     * @throws TooManyAttemptsException 429
     */
    public function verifyEmail(ServerRequestInterface $request, User $user, array $input): User|MergeOffer
    {
        $proven = $this->email->proveLink($request, $user, $input);

        return $this->link($user, WayIn::Email, function (User $user) use ($proven): MergeOffer|array {
            if ($user->email !== null) {
                throw AccountRefusedException::hasEmail();
            }
            $other = User::query()->where('email', $proven['email'])->first();

            return $other !== null ? $this->offers->offer($user, $other, ['email' => $proven['email']], $proven, byAddress: true) : $proven;
        });
    }

    /**
     * Take a way in off the account, never its last: an email goes with its password and two-factor sign-in (and the
     * address is mailed that it is no way in any more), a Telegram account with its handle. The customer is notified.
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

        // Only while another way in stays: two removals at once never leave none.
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
        if ($kind === WayIn::Telegram) {
            $this->provisioning->relabelServicesOf($user);
        }

        return $user;
    }

    /**
     * Add a way in of `$kind`. `$decide` looks at the account as it stands and answers the account (the way in is its own
     * already), a merge offer (another account's), or the values to fill the empty slot with — or throws when the account
     * has another of the kind. The slot is filled by one conditional UPDATE while it is empty; a race lost (the slot filled
     * meanwhile, or the way in taken by another account) reads the account again and decides afresh. The customer is
     * notified.
     *
     * @param \Closure(User): (User|MergeOffer|array<string, mixed>) $decide
     */
    private function link(User $user, WayIn $kind, \Closure $decide): User|MergeOffer
    {
        for ($try = 1; ; $try++) {
            $decided = $decide($user);
            if (!is_array($decided)) {
                return $decided;
            }
            if ($this->fill($user, $kind, $decided, $try)) {
                $this->notifier->wayInAdded($user->refresh(), $kind->label());
                if ($kind === WayIn::Telegram) {
                    $this->provisioning->relabelServicesOf($user);
                }

                return $user;
            }
            $user->refresh();
        }
    }

    /**
     * Fill the account's empty slot of `$kind`. False when it was filled meanwhile, or — the first try — the way in was
     * taken by another account at the same moment (its unique index); a second such clash is a bug, not a race.
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

    /** Mail the address taken off the account, which its notices no longer reach. A mail that does not go is the Mailer's to log. */
    private function tellAddress(User $user, string $address): void
    {
        if (!$this->mailer->ready()) {
            return;
        }

        try {
            $this->mailer->send(EmailRemoved::message($address, $this->bots->name($user->shop())));
        } catch (MailFailedException) {
            // The way in is gone whatever the mail server said; the account's own notice is kept on its website.
        }
    }
}
