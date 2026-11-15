<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Imports\Survey\SurveyAdmin;
use App\Support\Input;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * MirzaBot's people as it keeps them — and Faoxima's, descended from it: its customers (`user`) with their wallets, agent
 * levels and the reseller bots (`botsaz`) they came by, its admins (`admin`) with their roles. census() counts them for
 * the owner's review.
 */
final class People
{
    /** What the bot writes for a customer its admin excused from giving a phone (its spelling, and the right one). */
    private const PHONE_EXCUSED = ['confrim number by admin', 'confirm number by admin'];

    /** What the bot writes for a phone it has not got. */
    private const NO_PHONE = ['none', ...self::PHONE_EXCUSED];

    /** What the bot writes for a Telegram account without a @username. */
    private const NO_USERNAME = ['none', 'null', 'not_username'];

    /** Its full admins (`admin.rule`); its Seller and support staff are listed, never made admins here. */
    private const ADMINISTRATOR = 'administrator';

    /** A customer its admins blocked (`User_Status`). */
    private const BLOCKED = 'block';

    /** Its agent levels (`user.agent`) and the customer groups, paying agent prices, they join; `f` is a customer's. */
    public const GROUPS = ['n' => 'نماینده عادی', 'n2' => 'نماینده پیشرفته'];

    /** The advanced agents' level: only they buy on credit, below a zero balance. */
    private const SENIOR = 'n2';

    /** A reseller bot a customer belongs to that the bot no longer has. */
    private const GONE_RESELLER = 'ربات نماینده حذف‌شده';

    /**
     * What the invoices tell of each customer, by key — those tied to the bot itself (a reseller bot of their own, an
     * invoice in the bot itself) and those who bought —, read in one pass the first time a customer is asked for
     * (customer()): a services step, which asks only reseller(), never reads them.
     *
     * @var array{ownBots: array<string, true>, buyers: array<string, true>}|null
     */
    private ?array $invoices = null;

    /**
     * @param array<string, ?string> $admins By Telegram id, each with their role
     * @param array<string, string> $resellers By token, each named
     * @param array<string, true> $owners Customers who own a reseller bot, by key
     * @param (\Closure(array<string, ?string>): bool)|null $bought
     */
    private function __construct(
        private readonly Staged $staged,
        private readonly array $admins,
        private readonly array $resellers,
        private readonly array $owners,
        private readonly ?\Closure $bought,
    ) {}

    /**
     * @param (\Closure(array<string, ?string>): bool)|null $bought Whether an invoice made its customer one who had bought
     *                                                              there — each bot tells its own buyers; null for no one
     */
    public static function of(Staged $staged, ?\Closure $bought = null): self
    {
        $admins = [];
        foreach ($staged->all('admin') as $row) {
            $key = trim((string) ($row['id_admin'] ?? ''));
            if ($key !== '') {
                $admins[$key] = Cell::text($row['rule'] ?? null);
            }
        }
        // Each reseller bot by its token: named by its @username, else its owner's id.
        $resellers = [];
        $owners = [];
        foreach ($staged->all('botsaz') as $row) {
            $token = Cell::text($row['bot_token'] ?? null);
            if ($token !== null) {
                $username = Cell::text($row['username'] ?? null);
                $resellers[$token] = $username !== null ? '@' . ltrim($username, '@') : trim('ربات نماینده ' . Cell::text($row['id_user'] ?? null));
            }
            $owners[trim((string) ($row['id_user'] ?? ''))] = true;
        }

        return new self($staged, $admins, $resellers, $owners, $bought);
    }

    /**
     * Every customer (customer()) after the `$after`th row.
     *
     * @return iterable<int, ImportedCustomer>
     */
    public function customers(int $after = 0): iterable
    {
        foreach ($this->staged->rows('user', $after) as $seq => $row) {
            yield $seq => $this->customer($row);
        }
    }

