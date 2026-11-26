<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Core\Database\Ledger;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Modules\Users\Services\WalletService;
use App\Support\Mobile;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * The customers stage, quietly: a customer of the other bot is the shop's customer of the same Telegram account —
 * linked, gaining only what they lack —, or a new one written as though they had always been here: no newcomer report,
 * no referral gift, joined on the day they joined the other bot (never Users\Services\Customers, which registers a
 * newcomer). Then their group, and their balance as one wallet line — a debt as a debit their credit covers —, credited
 * once however many imports bring it. One transaction a customer, tried again on a deadlock as every money write is. An
 * account linked as the owner in the bot (OwnerLinks) is never banned nor made an admin by an import — only the owner
 * decides those of their own accounts —: the rest of it comes, and the report says so (ProblemReason::Refused).
 */
final class CustomerWriter
{
    /** The wallet line a balance comes as: «موجودی منتقل‌شده از Faoxima». */
    public const LINE = 'موجودی منتقل‌شده از %s';

    /** Why an account linked as the owner came without the other bot's ban or admin's role (its problem's detail). */
    public const OWNERS_ACCOUNT = 'حساب تلگرام مالک در ربات است: مسدودی یا مدیر شدنش از ربات قبلی آورده نشد و بقیه اطلاعاتش آمد.';

    private const KIND = 'customers';
    private const BALANCES = 'balances';

    /** The longest Telegram username the shop keeps. */
    private const USERNAME_MAX = 64;

    /** Telegram's first day: a join before it (or one in the future) is no date at all, and the customer joins today. */
    private const FIRST_DAY = '2013-08-14';

    public function __construct(
        private readonly WalletService $wallet,
        private readonly Links $links,
        private readonly ConnectionInterface $db,
        private readonly OwnerLinks $owners,
    ) {}

    public function write(Run $run, ImportedCustomer $customer): void
    {
        if ($customer->reseller !== null && !$run->choices->on('resellers')) {
            $run->skipped(self::KIND, $customer->key, ProblemReason::Reseller);

            return;
        }
        if ($customer->telegramId <= 0) {
            $run->skipped(self::KIND, $customer->key, ProblemReason::NotATelegramAccount, self::who($customer));

            return;
        }

        // What the transaction did is counted once it is committed: a try the database took back counts nothing.
        [$outcome, $credited, $spared] = $this->db->transaction(function () use ($run, $customer): array {
            if ($this->links->brought($run->import, Links::CUSTOMER, $customer->key)) {
                // Merged into another account since: that one (Links::customer()).
                $id = $this->links->customer($run->import, $customer->key);
                $user = $id === null ? null : User::query()->find($id);
                if ($user === null) {
                    // Deleted by the owner since: theirs to remove — never brought back, nor their balance.
                    return ['removed', false, false];
                }
                // Brought before: nothing of theirs is written again, but what came to be known since — that they bought
                // there, their group, a balance that did not come then — comes now.
                if ($customer->boughtBefore && !$user->boughtElsewhere()) {
                    $user->forceFill(['bought_elsewhere_at' => now()])->save();
                }
                $this->join($run, $user, $customer);

                return ['linked', $this->balance($run, $user, $customer), false];
            }

            [$user, $outcome, $spared] = $this->find($run, $customer);
            $this->join($run, $user, $customer);
            $this->links->add($run->import, Links::CUSTOMER, $customer->key, $user->id);

            return [$outcome, $this->balance($run, $user, $customer), $spared];
        }, Ledger::ATTEMPTS);

        match ($outcome) {
            'created' => $run->created(self::KIND),
            'removed' => $run->skipped(self::KIND, $customer->key, ProblemReason::Removed),
            default => $run->linked(self::KIND),
        };
        if ($credited) {
            $run->created(self::BALANCES);
        }
        if ($spared) {
            $run->problem(self::KIND, $customer->key, ProblemReason::Refused, self::OWNERS_ACCOUNT);
        }
    }

