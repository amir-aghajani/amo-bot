<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Database\Ledger;
use App\Core\Exceptions\KeyReusedException;
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
 * Support's decisions about a customer's account, from the panels, the website's admin side or the report group: ban,
 * the admin role (the panels and the report group), their groups, a wallet adjustment, turning two-factor sign-in off,
 * signing out everywhere. An admin never adjusts their own wallet; from the website never touches an admin's or an
 * agent's account; from the report group never their own account nor an agent's, never bans an admin (the role goes
 * first), never puts one in a «همکار» group (its agent prices would be theirs) nor makes an admin of someone in one or
 * with their own discount, never touches another admin's website sign-in. Each is logged with who did it.
 */
final class UserActions
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly TwoFactor $twoFactor,
        private readonly CustomerSessions $sessions,
        private readonly CustomerGroups $groups,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Bans the customer or lifts the ban.
     *
     * @param array<string, mixed> $input {status}
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's; from the report group, their
     *                               own account, an agent's, or an admin banned
     * @throws ValidationException 422 on `status`
     */
    public function setStatus(User $user, Actor $actor, array $input): User
    {
        $status = UserStatus::tryFrom(Input::text($input, 'status')) ?? throw ValidationException::on('status', 'وضعیت باید «فعال» یا «مسدود» باشد.');
        $this->mayTouch($user, $actor);
        if ($actor->isGroupAdmin() && $status === UserStatus::Banned && $user->isAdmin()) {
            throw ActorRefusedException::adminBanned();
        }

        $user->forceFill(['status' => $status])->save();
        $this->logger->info('Customer #{customer} made {status} by {reviewer}', ['customer' => $user->id, 'status' => $status->value, 'reviewer' => $actor->reviewer]);

        return $user;
    }

    /**
     * Gives or takes the admin role (UserRole). From the report group, never to someone with their own discount or in a
     * «همکار» group: an admin's prices are the panel's to set (PersonalDiscounts, assignGroups()), and the role taken
     * off, either set and the role given back would go round that.
     *
     * @param array<string, mixed> $input {role}
     * @throws ActorRefusedException 403 from the report group, their own account or an agent's, or made an admin with
     *                               their own discount or in a «همکار» group
     * @throws ValidationException 422 on `role`
     */
    public function setRole(User $user, Actor $actor, array $input): User
    {
        $role = UserRole::tryFrom(Input::text($input, 'role')) ?? throw ValidationException::on('role', 'نقش باید «مشتری» یا «مدیر» باشد.');
        $this->mayTouch($user, $actor);
        if ($actor->isGroupAdmin() && $role === UserRole::Admin && !$user->isAdmin() && ($user->discount_percent > 0 || $user->paysAgentPrices())) {
            throw ActorRefusedException::adminWithOwnPrices();
        }

        $user->forceFill(['role' => $role])->save();
        $this->logger->info('Customer #{customer} made {role} by {reviewer}', ['customer' => $user->id, 'role' => $role->value, 'reviewer' => $actor->reviewer]);

        return $user;
    }

    /**
     * Credits or debits the wallet by hand (a debit no further than the balance), with a note the customer sees, under the
     * actor's name — and under the request's Idempotency-Key `$key` when it sent one: the same change asked again is
     * answered by the line it wrote (`wasRecentlyCreated` false), never made twice.
     *
     * @param array<string, mixed> $input {type: credit|debit, amount, description?}
     * @throws ActorRefusedException 403 one of the shop's admins' own wallet; from the report group, an agent's
     * @throws ValidationException
     * @throws KeyReusedException 422 on `idempotency_key`: the key wrote another line
     */
    public function adjustWallet(User $user, Actor $actor, array $input, ?string $key = null): WalletTransaction
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
        // An agent's wallet carries their credit and their bot's traffic buying: the panel's, never the report group's.
        if ($actor->isGroupAdmin() && $user->isAgent()) {
            throw ActorRefusedException::agentAccount();
        }

        if ($type === WalletTransactionType::Credit) {
            return $this->wallet->credit($user, $amount, $note ?? WalletService::LINE_CREDIT_BY_SUPPORT, $actor->reviewer, $key);
        }
        try {
            return $this->wallet->debit($user, $amount, $note ?? WalletService::LINE_DEBIT_BY_SUPPORT, reviewer: $actor->reviewer, key: $key);
        } catch (InsufficientBalanceException $e) {
            throw ValidationException::on('amount', 'موجودی کاربر ' . Money::format($e->balance) . ' است و از این بیشتر نمی‌شود کم کرد.');
        }
    }

    /**
     * Puts the customer in the groups the admin ticked, every one listed and no other (CustomerGroups::assign()) — from
     * the website never an admin's account, their own included, nor an agent's: a «همکار» group would set their prices.
     *
     * @param mixed $ids The `group_ids` the panel sent
     * @throws ActorRefusedException 403 from the website, an admin's account or an agent's — or a «همکار» group added
     *                               without the `catalog` grant; from the report group, their own account, an
     *                               agent's, or an admin put in a «همکار» group
     * @throws ValidationException 422 on `group_ids` when one is not a group
     */
    public function assignGroups(User $user, Actor $actor, mixed $ids): void
    {
        $this->mayTouch($user, $actor);
        if ($actor->isGroupAdmin() && $user->isAdmin() && is_array($ids) && $this->groups->addsAgentPricing($user, $ids)) {
            throw ActorRefusedException::adminToAgentGroup();
        }

        $groups = $this->groups->assign($user, $actor, $ids);
        $this->logger->info('Customer #{customer} put in groups {groups} by {reviewer}', ['customer' => $user->id, 'groups' => $groups, 'reviewer' => $actor->reviewer]);
    }

    /**
     * Puts the customer in one group (`$in`) or takes them out of it, their other groups as they are — the report group's
     * switch, which names the group and the state it asks for, so it stands however often it is pressed and whoever
     * switches another group meanwhile (CustomerGroups::join(), leave()). By assignGroups()'s rules, and logged as it is.
     *
     * @throws ActorRefusedException 403 as assignGroups()
     * @throws ValidationException 422 on `group_ids` when it is not a group
     */
    public function switchGroup(User $user, Actor $actor, int $groupId, bool $in): void
    {
        $this->mayTouch($user, $actor);
        if ($in && $actor->isGroupAdmin() && $user->isAdmin() && $this->groups->addsAgentPricing($user, [$groupId])) {
            throw ActorRefusedException::adminToAgentGroup();
        }

        $groups = $in ? $this->groups->join($user, $actor, $groupId) : $this->groups->leave($user, $groupId);
        $this->logger->info('Customer #{customer} put in groups {groups} by {reviewer}', ['customer' => $user->id, 'groups' => $groups, 'reviewer' => $actor->reviewer]);
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
        $this->notAnAdminsSignIn($user, $actor);
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
        $this->notAnAdminsSignIn($user, $actor);
        $this->sessions->signOutEverywhere($user, $actor);
    }

    /**
     * From the report group, another admin's website sign-in — their two-factor, their devices — is the panel's: an
     * admin's sign-in opens the website's admin side.
     *
     * @throws ActorRefusedException 403
     */
    private function notAnAdminsSignIn(User $user, Actor $actor): void
    {
        if ($actor->isGroupAdmin() && $user->isAdmin()) {
            throw ActorRefusedException::adminAccount();
        }
    }

    /**
     * From the website, an admin's or an agent's account is off limits (the panels' to change); from the report group, the
     * admin's own account and an agent's.
     *
     * @throws ActorRefusedException 403
     */
    private function mayTouch(User $user, Actor $actor): void
    {
        if ($actor->isGroupAdmin()) {
            if ($actor->is($user->id)) {
                throw ActorRefusedException::ownAccount();
            }
            if ($user->isAgent()) {
                throw ActorRefusedException::agentAccount();
            }

            return;
        }
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
