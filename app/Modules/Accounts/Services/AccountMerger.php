<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Accounts\Enums\WayIn;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\AccountMerge;
use App\Modules\Bots\Models\Bot;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Two accounts of one person in a shop made one: a customer who signed up on the website and then links the Telegram
 * account that holds their services (or a Google account, an email). The older account stays, whichever asked. Everything
 * the other owned moves to it (REFERENCES), and its ways in and profile fill the survivor's empty slots (takeOn()).
 *
 * Refused before anything changes (refusal()): an account with itself, either banned, a different way in of one kind on
 * each, two agents. One transaction, both rows locked in id order and judged again as they are then; recorded in
 * `account_merges` and logged. A «همکار» group never comes into an admin's or an agent's account by it
 * (leaveAgentGroups()). After it, each service whose client on its panel names its owner otherwise than the survivor now
 * is relabelled (relabel()).
 */
final class AccountMerger
{
    /** Who merged two accounts when the customer did, from their website (`account_merges.actor`); else a panel's principal. */
    public const BY_CUSTOMER = 'customer';

    /**
     * Every column that points at a customer (each foreign key on `users` in database/schema.php, plus `bots.user_id`,
     * which has none because users are made after bots) and how a merge carries it: `move` the rows to the survivor;
     * `union` by `key` (a row the survivor already has for that key goes, the rest move); `unkey` by `key` (rows that
     * record what happened all move, a key the survivor already has taken off the merged account's); `drop` them;
     * `ledger` the wallet lines into one ledger (WalletService::takeOver()); `referrer` the customers it brought, never
     * the survivor itself. `as` names the count in `account_merges.moved`. A new such column goes here:
     * AccountMergerReferencesTest reads the schema and fails while one is missing.
     */
    public const REFERENCES = [
        'users.referred_by' => ['how' => 'referrer', 'as' => 'referrals'],
        'agency_requests.user_id' => ['how' => 'move', 'as' => 'agency_requests'],
        'wallet_transactions.user_id' => ['how' => 'ledger', 'as' => 'wallet_lines'],
        // Money sent counts against the one account's daily cap; a website request's key both accounts sent stays the survivor's.
        'wallet_transfers.sender_id' => ['how' => 'unkey', 'key' => 'request_key', 'as' => 'wallet_transfers_sent'],
        'wallet_transfers.recipient_id' => ['how' => 'move', 'as' => 'wallet_transfers_received'],
        'customer_group_user.user_id' => ['how' => 'union', 'key' => 'customer_group_id', 'as' => 'groups'],
        'customer_sessions.user_id' => ['how' => 'move', 'as' => 'sessions'],
        'auth_challenges.user_id' => ['how' => 'drop'],
        'account_merges.user_id' => ['how' => 'move', 'as' => 'merges'],
        'notifications.user_id' => ['how' => 'move', 'as' => 'notifications'],
        'subscriptions.user_id' => ['how' => 'move', 'as' => 'subscriptions'],
        // The tests both accounts took count against the one they become: a merge never gives a test back.
        'test_accounts.user_id' => ['how' => 'move', 'as' => 'test_accounts'],
        'orders.user_id' => ['how' => 'move', 'as' => 'orders'],
        // A website request's key names one order a customer: a key both accounts used stays the survivor's.
        'request_keys.user_id' => ['how' => 'union', 'key' => 'key', 'as' => 'request_keys'],
        'referral_commissions.referrer_id' => ['how' => 'move', 'as' => 'commissions'],
        'tickets.user_id' => ['how' => 'move', 'as' => 'tickets'],
        'reviews.user_id' => ['how' => 'move', 'as' => 'reviews'],
        'broadcasts.user_id' => ['how' => 'move', 'as' => 'broadcasts'],
        'broadcast_pins.user_id' => ['how' => 'union', 'key' => 'broadcast_id', 'as' => 'pins'],
        'bots.user_id' => ['how' => 'move', 'as' => 'bots'],
        'gift_codes.redeemed_by' => ['how' => 'move', 'as' => 'gift_codes'],
        'wheel_spins.user_id' => ['how' => 'move', 'as' => 'wheel_spins'],
        'cashbacks.user_id' => ['how' => 'move', 'as' => 'cashbacks'],
        'lottery_winners.user_id' => ['how' => 'move', 'as' => 'lottery_wins'],
        // A newcomer's start gift is once a newcomer: one both accounts were given stays the survivor's (both rows are the
        // shop's, so the shop's id is the key every row shares).
        'referral_bonuses.invitee_id' => ['how' => 'union', 'key' => 'bot_id', 'as' => 'referral_bonuses'],
        'referral_bonuses.referrer_id' => ['how' => 'move', 'as' => 'referral_bonuses_given'],
        'referral_rewards.referrer_id' => ['how' => 'union', 'key' => 'milestone', 'as' => 'referral_rewards'],
        'referral_alerts.referrer_id' => ['how' => 'move', 'as' => 'referral_alerts'],
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly WalletService $wallet,
        private readonly ProvisioningService $provisioning,
        private readonly ClientNaming $naming,
        private readonly LoggerInterface $logger,
    ) {}

