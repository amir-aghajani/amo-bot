<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\MirzaBot;

use App\Modules\Imports\Migrators\MirzaBot\Census;
use App\Modules\Imports\Migrators\MirzaBot\People;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Imports\Survey\SurveyAdmin;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\ArrayStaged;

/** MirzaBot's people as the import reads them (and Faoxima's): customers, their reseller bots, admins, and the census of them. */
final class PeopleTest extends TestCase
{
    public function testACustomerAsMirzaBotWritesThem(): void
    {
        // Each bot tells its own buyers: here, an invoice of «bought» status.
        $people = People::of(new ArrayStaged(['invoice' => [['id_user' => 'abc', 'Status' => 'bought'], ['id_user' => '1001', 'Status' => 'unpaid']]]), static fn(array $invoice): bool => $invoice['Status'] === 'bought');

        $customer = $people->customer(['id' => '1001', 'username' => '@Ali_99', 'number' => 'confirm number by admin', 'Balance' => '۱۰٬۰۰۰', 'User_Status' => 'Block', 'agent' => 'N2', 'affiliates' => '0', 'pricediscount' => '-5', 'expire' => '4000000000']);
        self::assertSame(
            [1001, 'Ali_99', null, true, true, '10000.00', 'نماینده پیشرفته', 0, null, false],
            [$customer->telegramId, $customer->username, $customer->phone, $customer->phoneExempt, $customer->banned, $customer->balance, $customer->group, $customer->discountPercent, $customer->referrerKey, $customer->boughtBefore],
            'the right spelling of the excuse too; a level in any case; no percent below 0; «0» brought by no one',
        );

        $odd = $people->customer(['id' => 'abc', 'username' => 'NOT_USERNAME', 'number' => '', 'Balance' => 'none', 'agent' => 'n', 'expire' => '1600000000', 'register' => '0']);
        self::assertSame([0, null, null, false, '0.00', null, null, true], [$odd->telegramId, $odd->username, $odd->phone, $odd->phoneExempt, $odd->balance, $odd->group, $odd->joinedAt, $odd->boughtBefore], 'no Telegram id (the core names it), MirzaBot\'s word for no @username, an agency past its end; a buyer by their key');
        self::assertNull($people->customer(['id' => '1002', 'username' => 'علی رضا'])->username, 'no @username Telegram would give');
    }

    public function testResellerBotsByTheirUsernameElseTheirOwner(): void
    {
        $people = People::of(new ArrayStaged(['botsaz' => [
            ['id' => '1', 'id_user' => '444000000', 'username' => '@agent_shop_bot', 'bot_token' => 'T1'],
            ['id' => '2', 'id_user' => '555000000', 'username' => '', 'bot_token' => 'T2'],
            ['id' => '3', 'id_user' => '666000000', 'username' => 'no_token_bot', 'bot_token' => ''],
        ]]));

        self::assertSame(
            ['@agent_shop_bot', 'ربات نماینده 555000000', 'ربات نماینده حذف‌شده', null, null, null],
            [$people->reseller('T1'), $people->reseller('T2'), $people->reseller('T3'), $people->reseller('0'), $people->reseller(null), $people->reseller(' ')],
            'a token no bot has is a bot deleted; «0» or none is the bot itself',
        );
    }

    public function testTheInvoicesAreReadOnceAndOnlyForACustomer(): void
    {
        $staged = new class (new ArrayStaged([
            'botsaz' => [['id' => '1', 'id_user' => '2002', 'username' => 'agent_bot', 'bot_token' => 'T1']],
            'invoice' => [['id_invoice' => 'i1', 'id_user' => '1001', 'bottype' => null], ['id_invoice' => 'i2', 'id_user' => '3003', 'bottype' => 'T1']],
        ])) implements Staged {
            public int $invoiceReads = 0;

            public function __construct(private readonly ArrayStaged $staged) {}

            public function rows(string $table, int $after = 0): iterable
            {
                $this->invoiceReads += $table === 'invoice' ? 1 : 0;

                return $this->staged->rows($table, $after);
            }

            public function count(string $table): int
            {
                return $this->staged->count($table);
            }

            public function all(string $table): array
            {
                return $this->staged->all($table);
            }
        };
        $people = People::of($staged, static fn(array $invoice): bool => $invoice['id_invoice'] === 'i2');

        self::assertSame(['@agent_bot', null], [$people->reseller('T1'), $people->reseller(null)]);
        self::assertSame(0, $staged->invoiceReads, 'a services step asks the reseller bot alone: no invoice read for it');

        $customer = static fn(string $id): array => [$people->customer(['id' => $id, 'Balance' => '0', 'agent' => 'f', 'bottype' => 'T1'])->reseller, $people->customer(['id' => $id])->boughtBefore];
        self::assertSame([[null, false], [null, false], ['@agent_bot', true]], [$customer('1001'), $customer('2002'), $customer('3003')], 'an invoice in the bot itself, a reseller bot of their own: the bot\'s own; who bought, as the bot tells it');
        self::assertSame(1, $staged->invoiceReads, 'one read for both, for every customer after');
    }

