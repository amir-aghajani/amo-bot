<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\Urls;
use App\Core\Security\Totp;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Store\Enums\StaffGrant;
use App\Modules\Store\Http\StaffMiddleware;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionActions;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * The shop's admins — its customers whose role is admin — work the shop from its website: the panels' own operations of
 * its daily work under `/admin` of the Store API (Store\Http\StaffMiddleware). Shut by default and asked at the door
 * every time: an admin of the shop, a website that lets them in, a strong sign-in while it asks one (a password alone
 * is made strong by its second step proven again), a way in proven within a working day, what the website grants for an
 * operation that needs a grant — and then a recent sign-in too, which approving a payment asks as well. Inside, an admin
 * reads what the panels read, in their shapes, decides under their own name,
 * never about themselves or another admin's account or an agent's, approves no payment without a receipt in review, leaves no client
 * on a panel that answers, reads a panel's failure in a word, works the panels at a pace, and every change they ask is
 * logged with them on the line.
 */
final class StaffApiTest extends HttpTestCase
{
    private Website $website;

    /** The shop's admin, signed in on its website. */
    private User $sara;

    /** A customer. */
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->fakePanel();
        $this->withoutQr();
        $this->website = $this->website();
        $this->sara = $this->customer(['telegram_id' => 7001, 'username' => 'sara', 'first_name' => 'Sara']);
        $this->ali = $this->customer(['telegram_id' => 7002, 'username' => 'ali', 'first_name' => 'Ali']);
    }

    public function testOnlyTheShopsAdminsGetInAndEachRefusalSaysWhy(): void
    {
        $none = $this->unchecked()->get($this->staffApi('/dashboard'));
        self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT, 'Bearer'], [$none->getStatusCode(), $this->decode($none)['message'], $none->getHeaderLine('WWW-Authenticate')]);

        // A customer, signed in strongly: no admin of the shop's, whatever the website says.
        $this->website->forceFill(['staff_enabled' => true])->save();
        $this->bearer($this->customerSession($this->sara, ['method' => SignInMethod::Telegram]));
        $this->assertRefused(StaffMiddleware::NOT_STAFF);
        self::assertNull($this->me()['staff'], 'no admin: nothing of the admin API');

        $this->loginAsStaff($this->website, $this->sara);
        self::assertSame(200, $this->get($this->staffApi('/dashboard'))->getStatusCode());
        self::assertSame(['grants' => [], 'strong_sign_in' => true, 'signed_in_strongly' => true, 'signed_in_until' => '2026-10-09T00:00:00+00:00', 'recent_until' => '2026-10-08T12:15:00+00:00'], $this->me()['staff']);

        $this->website->forceFill(['staff_enabled' => false])->save();
        $this->assertRefused(StaffMiddleware::STAFF_OFF);
        self::assertNull($this->me()['staff'], 'while the website lets no admin in');

        // A password alone — or a session from before the shop kept how it was signed in — is no strong sign-in.
        $this->website->forceFill(['staff_enabled' => true])->save();
        foreach ([SignInMethod::Password, null] as $method) {
            $this->bearer($this->customerSession($this->sara, ['method' => $method]));
            $refused = $this->assertRefused(StaffMiddleware::SIGN_IN_STRONGLY);
            self::assertSame(StaffMiddleware::STRONG_CHALLENGE, $refused->getHeaderLine('WWW-Authenticate'), 'the challenge names the strong ways');
            self::assertFalse($this->me()['staff']['signed_in_strongly'] ?? null);
        }
        $this->website->forceFill(['staff_strong_sign_in' => false])->save();
        self::assertSame(200, $this->get($this->staffApi('/dashboard'))->getStatusCode(), 'unless the website asks none');

        $this->sara->forceFill(['status' => 'banned'])->save();
        $this->assertRefused(SignInRefusedException::BANNED);
    }

    public function testAPasswordAloneIsMadeStrongByItsSecondStepProvenAgain(): void
    {
        $nima = $this->webCustomer(['role' => UserRole::Admin]);
        $secret = $this->twoFactorOn($this->website, $nima)['secret'];
        $this->website->forceFill(['staff_enabled' => true])->save();
        $this->bearer($this->customerSession($nima));
        $this->assertRefused(StaffMiddleware::SIGN_IN_STRONGLY);

        Carbon::setTestNow(now()->addSeconds(30));
        $proven = $this->postJson($this->storeApi($this->website, '/me/reauthenticate'), ['method' => 'password', 'password' => self::WEB_PASSWORD, 'code' => Totp::code($secret)]);
        self::assertSame(200, $proven->getStatusCode(), (string) $proven->getBody());

        self::assertSame(200, $this->get($this->staffApi('/dashboard'))->getStatusCode());
        self::assertSame(SignInMethod::PasswordAndCode, CustomerSession::query()->sole()->method);
    }

    /**
     * An admin's session works the admin API for a working day after a way in was proven on it — a token that leaked
     * works for hours, not for the months a customer's session lasts —; then every operation, a read too, asks a way in
     * proven again, which lets it work another day.
     */
    public function testEveryOperationAsksAWayInProvenWithinAWorkingDay(): void
    {
        $nima = $this->webCustomer(['role' => UserRole::Admin]);
        $secret = $this->twoFactorOn($this->website, $nima)['secret'];
        $this->website->forceFill(['staff_enabled' => true])->save();
        $this->bearer($this->customerSession($nima, ['method' => SignInMethod::PasswordAndCode]));
        self::assertSame(now()->addHours(StaffMiddleware::SIGN_IN_HOURS)->toIso8601String(), $this->me()['staff']['signed_in_until']);

        Carbon::setTestNow(now()->addHours(StaffMiddleware::SIGN_IN_HOURS)->subSecond());
        self::assertSame(200, $this->get($this->staffApi('/dashboard'))->getStatusCode(), 'its last second');

        Carbon::setTestNow(now()->addSecond());
        $stale = $this->assertRefused(RecentSignInMiddleware::SIGN_IN_AGAIN);
        self::assertSame(RecentSignInMiddleware::CHALLENGE, $stale->getHeaderLine('WWW-Authenticate'));
        self::assertSame([null, null], [$this->me()['staff']['signed_in_until'], $this->me()['staff']['recent_until']], 'the site is told to ask a way in again');
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me/sessions'))->getStatusCode(), 'the customer\'s own account is no admin\'s work: it stands');

        $proven = $this->postJson($this->storeApi($this->website, '/me/reauthenticate'), ['method' => 'password', 'password' => self::WEB_PASSWORD, 'code' => Totp::code($secret)]);
        self::assertSame(200, $proven->getStatusCode(), (string) $proven->getBody());
        self::assertSame(200, $this->get($this->staffApi('/dashboard'))->getStatusCode(), 'proven again: another working day');
    }

    /** Approving a payment delivers a service by the admin's word alone: it asks a recent sign-in, as a granted operation does. */
    public function testApprovingAPaymentAsksARecentSignInAsAGrantedOperationDoes(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $method = $this->cardMethod();
        $receipt = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '10000.00'), $method));
        Carbon::setTestNow(now()->addSeconds(CustomerSessions::RECENT_SECONDS + 1));

        $stale = $this->assertRefused(RecentSignInMiddleware::SIGN_IN_AGAIN, $this->postJson($this->staffApi("/payments/{$receipt->id}/approve")));
        self::assertSame(RecentSignInMiddleware::CHALLENGE, $stale->getHeaderLine('WWW-Authenticate'));
        self::assertSame(PaymentStatus::AwaitingReview, $receipt->refresh()->status, 'nothing approved');
        self::assertSame(200, $this->postJson($this->staffApi("/payments/{$receipt->id}/reject"), [])->getStatusCode(), 'the rest of the daily work asks none');

        $this->loginAsStaff($this->website, $this->sara);
        $another = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '20000.00'), $method));
        self::assertSame(200, $this->postJson($this->staffApi("/payments/{$another->id}/approve"))->getStatusCode(), 'signed in a moment ago');
    }

    public function testAGrantedOperationAsksItsGrantAndThenARecentSignIn(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $credit = fn() => $this->postJson($this->staffApi("/users/{$this->ali->id}/wallet"), ['type' => 'credit', 'amount' => 5000, 'description' => 'جبران قطعی']);

        $this->assertRefused(StaffMiddleware::NOT_GRANTED, $credit());

        $this->grant(StaffGrant::Wallet);
        $credited = $credit();
        self::assertSame(201, $credited->getStatusCode(), (string) $credited->getBody());
        self::assertSame(['5000.00', '@sara'], [$this->decode($credited)['transaction']['amount'], $this->decode($credited)['transaction']['reviewer']], 'written by hand, under the admin\'s name');

        // The sign-in grown old: a granted operation asks it again, the daily work does not.
        Carbon::setTestNow(now()->addSeconds(CustomerSessions::RECENT_SECONDS + 1));
        $stale = $this->assertRefused(RecentSignInMiddleware::SIGN_IN_AGAIN, $credit());
        self::assertSame(RecentSignInMiddleware::CHALLENGE, $stale->getHeaderLine('WWW-Authenticate'));
        self::assertNull($this->me()['staff']['recent_until']);
        self::assertSame(200, $this->putJson($this->staffApi("/users/{$this->ali->id}/groups"), ['group_ids' => []])->getStatusCode());
        self::assertSame('5000.00', $this->ali->balance(), 'credited once');
    }

    public function testEveryReadOfTheAdminApiAnswersTheShopsAdminInThePanelsShapes(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $receipt = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $plan, $server), $this->cardMethod()));
        $service = $this->subscription($this->ali, $plan, $server, 'ali_1');
        $ticket = $this->ticket($this->ali, 'قطعی', 'سرویسم وصل نمی‌شود.');

        // Every list and figure there is — a read added later included —, then each kind of row's own.
        $reads = [];
        foreach ($this->app()->http()->getRouteCollector()->getRoutes() as $route) {
            $relative = substr($route->getPattern(), strlen(Urls::STORE . '/admin'));
            if (str_starts_with($route->getPattern(), Urls::STORE . '/admin/') && in_array('GET', $route->getMethods(), true) && !str_contains($relative, '{')) {
                $reads[] = $relative;
            }
        }
        self::assertGreaterThan(15, count($reads));
        $reads = [...$reads, "/users/{$this->ali->id}", "/users/{$this->ali->id}/wallet", "/orders/{$receipt->order_id}", "/payments/{$receipt->id}", "/subscriptions/{$service->id}", "/tickets/{$ticket->id}"];

        foreach ($reads as $path) {
            $response = $this->get($this->staffApi($path));
            self::assertSame(200, $response->getStatusCode(), "GET {$path}: {$response->getBody()}");
        }
    }

    public function testTheAdminDecidesUnderTheirOwnName(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $receipt = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '10000.00'), $this->cardMethod()));

        $approved = $this->postJson($this->staffApi("/payments/{$receipt->id}/approve"));

        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame(['paid', '@sara'], [$receipt->refresh()->status->value, $receipt->reviewer]);

        // An admin the website alone knows — no Telegram, no handle — is their number.
        $nima = $this->webCustomer();
        $this->loginAsStaff($this->website, $nima);
        $another = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '20000.00'), $this->cardMethod('کارت دوم')));
        self::assertSame(200, $this->postJson($this->staffApi("/payments/{$another->id}/reject"), ['note' => 'ناخوانا'])->getStatusCode());
        self::assertSame("user#{$nima->id}", $another->refresh()->reviewer);
    }

    public function testNoAdminDecidesAboutThemselves(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $this->grant(StaffGrant::Wallet, StaffGrant::Refunds, StaffGrant::Extend, StaffGrant::Delete);
        $card = $this->cardMethod();
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);

        $hers = $this->receipt($this->cardPayment($this->topUpOrder($this->sara, '10000.00'), $card));
        $this->assertRefused(ActorRefusedException::OWN_PAYMENT, $this->postJson($this->staffApi("/payments/{$hers->id}/approve")));
        self::assertSame('awaiting_review', $hers->refresh()->status->value);

        $paid = $this->paidByCard($this->topUpOrder($this->sara, '20000.00'), $card);
        $this->assertRefused(ActorRefusedException::OWN_PAYMENT, $this->postJson($this->staffApi("/payments/{$paid->id}/refund"), []));
        $this->assertRefused(ActorRefusedException::OWN_WALLET, $this->postJson($this->staffApi("/users/{$this->sara->id}/wallet"), ['type' => 'credit', 'amount' => 1000]));
        self::assertSame('20000.00', $this->sara->balance(), 'her wallet as it was');

        $service = $this->mirrored($this->sara, $plan, $server, 'sara_1');
        $this->assertRefused(ActorRefusedException::OWN_SERVICE, $this->postJson($this->staffApi("/subscriptions/{$service->id}/extend"), ['days' => 3, 'traffic_gb' => 0]));
        $this->assertRefused(ActorRefusedException::OWN_SERVICE, $this->postJson($this->staffApi("/subscriptions/{$service->id}/disable"), []));
        $this->assertRefused(ActorRefusedException::OWN_SERVICE, $this->postJson($this->staffApi("/subscriptions/{$service->id}/move"), ['server_id' => $this->fakeServer('دوم')->id]));
        $this->assertRefused(ActorRefusedException::OWN_SERVICE, $this->postJson($this->staffApi("/subscriptions/{$service->id}/delete"), []));
        self::assertTrue(Subscription::query()->whereKey($service->id)->exists());
        // Switched off by someone else's decision, it is not theirs to switch back on; reading its panel again is.
        $service->update(['status' => SubscriptionStatus::Disabled]);
        $this->assertRefused(ActorRefusedException::OWN_SERVICE, $this->postJson($this->staffApi("/subscriptions/{$service->id}/enable")));
        self::assertSame(SubscriptionStatus::Disabled, $service->refresh()->status);
        self::assertSame(200, $this->postJson($this->staffApi("/subscriptions/{$service->id}/sync"))->getStatusCode());
        self::assertSame([], FakeProvider::updatedNames(), 'nothing of hers changed on the panel');

        // The review she wrote, signed in, is another admin's to decide.
        $review = $this->review($this->sara, 'Sara');
        $this->assertRefused(ActorRefusedException::OWN_REVIEW, $this->postJson($this->staffApi("/reviews/{$review->id}/approve")));
        $this->assertRefused(ActorRefusedException::OWN_REVIEW, $this->postJson($this->staffApi("/reviews/{$review->id}/reject")));
        $this->assertRefused(ActorRefusedException::OWN_REVIEW, $this->deleteJson($this->staffApi("/reviews/{$review->id}")));
        self::assertSame(ReviewStatus::Pending, $review->refresh()->status, 'her review as it was');

        // Another customer's, the same operations go through.
        $alis = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '30000.00'), $card));
        self::assertSame(200, $this->postJson($this->staffApi("/payments/{$alis->id}/approve"))->getStatusCode());
        self::assertSame(201, $this->postJson($this->staffApi("/users/{$this->ali->id}/wallet"), ['type' => 'credit', 'amount' => 1000])->getStatusCode());
        $theirs = $this->mirrored($this->ali, $plan, $server, 'ali_1');
        self::assertSame(200, $this->postJson($this->staffApi("/subscriptions/{$theirs->id}/extend"), ['days' => 3, 'traffic_gb' => 0])->getStatusCode());
        self::assertSame(204, $this->postJson($this->staffApi("/subscriptions/{$theirs->id}/delete"), [])->getStatusCode());
        self::assertSame(200, $this->postJson($this->staffApi("/reviews/{$this->review($this->ali)->id}/approve"))->getStatusCode());
    }

    public function testNoAdminTouchesAnAdminsAccountFromTheWebsite(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $this->grant(StaffGrant::AccountSecurity);
        $reza = $this->admin(['telegram_id' => 7003, 'username' => 'reza']);

        foreach ([$reza, $this->sara] as $admin) {
            $this->assertRefused(ActorRefusedException::ADMIN_ACCOUNT, $this->patchJson($this->staffApi("/users/{$admin->id}"), ['status' => 'banned']));
            $this->assertRefused(ActorRefusedException::ADMIN_ACCOUNT, $this->postJson($this->staffApi("/users/{$admin->id}/two-factor/disable")));
            $this->assertRefused(ActorRefusedException::ADMIN_ACCOUNT, $this->postJson($this->staffApi("/users/{$admin->id}/sessions/end")));
        }
        self::assertFalse($reza->refresh()->isBanned());
        self::assertSame(1, CustomerSession::query()->where('user_id', $this->sara->id)->count(), 'her session stands');

        self::assertSame('banned', $this->decode($this->patchJson($this->staffApi("/users/{$this->ali->id}"), ['status' => 'banned']))['user']['status']);
        $this->customerSession($this->ali);
        self::assertSame(204, $this->postJson($this->staffApi("/users/{$this->ali->id}/sessions/end"))->getStatusCode());
        self::assertSame(0, CustomerSession::query()->where('user_id', $this->ali->id)->count());

        // Who is an admin is the panels' to say: the website has no such address.
        self::assertSame(404, $this->putJson($this->staffApi("/users/{$this->ali->id}/role"), ['role' => 'admin'])->getStatusCode());
        self::assertFalse($this->ali->refresh()->isAdmin());
    }

    public function testNoAdminTouchesAnAgentsAccountFromTheWebsite(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $this->grant(StaffGrant::AccountSecurity);
        $agent = $this->agent(overrides: ['telegram_id' => 7004, 'username' => 'reza']);
        $this->customerSession($agent);

        $this->assertRefused(ActorRefusedException::AGENT_ACCOUNT, $this->patchJson($this->staffApi("/users/{$agent->id}"), ['status' => 'banned']));
        $this->assertRefused(ActorRefusedException::AGENT_ACCOUNT, $this->postJson($this->staffApi("/users/{$agent->id}/two-factor/disable")));
        $this->assertRefused(ActorRefusedException::AGENT_ACCOUNT, $this->postJson($this->staffApi("/users/{$agent->id}/sessions/end")));
        self::assertFalse($agent->refresh()->isBanned(), "the owner's partner: their account is the panels' to change");
        self::assertSame(1, CustomerSession::query()->where('user_id', $agent->id)->count(), 'their session stands');
    }

    public function testAnApprovalFromTheWebsiteNeedsAReceiptInReview(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $pending = $this->cardPayment($this->topUpOrder($this->ali, '10000.00'), $this->cardMethod());

        $this->assertRefused(ActorRefusedException::RECEIPT_FIRST, $this->postJson($this->staffApi("/payments/{$pending->id}/approve")));
        self::assertSame('pending', $pending->refresh()->status->value);

        // The owner, who saw the transfer some other way, approves it by hand from their panel.
        $this->loginAsAdmin();
        self::assertSame(200, $this->postJson("/api/admin/payments/{$pending->id}/approve")->getStatusCode());
    }

    /** Still in review as it is approved: a receipt another admin rejects the moment this approval has read it stays rejected. */
    public function testAReceiptRejectedWhileAnAdminApprovesItFromTheWebsiteIsNotApproved(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $receipt = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '10000.00'), $this->cardMethod()));

        $rejected = false;
        $response = $this->whileListening('eloquent.retrieved: ' . Payment::class, function (Payment $read) use (&$rejected): void {
            if (!$rejected) {
                $rejected = true;
                $this->service(PaymentService::class)->reject(Payment::query()->findOrFail($read->id), 'admin', 'ناخوانا');
            }
        }, fn(): ResponseInterface => $this->postJson($this->staffApi("/payments/{$receipt->id}/approve")));

        self::assertSame([422, PaymentActions::DECIDED_MEANWHILE], [$response->getStatusCode(), $this->decode($response)['errors']['status'][0] ?? null], (string) $response->getBody());
        $receipt->refresh();
        self::assertSame([PaymentStatus::Failed, 'ناخوانا', 'admin'], [$receipt->status, $receipt->note, $receipt->reviewer], 'the rejection stands');
        self::assertSame(OrderStatus::Pending, $receipt->order->status, 'nothing delivered');
    }

    public function testAClientIsLeftOnAPanelOnlyWhileThatPanelIsOutOfReach(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $this->grant(StaffGrant::Delete);
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $target = $this->fakeServer('هلند');
        $this->inbound($target, '7');
        $service = $this->mirrored($this->ali, $plan, $server, 'ali_1');

        $moved = $this->postJson($this->staffApi("/subscriptions/{$service->id}/move"), ['server_id' => $target->id, 'leave_previous' => true]);
        self::assertSame([sprintf(SubscriptionActions::LEAVE_REFUSED, 'آلمان')], $this->decode($moved)['errors']['status'] ?? null, "the main bot's shop, but no owner asks");
        $deleted = $this->postJson($this->staffApi("/subscriptions/{$service->id}/delete"), ['leave_panel' => true]);
        self::assertSame([sprintf(SubscriptionActions::LEAVE_REFUSED, 'آلمان')], $this->decode($deleted)['errors']['status'] ?? null);
        self::assertSame([], FakeProvider::$created);

        // The panel seen failing — a move that could not read it —: then the client may stay there.
        FakeProvider::$down = [$server->id];
        self::assertSame(502, $this->postJson($this->staffApi("/subscriptions/{$service->id}/move"), ['server_id' => $target->id])->getStatusCode());
        $left = $this->postJson($this->staffApi("/subscriptions/{$service->id}/move"), ['server_id' => $target->id, 'leave_previous' => true]);
        self::assertSame(200, $left->getStatusCode(), (string) $left->getBody());
        self::assertSame($target->id, $service->refresh()->server_id);
    }

    public function testAPanelsFailureIsTheSummaryForTheShopsAdmins(): void
    {
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $service = $this->mirrored($this->ali, $plan, $server, 'ali_1');
        FakeProvider::$unreachable = true;

        $this->loginAsAdmin();
        $owners = $this->decode($this->postJson("/api/admin/subscriptions/{$service->id}/sync"))['message'];
        self::assertSame('پنل «آلمان»: ' . ProviderErrorPresenter::describe(new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out')), $owners, 'the owner gets the diagnosis');

        $this->loginAsStaff($this->website, $this->sara);
        $synced = $this->postJson($this->staffApi("/subscriptions/{$service->id}/sync"));
        self::assertSame(502, $synced->getStatusCode());
        self::assertSame('پنل «آلمان»: ' . ProviderErrorPresenter::summary(new ConnectionException(ConnectionFailure::Timeout, '')), $this->decode($synced)['message'], 'an admin of the shop reads what happened, in a word');

        // A move's: the server it names, and the summary.
        FakeProvider::$unreachable = false;
        $target = $this->fakeServer('هلند');
        $this->inbound($target, '7');
        FakeProvider::$refusing = [$target->id => ['createClient']];
        $moved = $this->postJson($this->staffApi("/subscriptions/{$service->id}/move"), ['server_id' => $target->id]);
        self::assertSame(['سرور مقصد «هلند»: ' . ProviderErrorPresenter::summary(new PanelApiException('refused', 200, 'refused by the test'))], $this->decode($moved)['errors']['target'] ?? null);
        self::assertStringNotContainsString('refused by the test', (string) $moved->getBody());

        // A delivery the panel failed: its order keeps both — the owner reads the diagnosis, an admin of the shop the word.
        $this->inbound($server, '1');
        FakeProvider::$refusing = [$server->id => ['createClient']];
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $plan, $server));
        $refusal = new PanelApiException('The fake panel refused createClient.', 200, 'refused by the test');
        self::assertSame(ProviderErrorPresenter::summary($refusal), $this->decode($this->get($this->staffApi("/orders/{$payment->order_id}")))['order']['notes']);
        self::assertSame(ProviderErrorPresenter::summary($refusal), $this->decode($this->get($this->staffApi("/payments/{$payment->id}")))['payment']['order']['notes']);
        $this->loginAsAdmin();
        self::assertSame(ProviderErrorPresenter::describe($refusal), $this->decode($this->get("/api/admin/orders/{$payment->order_id}"))['order']['notes'], 'the owner reads what the panel said');
        self::assertSame(ProviderErrorPresenter::describe($refusal), $this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment']['order']['notes']);
    }

    public function testTheAdminsWorkOnThePanelsIsHeldToAPace(): void
    {
        $this->loginAsStaff($this->website, $this->sara);
        $server = $this->fakeServer();
        $service = $this->mirrored($this->ali, $this->plan([], $server), $server, 'ali_1');
        for ($i = 0; $i < SubscriptionActions::PANEL_WORK; $i++) {
            self::assertSame(200, $this->postJson($this->staffApi("/subscriptions/{$service->id}/sync"))->getStatusCode());
        }

        $refused = $this->postJson($this->staffApi("/subscriptions/{$service->id}/sync"));

        self::assertSame(429, $refused->getStatusCode(), "the main bot's shop's own pace, on its website");
        self::assertStringStartsWith(SubscriptionActions::TOO_MUCH_PANEL_WORK, $this->decode($refused)['message']);
    }

    public function testEveryChangeTheAdminAsksIsLoggedWithThemOnTheLine(): void
    {
        $logs = $this->logs();
        $this->loginAsStaff($this->website, $this->sara);
        $ticket = $this->ticket($this->ali);
        $reza = $this->admin(['telegram_id' => 7003, 'username' => 'reza']);

        self::assertSame(200, $this->postJson($this->staffApi("/tickets/{$ticket->id}/messages"), ['body' => 'درست شد.'])->getStatusCode());
        $this->assertRefused(ActorRefusedException::ADMIN_ACCOUNT, $this->patchJson($this->staffApi("/users/{$reza->id}"), ['status' => 'banned']));
        self::assertSame(200, $this->get($this->staffApi('/tickets'))->getStatusCode());

        $actor = "staff @sara #{$this->sara->id}";
        $lines = array_values(array_filter($logs->getRecords(), static fn(LogRecord $record): bool => ($record->extra['actor'] ?? null) === $actor));
        $said = array_map(static fn(LogRecord $record): string => $record->message, $lines);
        self::assertContains("Ticket {$ticket->id} answered by @sara (staff)", $said, "the decision's own line names them too");
        self::assertContains("A shop admin on the website asked POST /api/store/v1/{$this->website->key}/admin/tickets/{$ticket->id}/messages: 200", $said);
        self::assertContains("A shop admin on the website asked PATCH /api/store/v1/{$this->website->key}/admin/users/{$reza->id}: 403", $said, 'a refused change is logged too');
        self::assertCount(0, array_filter($said, static fn(string $line): bool => str_contains($line, 'asked GET')), 'a read leaves no line');
    }

    public function testATicketAnsweredFromTheWebsiteIsTheWebsitesAdminSide(): void
    {
        $this->reportGroup();
        $this->loginAsStaff($this->website, $this->sara);
        $ticket = $this->ticket($this->ali);

        self::assertSame(200, $this->postJson($this->staffApi("/tickets/{$ticket->id}/messages"), ['body' => 'درست شد.'])->getStatusCode());

        $answer = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        self::assertSame([TicketChannel::Staff, '@sara'], [$answer->channel, $answer->reviewer]);
        self::assertStringContainsString("↩️ <b>پاسخ پشتیبانی</b> · تیکت #{$ticket->id} · از مدیریت وب‌سایت", ReportMessage::query()->latest('id')->firstOrFail()->text);

        $this->loginAsAdmin();
        self::assertSame('staff', $this->decode($this->get("/api/admin/tickets/{$ticket->id}"))['ticket']['messages'][1]['channel']);
    }

    public function testAnotherShopsAdminsAreNotThisOnesAndTheirWebsiteOpensNothingHere(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Website => $this->website());
        $sara = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7001, 'username' => 'sara']));
        $this->loginAsStaff($theirs, $sara);
        $receipt = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '10000.00'), $this->cardMethod()));

        self::assertSame(404, $this->get($this->storeApi($theirs, "/admin/payments/{$receipt->id}"))->getStatusCode(), "the main bot's payment is not the agent's shop's");
        self::assertSame(401, $this->get($this->staffApi("/payments/{$receipt->id}"))->getStatusCode(), "the agent's shop's token opens nothing on the main bot's website");
        self::assertSame(1, Payment::query()->whereKey($receipt->id)->count());
    }

    /** An address of the website's admin API. */
    private function staffApi(string $path): string
    {
        return $this->storeApi($this->website, '/admin' . $path);
    }

    /** What the website lets its admins do beyond the shop's daily work, from now on. */
    private function grant(StaffGrant ...$grants): void
    {
        $this->website->forceFill(['staff_grants' => array_map(static fn(StaffGrant $grant): string => $grant->value, $grants)])->save();
    }

    /**
     * GET /me of the customer the requests are made for.
     *
     * @return array<string, mixed>
     */
    private function me(): array
    {
        return $this->decode($this->get($this->storeApi($this->website, '/me')));
    }

    /** A 403 with this message — the dashboard asked, unless the answer is given —: the refusal. */
    private function assertRefused(string $message, ?ResponseInterface $response = null): ResponseInterface
    {
        $response ??= $this->get($this->staffApi('/dashboard'));
        self::assertSame([403, $message], [$response->getStatusCode(), $this->decode($response)['message'] ?? null], (string) $response->getBody());

        return $response;
    }

    /** A service of the customer's whose client is on the fake panel as the row has it. */
    private function mirrored(User $user, Plan $plan, Server $server, string $name): Subscription
    {
        $subscription = $this->subscription($user, $plan, $server, $name);
        FakeProvider::mirror($subscription);

        return $subscription;
    }
}
