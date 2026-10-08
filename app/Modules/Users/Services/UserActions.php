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
 * What support decides about a customer's account — from either panel, or from the shop's website (its admins): a ban
 * or its end, the bot's admin role (the panels' alone), a credit or a debit of their wallet by hand, and their account on
 * the website taken in hand — two-factor sign-in turned off, every device signed out. Each held to who decides it (the
 * Actor): one of the shop's admins — its customer too — never changes their own wallet, and from the website never
 * touches an admin's account, their own included, nor an agent's. Logged with whoever did it.
 */
final class UserActions
{
    private const ADJUST_MAX = 1_000_000_000;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly TwoFactor $twoFactor,
        private readonly CustomerSessions $sessions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Ban the customer, or end their ban (`status`).
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
     * Give or take the bot's admin role (`role`: privileged commands, exempt from the bot's rules — and the shop's
     * website's admin side, while the website lets its admins in).
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
     * Credit or debit the wallet by hand, with a note the customer sees in their ledger — a debit no further than the
     * balance —, under the name of whoever wrote it.
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
        if ($amount === null || $amount <= 0 || $amount > self::ADJUST_MAX) {
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
     * Turn the customer's two-factor sign-in on the website off — the phone it was on lost (TwoFactor::turnOff(): they are
     * told, the log says who did it).
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
     * Sign the customer out of the website on every device (CustomerSessions::signOutEverywhere(), logged).
     *
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's
     */
    public function endSessions(User $user, Actor $actor): void
    {
        $this->mayTouch($user, $actor);
        $this->sessions->signOutEverywhere($user, $actor);
    }

    /**
     * An admin's account, and an agent's, are the panels' to change: the shop's admins on its website ban, unlock or sign
     * out customers, never one of themselves — another, or their own — nor an agent, the owner's partner.
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