    public function testTheCensusCountsTheBotsOwnCustomersAndItsStaff(): void
    {
        $staged = new ArrayStaged([
            'user' => [
                ['id' => '1', 'username' => 'owner', 'Balance' => '-2000', 'agent' => 'f', 'pricediscount' => '10'],
                ['id' => '2', 'username' => 'seller', 'Balance' => '3000', 'agent' => 'n2', 'maxbuyagent' => '0'],
                ['id' => '3', 'username' => 'capped', 'Balance' => '0', 'agent' => 'n2', 'maxbuyagent' => '50000', 'expire' => '4000000000'],
                ['id' => '4', 'username' => 'ended', 'Balance' => '0', 'agent' => 'n2', 'expire' => '1600000000'],
                ['id' => '5', 'username' => 'resold', 'Balance' => '0', 'agent' => 'f', 'pricediscount' => '20', 'bottype' => 'T1'],
                ['id' => '6', 'username' => 'tied', 'Balance' => '-9000', 'agent' => 'f', 'bottype' => 'T1'],
            ],
            'admin' => [['id_admin' => '1', 'rule' => 'Administrator'], ['id_admin' => '2', 'rule' => 'Seller'], ['id_admin' => '7', 'rule' => 'support']],
        ]);

        $census = People::of($staged)->census();
        self::assertSame(
            [6, 1, 3, '-8000.00', 2, '11000.00', ['نماینده پیشرفته' => 2], 1, 1, 2, 1, 1],
            [$census->customers, $census->resellers, $census->balances, $census->balanceTotal, $census->debtors, $census->debt, $census->groups, $census->agencyEnds, $census->personal, $census->seniorAgents, $census->creditCaps, $census->helpers],
            'a reseller bot\'s customer counted apart, out of the rest (one with a wallet here is the bot\'s own); an agency past its end is none; every advanced agent, capped or not',
        );
        self::assertEquals([new SurveyAdmin('1', 1, 'owner', 'Administrator')], $census->admins, 'an administrator in any case; staff who never started the bot are no one to list');
    }

    public function testEachBotsOwnWordsTakeTheirPlacesAmongWhatEveryBotSaysAlike(): void
    {
        $census = new Census(customers: 3, resellers: 0, balances: 1, balanceTotal: '-1000.00', debtors: 1, debt: '1000.00', groups: [], agencyEnds: 1, personal: 1, seniorAgents: 1, creditCaps: 0, helpers: 1, admins: []);
        $alike = [
            '۱ مشتری در ربات قبلی بدهکار بودند (جمعا ۱٬۰۰۰ تومان)؛ کیف پولشان اینجا هم منفی می‌آید.',
            '۱ نفر از ادمین‌های ربات قبلی پشتیبان یا فروشنده بودند؛ در فهرست هستند ولی اینجا ادمین نمی‌شوند.',
            '۱ نماینده در ربات قبلی تاریخ پایان نمایندگی داشتند؛ این تاریخ منتقل نمی‌شود و اینجا نماینده می‌مانند تا خودتان عوضشان کنید.',
        ];

        self::assertSame($alike, $census->warnings(null, null), 'debtors, staff, an agency\'s end; no word of its own on credit or personal discounts (MirzaBot has none on the second)');
        self::assertSame([$alike[0], $alike[1], 'اعتبار', $alike[2], 'تخفیف'], $census->warnings('اعتبار', 'تخفیف'), 'its credit after the staff, its customers\' own discounts last');
        self::assertSame([$alike[0], $alike[1], $alike[2], 'تخفیف'], $census->warnings(null, 'تخفیف'));
    }
}