    /** Why the two accounts cannot be made one, worded for the customer; null when they can. Asked again under the locks. */
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
     * Make the two accounts one; answers the older, which stays, as it stands after.
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

        [$survivor, $merge, $labels, $left] = $this->db->transaction(function () use ($keep, $go, $actor): array {
            $locked = User::query()->whereKey([$keep, $go])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $survivor = $locked->get($keep) ?? throw AccountRefusedException::offerGone();
            $merged = $locked->get($go) ?? throw AccountRefusedException::offerGone();
            $refusal = $this->refusal($survivor, $merged);
            if ($refusal !== null) {
                throw $refusal;
            }
            $labels = $this->labels($survivor, $merged);
            $left = $this->leaveAgentGroups($survivor, $merged);

            $moved = [];
            foreach (self::REFERENCES as $reference => $rule) {
                [$table, $column] = explode('.', $reference);
                $count = match ($rule['how']) {
                    'move' => $this->db->table($table)->where($column, $merged->id)->update([$column => $survivor->id]),
                    'union' => $this->union($table, $column, $rule['key'] ?? '', $survivor, $merged),
                    'unkey' => $this->unkey($table, $column, $rule['key'] ?? '', $survivor, $merged),
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

            return [User::query()->findOrFail($survivor->id), $merge, $labels, $left];
        });

        $this->logger->info('Customer #{merged} was merged into customer #{survivor} by {actor}', ['merged' => $merge->merged_user_id, 'survivor' => $survivor->id, 'actor' => $actor, 'moved' => $merge->moved, 'agent_groups_left' => $left]);
        $this->relabel($survivor, $labels);

        return $survivor;
    }

    /**
     * What each service of the two accounts — gone from its panel aside — says of its owner there before the merge: its
     * owner's comment (ClientNaming::comment(), which carries the Telegram id), by service id.
     *
     * @return array<int, string>
     */
    private function labels(User $survivor, User $merged): array
    {
        $comments = [$survivor->id => $this->naming->comment($survivor), $merged->id => $this->naming->comment($merged)];

        return array_map(
            static fn(mixed $owner): string => $comments[(int) $owner],
            $this->db->table('subscriptions')->whereIn('user_id', [$survivor->id, $merged->id])->where('status', '!=', SubscriptionStatus::Deleted->value)->pluck('user_id', 'id')->all(),
        );
    }

    /**
     * Relabel (ProvisioningService::relabel()) each service whose client names its owner otherwise than the survivor now:
     * every one the merged account had, unless the survivor took its Telegram account, and the survivor's own once it
     * did. A panel that fails it is logged there; the merge stands.
     *
     * @param array<int, string> $labels labels()
     */
    private function relabel(User $survivor, array $labels): void
    {
        $now = $this->naming->comment($survivor);
        $stale = array_keys(array_filter($labels, static fn(string $was): bool => $was !== $now));
        if ($stale !== []) {
            $this->provisioning->relabelServicesOf($survivor, $stale);
        }
    }

    /**
     * The merged account's ways in and profile fill the survivor's empty slots, then its row goes. Its unique values are
     * cleared first, so no unique index sees one twice. A slot takes what belongs with it: a Telegram account with its
     * handle and `bot_blocked`, an email with its password and two-factor columns, the two names together.
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
        // Who brought them — their own, else the merged account's: never the account they are now, nor the one that is
        // gone, nor one they brought themselves down a chain of referrals (who would then be brought by whom they brought).
        $referrer = null;
        foreach ([$survivor->referred_by, $merged->referred_by] as $candidate) {
            if ($candidate !== null && $candidate !== $survivor->id && $candidate !== $merged->id && !$this->broughtBy($candidate, $survivor->id)) {
                $referrer = $candidate;
                break;
            }
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
        // Their own discount («تخفیف اختصاصی»): the larger of the two the shop gave them.
        if ((int) $merged->discount_percent > (int) $survivor->discount_percent) {
            $fill += $take('discount_percent');
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

    /**
     * Whether `$customer` is up `$referrer`'s chain of referrers (the merged account's customers already the survivor's):
     * `$referrer` was brought, at some remove, by them.
     */
    private function broughtBy(int $referrer, int $customer): bool
    {
        $seen = [];
        for ($at = $referrer; !isset($seen[$at]); $at = (int) $next) {
            if ($at === $customer) {
                return true;
            }
            $seen[$at] = true;
            $next = $this->db->table('users')->where('id', $at)->value('referred_by');
            if ($next === null) {
                return false;
            }
        }

        return false;
    }

    /**
     * A «همکار» group sets its customers' prices, so a merge never brings one into a shop admin's or an agent's account —
     * the way around the website's guard (UserActions::assignGroups()): an admin's other account put in one, then merged
     * with theirs. While one side is an admin or an agent and the other neither, the other side's «همکار» memberships go
     * before the groups are joined (the plain groups join as ever); the admin's or agent's own, the panel's to give, stay.
     * Answers how many went.
     */
    private function leaveAgentGroups(User $survivor, User $merged): int
    {
        $privileged = static fn(User $user): bool => $user->isAdmin() || self::isAgent($user);
        $plain = match (true) {
            $privileged($survivor) && !$privileged($merged) => $merged,
            $privileged($merged) && !$privileged($survivor) => $survivor,
            default => null,
        };
        if ($plain === null) {
            return 0;
        }

        return $this->db->table('customer_group_user')
            ->where('user_id', $plain->id)
            ->whereIn('customer_group_id', $this->db->table('customer_groups')->where('agent_pricing', true)->select('id'))
            ->delete();
    }

    /** Move a table keyed by the customer and `$key`: the merged account's rows for keys the survivor has already go, the rest move. */
    private function union(string $table, string $column, string $key, User $survivor, User $merged): int
    {
        $held = $this->db->table($table)->where($column, $survivor->id)->pluck($key)->all();
        if ($held !== []) {
            $this->db->table($table)->where($column, $merged->id)->whereIn($key, $held)->delete();
        }

        return $this->db->table($table)->where($column, $merged->id)->update([$column => $survivor->id]);
    }

    /**
     * Move a table whose rows record what happened (a wallet transfer), keyed by the customer and a nullable `$key`: every
     * row moves; a key the survivor already has is taken off the merged account's row first, the survivor's answering it.
     */
    private function unkey(string $table, string $column, string $key, User $survivor, User $merged): int
    {
        $held = $this->db->table($table)->where($column, $survivor->id)->whereNotNull($key)->pluck($key)->all();
        if ($held !== []) {
            $this->db->table($table)->where($column, $merged->id)->whereIn($key, $held)->update([$key => null]);
        }

        return $this->db->table($table)->where($column, $merged->id)->update([$column => $survivor->id]);
    }

    /** The account a merge keeps: the one made earlier, or at the same moment the lower id. */
    public static function older(User $a, User $b): User
    {
        return $a->created_at->lt($b->created_at) || ($a->created_at->eq($b->created_at) && $a->id < $b->id) ? $a : $b;
    }

    /** An agent now or once: on a level, or owning a bot. */
    private static function isAgent(User $user): bool
    {
        return $user->isAgent() || Bot::query()->where('user_id', $user->id)->exists();
    }
}