    /**
     * A customer: their Telegram id is their key (text that is no id comes with id 0, for the core to name), the agent
     * level their group, `affiliates` who brought them. An admin only when the bot's full admin (`administrator`): its
     * Seller and support staff are no admins here. An agency whose end (`expire`) has come is a customer's.
     *
     * A reseller bot's customer only when nothing ties them to the bot itself: `bottype` names the reseller bot they
     * last opened — the bot writes it at every visit there and never clears it —, so anyone with a wallet here (a reseller
     * bot's customers keep theirs in its files), an agent's level, an admin's role, a reseller bot of their own or a
     * purchase in the bot itself is its own customer.
     *
     * @param array<string, ?string> $row
     */
    public function customer(array $row): ImportedCustomer
    {
        $key = trim((string) ($row['id'] ?? ''));
        $referrer = Cell::text($row['affiliates'] ?? null);
        $username = ltrim(trim((string) ($row['username'] ?? '')), '@');
        $phone = trim((string) ($row['number'] ?? ''));
        $agencyEnds = Cell::moment($row['expire'] ?? null);
        $balance = Cell::amount($row['Balance'] ?? null);
        $level = strtolower(trim((string) ($row['agent'] ?? '')));
        $invoices = $this->invoices();
        $ownCustomer = Money::compare($balance, 0) !== 0 || isset(self::GROUPS[$level]) || array_key_exists($key, $this->admins) || isset($invoices['ownBots'][$key]);

        return new ImportedCustomer(
            key: $key,
            telegramId: Input::telegramIdOf($key) ?? 0,
            username: preg_match('/^\w{1,64}$/', $username) === 1 && !in_array(strtolower($username), self::NO_USERNAME, true) ? $username : null,
            phone: preg_match('/\d/', $phone) === 1 && !in_array(strtolower($phone), self::NO_PHONE, true) ? $phone : null,
            banned: strtolower(trim((string) ($row['User_Status'] ?? ''))) === self::BLOCKED,
            balance: $balance,
            discountPercent: min(100, max(0, Cell::whole($row['pricediscount'] ?? null))),
            referrerKey: $referrer !== null && $referrer !== '0' && $referrer !== $key ? $referrer : null,
            group: $agencyEnds !== null && $agencyEnds <= Carbon::now() ? null : self::GROUPS[$level] ?? null,
            admin: strtolower((string) ($this->admins[$key] ?? '')) === self::ADMINISTRATOR,
            joinedAt: Cell::moment($row['register'] ?? null),
            reseller: $ownCustomer ? null : $this->reseller($row['bottype'] ?? null),
            boughtBefore: isset($invoices['buyers'][$key]),
            phoneExempt: in_array(strtolower($phone), self::PHONE_EXCUSED, true),
        );
    }

    /** The reseller bot a token (`bottype`) is of, by its name; null for the bot itself (no token, or «0»). */
    public function reseller(?string $token): ?string
    {
        return self::ofTheBot($token) ? null : $this->resellers[(string) Cell::text($token)] ?? self::GONE_RESELLER;
    }

    /**
     * Who owns a reseller bot or has an invoice in the bot itself (unpaid too), and who bought, by key: every invoice read
     * once, for both.
     *
     * @return array{ownBots: array<string, true>, buyers: array<string, true>}
     */
    private function invoices(): array
    {
        if ($this->invoices === null) {
            $ownBots = $this->owners;
            $buyers = [];
            foreach ($this->staged->rows('invoice') as $row) {
                $key = trim((string) ($row['id_user'] ?? ''));
                if (self::ofTheBot($row['bottype'] ?? null)) {
                    $ownBots[$key] = true;
                }
                if ($this->bought !== null && ($this->bought)($row)) {
                    $buyers[$key] = true;
                }
            }
            $this->invoices = ['ownBots' => $ownBots, 'buyers' => $buyers];
        }

        return $this->invoices;
    }

    /** Whether a row's `bottype` is the bot itself's: no reseller bot's token (none, or «0»). */
    private static function ofTheBot(?string $token): bool
    {
        $token = Cell::text($token);

        return $token === null || $token === '0';
    }

    /**
     * The customers as the owner reviews them. A reseller bot's customers are counted apart (`resellers`) and left out of
     * the balances, groups and the rest: they come only when the owner says so.
     */
    public function census(): Census
    {
        $counts = ['customers' => 0, 'resellers' => 0, 'balances' => 0, 'debtors' => 0, 'agency_ends' => 0, 'personal' => 0, 'senior' => 0, 'credit_caps' => 0];
        $balanceTotal = '0';
        $debt = '0';
        $groups = array_fill_keys(self::GROUPS, 0);
        $helpers = 0;
        $admins = [];
        foreach ($this->staged->rows('user') as $row) {
            $customer = $this->customer($row);
            $counts['customers']++;
            if (array_key_exists($customer->key, $this->admins)) {
                if ($customer->admin) {
                    $admins[] = new SurveyAdmin($customer->key, $customer->telegramId, $customer->username, $this->admins[$customer->key]);
                } else {
                    $helpers++;
                }
            }
            if ($customer->reseller !== null) {
                $counts['resellers']++;

                continue;
            }
            if (Money::compare($customer->balance, 0) !== 0) {
                $counts['balances']++;
                $balanceTotal = Money::add($balanceTotal, $customer->balance);
            }
            if (Money::compare($customer->balance, 0) < 0) {
                $counts['debtors']++;
                $debt = Money::subtract($debt, $customer->balance);
            }
            if ($customer->group !== null) {
                $groups[$customer->group]++;
                // An agency whose end has not come yet (one past it is a customer's: no group).
                $counts['agency_ends'] += Cell::moment($row['expire'] ?? null) !== null ? 1 : 0;
            }
            if ($customer->group === self::GROUPS[self::SENIOR]) {
                $counts['senior']++;
                $counts['credit_caps'] += Cell::whole($row['maxbuyagent'] ?? null) > 0 ? 1 : 0;
            }
            $counts['personal'] += $customer->discountPercent > 0 ? 1 : 0;
        }

        return new Census(
            customers: $counts['customers'],
            resellers: $counts['resellers'],
            balances: $counts['balances'],
            balanceTotal: $balanceTotal,
            debtors: $counts['debtors'],
            debt: $debt,
            groups: array_filter($groups),
            agencyEnds: $counts['agency_ends'],
            personal: $counts['personal'],
            seniorAgents: $counts['senior'],
            creditCaps: $counts['credit_caps'],
            helpers: $helpers,
            admins: $admins,
        );
    }
}
