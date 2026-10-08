<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Enums\WayIn;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\AccountMerge;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Services\Identities;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Bots\Models\Bot;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Telegram\Broadcasts\Audience;
use App\Modules\Telegram\Broadcasts\BroadcastMode;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Models\Broadcast;
use App\Modules\Telegram\Models\BroadcastPin;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * Two accounts of one person in a shop made one (Accounts\Services\AccountMerger): the older stays, whichever asked, and
 * everything the other owned is its own — orders and their payments, services, the wallet's lines in one ledger counted
 * again, sessions, the notices it was told, its tickets and reviews, groups and a broadcast's pins without a row twice,
 * the customers it brought, an agency request, an agent's bot, the merges it took in before —, its ways in and profile
 * filling the empty slots; recorded and logged. Refused before anything changes: an account with itself, a banned one,
 * one kind of way in different on each, two agents.
 */
final class AccountMergerTest extends DatabaseTestCase
{
    private AccountMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->merger = $this->service(AccountMerger::class);
    }

    public function testEverythingTheNewerAccountOwnedIsTheOldersAndItsWaysInFillTheEmptySlots(): void
    {
        $logs = $this->logs();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $reza = $this->customer(['telegram_id' => 7001, 'first_name' => 'Reza']);
        // The older account: signed up on the website — its Telegram account, once theirs, taken off since.
        $sara = $this->webCustomer(['telegram_id' => 7002, 'username' => 'sara_tg', 'last_seen_at' => now()]);

        Carbon::setTestNow('2026-09-10 10:00:00');
        $ali = $this->agent(credit: '500000', overrides: ['telegram_id' => 7003, 'username' => 'ali', 'role' => UserRole::Admin, 'phone' => '+989120000003', 'referral_code' => 'alicode2', 'referred_by' => $reza->id]);
        $bot = $ali->ownBot ?? self::fail('The agent has no bot.');
        $level = $ali->agency_level_id;
        $nima = $this->customer(['telegram_id' => 7004, 'first_name' => 'Nima', 'referred_by' => $ali->id]);
        $sara->forceFill(['referred_by' => $ali->id])->save();
        // An account Ali took in before: its Google account is Ali's now, and the merge is recorded under Ali.
        Carbon::setTestNow('2026-09-11 10:00:00');
        $dara = $this->webCustomer(['email' => null, 'password_hash' => null, 'google_sub' => 'dara-google', 'first_name' => 'Dara']);
        $this->merger->merge($ali, $dara, AccountMerger::BY_CUSTOMER);
        $ali->refresh();

        Carbon::setTestNow('2026-09-12 10:00:00');
        $pinnedBoth = $this->broadcast($ali);
        $this->service(Identities::class)->remove($sara->refresh(), WayIn::Telegram);
        $pinnedAli = $this->broadcast($ali);
        $this->customerGroup('VIP', [$sara, $ali]);
        $team = $this->customerGroup('Team', [$ali]);
        $wallet = $this->service(WalletService::class);
        $wallet->credit($sara, '50000', 'sara in');
        $wallet->credit($ali, '30000', 'ali in');
        $wallet->debit($sara, '20000', 'sara out');
        $wallet->debit($ali, '10000', 'ali out');
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $order = $this->purchaseOrder($ali, $plan, $server);
        $payment = $this->cardPayment($order, $this->cardMethod());
        $service = $this->subscription($ali, $plan, $server, 'ali_1');
        $ticket = $this->ticket($ali, overrides: ['subscription_id' => $service->id]);
        $review = $this->review($ali);
        $request = $this->agencyRequest($ali);
        $this->service(CustomerNotifier::class)->referralJoined($ali, $nima);
        $token = $this->customerSession($ali);
        $this->service(AuthChallenges::class)->issueCode(ChallengePurpose::PasswordReset, 'ali@example.com', $ali, [], AuthChallenges::CODE_SECONDS);
        Carbon::setTestNow('2026-09-20 10:00:00');
        $ali->forceFill(['last_seen_at' => now(), 'bot_blocked' => true])->save();

        $survivor = $this->merger->merge($ali, $sara->refresh(), AccountMerger::BY_CUSTOMER);

        self::assertSame($sara->id, $survivor->id, 'the older account stays, though the newer asked');
        self::assertNull(User::query()->find($ali->id), 'the newer is gone');
        self::assertSame([$sara->id], Order::query()->pluck('user_id')->unique()->values()->all());
        self::assertSame($sara->id, $payment->refresh()->order->user_id, 'a payment follows its order');
        self::assertSame($sara->id, $service->refresh()->user_id);
        self::assertSame([$sara->id, $service->id], [$ticket->refresh()->user_id, $ticket->subscription_id], 'its support tickets, with the service they are about');
        self::assertSame($sara->id, $review->refresh()->user_id, 'its reviews of the shop');
        self::assertSame($sara->id, AgencyRequest::query()->findOrFail($request->id)->user_id);
        self::assertSame([$sara->id], Broadcast::query()->pluck('user_id')->unique()->values()->all(), 'the broadcasts it sent as a bot admin');
        self::assertSame([[$pinnedBoth->id, $sara->id], [$pinnedAli->id, $sara->id]], BroadcastPin::query()->whereIn('user_id', [$sara->id, $ali->id])->orderBy('broadcast_id')->get()->map(static fn(BroadcastPin $pin): array => [$pin->broadcast_id, $pin->user_id])->all(), 'one pin a broadcast: its own kept, the other moved');
        self::assertSame(['Team', 'VIP'], $survivor->groups->pluck('name')->sort()->values()->all(), 'each group once');
        self::assertSame([$sara->id], $team->users()->pluck('users.id')->all());
        self::assertSame($sara->id, $nima->refresh()->referred_by, 'the customers it brought');
        self::assertSame($sara->id, $bot->refresh()->user_id, 'its bot');
        self::assertSame($sara->id, $this->service(CustomerSessions::class)->find($token)?->user_id, 'its devices signed in, as the account that stays');
        self::assertSame([$sara->id], Notification::query()->pluck('user_id')->unique()->values()->all(), 'what the shop told it, in the feed of the account that stays');
        self::assertSame(0, AuthChallenge::query()->count(), "its sign-ins' secrets dropped");
        self::assertSame([$sara->id, $sara->id], AccountMerge::query()->orderBy('id')->pluck('user_id')->all(), 'the merge it took in before, and this one');

        self::assertSame([
            'telegram_id' => 7003, 'username' => 'ali', 'bot_blocked' => true, 'google_sub' => 'dara-google', 'email' => self::WEB_EMAIL,
            'first_name' => 'Sara', 'last_name' => 'Ahmadi', 'phone' => '+989120000003', 'referral_code' => 'alicode2', 'referred_by' => $reza->id,
            'agency_level_id' => $level, 'credit_limit' => '500000.00', 'role' => UserRole::Admin, 'last_seen_at' => '2026-09-20 10:00:00',
        ], [
            'telegram_id' => $survivor->telegram_id, 'username' => $survivor->username, 'bot_blocked' => $survivor->bot_blocked, 'google_sub' => $survivor->google_sub, 'email' => $survivor->email,
            'first_name' => $survivor->first_name, 'last_name' => $survivor->last_name, 'phone' => $survivor->phone, 'referral_code' => $survivor->referral_code, 'referred_by' => $survivor->referred_by,
            'agency_level_id' => $survivor->agency_level_id, 'credit_limit' => $survivor->credit_limit, 'role' => $survivor->role, 'last_seen_at' => $survivor->last_seen_at?->format('Y-m-d H:i:s'),
        ], 'each empty slot filled with what belongs to it — its own names, email and password stay');
        self::assertTrue(password_verify(self::WEB_PASSWORD, (string) $survivor->password_hash));

        $lines = WalletTransaction::query()->orderBy('id')->get();
        self::assertSame(array_fill(0, 4, $sara->id), $lines->pluck('user_id')->all(), 'one ledger');
        self::assertSame(['50000.00', '80000.00', '60000.00', '50000.00'], $lines->pluck('balance_after')->map(static fn(mixed $balance): string => number_format((float) $balance, 2, '.', ''))->all(), 'every balance counted again, line by line');
        self::assertSame('50000.00', $survivor->balance(), 'the two balances together');

        $merge = AccountMerge::query()->latest('id')->firstOrFail();
        self::assertSame([$ali->id, AccountMerger::BY_CUSTOMER], [$merge->merged_user_id, $merge->actor]);
        self::assertSame(['telegram_id' => 7003, 'username' => 'ali', 'email' => null, 'google' => true, 'first_name' => 'Ali', 'last_name' => null, 'created_at' => '2026-09-10T10:00:00+00:00'], $merge->merged);
        self::assertSame(['referrals' => 1, 'agency_requests' => 1, 'wallet_lines' => 2, 'groups' => 1, 'sessions' => 1, 'merges' => 1, 'notifications' => 1, 'subscriptions' => 1, 'orders' => 1, 'request_keys' => 0, 'commissions' => 0, 'tickets' => 1, 'reviews' => 1, 'broadcasts' => 2, 'pins' => 1, 'bots' => 1], $merge->moved);
        self::assertTrue($logs->hasInfoThatContains('was merged into customer'));
    }

    public function testTheOlderAccountStaysWhicheverAsks(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $older = $this->customer(['telegram_id' => 7101]);
        $twin = $this->customer(['telegram_id' => 7102]);
        Carbon::setTestNow('2026-09-02 10:00:00');
        $newer = $this->webCustomer();

        self::assertSame($older->id, $this->merger->merge($older, $newer, AccountMerger::BY_CUSTOMER)->id);

        Carbon::setTestNow('2026-09-01 10:00:00');
        $sameMoment = $this->webCustomer(['email' => 'same@example.com']);
        self::assertSame($twin->id, $this->merger->merge($sameMoment, $twin, 'root')->id, 'the same moment: numbered first');
        self::assertSame('root', AccountMerge::query()->latest('id')->firstOrFail()->actor, "a panel's principal");
    }

    public function testTheMergesTheRulesRefuseChangeNothing(): void
    {
        $ali = $this->customer(['telegram_id' => 7201]);
        $other = $this->customer(['telegram_id' => 7202]);
        $sara = $this->webCustomer(['google_sub' => 'sara-google']);
        $leila = $this->webCustomer(['email' => 'leila@example.com', 'google_sub' => 'leila-google']);
        $banned = $this->webCustomer(['email' => 'banned@example.com', 'status' => UserStatus::Banned]);

        self::assertSame(AccountRefusedException::SAME_ACCOUNT, $this->refusal($ali, $ali));
        self::assertSame(AccountRefusedException::BANNED, $this->refusal($ali, $banned));
        self::assertSame('هر دو حساب به یک نوع روش ورود متفاوت وصل هستند (تلگرام)؛ اول یکی را جدا کنید.', $this->refusal($ali, $other));
        self::assertSame('هر دو حساب به یک نوع روش ورود متفاوت وصل هستند (گوگل، ایمیل)؛ اول یکی را جدا کنید.', $this->refusal($sara, $leila));

        $agent = $this->agent(overrides: ['telegram_id' => 7203]);
        $levelled = $this->webCustomer(['email' => 'levelled@example.com', 'agency_level_id' => $agent->agency_level_id]);
        self::assertSame(AccountRefusedException::BOTH_AGENTS, $this->refusal($agent, $levelled), 'a level each');
        $this->service(AgencyActions::class)->revoke($agent, null);
        self::assertSame(AccountRefusedException::BOTH_AGENTS, $this->refusal($agent->refresh(), $levelled), 'a bot of their own, and a level');

        self::assertSame(7, User::query()->count(), 'nothing merged');
        self::assertSame(0, AccountMerge::query()->count());
        self::assertSame(1, Bot::query()->where('user_id', $agent->id)->count());
    }

    /** A pinned broadcast the bot admin sends to everyone, worked through: a pin in each chat it reached. */
    private function broadcast(User $admin): Broadcast
    {
        $broadcasts = $this->service(BroadcastService::class);
        $run = $broadcasts->start($admin, ['message_id' => 5, 'mode' => BroadcastMode::Copy, 'audience' => Audience::ALL, 'pin' => true], null);
        $broadcasts->process($run, 50);

        return $run;
    }

    /** What the merge of the two is refused with, from either side. */
    private function refusal(User $a, User $b): string
    {
        $words = [];
        foreach ([[$a, $b], [$b, $a]] as [$asking, $other]) {
            try {
                $this->merger->merge($asking, $other, AccountMerger::BY_CUSTOMER);
                self::fail('The merge was not refused.');
            } catch (AccountRefusedException $e) {
                $words[] = $e->getMessage();
            }
        }
        self::assertSame($words[0], $words[1], 'the same from either side');

        return $words[0];
    }
}
