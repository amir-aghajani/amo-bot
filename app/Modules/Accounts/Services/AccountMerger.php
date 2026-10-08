<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Accounts\Enums\WayIn;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\AccountMerge;
use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Two accounts of one person in a shop made one — a customer who signed up on the website and then linked the Telegram
 * account that has their services, an email, a Google account. The older account stays (`created_at`, then its number),
 * whichever asked; the other is merged into it and goes: everything it owned is the survivor's (REFERENCES — orders and
 * so their payments, services, the wallet's lines in one ledger again, sessions, the notices it was told, its support
 * tickets, its reviews of the shop, groups, a broadcast's pins, the customers it brought, an agency request, an agent's
 * bot), and its ways in and profile fill the survivor's empty slots (a Telegram account with its handle, an email with
 * its password and two-factor sign-in, a Google account, the name, the phone, the invite code, who brought it, an agency,
 * the bot's admin role, the later visit). Refused before anything changes (refusal()): an account with itself, either
 * banned, a different way in of one kind on each (two Telegram accounts, two Google accounts, two emails — one goes
 * first), two agents. One transaction, both rows locked in id order and judged again as they are then; recorded in
 * `account_merges` and logged.
 */
final class AccountMerger
{
    /** Who merged two accounts when the customer did, from their website (`account_merges.actor`); else a panel's principal. */
    public const BY_CUSTOMER = 'customer';

    /**
     * Every column of the shop's tables that points at a customer — each foreign key on `users` in database/schema.php,
     * and `bots.user_id` (an agent's own bot, which has none: the users come after the bots) — and how a merge carries
     * what it points at to the account that stays: `move` (the merged account's rows are the survivor's), `union` (a row
     * the survivor has already for the same `key` goes, the rest move: a group, a broadcast's pin), `drop` (gone: a
     * sign-in's short-lived secrets), `ledger` (the wallet's lines, the balance counted again — WalletService::takeOver()),
     * `referrer` (the customers it brought — never the survivor itself). `as` is what the merge's record counts it
     * under (`account_merges.moved`). A table that adds such a column adds it here: AccountMergerReferencesTest reads the
     * schema and fails while one is missing.
     */
    public const REFERENCES = [
        'users.referred_by' => ['how' => 'referrer', 'as' => 'referrals'],
        'agency_requests.user_id' => ['how' => 'move', 'as' => 'agency_requests'],
        'wallet_transactions.user_id' => ['how' => 'ledger', 'as' => 'wallet_lines'],
        'customer_group_user.user_id' => ['how' => 'union', 'key' => 'customer_group_id', 'as' => 'groups'],
        'customer_sessions.user_id' => ['how' => 'move', 'as' => 'sessions'],
        'auth_challenges.user_id' => ['how' => 'drop'],
        'account_merges.user_id' => ['how' => 'move', 'as' => 'merges'],
        'notifications.user_id' => ['how' => 'move', 'as' => 'notifications'],
        'subscriptions.user_id' => ['how' => 'move', 'as' => 'subscriptions'],
        'orders.user_id' => ['how' => 'move', 'as' => 'orders'],
        // A website request's key names one order a customer: a key both accounts used stays the survivor's.
        'request_keys.user_id' => ['how' => 'union', 'key' => 'key', 'as' => 'request_keys'],
        'referral_commissions.referrer_id' => ['how' => 'move', 'as' => 'commissions'],
        'tickets.user_id' => ['how' => 'move', 'as' => 'tickets'],
        'reviews.user_id' => ['how' => 'move', 'as' => 'reviews'],
        'broadcasts.user_id' => ['how' => 'move', 'as' => 'broadcasts'],
        'broadcast_pins.user_id' => ['how' => 'union', 'key' => 'broadcast_id', 'as' => 'pins'],
        'bots.user_id' => ['how' => 'move', 'as' => 'bots'],
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly WalletService $wallet,
        private readonly LoggerInterface $logger,
    ) {}

    /** Why the two accounts cannot be made one, in the customer's words; null when they can. */
    public function refusal(User $a, User $b): ?AccountRefusedException
    {
        if ($a->id === $b->id) {
            return AccountRefusedException::sameAccount();
        }
        if ($a->isBanned() || $b->isBanned()) {
            return AccountRefusedException::banned();
        }

        // Every kind of way in an account has one of at most, as the refusal names them.
        $both = [];
        foreach (WayIn::cases() as $kind) {
            $mine = $a->getAttribute($kind->column());
            $theirs = $b->getAttribute($kind->column());
            if ($mine !== null && $theirs !== null && $mine !== $theirs) {
                $both[] = $kind->label();
            }
        }
        if ($both !== []) {
            return AccountRefusedException::bothHave($both);
        }

        return self::isAgent($a) && self::isAgent($b) ? AccountRefusedException::bothAgents() : null;
    }

