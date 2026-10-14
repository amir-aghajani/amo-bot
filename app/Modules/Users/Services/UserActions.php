<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Database\Ledger;
use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Services\TwoFactor;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\Input;
use App\Support\Money;
use Psr\Log\LoggerInterface;

/**
 * Support's decisions about a customer's account, from the panels or the website's admin side: ban, the admin role
 * (panels only), a wallet adjustment, turning two-factor sign-in off, signing out everywhere. An admin never adjusts
 * their own wallet, and from the website never touches an admin's or an agent's account. Each is logged with who did it.
 */
final class UserActions
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly TwoFactor $twoFactor,
        private readonly CustomerSessions $sessions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Bans the customer or lifts the ban.
     *
     * @param array<string, mixed> $input {status}
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's
     * @throws ValidationException 422 on `status`
     */
    public function setStatus(User $user, Actor $actor, array $input): User
    {
        $status = UserStatus::tryFrom(Input::text($input, 'status')) ?? throw ValidationException::on('status', 'وضعیت باید «فعال» یا «مسدود» باشد.');
        $this->mayTouch($user, $actor);

        $user->forceFill(['status' => $status])->save();
        $this->logger->info('Customer #{customer} made {status} by {reviewer}', ['customer' => $user->id, 'status' => $status->value, 'reviewer' => $actor->reviewer]);

        return $user;
    }

    /**
     * Gives or takes the admin role (UserRole).
     *
     * @param array<string, mixed> $input {role}
     * @throws ValidationException 422 on `role`
     */
    public function setRole(User $user, Actor $actor, array $input): User
    {
        $role = UserRole::tryFrom(Input::text($input, 'role')) ?? throw ValidationException::on('role', 'نقش باید «مشتری» یا «مدیر» باشد.');

        $user->forceFill(['role' => $role])->save();
        $this->logger->info('Customer #{customer} made {role} by {reviewer}', ['customer' => $user->id, 'role' => $role->value, 'reviewer' => $actor->reviewer]);

        return $user;
    }

    /**
     * Credits or debits the wallet by hand (a debit no further than the balance), with a note the customer sees, under the
     * actor's name.
     *
     * @param array<string, mixed> $input {type: credit|debit, amount, description?}
     * @throws ActorRefusedException 403 one of the shop's admins' own wallet
     * @throws ValidationException
     */
    public function adjustWallet(User $user, Actor $actor, array $input): WalletTransaction
    {
        $errors = [];

        $type = WalletTransactionType::tryFrom(Input::text($input, 'type'));
        if ($type === null) {
            $errors['type'][] = 'نوع تغییر باید افزایش یا کاهش باشد.';
        }

        $amount = Input::amount($input, 'amount');
        if ($amount === null || $amount <= 0 || $amount > Money::AMOUNT_MAX) {
            $errors['amount'][] = 'مبلغ باید عددی مثبت به تومان و بدون اعشار باشد.';
        }

        $note = null;
        try {
            $note = Input::note($input, 'description', Ledger::NOTE_MAX);
        } catch (ValidationException $e) {
            $errors += $e->errors();
        }
        if ($type === null || $amount === null || $errors !== []) {
            throw new ValidationException($errors);
        }
        if ($actor->is($user->id)) {
            throw ActorRefusedException::ownWallet();
        }

        if ($type === WalletTransactionType::Credit) {
            return $this->wallet->credit($user, $amount, $note ?? WalletService::LINE_CREDIT_BY_SUPPORT, $actor->reviewer);
        }
        try {
            return $this->wallet->debit($user, $amount, $note ?? WalletService::LINE_DEBIT_BY_SUPPORT, reviewer: $actor->reviewer);
        } catch (InsufficientBalanceException $e) {
            throw ValidationException::on('amount', 'موجودی کاربر ' . Money::format($e->balance) . ' است و از این بیشتر نمی‌شود کم کرد.');
        }
    }

    /**
     * Turns the customer's website two-factor sign-in off (they lost the phone); they are notified.
     *
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's
     * @throws AccountRefusedException 422 it is not on
     */
    public function disableTwoFactor(User $user, Actor $actor): void
    {
        $this->mayTouch($user, $actor);
        $this->twoFactor->turnOff($user, $actor);
    }

    /**
     * Signs the customer out of the website on every device.
     *
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's
     */
    public function endSessions(User $user, Actor $actor): void
    {
        $this->mayTouch($user, $actor);
        $this->sessions->signOutEverywhere($user, $actor);
    }

    /**
     * From the website, an admin's or an agent's account is off limits (the panels' to change).
     *
     * @throws ActorRefusedException 403
     */
    private function mayTouch(User $user, Actor $actor): void
    {
        if (!$actor->isStaff()) {
            return;
        }
        if ($user->isAdmin()) {
            throw ActorRefusedException::adminAccount();
        }
        if ($user->isAgent()) {
            throw ActorRefusedException::agentAccount();
        }
    }
}
