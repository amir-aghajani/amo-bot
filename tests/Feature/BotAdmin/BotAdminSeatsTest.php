<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Http\ErrorHandler;
use App\Modules\Auth\Actor;
use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Settings\Services\Settings;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Admin\SeatKind;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerWalletScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserActions;
use Tests\BotTestCase;

/**
 * Who manages a shop from its bot, and nothing more (AdminSeats, OwnerLinks): the owner's link spent once whoever opens
 * it at the same moment; the main bot's admins on the owner's seat — every section and any shop, under their own name,
 * the owner's login read as «پشتیبانی», nothing decided about their own account, the owner's Telegram accounts the
 * owner's alone —; an agent's bot's admins on the agent's seat while the agency stands; a seat ended by a ban, a role
 * taken or an agency ended on the next update; and a forged button — another shop's payment, an agent's shop switch —
 * doing nothing the seat could not do in its own panel.
 */
final class BotAdminSeatsTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnersLinkIsSpentOnceWhenTwoOpenItAtOnce(): void
    {
        $links = $this->service(OwnerLinks::class);
        $payload = self::payload($links->link());
        // Another request (another update, its own process) read the settings while the link was open.
        $elsewhere = new OwnerLinks(new Settings(), $this->service(Bots::class));
        self::assertTrue($elsewhere->opens($payload));

        self::assertTrue($links->redeem($payload, 7001));
        self::assertFalse($elsewhere->redeem($payload, 7002), 'spent: the other opening links nothing');
        self::assertSame([7001], (new OwnerLinks(new Settings(), $this->service(Bots::class)))->ids());

        // A new link made while a stale reader still holds the old one: the old one opens nothing, the new one stays open.
        $stale = new OwnerLinks(new Settings(), $this->service(Bots::class));
        $old = self::payload($links->link());
        self::assertTrue($stale->opens($old));
        $new = self::payload($links->link());
        self::assertFalse($stale->redeem($old, 7003));
        self::assertTrue($links->opens($new), 'the new link is not spent by the old one\'s failure');
        self::assertSame([7001], $links->ids());
    }

    public function testAnAgentWhoseAgencyEndedManagesNothing(): void
    {
        $bot = $this->agentBot();
        $account = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => self::AGENT_TELEGRAM_ID]));
        $seats = $this->service(AdminSeats::class);
        $seat = CurrentBot::run($bot, static fn(): ?AdminSeat => $seats->of($account));
        self::assertSame(SeatKind::Agent, $seat?->kind);

        CurrentBot::run(Bot::MAIN, static fn(): bool => $bot->agent()->firstOrFail()->forceFill(['agency_level_id' => null])->save());
        $bot = $bot->refresh();
        self::assertSame(BotStatus::Disabled, $bot->status());

        self::assertNull(CurrentBot::run($bot, static fn(): ?AdminSeat => $seats->of($account)), 'as their panel refuses them');
        self::assertFalse(CurrentBot::run($bot, static fn(): bool => $seats->exempt($account)));
    }

    public function testAForgedTapNamingAnotherShopsPaymentChangesNothing(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, function (): Payment {
            $customer = $this->customer(['telegram_id' => 8800]);

            return $this->receipt($this->cardPayment($this->topUpOrder($customer, '50000.00'), $this->cardMethod()));
        });
        $ours = $this->receipt($this->cardPayment($this->topUpOrder($this->customer(['telegram_id' => 8801]), '70000.00'), $this->cardMethod()));

        // One of the main shop's admins, at an agent's payment.
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $this->send($this->tap(Nav::to('pyv', 'ok', $theirs->id), chat: 7100));
        self::assertSame(ErrorHandler::NOT_FOUND, $this->popup());
        self::assertSame(PaymentStatus::AwaitingReview, CurrentBot::run($bot, static fn(): PaymentStatus => $theirs->refresh()->status));

        // The agent, at the main shop's.
        $this->sendTo($bot, $this->tap(Nav::to('pyv', 'ok', $ours->id), chat: self::AGENT_TELEGRAM_ID));
        self::assertSame(ErrorHandler::NOT_FOUND, $this->popup());
        self::assertSame(PaymentStatus::AwaitingReview, $ours->refresh()->status);
    }

    public function testTheSearchFindsNothingOfAnotherShop(): void
    {
        $bot = $this->agentBot();
        $sara = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 8800, 'username' => 'sara_a']));
        $this->customer(['telegram_id' => 8801, 'username' => 'ali_r']);

        // The agent: their own customer found, the main shop's not.
        $this->sendTo($bot, $this->tap(Nav::to(Screens::SEARCH), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('@sara_a', chat: self::AGENT_TELEGRAM_ID));
        self::assertContains(Nav::to('usv', 'show', $sara->id), $this->callbacks($this->windowCall()));
        $this->sendTo($bot, $this->tap(Nav::to(Screens::SEARCH), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('@ali_r', chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString(Words::NOTHING_FOUND, implode("\n", $this->said()));

        // The main shop's admin: the other way round.
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $this->send($this->tap(Nav::to(Screens::SEARCH), chat: 7100));
        $this->send($this->message('@sara_a', chat: 7100));
        self::assertStringContainsString(Words::NOTHING_FOUND, implode("\n", $this->said()));
    }

    public function testARoleAdminInTheMainBotHasTheOwnersSeatUnderTheirOwnName(): void
    {
        $admin = $this->customer(['telegram_id' => 7100, 'username' => 'sara_admin', 'role' => UserRole::Admin]);
        $seats = $this->service(AdminSeats::class);

        $seat = $seats->of($admin);
        self::assertNotNull($seat);
        self::assertSame([SeatKind::Owner, PrincipalKind::Owner, Bot::MAIN], [$seat->kind, $seat->principal->kind, $seat->shop()->id], 'the owner\'s seat: every section');
        self::assertSame([Reviewers::forAdmin($admin), $admin->id, null], [$seat->principal->name, $seat->principal->customer?->id, $seat->principal->grants], 'under their own name, their account beside it, no grant to ask');
        self::assertSame([true, false, true], [$seat->isAdmin(), $seat->principal->isTheOwner(), $seat->principal->inBot]);

        // Any shop, as the owner's panel opens any: the same person, the same name.
        $bot = $this->agentBot();
        $elsewhere = $seat->in($bot);
        self::assertSame([$bot->id, '@sara_admin', $admin->id], [$elsewhere->shop()->id, $elsewhere->principal->name, $elsewhere->principal->customer?->id]);
        self::assertSame($bot->id, $seats->of($admin, $bot->id)?->shop()->id, 'the shop the chat picked');
        self::assertTrue($seats->exempt($admin), 'the bot\'s gates never stop them');
    }

    public function testAnAdminSwitchesShopsAsTheOwnerDoes(): void
    {
        $bot = $this->agentBot();
        $admin = $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->message('/admin', chat: 7100));
        self::assertContains(Nav::to('shop'), $this->callbacks(0), 'the shop switcher, on the home');

        $this->send($this->tap(Nav::to('shop', 'pick', $bot->id), chat: 7100));
        self::assertStringContainsString('فروشگاه نماینده', $this->said()[0], 'the home of the shop picked');
        $fact = $this->service(SessionStore::class)->load(7100)->get(Admin::FACT);
        self::assertSame($bot->id, is_array($fact) ? $fact['shop'] ?? null : null, 'the pick is the chat\'s');
        $seat = $this->service(AdminSeats::class)->of($admin, $bot->id);
        self::assertSame([SeatKind::Owner, $bot->id], [$seat?->kind, $seat?->shop()->id]);
    }

    public function testOnlyTheOwnersSeatSwitchesShops(): void
    {
        $bot = $this->agentBot();

        $this->sendTo($bot, $this->tap(Nav::to('shop', 'pick', Bot::MAIN), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringStartsWith('<b>⚠️ ' . Words::OLD_BUTTON, $this->said()[0] ?? '', 'the agent\'s seat has no switcher: the home, as an older window\'s button');
        $fact = $this->service(SessionStore::class)->load(self::AGENT_TELEGRAM_ID)->get(Admin::FACT);
        self::assertNull(is_array($fact) ? $fact['shop'] ?? null : null, 'no shop picked');

        // An agent's bot's admin: their seat works their bot's shop, whatever shop is asked of it.
        $admin = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7300, 'role' => UserRole::Admin]));
        $seat = CurrentBot::run($bot, fn(): ?AdminSeat => $this->service(AdminSeats::class)->of($admin, Bot::MAIN));
        self::assertSame([SeatKind::Agent, $bot->id], [$seat?->kind, $seat?->shop()->id]);
        $this->expectException(\LogicException::class);
        $seat?->in(CurrentBot::main());
    }

    public function testARoleAdminInAnAgentsBotHasTheAgentsSeatWhileTheAgencyStands(): void
    {
        $bot = $this->agentBot();
        $seats = $this->service(AdminSeats::class);
        $admin = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7300, 'username' => 'reza_admin', 'role' => UserRole::Admin]));

        $seat = CurrentBot::run($bot, static fn(): ?AdminSeat => $seats->of($admin));
        self::assertNotNull($seat);
        self::assertSame([SeatKind::Agent, PrincipalKind::Agent, $bot->id], [$seat->kind, $seat->principal->kind, $seat->shop()->id], 'the agent\'s seat, in the agent\'s shop');
        self::assertSame(['@reza_admin', $admin->id], [$seat->principal->name, $seat->principal->customer?->id], 'under their own name, not the bot\'s');
        self::assertSame([true, false, false], [$seat->isAdmin(), $seat->isOwner(), $seat->principal->isTheOwner()], 'never the owner\'s');

        // The same Telegram account is no admin of the main bot: nothing there.
        self::assertNull($seats->of($this->customer(['telegram_id' => 7300])));

        // The agency ended: its admins manage nothing either, as the agent does not.
        CurrentBot::run(Bot::MAIN, static fn(): bool => $bot->agent()->firstOrFail()->forceFill(['agency_level_id' => null])->save());
        $bot = $bot->refresh();
        self::assertSame(BotStatus::Disabled, $bot->status());
        self::assertNull(CurrentBot::run($bot, static fn(): ?AdminSeat => $seats->of($admin)));
    }

    public function testNobodyBannedManagesAnything(): void
    {
        $seats = $this->service(AdminSeats::class);
        $admin = $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin, 'status' => UserStatus::Banned]);
        self::assertNull($seats->of($admin), 'the main bot\'s admin');

        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7300, 'role' => UserRole::Admin, 'status' => UserStatus::Banned]));
        self::assertNull(CurrentBot::run($bot, static fn(): ?AdminSeat => $seats->of($theirs)), 'an agent\'s bot\'s');
    }

    public function testTheSeatEndsOnTheNextUpdateOnceTheRoleIsTaken(): void
    {
        $admin = $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $this->send($this->message('/admin', chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0]);

        $admin->forceFill(['role' => UserRole::Customer])->save();
        $this->send($this->message('/admin', chat: 7100));
        self::assertFalse($this->managed(), 'the next update: no seat');
        $this->send($this->tap(Nav::to('h'), chat: 7100));
        self::assertFalse($this->managed(), 'nor from an older window\'s button');

        // In an agent's bot, the same.
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7300, 'role' => UserRole::Admin]));
        $this->sendTo($bot, $this->message('/admin', chat: 7300));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0]);
        CurrentBot::run($bot, static fn(): bool => $theirs->forceFill(['role' => UserRole::Customer])->save());
        $this->sendTo($bot, $this->message('/admin', chat: 7300));
        self::assertFalse($this->managed());
    }

    public function testAnAdminWithTheOwnersSeatReadsTheOwnersLoginAsSupport(): void
    {
        $this->config(['admin.username' => 'root']);
        $seats = $this->service(AdminSeats::class);
        $owner = $seats->of($this->owner());
        $admin = $seats->of($this->customer(['telegram_id' => 7100, 'username' => 'sara_admin', 'role' => UserRole::Admin]));
        self::assertNotNull($owner);
        self::assertNotNull($admin);
        self::assertSame([SeatKind::Owner, SeatKind::Owner], [$owner->kind, $admin->kind], 'the same seat');
        self::assertSame([true, false], [$owner->principal->isTheOwner(), $admin->principal->isTheOwner()], 'not the same person');

        $reviewers = $this->service(Reviewers::class);
        $read = static fn(AdminSeat $seat, string $reviewer): ?string => CurrentPrincipal::run($seat->principal, static fn(): ?string => $reviewers->present($reviewer));
        self::assertSame('root', $read($owner, 'root'), 'the owner reads their own login');
        self::assertSame(Reviewers::SUPPORT, $read($admin, 'root'), 'an admin reads «پشتیبانی» for it: the login is half the credential');
        self::assertSame('@sara_admin', $read($owner, '@sara_admin'), 'an admin\'s own name, as kept');
        self::assertSame('@sara_admin', $read($admin, '@sara_admin'));
    }

    public function testAnAdminsSeatDecidesNothingAboutTheirOwnAccount(): void
    {
        $admin = $this->customer(['telegram_id' => 7100, 'first_name' => 'Sara', 'role' => UserRole::Admin]);
        $partners = $this->customerGroup('همکاران', overrides: ['agent_pricing' => true]);
        $theirs = $this->customerGroup('VIP', [$admin]);
        $gold = $this->customerGroup('Gold');
        $bridge = $this->bridge($admin);

        $refusals = [
            'a group they are in made «همکار»' => [ActorRefusedException::OWN_PRICES, static fn() => $bridge->put("/customer-groups/{$theirs->id}", ['name' => 'VIP', 'agent_pricing' => true])],
            'their own wallet' => [ActorRefusedException::OWN_WALLET, static fn() => $bridge->post("/users/{$admin->id}/wallet", ['type' => 'credit', 'amount' => 20000])],
            'an order for themselves' => [ActorRefusedException::OWN_ORDER, static fn() => $bridge->post("/users/{$admin->id}/orders", ['kind' => 'plan'], [IdempotencyKey::HEADER => 'own-order'])],
            'their own discount' => [ActorRefusedException::OWN_PRICES, static fn() => $bridge->put("/users/{$admin->id}/discount", ['percent' => 20])],
            'their own «همکار» group' => [ActorRefusedException::OWN_PRICES, static fn() => $bridge->put("/users/{$admin->id}/groups", ['group_ids' => [$partners->id]])],
        ];
        foreach ($refusals as $what => [$message, $call]) {
            $refused = self::refusal($call);
            self::assertSame([403, $message], [$refused->status, $refused->getMessage()], $what);
        }
        $admin->refresh();
        self::assertSame(['0.00', 0, [$theirs->id], false], [$admin->balance(), $admin->discount_percent, $admin->groups()->get()->modelKeys(), $theirs->refresh()->agent_pricing], 'nothing changed');
        self::assertSame(0, Order::query()->count());
        $bridge->put("/customer-groups/{$gold->id}", ['name' => 'Gold', 'agent_pricing' => true]);
        self::assertTrue($gold->refresh()->agent_pricing, 'a group they are not in: as the owner makes it');

        // Another admin does it for them.
        $other = $this->customer(['telegram_id' => 7200, 'first_name' => 'Reza', 'role' => UserRole::Admin]);
        $this->bridge($other)->post("/users/{$admin->id}/wallet", ['type' => 'credit', 'amount' => 20000]);
        $this->bridge($other)->put("/users/{$admin->id}/discount", ['percent' => 20]);
        self::assertSame(['20000.00', 20], [$admin->balance(), $admin->refresh()->discount_percent]);

        // The bot offers none of it on their own page, and all of it on another's.
        $this->send($this->tap(Nav::to('usv', 'show', $admin->id), chat: 7100));
        $own = $this->callbacks($this->windowCall());
        self::assertNotContains(Nav::to('uso', 'new', $admin->id), $own, 'no order by hand for themselves');
        self::assertNotContains(Nav::to('usv', 'disc', $admin->id), $own, 'no own discount');
        $this->send($this->tap(Nav::to('usw', 'show', $admin->id), chat: 7100));
        self::assertNotContains(Nav::to('usw', 'add', $admin->id), $this->callbacks($this->windowCall()), 'their wallet only to read');

        $this->send($this->tap(Nav::to('usv', 'show', $other->id), chat: 7100));
        self::assertContains(Nav::to('uso', 'new', $other->id), $this->callbacks($this->windowCall()));
        self::assertContains(Nav::to('usv', 'disc', $other->id), $this->callbacks($this->windowCall()));
        $this->send($this->tap(Nav::to('usw', 'show', $other->id), chat: 7100));
        self::assertContains(Nav::to('usw', 'add', $other->id), $this->callbacks($this->windowCall()));
    }

    public function testAnAccountLinkedAsTheOwnerIsBannedByTheOwnerAlone(): void
    {
        $owner = $this->owner(UserRole::Admin);
        $second = $this->customer(['telegram_id' => 7002, 'first_name' => 'Amir 2']);
        $this->linkOwner(7002);
        $admin = $this->bridge($this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]));

        $refused = self::refusal(static fn() => $admin->patch("/users/{$owner->id}", ['status' => 'banned']));
        self::assertSame([403, ActorRefusedException::OWNER_BANNED], [$refused->status, $refused->getMessage()], 'an admin on the owner\'s seat bans no account of the owner\'s');
        self::assertSame(UserStatus::Active, $owner->refresh()->status);

        // Another admin's account: as the owner bans it.
        $boss = $this->customer(['telegram_id' => 7200, 'role' => UserRole::Admin]);
        $admin->patch("/users/{$boss->id}", ['status' => 'banned']);
        self::assertSame(UserStatus::Banned, $boss->refresh()->status);

        // The owner themselves may.
        $this->bridge($owner)->patch("/users/{$second->id}", ['status' => 'banned']);
        self::assertSame(UserStatus::Banned, $second->refresh()->status);
    }

    public function testTheRoleOfAnAccountLinkedAsTheOwnerIsTheOwnersAlone(): void
    {
        $owner = $this->owner(UserRole::Admin);
        $second = $this->customer(['telegram_id' => 7002, 'first_name' => 'Amir 2']);
        $this->linkOwner(7002);
        $roles = [$owner->refresh()->role, $second->refresh()->role];
        $admin = $this->bridge($this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]));

        foreach ([[$owner, 'customer'], [$second, 'customer'], [$second, 'admin']] as [$account, $role]) {
            $refused = self::refusal(static fn() => $admin->put("/users/{$account->id}/role", ['role' => $role]));
            self::assertSame([403, ActorRefusedException::OWNER_ROLE], [$refused->status, $refused->getMessage()], "an admin on the owner's seat makes no account of the owner's {$role}");
        }
        try {
            $this->service(UserActions::class)->setRole($owner, Actor::groupAdmin($this->customer(['telegram_id' => 7300, 'role' => UserRole::Admin])), ['role' => 'customer']);
            self::fail('an admin in the report group changed the owner\'s role');
        } catch (ActorRefusedException $e) {
            self::assertSame(ActorRefusedException::OWNER_ROLE, $e->getMessage(), 'nor does an admin in the report group');
        }
        self::assertSame($roles, [$owner->refresh()->role, $second->refresh()->role], 'nothing changed');

        // Another admin's role: as the owner sets it.
        $boss = $this->customer(['telegram_id' => 7200, 'role' => UserRole::Admin]);
        $admin->put("/users/{$boss->id}/role", ['role' => 'customer']);
        self::assertSame(UserRole::Customer, $boss->refresh()->role);

        // The owner themselves may, and the shop itself (no person).
        $this->bridge($owner)->put("/users/{$second->id}/role", ['role' => 'customer']);
        self::assertSame(UserRole::Customer, $second->refresh()->role, 'the owner\'s linked account');
        $this->service(UserActions::class)->setRole($second, Actor::system(), ['role' => 'admin']);
        self::assertSame(UserRole::Admin, $second->refresh()->role, 'Actor::system()');
    }

    public function testAnAgentsOwnAccountInTheirBotIsBannedByTheAgentOrTheOwnerAlone(): void
    {
        $owner = $this->owner();
        $bot = $this->agentBot();
        $seats = $this->service(AdminSeats::class);
        [$agents, $customer, $theirAdmin] = CurrentBot::run($bot, fn(): array => [
            $this->customer(['telegram_id' => self::AGENT_TELEGRAM_ID, 'first_name' => 'Agent']),
            $this->customer(['telegram_id' => 8800]),
            $this->customer(['telegram_id' => 7300, 'role' => UserRole::Admin]),
        ]);
        $mainAdmin = $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $inTheirBot = fn(User $user): PanelBridge => $this->service(PanelBridge::class)->for(CurrentBot::run($bot, static fn(): ?AdminSeat => $seats->of($user)) ?? self::fail('no seat'));
        $workingTheirShop = fn(User $user): PanelBridge => $this->service(PanelBridge::class)->for($seats->of($user, $bot->id) ?? self::fail('no seat'));
        $status = static fn(User $user): UserStatus => CurrentBot::run($bot, static fn(): UserStatus => $user->refresh()->status);

        foreach (['one of their bot\'s admins, on the agent\'s seat' => $inTheirBot($theirAdmin), 'a main-bot admin working their shop' => $workingTheirShop($mainAdmin)] as $who => $bridge) {
            $refused = self::refusal(static fn() => $bridge->patch("/users/{$agents->id}", ['status' => 'banned']));
            self::assertSame([403, ActorRefusedException::AGENT_BANNED], [$refused->status, $refused->getMessage()], $who);
        }
        self::assertSame(UserStatus::Active, $status($agents), 'not banned');

        // Another customer of theirs: as the agent bans them.
        $inTheirBot($theirAdmin)->patch("/users/{$customer->id}", ['status' => 'banned']);
        self::assertSame(UserStatus::Banned, $status($customer));

        // The owner, working their shop, may; and the agent themselves.
        $workingTheirShop($owner)->patch("/users/{$agents->id}", ['status' => 'banned']);
        self::assertSame(UserStatus::Banned, $status($agents), 'the owner');
        $workingTheirShop($owner)->patch("/users/{$agents->id}", ['status' => 'active']);
        self::assertSame(UserStatus::Active, $status($agents), 'and lifts it');
        $inTheirBot($agents)->patch("/users/{$agents->id}", ['status' => 'banned']);
        self::assertSame(UserStatus::Banned, $status($agents), 'the agent themselves');
    }

    public function testAnAdminWorkingAnotherShopDecidesNothingAboutTheirOwnAccountThere(): void
    {
        $bot = $this->agentBot();
        $admin = $this->customer(['telegram_id' => 7100, 'username' => 'sara_admin', 'role' => UserRole::Admin]);
        [$theirs, $other, $payment, $othersPayment] = CurrentBot::run($bot, function (): array {
            $theirs = $this->customer(['telegram_id' => 7100]);
            $other = $this->customer(['telegram_id' => 8800]);
            $method = $this->cardMethod();

            return [$theirs, $other, $this->receipt($this->cardPayment($this->topUpOrder($theirs, '50000.00'), $method)), $this->receipt($this->cardPayment($this->topUpOrder($other, '70000.00'), $method), messageId: 778)];
        });
        $seat = $this->service(AdminSeats::class)->of($admin, $bot->id);
        self::assertNotNull($seat);
        self::assertSame([$bot->id, $theirs->id, '@sara_admin', false], [$seat->shop()->id, $seat->principal->customer?->id, $seat->principal->name, $seat->principal->isTheOwner()], 'their account in that shop beside the seat, under their own name');
        $another = $this->customer(['telegram_id' => 7200, 'role' => UserRole::Admin]);
        $none = $this->service(AdminSeats::class)->of($another, $bot->id);
        self::assertSame([$bot->id, $another->id, false], [$none?->shop()->id, $none?->principal->customer?->id, $none?->principal->isTheOwner()], 'no account there: their own stays beside the seat');
        $bridge = $this->service(PanelBridge::class)->for($seat);

        $refusals = [
            'their wallet there' => [ActorRefusedException::OWN_WALLET, static fn() => $bridge->post("/users/{$theirs->id}/wallet", ['type' => 'credit', 'amount' => 20000])],
            'an order for themselves there' => [ActorRefusedException::OWN_ORDER, static fn() => $bridge->post("/users/{$theirs->id}/orders", ['kind' => 'plan'], [IdempotencyKey::HEADER => 'own-order'])],
            'their payment there' => [ActorRefusedException::OWN_PAYMENT, static fn() => $bridge->post("/payments/{$payment->id}/approve")],
        ];
        foreach ($refusals as $what => [$message, $call]) {
            $refused = self::refusal($call);
            self::assertSame([403, $message], [$refused->status, $refused->getMessage()], $what);
        }
        CurrentBot::run($bot, static function () use ($theirs, $payment): void {
            self::assertSame(['0.00', PaymentStatus::AwaitingReview, 1], [$theirs->balance(), $payment->refresh()->status, Order::query()->where('user_id', $theirs->id)->count()], 'nothing changed');
        });

        // Another customer of that shop: as the owner does it.
        $bridge->post("/users/{$other->id}/wallet", ['type' => 'credit', 'amount' => 20000]);
        $bridge->post("/payments/{$othersPayment->id}/approve");
        CurrentBot::run($bot, static function () use ($other, $othersPayment): void {
            self::assertSame(PaymentStatus::Paid, $othersPayment->refresh()->status);
            self::assertSame('90000.00', $other->refresh()->balance(), 'the credit and the top-up');
        });

        // The bot offers none of it on their own page in that shop, and all of it on another's.
        $this->send($this->tap(Nav::to('shop', 'pick', $bot->id), chat: 7100));
        $this->send($this->tap(Nav::to('usw', 'show', $theirs->id), chat: 7100));
        self::assertStringContainsString(CustomerWalletScreen::TITLE, $this->lastSaid(), 'their wallet\'s page');
        self::assertNotContains(Nav::to('usw', 'add', $theirs->id), $this->callbacks($this->windowCall()), 'their wallet there only to read');
        $this->send($this->tap(Nav::to('usv', 'show', $theirs->id), chat: 7100));
        self::assertContains(Nav::to('usw', 'show', $theirs->id), $this->callbacks($this->windowCall()), 'their page');
        self::assertNotContains(Nav::to('uso', 'new', $theirs->id), $this->callbacks($this->windowCall()), 'no order by hand for themselves');
        $this->send($this->tap(Nav::to('usw', 'show', $other->id), chat: 7100));
        self::assertContains(Nav::to('usw', 'add', $other->id), $this->callbacks($this->windowCall()));
    }

    public function testTheOwnersTelegramAccountsAreTheOwnersAlone(): void
    {
        $this->owner(UserRole::Admin);
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('hc'), chat: self::OWNER));
        self::assertContains(Nav::to('ol'), $this->callbacks(0), 'the owner\'s');
        $this->send($this->tap(Nav::to('hc'), chat: 7100));
        self::assertContains(Nav::to('bs'), $this->callbacks(0), 'an admin has the settings…');
        self::assertNotContains(Nav::to('ol'), $this->callbacks(0), '…but not which accounts are the owner');
        $this->send($this->tap(Nav::to('ppl'), chat: 7100));
        self::assertNotContains(Nav::to('ol'), $this->callbacks($this->windowCall()));

        $this->send($this->tap(Nav::to('ol', 'link'), chat: 7100));
        self::assertStringStartsWith('<b>⚠️ ' . Words::OLD_BUTTON, $this->said()[0] ?? '', 'a forged tap: the home');
        self::assertNull($this->service(OwnerLinks::class)->openUntil(), 'no link made');
        self::assertSame([self::OWNER], $this->service(OwnerLinks::class)->ids());
    }

    /** Whether anything the bot said last is the management's. */
    private function managed(): bool
    {
        return in_array(true, array_map(static fn(string $said): bool => str_contains($said, 'مدیریت فروشگاه'), $this->said()), true);
    }

    /** The panels' API as `$user`'s seat in the main bot asks it, the seat as the bot gives it (AdminSeats::of()). */
    private function bridge(User $user): PanelBridge
    {
        $seat = $this->service(AdminSeats::class)->of($user);
        self::assertNotNull($seat);

        return $this->service(PanelBridge::class)->for($seat);
    }

    private static function refusal(\Closure $call): ApiRefusal
    {
        try {
            $call();
        } catch (ApiRefusal $refusal) {
            return $refusal;
        }

        self::fail('the API answered');
    }

    /**
     * The /start payload of a link OwnerLinks::link() made.
     *
     * @param array{url: string}|null $link
     */
    private static function payload(?array $link): string
    {
        self::assertNotNull($link);

        return substr($link['url'], (int) strpos($link['url'], '=') + 1);
    }
}