    /**
     * The shop's customer of their Telegram account, given what they lack (linked), or a new one (created) — the one the
     * bot made of them a moment ago, should they arrive meanwhile: the row is the unique index's to decide. And whether a
     * ban or an admin's role was left out for an account linked as the owner (spared()).
     *
     * @return array{User, 'created'|'linked', bool}
     */
    private function find(Run $run, ImportedCustomer $customer): array
    {
        $user = User::query()->where('telegram_id', $customer->telegramId)->first();
        if ($user === null) {
            try {
                [$user, $spared] = $this->db->transaction(fn(): array => $this->make($run, $customer));

                return [$user, 'created', $spared];
            } catch (UniqueConstraintViolationException) {
                // A locking read: MySQL's snapshot of this transaction would not show the row the bot just committed.
                $user = User::query()->where('telegram_id', $customer->telegramId)->lockForUpdate()->firstOrFail();
            }
        }
        [$user, $spared] = $this->fill($run, $user, $customer);

        return [$user, 'linked', $spared];
    }

    /**
     * A customer the shop does not have: their row as a newcomer's, but the day they joined the other bot.
     *
     * @return array{User, bool} The row, and whether a ban or an admin's role was left out (spared())
     */
    private function make(Run $run, ImportedCustomer $customer): array
    {
        $profile = Customers::profile($customer->username, $customer->firstName, $customer->lastName);
        $joined = $customer->joinedAt === null ? null : Carbon::instance($customer->joinedAt)->utc();
        $banned = $customer->banned && $run->choices->on('banned');
        $admin = $customer->admin && $run->choices->on('admins');
        $spared = $this->spared($customer, $banned, $admin);
        $user = new User();
        $user->forceFill([
            'telegram_id' => $customer->telegramId,
            'username' => $profile['username'] === null ? null : Text::fit($profile['username'], self::USERNAME_MAX),
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name'],
            'phone' => $run->choices->on('phones') ? self::mobile($customer->phone) : null,
            'phone_exempt' => $customer->phoneExempt && $run->choices->on('phones'),
            'status' => $banned && !$spared ? UserStatus::Banned : UserStatus::Active,
            'role' => $admin && !$spared ? UserRole::Admin : UserRole::Customer,
            'discount_percent' => $run->choices->on('discounts') ? self::percent($customer->discountPercent) : 0,
            // Bought there: no newcomer here (User::boughtElsewhere()).
            'bought_elsewhere_at' => $customer->boughtBefore ? now() : null,
            'created_at' => $joined !== null && $joined->gte(self::FIRST_DAY) && $joined->lte(now()) ? $joined : now(),
        ])->save();

        return [$user, $spared];
    }

    /**
     * A customer the shop has already: only what their row lacks — a username, a name, a mobile or an excuse from giving
     * one, a discount of their own, that they bought there — and what the owner chose to bring over it: a ban, an admin's
     * role.
     *
     * @return array{User, bool} The row, and whether a ban or an admin's role was left out (spared())
     */
    private function fill(Run $run, User $user, ImportedCustomer $customer): array
    {
        $profile = Customers::profile($customer->username, $customer->firstName, $customer->lastName);
        $banned = $customer->banned && $run->choices->on('banned') && !$user->isBanned();
        $admin = $customer->admin && $run->choices->on('admins') && !$user->isAdmin();
        $spared = $this->spared($customer, $banned, $admin);
        $fill = array_filter([
            'username' => $user->username === null && $profile['username'] !== null ? Text::fit($profile['username'], self::USERNAME_MAX) : null,
            'first_name' => $user->first_name === null ? $profile['first_name'] : null,
            'last_name' => $user->last_name === null ? $profile['last_name'] : null,
            'phone' => $user->phone === null && $run->choices->on('phones') ? self::mobile($customer->phone) : null,
            'phone_exempt' => $customer->phoneExempt && $run->choices->on('phones') && !$user->phone_exempt ? true : null,
            'discount_percent' => $user->discount_percent === 0 && $run->choices->on('discounts') && self::percent($customer->discountPercent) > 0 ? self::percent($customer->discountPercent) : null,
            'status' => $banned && !$spared ? UserStatus::Banned : null,
            'role' => $admin && !$spared ? UserRole::Admin : null,
            'bought_elsewhere_at' => $customer->boughtBefore && !$user->boughtElsewhere() ? now() : null,
        ], static fn(mixed $value): bool => $value !== null);
        if ($fill !== []) {
            $user->forceFill($fill)->save();
        }

        return [$user, $spared];
    }

