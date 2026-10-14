<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransfer;
use App\Modules\Users\Services\CustomerLookup;
use App\Modules\Users\Services\WalletTransfers;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * «انتقال موجودی» on the website: what the customer may send (`GET /wallet`'s `transfer`, null while the shop offers
 * none), who an id names before it is done, and the money sent — the answer is the transfer and the wallet, the
 * recipient told, the sender's notice in their feed and chat, the report group's line. Every refusal under its field;
 * the same Idempotency-Key answered by its transfer, never a second; lookups and transfers limited; sending asks a
 * recent sign-in.
 */
final class StoreWalletTransfersTest extends HttpTestCase
{
    private const SARA = 7070;

    private Website $website;
    private User $ali;
    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->telegram();
        $this->reportGroup();
        $this->website = $this->website();
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '100000');
        $this->sara = $this->customer(['telegram_id' => self::SARA, 'first_name' => 'Sara', 'username' => 'sara']);
        $this->bearer($this->customerSession($this->ali));
    }

    public function testTheWalletSaysWhatTheCustomerMaySend(): void
    {
        self::assertNull($this->decode($this->get($this->storeApi($this->website, '/wallet')))['transfer'], 'off by default');

        $this->walletTransfersOn(['wallet_transfer_max' => 80_000, 'wallet_transfer_daily' => 150_000]);
        self::assertSame(
            ['min' => '10000.00', 'max' => '80000.00', 'daily_cap' => '150000.00', 'left_today' => '150000.00', 'most' => '80000.00'],
            $this->decode($this->get($this->storeApi($this->website, '/wallet')))['transfer'],
        );

        $this->walletTransfersOn(['wallet_transfer_daily' => 0, 'wallet_transfer_max' => 5_000_000]);
        self::assertSame(
            ['min' => '10000.00', 'max' => '5000000.00', 'daily_cap' => null, 'left_today' => null, 'most' => '100000.00'],
            $this->decode($this->get($this->storeApi($this->website, '/wallet')))['transfer'],
            'without a cap, the balance',
        );
    }

    public function testWhoAnIdNamesIsShownBeforeAnythingIsDone(): void
    {
        $this->walletTransfersOn();

        $response = $this->get($this->storeApi($this->website, '/wallet/transfer?telegram_id=' . urlencode('۷۰۷۰')));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['recipient' => ['name' => 'Sara', 'username' => 'sara']], $this->decode($response));
        self::assertSame([0, 0], [WalletTransfer::query()->count(), ReportMessage::query()->count()]);
    }

    /** One who may send nothing learns no customer's name: refused under `amount` before any id is looked up, as in the bot. */
    public function testACustomerWhoMaySendNothingLooksNobodyUp(): void
    {
        $this->walletTransfersOn(['wallet_transfer_daily' => 50_000]);
        $broke = $this->customer(['telegram_id' => 7272, 'username' => 'broke']);
        $this->bearer($this->customerSession($broke));
        $lookUp = fn(string $id): ResponseInterface => $this->get($this->storeApi($this->website, "/wallet/transfer?telegram_id={$id}"));

        $little = sprintf(WalletTransfers::TOO_LITTLE, '۱۰٬۰۰۰ تومان');
        $this->assertRefusedUnder('amount', $little, $lookUp((string) self::SARA), message: 'nothing in the wallet: not whose id it is');
        $this->assertRefusedUnder('amount', $little, $lookUp('9999'), message: "nor whether it is anyone's");

        $this->wallet($broke, '20000');
        self::assertSame(['recipient' => ['name' => 'Sara', 'username' => 'sara']], $this->decode($lookUp((string) self::SARA)), 'with the least to send, who it is');

        $this->bearer($this->customerSession($this->ali));
        self::assertSame(200, $this->transfer(['telegram_id' => self::SARA, 'amount' => 50000])->getStatusCode());
        $this->assertRefusedUnder('amount', sprintf(WalletTransfers::DAILY_SPENT, '۵۰٬۰۰۰ تومان'), $lookUp((string) self::SARA), message: "the day's cap used up");
    }

    public function testTheMoneyIsSentAndTheAnswerIsTheTransferAndTheWallet(): void
    {
        $this->walletTransfersOn(['wallet_transfer_daily' => 100_000]);

        $response = $this->transfer(['telegram_id' => self::SARA, 'amount' => '۳۰٬۰۰۰ تومان'], 'try-1');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $answer = $this->decode($response);
        $made = WalletTransfer::query()->sole();
        self::assertSame(['id' => $made->id, 'amount' => '30000.00', 'recipient' => ['name' => 'Sara', 'username' => 'sara'], 'created_at' => '2026-10-14T12:00:00+00:00'], $answer['transfer']);
        self::assertSame(['70000.00', '70000.00'], [$answer['wallet']['balance'], $answer['wallet']['spendable']]);
        self::assertSame(['70000.00', '70000.00'], [$answer['wallet']['transfer']['left_today'], $answer['wallet']['transfer']['most']]);
        self::assertSame(['70000.00', '30000.00', 'try-1'], [$this->ali->balance(), $this->sara->balance(), $made->request_key]);

        self::assertCount(1, $this->telegram()->sentTo(self::SARA), 'the recipient told in the bot');
        self::assertCount(1, $this->telegram()->sentTo(self::TELEGRAM_ID), 'the sender, who sent it from the website, too');
        self::assertStringContainsString('💸 <b>انتقال موجودی بین مشتری‌ها</b>', (string) ReportMessage::query()->where('topic', Topic::Wallet->value)->value('text'));

        $feed = $this->decode($this->get($this->storeApi($this->website, '/notifications')))['notifications'];
        self::assertSame([NoticeType::WalletSent->value], array_column($feed, 'type'), "the sender's own notice");
        $lines = $this->decode($this->get($this->storeApi($this->website, '/wallet/transactions')))['transactions'];
        self::assertSame(['debit', '30000.00', 'انتقال موجودی به Sara (@sara)'], [$lines[0]['type'], $lines[0]['amount'], $lines[0]['description']]);

        $this->bearer($this->customerSession($this->sara));
        $feed = $this->decode($this->get($this->storeApi($this->website, '/notifications')))['notifications'];
        self::assertSame([NoticeType::WalletReceived->value], array_column($feed, 'type'));
    }

    public function testTheSameKeyIsAnsweredByItsTransferAndNeverSendsTwice(): void
    {
        $this->walletTransfersOn();

        $first = $this->transfer(['telegram_id' => self::SARA, 'amount' => 30000], 'try-1');
        $again = $this->transfer(['telegram_id' => (string) self::SARA, 'amount' => '30000.00'], 'try-1');

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($this->decode($first)['transfer'], $this->decode($again)['transfer']);
        self::assertSame(['70000.00', '30000.00', 1], [$this->ali->balance(), $this->sara->balance(), WalletTransfer::query()->count()]);
        self::assertCount(1, $this->telegram()->sentTo(self::SARA), 'told once');

        $this->assertRefusedUnder('idempotency_key', WalletTransfers::KEY_REUSED, $this->transfer(['telegram_id' => self::SARA, 'amount' => 40000], 'try-1'));
        $this->assertRefusedUnder('idempotency_key', IdempotencyKey::MISSING, $this->unchecked()->send('POST', $this->storeApi($this->website, '/wallet/transfer'), ['telegram_id' => self::SARA, 'amount' => 30000]));
        $this->assertRefusedUnder('idempotency_key', IdempotencyKey::MALFORMED, $this->unchecked()->transfer(['telegram_id' => self::SARA, 'amount' => 30000], 'no spaces, please'));
        self::assertSame(1, WalletTransfer::query()->count());
    }

    public function testEachRefusalIsUnderItsField(): void
    {
        $this->assertRefusedUnder('status', WalletTransfers::OFF, $this->transfer(['telegram_id' => self::SARA, 'amount' => 30000]), message: 'the shop offers none');
        $this->assertRefusedUnder('status', WalletTransfers::OFF, $this->get($this->storeApi($this->website, '/wallet/transfer?telegram_id=7070')));

        $this->walletTransfersOn();
        $this->assertRefusedUnder('telegram_id', CustomerLookup::NOT_AN_ID, $this->transfer(['telegram_id' => '@sara', 'amount' => 30000]));
        $this->assertRefusedUnder('telegram_id', CustomerLookup::UNKNOWN, $this->transfer(['telegram_id' => '9999', 'amount' => 30000]));
        $this->assertRefusedUnder('telegram_id', WalletTransfers::TO_THEMSELVES, $this->transfer(['telegram_id' => self::TELEGRAM_ID, 'amount' => 30000]));
        $this->assertRefusedUnder('amount', 'مبلغ را به تومان و بدون اعشار بنویسید.', $this->transfer(['telegram_id' => self::SARA, 'amount' => 'سی هزار']));
        $this->assertRefusedUnder('amount', 'کمترین مبلغ انتقال ۱۰٬۰۰۰ تومان است.', $this->transfer(['telegram_id' => self::SARA, 'amount' => 5000]));
        $this->assertRefusedUnder('amount', 'موجودی کیف پول شما (۱۰۰٬۰۰۰ تومان) برای این انتقال کافی نیست.', $this->transfer(['telegram_id' => self::SARA, 'amount' => 150000]));
        self::assertSame(['100000.00', '0.00', 0], [$this->ali->balance(), $this->sara->balance(), WalletTransfer::query()->count()]);
    }

    /** A leaked token alone gives no money away: sending asks a recent sign-in, the look-up does not. */
    public function testSendingMoneyAsksARecentSignIn(): void
    {
        $this->walletTransfersOn();
        $this->bearer($this->customerSession($this->ali, ['authenticated_at' => now()->subSeconds(CustomerSessions::RECENT_SECONDS + 1)]));

        self::assertSame(200, $this->get($this->storeApi($this->website, '/wallet/transfer?telegram_id=7070'))->getStatusCode(), 'who an id names, still');
        $refused = $this->transfer(['telegram_id' => self::SARA, 'amount' => 30000]);

        self::assertSame([403, RecentSignInMiddleware::SIGN_IN_AGAIN], [$refused->getStatusCode(), $this->decode($refused)['message']]);
        self::assertSame(RecentSignInMiddleware::CHALLENGE, $refused->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['100000.00', '0.00'], [$this->ali->balance(), $this->sara->balance()], 'nothing sent');
        self::assertSame([], $this->telegram()->sentTo(self::SARA));
    }

    public function testLookupsAreLimitedWithTheServicesOnes(): void
    {
        $this->walletTransfersOn();
        for ($i = 0; $i < CustomerLookup::LOOKUPS; $i++) {
            self::assertSame(200, $this->get($this->storeApi($this->website, '/wallet/transfer?telegram_id=7070'))->getStatusCode());
        }

        $response = $this->transfer(['telegram_id' => self::SARA, 'amount' => 30000]);

        self::assertSame(429, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame((string) CustomerLookup::LOOKUP_WINDOW, $response->getHeaderLine('Retry-After'));
        self::assertSame('0.00', $this->sara->balance());
    }

    public function testTransfersAreLimitedToTenAnHour(): void
    {
        $this->walletTransfersOn();
        $this->wallet($this->ali, '1000000');
        for ($i = 1; $i <= WalletTransfers::TRANSFERS; $i++) {
            self::assertSame(200, $this->transfer(['telegram_id' => self::SARA, 'amount' => 10000], "try-{$i}")->getStatusCode());
            // Every one a lookup too, ten in ten minutes at most: a minute apart, the eleventh in a window of its own.
            Carbon::setTestNow(now()->addMinute());
        }

        $response = $this->transfer(['telegram_id' => self::SARA, 'amount' => 10000], 'try-11');

        self::assertSame(429, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('100000.00', $this->sara->balance());
    }

    /** @param array<string, mixed> $body */
    private function transfer(array $body, string $key = 'try-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, '/wallet/transfer'), $body, ['Idempotency-Key' => $key]);
    }
}
