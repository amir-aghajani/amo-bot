<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Models\AccountMerge;
use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Models\WalletTransfer;
use App\Modules\Users\Services\CustomerLookup;
use App\Modules\Users\Services\UserDirectory;
use App\Modules\Users\Services\WalletService;
use App\Modules\Users\Services\WalletTransfers;
use App\Modules\Users\Services\WalletTransferSettings;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * «انتقال موجودی»: money a customer sends from their wallet to another customer of their shop by their Telegram id —
 * while the shop offers it, within its bounds, from the wallet's own balance (never an agent's credit) and within the
 * daily cap on the shop's clock. Both wallets' lines naming the other, the transfer's row and the report group's line in
 * one transaction, read under both customers' locks; both customers told. Every refusal under its field; a website
 * request's key answered by the transfer it made; a customer's lookups and transfers limited.
 */
final class WalletTransfersTest extends DatabaseTestCase
{
    /** Sara's Telegram id: the recipient. */
    private const SARA = 7070;

    private User $ali;
    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->telegram();
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '100000');
        $this->sara = $this->customer(['telegram_id' => self::SARA, 'first_name' => 'Sara', 'last_name' => 'Ahmadi', 'username' => 'sara']);
    }

    public function testTheMoneyMovesWithALineInEachWalletNamingTheOther(): void
    {
        $this->walletTransfersOn();

        $transfer = $this->transfers()->transfer($this->ali, $this->sara, '۳۰٬۰۰۰ تومان');

        self::assertSame(['70000.00', '30000.00'], [$this->ali->balance(), $this->sara->balance()], 'no fee: what one gave, the other got');
        $sent = WalletTransaction::query()->where('user_id', $this->ali->id)->latest('id')->firstOrFail();
        $received = WalletTransaction::query()->where('user_id', $this->sara->id)->latest('id')->firstOrFail();
        self::assertSame([WalletTransactionType::Debit, '30000.00', 'انتقال موجودی به Sara Ahmadi (@sara)', null], [$sent->type, $sent->amount, $sent->description, $sent->reviewer]);
        self::assertSame([WalletTransactionType::Credit, '30000.00', 'دریافت موجودی از Ali (@ali)', null], [$received->type, $received->amount, $received->description, $received->reviewer]);

        $row = WalletTransfer::query()->sole();
        self::assertSame($transfer->id, $row->id);
        self::assertSame([$this->ali->id, $this->sara->id, '30000.00', $sent->id, $received->id, null], [$row->sender_id, $row->recipient_id, $row->amount, $row->debit_id, $row->credit_id, $row->request_key]);
    }

    public function testThePanelsLedgerTellsEachTransfersLineItsOtherCustomer(): void
    {
        $this->walletTransfersOn();
        $this->transfers()->transfer($this->ali, $this->sara, 30_000);
        $this->transfers()->transfer($this->sara, $this->ali, 10_000);

        $ledger = static fn(array $lines): array => array_map(static fn(array $line): array => [$line['description'], $line['counterpart']['id'] ?? null], $lines);
        self::assertSame([
            ['دریافت موجودی از Sara Ahmadi (@sara)', $this->sara->id],
            ['انتقال موجودی به Sara Ahmadi (@sara)', $this->sara->id],
            ['test', null],
        ], $ledger($this->service(UserDirectory::class)->ledger($this->ali)));
        self::assertSame(UserDirectory::presentRef($this->ali), $this->service(UserDirectory::class)->ledger($this->sara)[0]['counterpart'], 'whom it went to');
    }

    public function testALineNamesAnotherCustomerAsTheyCanBeTold(): void
    {
        $this->walletTransfersOn();
        $nameless = $this->customer(['telegram_id' => 7272, 'first_name' => null, 'username' => 'reza']);
        $handleless = $this->customer(['telegram_id' => 7373, 'first_name' => 'Mina']);
        $bare = $this->customer(['telegram_id' => 7474, 'first_name' => null]);

        foreach ([$nameless, $handleless, $bare] as $to) {
            $this->transfers()->transfer($this->ali, $to, 10_000);
        }

        self::assertSame(
            ['انتقال موجودی به @reza', 'انتقال موجودی به Mina', 'انتقال موجودی به 7474'],
            WalletTransaction::query()->where('user_id', $this->ali->id)->where('type', 'debit')->oldest('id')->pluck('description')->all(),
        );
    }

    public function testTheRecipientIsToldAndTheSenderFindsItAmongTheirNotices(): void
    {
        $this->walletTransfersOn();

        $this->transfers()->transfer($this->ali, $this->sara, 30_000);

        $received = self::text(BotText::WalletReceived, ['amount' => '۳۰٬۰۰۰ تومان', 'name' => 'Ali (@ali)', 'balance' => '۳۰٬۰۰۰ تومان']);
        self::assertSame([$received], $this->telegram()->sentTo(self::SARA));
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the bot answered the sender in place');
        $sent = self::text(BotText::WalletSent, ['amount' => '۳۰٬۰۰۰ تومان', 'name' => 'Sara Ahmadi (@sara)', 'balance' => '۷۰٬۰۰۰ تومان']);
        self::assertSame([
            [$this->sara->id, NoticeType::WalletReceived, $received],
            [$this->ali->id, NoticeType::WalletSent, $sent],
        ], Notification::query()->oldest('id')->get()->map(static fn(Notification $notice): array => [$notice->user_id, $notice->type, $notice->text])->all(), 'both kept for their websites');
    }

    public function testASenderOnTheWebsiteIsToldInTheirChatToo(): void
    {
        $this->walletTransfersOn();

        $this->transfers()->transferById($this->ali, (string) self::SARA, '30000', 'key-1');

        self::assertSame([self::text(BotText::WalletSent, ['amount' => '۳۰٬۰۰۰ تومان', 'name' => 'Sara Ahmadi (@sara)', 'balance' => '۷۰٬۰۰۰ تومان'])], $this->telegram()->sentTo(self::TELEGRAM_ID));
        self::assertCount(1, $this->telegram()->sentTo(self::SARA));
    }

    public function testTheReportGroupHearsOfItWhereTopUpsAre(): void
    {
        $this->reportGroup();
        $this->walletTransfersOn();

        $transfer = $this->transfers()->transfer($this->ali, $this->sara, 30_000);

        $report = ReportMessage::query()->sole();
        self::assertSame(Topic::Wallet, $report->topic);
        self::assertSame(implode("\n", [
            sprintf('💸 <b>انتقال موجودی بین مشتری‌ها</b> · انتقال #%d', $transfer->id),
            '👤 از: <a href="tg://user?id=' . self::TELEGRAM_ID . '">Ali</a> · @ali · <code>' . self::TELEGRAM_ID . '</code>',
            '👤 به: <a href="tg://user?id=' . self::SARA . '">Sara Ahmadi</a> · @sara · <code>' . self::SARA . '</code>',
            '💳 مبلغ: ۳۰٬۰۰۰ تومان',
            '👛 موجودی فرستنده: ۷۰٬۰۰۰ تومان · گیرنده: ۳۰٬۰۰۰ تومان',
        ]), $report->text);
    }

    public function testNothingMovesWhileTheShopOffersNone(): void
    {
        foreach ([
            fn() => $this->transfers()->transfer($this->ali, $this->sara, 30_000),
            fn() => $this->transfers()->recipient($this->ali, (string) self::SARA),
            fn() => $this->transfers()->check($this->ali),
            fn() => $this->transfers()->transferById($this->ali, (string) self::SARA, 30_000, 'key-1'),
        ] as $i => $refused) {
            self::assertSame([['status' => [WalletTransfers::OFF]], WalletTransfers::OFF], $this->refusal($refused), "door {$i}");
        }
        self::assertFalse($this->transfers()->offered(), 'off by default');
        $this->assertNothingMoved();
    }

    public function testEachAmountRefusalIsUnderAmount(): void
    {
        $this->walletTransfersOn(['wallet_transfer_max' => 80_000]);

        $notAnAmount = 'مبلغ را به تومان و بدون اعشار بنویسید.';
        foreach (['abc' => $notAnAmount, '0' => $notAnAmount, '15000.5' => $notAnAmount, '9999' => 'کمترین مبلغ انتقال ۱۰٬۰۰۰ تومان است.', '80001' => 'بیشترین مبلغ هر انتقال ۸۰٬۰۰۰ تومان است.'] as $typed => $reason) {
            self::assertSame([['amount' => [$reason]], $reason], $this->refusal(fn() => $this->transfers()->transfer($this->ali, $this->sara, (string) $typed)), (string) $typed);
        }

        $this->wallet($this->ali, '50000');
        $short = 'موجودی کیف پول شما (۵۰٬۰۰۰ تومان) برای این انتقال کافی نیست.';
        self::assertSame([['amount' => [$short]], $short], $this->refusal(fn() => $this->transfers()->transfer($this->ali, $this->sara, 60_000)));
        self::assertSame(['50000.00', '0.00'], [$this->ali->balance(), $this->sara->balance()]);
        self::assertSame(0, WalletTransfer::query()->count());
    }

    public function testEachRecipientRefusalIsUnderTelegramId(): void
    {
        $this->walletTransfersOn();
        $banned = $this->customer(['telegram_id' => 7171, 'status' => UserStatus::Banned]);
        $other = Bot::query()->forceCreate([]);
        $theirs = CurrentBot::run($other, fn(): User => $this->customer(['telegram_id' => 8080]));

        foreach ([
            'sara' => CustomerLookup::NOT_AN_ID,
            '9999' => CustomerLookup::UNKNOWN,
            '8080' => CustomerLookup::UNKNOWN,
            (string) self::TELEGRAM_ID => WalletTransfers::TO_THEMSELVES,
            '7171' => WalletTransfers::BANNED,
        ] as $typed => $reason) {
            self::assertSame(['telegram_id' => [$reason]], $this->refusedWith(fn() => $this->transfers()->recipient($this->ali, (string) $typed)), (string) $typed);
        }
        self::assertSame(['telegram_id' => [CustomerLookup::UNKNOWN]], $this->refusedWith(fn() => $this->transfers()->transfer($this->ali, $theirs, 10_000)), "another shop's customer");
        self::assertSame(['telegram_id' => [WalletTransfers::TO_THEMSELVES]], $this->refusedWith(fn() => $this->transfers()->transfer($this->ali, $this->ali, 10_000)));
        self::assertSame(['telegram_id' => [WalletTransfers::BANNED]], $this->refusedWith(fn() => $this->transfers()->transfer($this->ali, $banned, 10_000)));
        self::assertSame($this->sara->id, $this->transfers()->recipient($this->ali, '۷۰۷۰')->id, 'Persian digits are read');
        $this->assertNothingMoved();
    }

    public function testAnAgentsCreditIsNeverSent(): void
    {
        $this->walletTransfersOn();
        $agent = $this->wallet($this->customer(['telegram_id' => 5050, 'username' => 'agent', 'agency_level_id' => $this->agencyLevel()->id, 'credit_limit' => '300000']), '40000');

        self::assertSame('40000.00', $this->transfers()->most($agent), 'their balance, not what their credit could pay');
        $short = 'موجودی کیف پول شما (۴۰٬۰۰۰ تومان) برای این انتقال کافی نیست.' . WalletTransfers::NOT_CREDIT;
        self::assertSame(['amount' => [$short]], $this->refusedWith(fn() => $this->transfers()->transfer($agent, $this->sara, 50_000)));

        $this->wallet($agent, '-100000');
        $little = 'برای انتقال، دست‌کم ۱۰٬۰۰۰ تومان موجودی در کیف پول لازم است.' . WalletTransfers::NOT_CREDIT;
        self::assertSame(['amount' => [$little]], $this->refusedWith(fn() => $this->transfers()->check($agent)), 'in debt, nothing to send');
        self::assertSame(['amount' => [$little]], $this->refusedWith(fn() => $this->transfers()->recipient($agent, (string) self::SARA)), 'nor anyone looked up for them');
        self::assertSame(['amount' => ['موجودی کیف پول شما (۰ تومان) برای این انتقال کافی نیست.' . WalletTransfers::NOT_CREDIT]], $this->refusedWith(fn() => $this->transfers()->transfer($agent, $this->sara, 10_000)));
        self::assertSame(['-100000.00', '0.00'], [$agent->balance(), $this->sara->balance()]);
    }

    public function testTheDailyCapCountsTheShopsDayAndOpensAgainAtItsMidnight(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            $this->walletTransfersOn(['wallet_transfer_daily' => 100_000]);
            $this->wallet($this->ali, '500000');
            Carbon::setTestNow('2026-10-13 21:00:00'); // 00:30 on the 14th in Tehran
            $this->transfers()->transfer($this->ali, $this->sara, 20_000);
            Carbon::setTestNow('2026-10-14 20:00:00'); // 23:30 on the 14th in Tehran: the same day
            $this->transfers()->transfer($this->ali, $this->sara, 40_000);

            self::assertSame('40000.00', $this->transfers()->leftToday($this->ali));
            $over = 'سقف انتقال روزانه ۱۰۰٬۰۰۰ تومان است و امروز تا ۴۰٬۰۰۰ تومان دیگر می‌توانید منتقل کنید.';
            self::assertSame(['amount' => [$over]], $this->refusedWith(fn() => $this->transfers()->transfer($this->ali, $this->sara, 50_000)));
            $this->transfers()->transfer($this->ali, $this->sara, 40_000);

            $spent = 'سقف انتقال روزانه شما (۱۰۰٬۰۰۰ تومان) امروز پر شده است؛ فردا دوباره امتحان کنید.';
            self::assertSame(['amount' => [$spent]], $this->refusedWith(fn() => $this->transfers()->transfer($this->ali, $this->sara, 10_000)));
            self::assertSame(['amount' => [$spent]], $this->refusedWith(fn() => $this->transfers()->check($this->ali)), 'the bot says so at its first step');
            self::assertSame('0.00', $this->transfers()->most($this->ali));

            Carbon::setTestNow('2026-10-14 20:31:00'); // 00:01 on the 15th in Tehran, still the 14th in UTC
            self::assertSame('100000.00', $this->transfers()->leftToday($this->ali));
            $this->transfers()->transfer($this->ali, $this->sara, 100_000);
            self::assertSame('200000.00', $this->sara->balance());
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testWithoutACapOnlyTheBalanceAndTheShopsMostLimitADay(): void
    {
        $this->walletTransfersOn(['wallet_transfer_daily' => 0, 'wallet_transfer_max' => 60_000]);
        $this->wallet($this->ali, '200000');

        self::assertNull($this->transfers()->leftToday($this->ali));
        self::assertSame('60000.00', $this->transfers()->most($this->ali));
        foreach ([60_000, 60_000, 60_000] as $amount) {
            $this->transfers()->transfer($this->ali, $this->sara, $amount);
        }
        self::assertSame('20000.00', $this->transfers()->most($this->ali), 'what is left of the balance');
        self::assertTrue($this->transfers()->allows($this->ali, 20_000));
        self::assertFalse($this->transfers()->allows($this->ali, 20_001));
        self::assertFalse($this->transfers()->allows($this->ali, 9_999));
    }

    public function testADailyCapIsNoneOrAtLeastTheLeastTransfer(): void
    {
        $belowMin = ['wallet_transfer_daily' => [WalletTransferSettings::DAILY_BELOW_MIN]];
        self::assertSame($belowMin, $this->refusal(fn() => $this->walletTransfersOn(['wallet_transfer_min' => 20_000, 'wallet_transfer_daily' => 15_000]))[0], 'a cap no transfer fits under');

        $this->walletTransfersOn(['wallet_transfer_min' => 20_000, 'wallet_transfer_daily' => 20_000]);
        self::assertSame($belowMin, $this->refusal(fn() => $this->botSettings('wallet_transfers', ['wallet_transfer_min' => 25_000]))[0], 'nor a least raised past the cap');
        self::assertSame(20_000, $this->service(WalletTransferSettings::class)->dailyCap(), 'nothing saved');

        $this->wallet($this->ali, '50000');
        $this->transfers()->check($this->ali);
        $this->transfers()->transfer($this->ali, $this->sara, 20_000);
        self::assertSame([['amount' => [sprintf(WalletTransfers::DAILY_SPENT, '۲۰٬۰۰۰ تومان')]], sprintf(WalletTransfers::DAILY_SPENT, '۲۰٬۰۰۰ تومان')], $this->refusal(fn() => $this->transfers()->check($this->ali)), 'a cap as large as the least: one transfer a day');

        $this->walletTransfersOn(['wallet_transfer_min' => 25_000, 'wallet_transfer_daily' => 0]);
        self::assertNull($this->transfers()->dailyCap(), 'none at all is fine whatever the least');
    }

    public function testTheBalanceIsReadUnderTheLocksAsAnotherSpendingLeftIt(): void
    {
        $this->walletTransfersOn();

        $refused = $this->whenTheSenderIsLocked(fn() => $this->service(WalletService::class)->debit($this->ali, 80_000, 'خرید در همان لحظه'), fn() => $this->refusedWith(fn() => $this->transfers()->transfer($this->ali, $this->sara, 50_000)));

        self::assertSame(['amount' => ['موجودی کیف پول شما (۲۰٬۰۰۰ تومان) برای این انتقال کافی نیست.']], $refused, 'the balance as the other spending left it');
        self::assertSame(0, WalletTransfer::query()->count());
        self::assertSame('0.00', $this->sara->balance());
    }

    public function testTheDailyCapIsSummedUnderTheLocksAsAnotherTransferLeftIt(): void
    {
        $this->walletTransfersOn(['wallet_transfer_daily' => 50_000]);
        $other = $this->customer(['telegram_id' => 7272]);

        $refused = $this->whenTheSenderIsLocked(fn() => WalletTransfer::query()->forceCreate([
            'sender_id' => $this->ali->id,
            'recipient_id' => $other->id,
            'amount' => '30000',
            'debit_id' => $this->service(WalletService::class)->debit($this->ali, 30_000, 'x')->id,
            'credit_id' => $this->service(WalletService::class)->credit($other, 30_000, 'x')->id,
        ]), fn() => $this->refusedWith(fn() => $this->transfers()->transfer($this->ali, $this->sara, 30_000)));

        self::assertSame(['amount' => ['سقف انتقال روزانه ۵۰٬۰۰۰ تومان است و امروز تا ۲۰٬۰۰۰ تومان دیگر می‌توانید منتقل کنید.']], $refused);
        self::assertSame('0.00', $this->sara->balance());
    }

    public function testTheSameWebsiteKeyIsAnsweredByTheTransferItMade(): void
    {
        $this->walletTransfersOn();

        $first = $this->transfers()->transferById($this->ali, (string) self::SARA, '30000', 'key-1');
        $again = $this->transfers()->transferById($this->ali, '۷۰۷۰', '30000.00', 'key-1');

        self::assertSame($first->id, $again->id);
        self::assertSame(['70000.00', '30000.00'], [$this->ali->balance(), $this->sara->balance()], 'moved once');
        self::assertSame('key-1', WalletTransfer::query()->sole()->request_key);
        self::assertCount(1, $this->telegram()->sentTo(self::SARA), 'told once');

        self::assertSame(['idempotency_key' => [WalletTransfers::KEY_REUSED]], $this->refusedWith(fn() => $this->transfers()->transferById($this->ali, (string) self::SARA, '20000', 'key-1')), 'another amount');
        self::assertSame(['idempotency_key' => [WalletTransfers::KEY_REUSED]], $this->refusedWith(fn() => $this->transfers()->transferById($this->ali, '7272', '30000', 'key-1')), 'another recipient');
        $this->transfers()->transferById($this->ali, (string) self::SARA, '30000', 'key-2');
        self::assertSame(['40000.00', '60000.00'], [$this->ali->balance(), $this->sara->balance()], 'a new key is a new transfer');
    }

    public function testAKeyAnotherRequestCommittedFirstIsAnsweredByItsTransfer(): void
    {
        $this->walletTransfersOn();
        $made = null;

        $answer = $this->whenTheSenderIsLocked(function () use (&$made): void {
            $made = WalletTransfer::query()->forceCreate([
                'sender_id' => $this->ali->id,
                'recipient_id' => $this->sara->id,
                'amount' => '30000',
                'debit_id' => $this->service(WalletService::class)->debit($this->ali, 30_000, 'x')->id,
                'credit_id' => $this->service(WalletService::class)->credit($this->sara, 30_000, 'x')->id,
                'request_key' => 'key-1',
            ]);
        }, fn() => $this->transfers()->transferById($this->ali, (string) self::SARA, '30000', 'key-1'));

        self::assertSame($made?->id, $answer->id);
        self::assertSame(['70000.00', '30000.00'], [$this->ali->balance(), $this->sara->balance()], 'moved once, by the request that got there first');
        self::assertSame([], $this->telegram()->sentTo(self::SARA), 'told by that one, not this');
    }

    public function testACustomerLooksUpTenIdsInTenMinutesServicesAndMoneyTogether(): void
    {
        $this->walletTransfersOn();
        for ($i = 0; $i < CustomerLookup::LOOKUPS - 1; $i++) {
            $this->service(CustomerLookup::class)->find($this->ali->shop(), (string) self::SARA, $this->ali->id);
        }
        $this->transfers()->recipient($this->ali, (string) self::SARA);
        self::assertSame(['telegram_id' => [CustomerLookup::NOT_AN_ID]], $this->refusedWith(fn() => $this->transfers()->recipient($this->ali, '@sara')), 'what is no id is no lookup');

        try {
            $this->transfers()->recipient($this->ali, (string) self::SARA);
            self::fail('one too many');
        } catch (TooManyAttemptsException $e) {
            self::assertSame([429, CustomerLookup::LOOKUP_WINDOW], [$e->status(), $e->retryAfter]);
        }

        Carbon::setTestNow(now()->addSeconds(CustomerLookup::LOOKUP_WINDOW + 1));
        self::assertSame($this->sara->id, $this->transfers()->recipient($this->ali, (string) self::SARA)->id, 'a new window');
    }

    public function testACustomerSendsTenTransfersAnHourAtMost(): void
    {
        $this->walletTransfersOn();
        for ($i = 0; $i < WalletTransfers::TRANSFERS; $i++) {
            $this->transfers()->transfer($this->ali, $this->sara, 10_000);
        }
        $this->wallet($this->ali, '100000');

        try {
            $this->transfers()->transfer($this->ali, $this->sara, 10_000);
            self::fail('one too many');
        } catch (TooManyAttemptsException $e) {
            self::assertSame([429, WalletTransfers::TRANSFER_WINDOW], [$e->status(), $e->retryAfter]);
        }
        self::assertSame('100000.00', $this->sara->balance());
    }

    public function testAMergeCarriesEveryTransferAndAKeyBothAccountsUsedStaysTheSurvivors(): void
    {
        $this->walletTransfersOn(['wallet_transfer_daily' => 100_000]);
        $web = $this->wallet($this->webCustomer(['first_name' => 'Ali', 'last_name' => null]), '100000');
        $mine = $this->transfers()->transferById($this->ali, (string) self::SARA, '30000', 'key-1');
        $theirs = $this->transfers()->transferById($web, (string) self::SARA, '20000', 'key-1');
        $this->transfers()->transfer($this->sara, $this->ali, 10_000);

        $this->service(AccountMerger::class)->merge($this->ali, $web, AccountMerger::BY_CUSTOMER);

        self::assertSame(
            [[$mine->id, $this->ali->id, 'key-1'], [$theirs->id, $this->ali->id, null]],
            WalletTransfer::query()->where('recipient_id', $this->sara->id)->oldest('id')->get()->map(static fn(WalletTransfer $row): array => [$row->id, $row->sender_id, $row->request_key])->all(),
            'both kept, the key the survivor used too taken off the other',
        );
        self::assertSame(['wallet_transfers_sent' => 1, 'wallet_transfers_received' => 0], array_intersect_key(AccountMerge::query()->sole()->moved, ['wallet_transfers_sent' => 0, 'wallet_transfers_received' => 0]));
        self::assertSame('50000.00', $this->transfers()->leftToday($this->ali), "both accounts' sending counted against one cap");
        self::assertSame($mine->id, $this->transfers()->transferById($this->ali, (string) self::SARA, '30000', 'key-1')->id, "the key answered by the survivor's transfer");
        self::assertSame($this->sara->id, $this->service(UserDirectory::class)->ledger($this->ali)[1]['counterpart']['id'] ?? null, "the merged account's line still names whom it went to");
    }

    /**
     * `$work`, while `$meanwhile` runs once the moment the sender's row is first read — under the transfer's lock, as
     * another process's change that committed before the lock was the transfer's.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function whenTheSenderIsLocked(\Closure $meanwhile, \Closure $work): mixed
    {
        $done = false;

        return $this->whileListening('eloquent.retrieved: ' . User::class, function (User $read) use ($meanwhile, &$done): void {
            if (!$done && $read->id === $this->ali->id) {
                $done = true;
                $meanwhile();
            }
        }, $work);
    }

    /** @return array{array<string, list<string>>, string} What `$work` was refused with: under each field, and its message. */
    private function refusal(\Closure $work): array
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return [$e->errors(), $e->getMessage()];
        }

        self::fail('Nothing was refused.');
    }

    private function assertNothingMoved(): void
    {
        self::assertSame(['100000.00', '0.00'], [$this->ali->balance(), $this->sara->balance()]);
        self::assertSame(0, WalletTransfer::query()->count());
        self::assertSame([], $this->telegram()->sentTo(self::SARA));
    }

    private function transfers(): WalletTransfers
    {
        return $this->service(WalletTransfers::class);
    }
}