    /**
     * Whether the ban or the admin's role the import would write (`$banned`, `$admin`) is left out: the account is linked
     * as the owner in the bot (OwnerLinks), whose standing only the owner decides — UserActions refuses anyone else a ban
     * of it, and an admin's role there would outlive the link.
     */
    private function spared(ImportedCustomer $customer, bool $banned, bool $admin): bool
    {
        return ($banned || $admin) && $this->owners->isOwner($customer->telegramId);
    }

    /**
     * Into the group of their name (a group link the groups stage made), when the owner brings groups — once (a
     * membership link): taken out of it by the owner since, they stay out.
     */
    private function join(Run $run, User $user, ImportedCustomer $customer): void
    {
        if ($customer->group === null || !$run->choices->on('groups') || $this->links->brought($run->import, Links::MEMBERSHIP, $customer->key)) {
            return;
        }
        // Never a group the owner deleted since: its row would refuse the customer, and their balance with them; nor is it
        // made again (brought once).
        $group = $this->links->row($run->import, Links::GROUP, $customer->group, CustomerGroup::class);
        if ($group !== null) {
            $user->groups()->syncWithoutDetaching([$group]);
            $this->links->add($run->import, Links::MEMBERSHIP, $customer->key, $group);
        }
    }

    /**
     * Their balance as one wallet line, when the owner brings balances and no import of this bot brought it before (its
     * `balance` link — whatever became of that line since: a balance is credited once, ever): a credit, or for a debt a
     * debit the credit allows — the debt, below whatever the customer is in debt here already. True when a line was
     * written.
     */
    private function balance(Run $run, User $user, ImportedCustomer $customer): bool
    {
        if (!$run->choices->on('balances') || !is_numeric($customer->balance) || Money::compare($customer->balance, 0) === 0 || $this->links->brought($run->import, Links::BALANCE, $customer->key)) {
            return false;
        }
        $description = sprintf(self::LINE, $run->label);
        $debt = Money::subtract(0, $customer->balance);
        $line = Money::isPositive($customer->balance)
            ? $this->wallet->credit($user, $customer->balance, $description)
            : $this->wallet->debit($user, $debt, $description, credit: Money::add($debt, Money::atLeastZero(Money::subtract(0, $user->balance()))));
        $this->links->add($run->import, Links::BALANCE, $customer->key, $line->id);

        return true;
    }

    /** A number Support\Mobile reads as an Iranian mobile, as the shop keeps one; null for anything else. */
    private static function mobile(?string $phone): ?string
    {
        return $phone === null ? null : Mobile::of($phone);
    }

    private static function percent(int $percent): int
    {
        return max(0, min(100, $percent));
    }

    /** What finds a customer without a Telegram account in the other bot: their username and names, when they have any. */
    private static function who(ImportedCustomer $customer): ?string
    {
        $profile = Customers::profile($customer->username, $customer->firstName, $customer->lastName);
        $words = array_filter([$profile['username'] === null ? null : Run::isolate('@' . $profile['username']), trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))], static fn(?string $part): bool => $part !== null && $part !== '');

        return $words === [] ? null : implode(' — ', $words);
    }
}
