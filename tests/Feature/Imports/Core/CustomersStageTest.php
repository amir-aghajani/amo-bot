<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Models\ImportLink;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Writers\CustomerWriter;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeMigrator;

/**
 * The customers' stages: a newcomer written quietly as though always here, a customer the shop has matched by their
 * Telegram account and only given what they lack, the owner's switches (balances, bans, phones, discounts, groups,
 * admins, resellers' customers), their balance as one wallet line credited once — a debt as a debit —, who brought whom,
 * a customer merged since into another account, and the customers it cannot bring.
 */
final class CustomersStageTest extends ImportTestCase
{
    public function testANewcomerIsWrittenQuietlyAsThoughTheyHadAlwaysBeenHere(): void
    {
        $this->reportGroup();
        $this->migrator->customers = [new ImportedCustomer('1001', 1001, username: '@sara_k', firstName: 'سارا', lastName: 'کریمی', phone: '09121234567', discountPercent: 15, joinedAt: new \DateTimeImmutable('2024-03-01 10:00:00'))];

        $import = $this->importAll();

        $user = User::query()->where('telegram_id', 1001)->firstOrFail();
        self::assertSame(['sara_k', 'سارا', 'کریمی', '+989121234567', UserStatus::Active, UserRole::Customer, 15], [$user->username, $user->first_name, $user->last_name, $user->phone, $user->status, $user->role, $user->discount_percent]);
        self::assertSame('2024-03-01 10:00:00', $user->created_at?->format('Y-m-d H:i:s'), 'joined the day they joined the other bot');
        self::assertNull($user->last_seen_at, 'never seen by this shop yet');
        self::assertNull($user->referred_by, 'no referral attribution');
        self::assertSame(0, ReportMessage::query()->count(), 'no newcomer report');
        self::assertSame(ImportPhase::Done, $import->phase);
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($import)['customers']);
        self::assertSame($user->id, ImportLink::query()->where(['kind' => Links::CUSTOMER, 'source_key' => '1001'])->value('target_id'));
    }

    public function testACustomerTheShopHasIsLinkedAndGivenOnlyWhatTheyLack(): void
    {
        $known = $this->customer(['telegram_id' => 2002, 'username' => 'old_name', 'first_name' => null, 'discount_percent' => 5]);
        $this->migrator->customers = [new ImportedCustomer('x2', 2002, username: 'new_name', firstName: 'رضا', phone: '+98 912 765 4321', banned: true, discountPercent: 30, admin: true)];

        $import = $this->importAll(['admins' => true]);

        $known->refresh();
        self::assertSame(['old_name', 'رضا', '+989127654321', 5, UserStatus::Banned, UserRole::Admin], [$known->username, $known->first_name, $known->phone, $known->discount_percent, $known->status, $known->role]);
        self::assertSame(1, User::query()->count());
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($import)['customers']);
    }

    /**
     * Only the owner bans their own Telegram accounts or decides their role: an account linked as the owner in the bot
     * comes neither banned nor an admin, whatever the other bot says and the switches bring — the shop's row, or one
     * made now for a linked account the shop has no row of —, the rest of it as anyone's, and the report says so.
     */
    public function testAnAccountLinkedAsTheOwnerIsNeverBannedNorMadeAnAdminByAnImport(): void
    {
        $this->config(['telegram.username' => 'amo_shop_bot']);
        $owner = $this->customer(['telegram_id' => 7001, 'first_name' => null]);
        foreach ([7001, 7003, 7004] as $telegramId) {
            $links = $this->service(OwnerLinks::class);
            $link = $links->link();
            self::assertNotNull($link);
            self::assertTrue($links->redeem(substr($link['url'], (int) strpos($link['url'], '=') + 1), $telegramId));
        }
        $this->migrator->customers = [
            new ImportedCustomer('o1', 7001, firstName: 'امیر', phone: '09121234567', banned: true, discountPercent: 10, admin: true, balance: '5000'),
            new ImportedCustomer('r2', 7002, banned: true, admin: true),
            new ImportedCustomer('o3', 7003, firstName: 'امیر', banned: true, admin: true),
            new ImportedCustomer('o4', 7004, firstName: 'امیر'),
        ];

        $import = $this->importAll(['admins' => true]);

        $owner->refresh();
        self::assertSame([UserStatus::Active, UserRole::Customer, 'امیر', '+989121234567', 10, '5000.00'], [$owner->status, $owner->role, $owner->first_name, $owner->phone, $owner->discount_percent, $owner->balance()], 'the rest of it comes');
        $made = User::query()->where('telegram_id', 7003)->sole();
        self::assertSame([UserStatus::Active, UserRole::Customer], [$made->status, $made->role], 'a linked account the shop had no row of');
        $other = User::query()->where('telegram_id', 7002)->sole();
        self::assertSame([UserStatus::Banned, UserRole::Admin], [$other->status, $other->role], 'anyone else as the switches bring it');
        self::assertSame(['created' => 3, 'linked' => 1, 'skipped' => 0], self::counts($import)['customers']);
        self::assertSame([
            ['kind' => 'customers', 'key' => 'o1', 'reason' => ProblemReason::Refused->value, 'detail' => CustomerWriter::OWNERS_ACCOUNT],
            ['kind' => 'customers', 'key' => 'o3', 'reason' => ProblemReason::Refused->value, 'detail' => CustomerWriter::OWNERS_ACCOUNT],
        ], self::problemRows($import), 'said only where something was left out');
    }

    public function testACustomerTheBotMadeAMomentAgoIsLinkedNotMadeTwice(): void
    {
        $this->migrator->customers = [new ImportedCustomer('1', 1001, username: 'ali', balance: '5000')];
        $arrived = false;

        // The bot makes them right after the import looked for them.
        $import = $this->whileListening(QueryExecuted::class, function (QueryExecuted $query) use (&$arrived): void {
            if (!$arrived && str_starts_with($query->sql, 'select') && str_contains($query->sql, self::sqlTable('users')) && str_contains($query->sql, '"telegram_id" = ?')) {
                $arrived = true;
                $this->db()->table('users')->insert(['bot_id' => 1, 'telegram_id' => 1001, 'first_name' => 'Ali', 'created_at' => now(), 'updated_at' => now()]);
            }
        }, fn() => $this->importAll());

        $user = User::query()->where('telegram_id', 1001)->sole();
        self::assertSame(['ali', '5000.00'], [$user->username, $user->balance()]);
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($import)['customers']);
    }

    public function testTheOwnersSwitchesKeepBansPhonesDiscountsAndAdminsBack(): void
    {
        $this->migrator->customers = [new ImportedCustomer('3', 3003, phone: '09121234567', banned: true, discountPercent: 20, admin: true, phoneExempt: true)];

        $this->importAll(['banned' => false, 'phones' => false, 'discounts' => false]);

        $user = User::query()->where('telegram_id', 3003)->firstOrFail();
        self::assertSame([null, false, UserStatus::Active, 0, UserRole::Customer], [$user->phone, $user->phone_exempt, $user->status, $user->discount_percent, $user->role], 'admins are off by default');
    }

    public function testACustomerTheOtherBotExcusedFromAPhoneComesExcused(): void
    {
        $known = $this->customer(['telegram_id' => 4141]);
        $excused = $this->customer(['telegram_id' => 4242]);
        $excused->forceFill(['phone_exempt' => true])->save();
        $this->migrator->customers = [new ImportedCustomer('40', 4040, phoneExempt: true), new ImportedCustomer('41', 4141, phoneExempt: true), new ImportedCustomer('42', 4242), new ImportedCustomer('43', 4343)];

        $this->importAll();

        self::assertTrue(User::query()->where('telegram_id', 4040)->sole()->phone_exempt, 'a newcomer');
        self::assertTrue($known->refresh()->phone_exempt, 'a customer the shop has');
        self::assertTrue($excused->refresh()->phone_exempt, 'an excuse the shop gave stays');
        self::assertFalse(User::query()->where('telegram_id', 4343)->sole()->phone_exempt);
    }

    public function testOnlyAMobileIsKeptAsTheirPhone(): void
    {
        $this->migrator->customers = [new ImportedCustomer('4', 4004, phone: '+4915112345678')];

        $this->importAll();

        self::assertNull(User::query()->where('telegram_id', 4004)->value('phone'), 'another country\'s number is no mobile the shop texts');
    }

    public function testABalanceComesAsOneWalletLineAndADebtAsADebitCoveredByItself(): void
    {
        $known = $this->wallet($this->customer(['telegram_id' => 5005]), '10000');
        $this->migrator->customers = [
            new ImportedCustomer('5', 5005, balance: '25000'),
            new ImportedCustomer('6', 6006, balance: '-40000'),
            new ImportedCustomer('7', 7007, balance: '0'),
        ];

        $import = $this->importAll();

        self::assertSame('35000.00', $known->balance());
        $debtor = User::query()->where('telegram_id', 6006)->firstOrFail();
        self::assertSame('-40000.00', $debtor->balance());
        $line = WalletTransaction::query()->where('user_id', $debtor->id)->firstOrFail();
        self::assertSame(sprintf(CustomerWriter::LINE, FakeMigrator::LABEL), $line->description);
        self::assertSame('0.00', User::query()->where('telegram_id', 7007)->firstOrFail()->balance());
        self::assertSame(['created' => 2, 'linked' => 0, 'skipped' => 0], self::counts($import)['balances']);
        self::assertSame($line->id, ImportLink::query()->where(['kind' => Links::BALANCE, 'source_key' => '6'])->value('target_id'));
    }

    public function testABalanceIsCreditedOnceHoweverManyImportsBringIt(): void
    {
        $this->migrator->customers = [new ImportedCustomer('8', 8008, balance: '12000')];
        $this->importAll(['balances' => false]);
        $user = User::query()->where('telegram_id', 8008)->firstOrFail();
        self::assertSame('0.00', $user->balance());

        $again = $this->importAll();
        self::assertSame('12000.00', $user->balance(), 'a balance left behind before comes with the next import');
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($again)['customers']);

        $this->importAll();
        self::assertSame('12000.00', $user->balance(), 'and never twice');
        self::assertSame(1, WalletTransaction::query()->where('user_id', $user->id)->count());
    }

    public function testCustomersJoinTheirGroupFoundOrMadeByName(): void
    {
        $vip = CustomerGroup::query()->create(['name' => 'نماینده عادی', 'agent_pricing' => false, 'sort' => 1]);
        $this->migrator->customers = [
            new ImportedCustomer('9', 9009, group: 'نماینده عادی'),
            new ImportedCustomer('10', 1010, group: 'نماینده پیشرفته'),
        ];

        $import = $this->importAll();

        $made = CustomerGroup::query()->where('name', 'نماینده پیشرفته')->firstOrFail();
        self::assertTrue($made->agent_pricing, 'as the survey said');
        self::assertSame([$vip->id], User::query()->where('telegram_id', 9009)->firstOrFail()->groups()->pluck('customer_groups.id')->all());
        self::assertSame([$made->id], User::query()->where('telegram_id', 1010)->firstOrFail()->groups()->pluck('customer_groups.id')->all());
        self::assertSame(['created' => 1, 'linked' => 1, 'skipped' => 0], self::counts($import)['groups']);
    }

    public function testAGroupDeletedSinceStaysDeletedAndNobodyJoinsIt(): void
    {
        $this->migrator->customers = [new ImportedCustomer('9', 9009, group: 'نماینده عادی')];
        $this->importAll();
        CustomerGroup::query()->delete();
        $this->migrator->customers = [new ImportedCustomer('9', 9009, group: 'نماینده عادی'), new ImportedCustomer('10', 1010, balance: '7000', group: 'نماینده عادی')];

        $again = $this->importAll();

        self::assertSame(0, CustomerGroup::query()->count(), 'the owner deleted it: brought once, never made again');
        self::assertSame([], User::query()->where('telegram_id', 1010)->sole()->groups()->pluck('customer_groups.id')->all());
        self::assertSame('7000.00', User::query()->where('telegram_id', 1010)->sole()->balance(), 'never refused with the group gone');
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 1], self::counts($again)['groups']);
    }

    public function testAnAgentBroughtWithoutGroupsJoinsTheirGroupOnceGroupsComeAndOnlyOnce(): void
    {
        $this->migrator->customers = [new ImportedCustomer('9', 9009, group: 'نماینده عادی')];
        $this->importAll(['groups' => false]);

        $this->importAll();

        $group = CustomerGroup::query()->where('name', 'نماینده عادی')->sole();
        $user = User::query()->where('telegram_id', 9009)->sole();
        self::assertSame([$group->id], $user->groups()->pluck('customer_groups.id')->all(), 'the agent joins the group made for them');

        $user->groups()->detach();
        $this->importAll();
        self::assertSame(0, $user->groups()->count(), 'taken out by the owner since, they stay out');
    }

    public function testACustomerMergedSinceIntoAnotherAccountBringsTheirBalanceAndReferralsToTheOneThatStayed(): void
    {
        $this->telegram();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $web = $this->webCustomer(['first_name' => 'Sara']);
        Carbon::setTestNow('2026-11-13 12:00:00');
        $this->migrator->customers = [new ImportedCustomer('c1', 5001, balance: '30000')];
        $this->importAll(['balances' => false]);
        // They sign in to the website with their Telegram: the older, website account stays.
        $survivor = $this->service(AccountMerger::class)->merge($web->refresh(), User::query()->where('telegram_id', 5001)->sole(), AccountMerger::BY_CUSTOMER);
        self::assertSame($web->id, $survivor->id);
        $this->migrator->customers = [new ImportedCustomer('c1', 5001, balance: '30000'), new ImportedCustomer('c2', 6001, referrerKey: 'c1')];

        $again = $this->importAll();

        self::assertSame('30000.00', $survivor->refresh()->balance(), 'their balance reaches the account that stayed');
        self::assertSame($survivor->id, User::query()->where('telegram_id', 6001)->value('referred_by'), 'and whom they brought is theirs');
        self::assertSame(['created' => 1, 'linked' => 1, 'skipped' => 0], self::counts($again)['customers']);
    }

    public function testACustomerDeletedSinceIsNeverBroughtBackNorTheirBalance(): void
    {
        $this->migrator->customers = [new ImportedCustomer('8', 8008, balance: '12000')];
        $this->importAll();
        User::query()->where('telegram_id', 8008)->delete();

        $again = $this->importAll();

        self::assertSame(0, User::query()->where('telegram_id', 8008)->count(), 'the owner removed them: theirs to remove');
        self::assertSame(0, WalletTransaction::query()->count(), 'and their old balance is never credited again');
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 1], self::counts($again)['customers']);
        self::assertSame([], self::problemRows($again), 'only counted: nothing to act on');
    }

    public function testADebtLandsOnACustomerInDebtHereAlready(): void
    {
        $known = $this->wallet($this->customer(['telegram_id' => 6006]), '-15000');
        $this->migrator->customers = [new ImportedCustomer('6', 6006, balance: '-40000')];

        $this->importAll();

        self::assertSame('-55000.00', $known->balance(), 'both debts');
    }

    public function testWithoutGroupsNobodyJoinsOne(): void
    {
        $this->migrator->customers = [new ImportedCustomer('11', 1111, group: 'نماینده عادی')];

        $this->importAll(['groups' => false]);

        self::assertSame(0, CustomerGroup::query()->count());
        self::assertSame(0, User::query()->where('telegram_id', 1111)->firstOrFail()->groups()->count());
    }

    public function testResellersCustomersAndAccountsWithoutTelegramStayBehind(): void
    {
        $this->migrator->customers = [
            new ImportedCustomer('12', 1212, reseller: 'shop_bot'),
            new ImportedCustomer('13', 0, username: 'no_id', firstName: 'Bad'),
        ];

        $import = $this->importAll();

        self::assertSame(0, User::query()->count());
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 2], self::counts($import)['customers']);
        self::assertSame([['kind' => 'customers', 'key' => '13', 'reason' => 'not_telegram', 'detail' => "\u{2068}@no_id\u{2069} — Bad"]], self::problemRows($import));
        $byReason = array_column($this->imports()->present($import)['problems']['by_reason'], 'count', 'reason');
        self::assertSame(['reseller' => 1, 'not_telegram' => 1], $byReason);

        $this->importAll(['resellers' => true]);
        self::assertTrue(User::query()->where('telegram_id', 1212)->exists(), 'brought once the owner chooses them');
    }

    public function testWhoBroughtWhomIsSetWhereTheShopKnowsNoReferrer(): void
    {
        $other = $this->customer(['telegram_id' => 3030]);
        $known = $this->customer(['telegram_id' => 4040, 'referred_by' => $other->id]);
        $this->migrator->customers = [
            new ImportedCustomer('20', 2020, referrerKey: '21'),
            new ImportedCustomer('21', 2121),
            new ImportedCustomer('22', 4040, referrerKey: '21'),
            new ImportedCustomer('23', 2323, referrerKey: '23'),
            new ImportedCustomer('24', 2424, referrerKey: 'gone'),
        ];

        $this->importAll();

        $referrer = User::query()->where('telegram_id', 2121)->firstOrFail();
        self::assertSame($referrer->id, User::query()->where('telegram_id', 2020)->value('referred_by'));
        self::assertSame($other->id, $known->refresh()->referred_by, 'a referrer the shop knows stays');
        self::assertNull(User::query()->where('telegram_id', 2323)->value('referred_by'), 'never themselves');
        self::assertNull(User::query()->where('telegram_id', 2424)->value('referred_by'));
    }

    public function testWithoutReferralsNobodysReferrerIsSet(): void
    {
        $this->migrator->customers = [new ImportedCustomer('20', 2020, referrerKey: '21'), new ImportedCustomer('21', 2121)];

        $this->importAll(['referrals' => false]);

        self::assertNull(User::query()->where('telegram_id', 2020)->value('referred_by'));
    }

    public function testAJoinDayThatIsNoDateIsToday(): void
    {
        $this->migrator->customers = [new ImportedCustomer('30', 3030, joinedAt: new \DateTimeImmutable('2001-01-01')), new ImportedCustomer('31', 3131, joinedAt: new \DateTimeImmutable('+3 days'))];

        $this->importAll();

        foreach ([3030, 3131] as $telegramId) {
            self::assertTrue(User::query()->where('telegram_id', $telegramId)->firstOrFail()->created_at?->isToday());
        }
    }
}