    /**
     * Make the two accounts one — the older stays — and answer it, as it stands after.
     *
     * @throws AccountRefusedException 422 when they cannot be one (refusal()), now or as they are once locked
     */
    public function merge(User $a, User $b, string $actor): User
    {
        if ($a->bot_id !== $b->bot_id) {
            throw new \LogicException('The customers of two shops are never one.');
        }
        $refusal = $this->refusal($a, $b);
        if ($refusal !== null) {
            throw $refusal;
        }
        [$keep, $go] = self::older($a, $b) === $a ? [$a->id, $b->id] : [$b->id, $a->id];

        [$survivor, $merge] = $this->db->transaction(function () use ($keep, $go, $actor): array {
            $locked = User::query()->whereKey([$keep, $go])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $survivor = $locked->get($keep) ?? throw AccountRefusedException::offerGone();
            $merged = $locked->get($go) ?? throw AccountRefusedException::offerGone();
            $refusal = $this->refusal($survivor, $merged);
            if ($refusal !== null) {
                throw $refusal;
            }

            $moved = [];
            foreach (self::REFERENCES as $reference => $rule) {
                [$table, $column] = explode('.', $reference);
                $count = match ($rule['how']) {
                    'move' => $this->db->table($table)->where($column, $merged->id)->update([$column => $survivor->id]),
                    'union' => $this->union($table, $column, $rule['key'] ?? '', $survivor, $merged),
                    'drop' => $this->db->table($table)->where($column, $merged->id)->delete(),
                    'ledger' => $this->wallet->takeOver($survivor, $merged),
                    'referrer' => $this->db->table($table)->where($column, $merged->id)->where('id', '!=', $survivor->id)->update([$column => $survivor->id]),
                };
                if (isset($rule['as'])) {
                    $moved[$rule['as']] = $count;
                }
            }

            $this->takeOn($survivor, $merged);
            $merge = AccountMerge::query()->forceCreate([
                'bot_id' => $survivor->bot_id,
                'user_id' => $survivor->id,
                'merged_user_id' => $merged->id,
                'merged' => [
                    'telegram_id' => $merged->telegram_id,
                    'username' => $merged->username,
                    'email' => $merged->email,
                    'google' => $merged->google_sub !== null,
                    'first_name' => $merged->first_name,
                    'last_name' => $merged->last_name,
                    'created_at' => $merged->created_at->toIso8601String(),
                ],
                'moved' => $moved,
                'actor' => $actor,
            ]);

            return [User::query()->findOrFail($survivor->id), $merge];
        });

        $this->logger->info('Customer #{merged} was merged into customer #{survivor} by {actor}', ['merged' => $merge->merged_user_id, 'survivor' => $survivor->id, 'actor' => $actor, 'moved' => $merge->moved]);

        return $survivor;
    }

    /**
     * The merged account's ways in and profile fill the survivor's empty slots — taken off it first, so no unique index
     * sees one twice — and the merged row goes. A slot is filled with what belongs to it: a Telegram account with its
     * handle and whether it turned the bot away, an email with its password and two-factor sign-in, the names together.
     */
    private function takeOn(User $survivor, User $merged): void
    {
        $raw = $merged->getAttributes();
        $take = static fn(string ...$columns): array => array_intersect_key($raw, array_flip($columns));

        $fill = [];
        if ($survivor->telegram_id === null && $merged->telegram_id !== null) {
            $fill += $take('telegram_id', 'username', 'bot_blocked');
        }
        if ($survivor->email === null && $merged->email !== null) {
            $fill += $take('email', 'password_hash', ...TwoFactor::COLUMNS);
        }
        if ($survivor->google_sub === null && $merged->google_sub !== null) {
            $fill += $take('google_sub');
        }
        if ($survivor->name() === null && $merged->name() !== null) {
            $fill += $take('first_name', 'last_name');
        }
        if ($survivor->phone === null && $merged->phone !== null) {
            $fill += $take('phone');
        }
        if ($survivor->referral_code === null && $merged->referral_code !== null) {
            $fill += $take('referral_code');
        }
        // Who brought them: never the account they are now, nor the one that is gone.
        $referrer = $survivor->referred_by === $merged->id ? null : $survivor->referred_by;
        if ($referrer === null && $merged->referred_by !== null && $merged->referred_by !== $survivor->id) {
            $referrer = $merged->referred_by;
        }
        if ($referrer !== $survivor->referred_by) {
            $fill['referred_by'] = $referrer;
        }
        if (!$survivor->isAgent() && $merged->isAgent()) {
            $fill += $take('agency_level_id', 'credit_limit');
        }
        if ($merged->isAdmin() && !$survivor->isAdmin()) {
            $fill['role'] = UserRole::Admin->value;
        }
        if ($merged->last_seen_at !== null && ($survivor->last_seen_at === null || $merged->last_seen_at->gt($survivor->last_seen_at))) {
            $fill += $take('last_seen_at');
        }

        $this->db->table('users')->where('id', $merged->id)->update(['telegram_id' => null, 'email' => null, 'google_sub' => null, 'referral_code' => null]);
        if ($fill !== []) {
            $this->db->table('users')->where('id', $survivor->id)->update($fill + ['updated_at' => now()]);
        }
        $this->db->table('users')->where('id', $merged->id)->delete();
    }

    /** A table keyed by the customer and `$key`: what the survivor has already for a key goes from the merged one, the rest moves. */
    private function union(string $table, string $column, string $key, User $survivor, User $merged): int
    {
        $held = $this->db->table($table)->where($column, $survivor->id)->pluck($key)->all();
        if ($held !== []) {
            $this->db->table($table)->where($column, $merged->id)->whereIn($key, $held)->delete();
        }

        return $this->db->table($table)->where($column, $merged->id)->update([$column => $survivor->id]);
    }

    /** The older of the two accounts — the one a merge keeps: a customer since earlier, or (the same moment) numbered first. */
    public static function older(User $a, User $b): User
    {
        return $a->created_at->lt($b->created_at) || ($a->created_at->eq($b->created_at) && $a->id < $b->id) ? $a : $b;
    }

    /** An agent, or one once: on a level, or with a bot of their own. */
    private static function isAgent(User $user): bool
    {
        return $user->isAgent() || Bot::query()->where('user_id', $user->id)->exists();
    }
}
