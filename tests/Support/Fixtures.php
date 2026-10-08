<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database\Sorting;
use App\Core\Security\Totp;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Services\TwoFactor;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Models\PlanServer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Reviews\Models\Review;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Models\BotChannel;
use App\Modules\Telegram\Models\ReportTopic;
use App\Modules\Telegram\Reports\ReportGroupState;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use App\Support\Traffic;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Fakes\FakeProvider;

/**
 * The rows a scenario is built from, each with the defaults a test rarely cares about and
 * `$overrides` for the ones it does (merged last, so they win). Names read like the shop: a
 * customer, a server on the fake panel, the one-month plan sold on it, a card to pay with.
 */
trait Fixtures
{
    /** The Telegram id of `customer()` and `admin()` — the chat the bot tests speak from. */
    public const TELEGRAM_ID = 5151;

    /** The Telegram id of the agent agentBot() makes, when it makes one. */
    public const AGENT_TELEGRAM_ID = 6262;

    /** The Telegram message id of the receipt `receipt()` records. */
    public const RECEIPT_MESSAGE = 777;

    /** The chat id of the admins' report group `reportGroup()` connects. */
    public const REPORT_GROUP = -1001234567890;

    /** The thread id of the first topic `reportGroup()` makes; the others follow in Topic order. */
    public const FIRST_THREAD = 11;

    /** The Client ID @BotFather shows for the site `website()` makes — what its Telegram sign-in is under. */
    public const WEBSITE_CLIENT_ID = '7354869120';

    /** A site's OAuth client id in Google Cloud — what a website's Google sign-in is under, once a test gives it one. */
    public const GOOGLE_CLIENT_ID = '481516234200-a1b2c3d4e5f6g7h8.apps.googleusercontent.com';

    /** The address and password `webCustomer()` signed up with. */
    public const WEB_EMAIL = 'sara@example.com';
    public const WEB_PASSWORD = 'sara-secret-1';

    /** The Telegram id of @boss, the report group's admin groupAdminActor() makes (BotTestCase's GROUP_ADMIN). */
    public const BOSS_TELEGRAM_ID = 6060;

    /** The browser a `customerSession()` signs in from. */
    public const CHROME_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    /** @param array<string, mixed> $overrides */
    protected function customer(array $overrides = []): User
    {
        return User::query()->create($overrides + ['telegram_id' => self::TELEGRAM_ID, 'first_name' => 'Ali']);
    }

    /**
     * A customer who signed up on the shop's website with their email (WEB_EMAIL) and a password (WEB_PASSWORD) — no
     * Telegram account: the bot knows nothing of them. Their password's hash is a cheap one, as a PHP of old made it.
     *
     * @param array<string, mixed> $overrides
     */
    protected function webCustomer(array $overrides = []): User
    {
        return User::query()->create($overrides + [
            'email' => self::WEB_EMAIL,
            'password_hash' => password_hash(self::WEB_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'first_name' => 'Sara',
            'last_name' => 'Ahmadi',
        ]);
    }

    /**
     * The customer's wallet brought to `$balance` with one ledger line — a credit, or a debit (below zero as far as it
     * says) — as support would by hand.
     */
    protected function wallet(User $user, string $balance): User
    {
        $wallet = $this->service(WalletService::class);
        $difference = Money::subtract($balance, $user->balance());
        if (Money::isPositive($difference)) {
            $wallet->credit($user, $difference, 'test');
        } elseif (Money::compare($difference, 0) < 0) {
            $wallet->debit($user, Money::subtract(0, $difference), 'test', Money::compare($balance, 0) < 0 ? Money::subtract(0, $balance) : 0);
        }

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    protected function admin(array $overrides = []): User
    {
        return User::query()->create($overrides + ['telegram_id' => self::TELEGRAM_ID, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
    }

    /**
     * Who decides an operation a test calls straight on its service, as a panel's principal — the owner under their login
     * (`root`, HttpTestCase::ADMIN_USERNAME), unless another is named (an agent by their bot: `@agent_shop_bot`) —, in the
     * current shop.
     */
    protected function panelActor(string $name = 'root', PrincipalKind $kind = PrincipalKind::Owner): Actor
    {
        return (new Principal($kind, $name, CurrentBot::get()))->actor();
    }

    /**
     * One of the shop's admins deciding in its report group, as an operation a test calls straight on its service takes
     * them: @boss — the shop's admin of that handle, made the first time a test asks for them.
     */
    protected function groupAdminActor(): Actor
    {
        return Actor::groupAdmin(User::query()->where('username', 'boss')->first() ?? $this->admin(['telegram_id' => self::BOSS_TELEGRAM_ID, 'username' => 'boss']));
    }

    /**
     * A server on the fake panel (see `fakePanel()`): active and serving subscription links, so it can be sold.
     *
     * @param array<string, mixed> $overrides
     */
    protected function fakeServer(string $name = 'آلمان', array $overrides = []): Server
    {
        return Server::query()->create($overrides + [
            'name' => $name,
            'driver' => FakeProvider::driver(),
            'base_url' => 'https://' . self::slug($name) . '.test',
            'api_token' => 't',
            'is_active' => true,
            'serves_subscriptions' => true,
        ]);
    }

    /**
     * A server the shop sells on today: on the fake panel (made this test's driver — `fakePanel()`), active, serving
     * links, with one selectable inbound (key "1") for a plan sold on the whole server to deliver to.
     *
     * @param array<string, mixed> $overrides
     */
    protected function sellingServer(string $name = 'آلمان', array $overrides = []): Server
    {
        $this->fakePanel();
        $server = $this->fakeServer($name, $overrides);
        $this->inbound($server, '1');

        return $server;
    }

    /**
     * A 3x-ui server with a token, as the admin just added it: nothing checked yet.
     *
     * @param array<string, mixed> $overrides
     */
    protected function panelServer(string $name = 'DE-1', array $overrides = []): Server
    {
        return Server::query()->create($overrides + [
            'name' => $name,
            'driver' => '3x-ui',
            'base_url' => 'https://' . self::slug($name) . '.example:2053/base',
            'api_token' => 'tok-1',
        ]);
    }

    /**
     * An inbound the server has synced: enabled and selectable, tagged after its key, on a port from it when numeric.
     *
     * @param array<string, mixed> $overrides
     */
    protected function inbound(Server $server, string $remoteKey, array $overrides = []): ServerInbound
    {
        return ServerInbound::query()->create($overrides + [
            'server_id' => $server->id,
            'remote_key' => $remoteKey,
            'tag' => "in-{$remoteKey}",
            'protocol' => 'vless',
            'port' => ctype_digit($remoteKey) ? 400 + (int) $remoteKey : 443,
            'remark' => "Inbound {$remoteKey}",
            'enabled' => true,
            'is_selectable' => true,
        ]);
    }

    /**
     * The one-month plan (120,000 Toman, 30 days, 30 GB, one device), active, sold on `$on` — whole
     * server entries in the order given.
     *
     * @param array<string, mixed> $overrides
     * @param Server|list<Server>|null $on
     */
    protected function plan(array $overrides = [], Server|array|null $on = null): Plan
    {
        $plan = Plan::query()->create($overrides + [
            'name' => 'یک‌ماهه',
            'price' => '120000.00',
            'duration_days' => 30,
            'traffic_gb' => 30,
            'ip_limit' => 1,
            'is_active' => true,
            'sort' => Sorting::next(Plan::class),
        ]);

        foreach (is_array($on) ? $on : array_filter([$on]) as $server) {
            $this->planEntry($plan, $server);
        }

        return $plan;
    }

    /**
     * One server the plan is sold on: the whole server, or exactly the given inbounds.
     *
     * @param list<int> $inboundIds
     */
    protected function planEntry(Plan $plan, Server $server, array $inboundIds = []): PlanServer
    {
        $entry = $plan->servers()->create([
            'server_id' => $server->id,
            'all_inbounds' => $inboundIds === [],
            'sort' => $plan->servers()->count() + 1,
        ]);
        if ($inboundIds !== []) {
            $entry->inbounds()->attach($inboundIds);
        }

        return $entry;
    }

    /** @param array<string, mixed> $overrides */
    protected function category(array $overrides = []): PlanCategory
    {
        return PlanCategory::query()->create($overrides + ['name' => 'اقتصادی', 'sort' => Sorting::next(PlanCategory::class)]);
    }

    /**
     * A card-to-card method, enabled, after the wallet in checkout order. `$overrides['config']` is merged
     * into the card's config (number, holder, instructions, window), not swapped for it.
     *
     * @param int $autoApproveAfter Minutes before an unreviewed receipt is accepted; 0 = only by hand
     * @param array<string, mixed> $overrides
     */
    protected function cardMethod(string $label = 'کارت به کارت (ملت)', int $autoApproveAfter = 0, array $overrides = []): PaymentMethod
    {
        $config = (array) ($overrides['config'] ?? []) + [
            'card_number' => '6037997700001119',
            'card_holder' => 'AmoBot',
            'instructions' => '',
            'auto_approve_after' => $autoApproveAfter,
        ];

        return PaymentMethod::query()->create(['config' => $config] + $overrides + [
            'driver' => 'manual',
            'label' => $label,
            'enabled' => true,
            'sort' => Sorting::next(PaymentMethod::class),
        ]);
    }

    /** The wallet: the built-in method every shop has. */
    protected function walletMethod(): PaymentMethod
    {
        return PaymentMethod::query()->where('driver', 'wallet')->firstOrFail();
    }

    /**
     * A purchase of the plan on that server, still to be paid.
     *
     * @param array<string, mixed> $overrides
     */
    protected function purchaseOrder(User $user, Plan $plan, Server $server, array $overrides = []): Order
    {
        return Order::query()->create($overrides + [
            'user_id' => $user->id,
            'type' => OrderType::Purchase,
            'status' => OrderStatus::Pending,
            'plan_id' => $plan->id,
            'server_id' => $server->id,
            'amount' => $plan->price,
        ]);
    }

    /**
     * A wallet top-up of `$amount`, still to be paid.
     *
     * @param array<string, mixed> $overrides
     */
    protected function topUpOrder(User $user, string $amount, array $overrides = []): Order
    {
        return Order::query()->create($overrides + [
            'user_id' => $user->id,
            'type' => OrderType::WalletTopUp,
            'status' => OrderStatus::Pending,
            'amount' => $amount,
        ]);
    }

    /**
     * A payment of the order started on that method (a card, unless told otherwise) and not finished.
     *
     * @param array<string, mixed> $overrides
     */
    protected function cardPayment(Order $order, PaymentMethod $method, array $overrides = []): Payment
    {
        return Payment::query()->create($overrides + [
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'status' => PaymentStatus::Pending,
            'amount' => $order->amount,
        ]);
    }

    /** The order paid by card the way the payments screen settles one: a receipt sent, then approved by the admin. */
    protected function paidByCard(Order $order, ?PaymentMethod $method = null): Payment
    {
        $payment = $this->receipt($this->cardPayment($order, $method ?? $this->cardMethod()));
        $this->service(PaymentService::class)->approve($payment, 'admin');

        return $payment->refresh();
    }

    /** The customer sent a photo of the receipt (Telegram message `$messageId`): the payment now waits for review. */
    protected function receipt(Payment $payment, ?string $note = null, int $messageId = self::RECEIPT_MESSAGE): Payment
    {
        self::assertTrue($this->service(PaymentService::class)->submitReceipt($payment, 'receipt-1', $note, null, $messageId), 'The payment did not take the receipt.');

        return $payment;
    }

    /**
     * An active service on that server, named `$name` on the panel, with the plan's quota and term
     * running from now.
     *
     * @param array<string, mixed> $overrides
     */
    protected function subscription(User $user, Plan $plan, Server $server, string $name, array $overrides = []): Subscription
    {
        return Subscription::query()->create($overrides + [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'server_id' => $server->id,
            'remote_name' => $name,
            'subscription_url' => 'https://fake.test/sub/' . $name,
            'status' => SubscriptionStatus::Active,
            'traffic_limit_bytes' => $plan->trafficBytes(),
            'ip_limit' => $plan->ip_limit,
            'duration_days' => $plan->duration_days,
            'starts_at' => now(),
            'expires_at' => $plan->duration_days > 0 ? now()->addDays($plan->duration_days) : null,
        ]);
    }

    /**
     * A channel the customer must join, public under `@channel<n>` unless told otherwise.
     *
     * @param array<string, mixed> $overrides
     */
    protected function channel(int $chatId, string $title, array $overrides = []): BotChannel
    {
        return BotChannel::query()->create($overrides + [
            'chat_id' => $chatId,
            'type' => 'channel',
            'title' => $title,
            'username' => 'channel' . abs($chatId),
            'sort' => Sorting::next(BotChannel::class),
        ]);
    }

    /**
     * The start menu as inline buttons under the greeting (a reply menu is the default), so first-level
     * screens carry the inline "back".
     *
     * @param list<list<array{action: string, label: string, style?: string|null, icon?: string|null}>> $rows
     */
    protected function inlineStartMenu(array $rows = [[['action' => 'services', 'label' => 'سرویس‌های من']]]): void
    {
        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'inline', 'rows' => $rows]);
    }

    /**
     * The admins' report group, connected with every topic made — threads FIRST_THREAD, FIRST_THREAD + 1, … in Topic
     * order — as the bot leaves it once the admin used the panel's link.
     *
     * @return array<string, int> Thread id by topic
     */
    protected function reportGroup(int $chatId = self::REPORT_GROUP): array
    {
        $this->service(ReportGroupState::class)->connected($chatId, 'گزارش‌های فروشگاه');

        $threads = [];
        foreach (Topic::cases() as $i => $topic) {
            $threads[$topic->value] = self::FIRST_THREAD + $i;
            ReportTopic::query()->create(['topic' => $topic, 'thread_id' => self::FIRST_THREAD + $i]);
        }

        return $threads;
    }

    /**
     * A level of the agents: «طلایی», its traffic at 3,000 Toman a GB, after the levels there are.
     *
     * @param array<string, mixed> $overrides
     */
    protected function agencyLevel(array $overrides = []): AgencyLevel
    {
        return AgencyLevel::query()->create($overrides + ['name' => 'طلایی', 'price_per_gb' => '3000', 'sort' => Sorting::next(AgencyLevel::class)]);
    }

    /**
     * The fixtures' customer of the main bot as an agent on the level (the first there is, or a fresh «طلایی»), with
     * `$credit` Toman their wallet may go below zero by, and their shop open as an approval opens it (their bot's row, no
     * token yet, with its wallet) — the agency program switched on when it is off.
     *
     * @param array<string, mixed> $overrides
     */
    protected function agent(?AgencyLevel $level = null, string $credit = '0', array $overrides = []): User
    {
        if (!$this->service(AgencySettings::class)->enabled()) {
            $this->agencyProgram();
        }

        $level ??= AgencyLevel::query()->oldest('id')->first() ?? $this->agencyLevel();
        $agent = CurrentBot::run(Bot::MAIN, fn(): User => $this->customer($overrides + ['agency_level_id' => $level->id, 'credit_limit' => $credit]));
        $bot = $agent->ownBot()->create();
        CurrentBot::run($bot, fn() => $this->service(PaymentMethods::class)->createBuiltins());

        return $agent->refresh();
    }

    /**
     * An agent's bot, its token handed over (FakeTelegram::AGENT_TOKEN, @agent_shop_bot) — an agent made for it when
     * none is given — with `$traffic` GB the bot may sell (bought: one line of its ledger).
     *
     * @param array<string, mixed> $overrides
     */
    protected function agentBot(?User $agent = null, int $traffic = 100, array $overrides = []): Bot
    {
        $agent ??= $this->agent(overrides: ['telegram_id' => self::AGENT_TELEGRAM_ID, 'username' => 'agent']);
        $bot = $agent->ownBot ?? self::fail('The agent has no shop.');
        $bot->forceFill($overrides + [
            'token' => FakeTelegram::AGENT_TOKEN,
            'telegram_id' => (int) strstr(FakeTelegram::AGENT_TOKEN, ':', true),
            'username' => 'agent_shop_bot',
            'title' => 'فروشگاه نماینده',
            'connected_at' => now(),
        ])->save();
        if ($traffic > 0) {
            $this->service(TrafficPool::class)->add($bot, Traffic::bytesOfGb($traffic));
        }

        return $bot;
    }

    /** The agent's bot brought to `$gb` GB it may sell, with one line of its ledger — the shop setting it right. */
    protected function traffic(Bot $bot, int $gb): Bot
    {
        $difference = Traffic::bytesOfGb($gb) - $bot->trafficBalance();
        if ($difference !== 0) {
            $this->service(TrafficPool::class)->adjust($bot, $this->panelActor(), $difference, 'test');
        }

        return $bot;
    }

    /**
     * One group of the current bot's settings saved as the bot settings screen saves it («general», «wallet», «reminders»…).
     *
     * @param array<string, mixed> $values The group's form
     */
    protected function botSettings(string $group, array $values): void
    {
        $this->service(BotSettingsScreen::class)->save($group, $values);
    }

    /** The agency program, switched on (or off) — agents start without credit; traffic offered as 50/100 GB, 10 GB at least. */
    protected function agencyProgram(bool $enabled = true, string $credit = '0'): void
    {
        $this->service(AgencySettings::class)->save(['enabled' => $enabled, 'default_credit' => $credit, 'traffic_presets' => [50, 100], 'traffic_min' => 10]);
    }

    /** The referral program, switched on (or off): `$rate` percent of the money a referral pays — or of their first only. */
    protected function referralProgram(bool $enabled = true, bool $firstOnly = false, int $rate = 10): void
    {
        $this->botSettings('referral', ['referral_enabled' => $enabled, 'referral_rate' => $rate, 'referral_first_only' => $firstOnly]);
    }

    /** A customer's request to become an agent, waiting for support. */
    protected function agencyRequest(User $user): AgencyRequest
    {
        return AgencyRequest::query()->create(['user_id' => $user->id, 'note' => 'کانال فروش دارم']);
    }

    /**
     * One of the admin's customer groups, last in their order, with these customers in it.
     *
     * @param list<User> $members
     */
    protected function customerGroup(string $name = 'VIP', array $members = []): CustomerGroup
    {
        $group = CustomerGroup::query()->create(['name' => $name, 'sort' => Sorting::next(CustomerGroup::class)]);
        $group->users()->attach(array_map(static fn(User $user): int => $user->id, $members));

        return $group;
    }

    /**
     * The current shop's website, switched on: at https://shop.example, called from http://localhost:3000 too while its
     * site is being built, its customers signing in with Telegram under WEBSITE_CLIENT_ID (the popup; no client secret
     * for the redirect) — with a key of its own.
     *
     * @param array<string, mixed> $overrides
     */
    protected function website(array $overrides = []): Website
    {
        return Website::query()->create($overrides + [
            'key' => bin2hex(random_bytes(12)),
            'enabled' => true,
            'url' => 'https://shop.example',
            'origins' => ['http://localhost:3000'],
            'telegram_login' => true,
            'telegram_client_id' => self::WEBSITE_CLIENT_ID,
        ]);
    }

    /**
     * The customer signed in on the shop's website as a sign-in signs them in — a session opened from Chrome on Windows,
     * at 203.0.113.7, with a password —, `$overrides` written on its row afterwards (its use, its end, how it signed in):
     * the bearer token it opens.
     *
     * @param array<string, mixed> $overrides
     */
    protected function customerSession(User $user, array $overrides = []): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://shop.example/', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('User-Agent', self::CHROME_ON_WINDOWS);
        // In the customer's own shop, as their website's sign-in opens it.
        $signedIn = CurrentBot::run($user->shop(), fn() => $this->service(CustomerSessions::class)->open($user, $request, SignInMethod::Password));
        if ($overrides !== []) {
            $signedIn->session->forceFill($overrides)->save();
        }

        return $signedIn->token;
    }

    /**
     * The customer's two-factor sign-in turned on as their website turns it on — a new secret for their authenticator
     * app (TwoFactor::setup()), and its first code at the shop's clock now (enable()): the secret, to make the app's
     * codes with (Core\Security\Totp::code()), and their recovery codes. The code of this step is taken: the next sign-in
     * asks one of a later step (move the clock 30 seconds).
     *
     * @return array{secret: string, recovery_codes: list<string>}
     */
    protected function twoFactorOn(Website $website, User $user): array
    {
        $twoFactor = $this->service(TwoFactor::class);
        $secret = $twoFactor->setup($website, $user)['secret'];
        $codes = $twoFactor->enable($user, ['code' => Totp::code($secret)]);

        return ['secret' => $secret, 'recovery_codes' => $codes];
    }

    /**
     * A support ticket of the customer's, opened from the website with its first message (`$body`) — waiting on support
     * unless `$overrides` (written on its row) say otherwise: its status, the service it is about, a rating.
     *
     * @param array<string, mixed> $overrides
     */
    protected function ticket(User $user, string $subject = 'قطعی اتصال', string $body = 'سرویس من وصل نمی‌شود.', array $overrides = []): Ticket
    {
        $ticket = Ticket::query()->create(['user_id' => $user->id, 'subject' => $subject, 'last_message_at' => now()]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'author' => TicketAuthor::Customer, 'body' => $body, 'channel' => TicketChannel::Web]);
        if ($overrides !== []) {
            $ticket->forceFill($overrides)->save();
        }

        return $ticket->refresh();
    }

    /** `$count` more messages in the ticket — support's and the customer's in turn, as rows: a long conversation. */
    protected function ticketMessages(Ticket $ticket, int $count): void
    {
        foreach (range(1, $count) as $n) {
            $support = $n % 2 === 1;
            TicketMessage::query()->create([
                'ticket_id' => $ticket->id,
                'author' => $support ? TicketAuthor::Support : TicketAuthor::Customer,
                'reviewer' => $support ? 'root' : null,
                'body' => "پیام {$n}",
                'channel' => $support ? TicketChannel::Panel : TicketChannel::Web,
            ]);
        }
    }

    /**
     * A review of the shop written on its website — by `$writer`, the customer signed in there (null: a guest) —, waiting
     * on support unless `$overrides` (written on its row) say otherwise: its status, who decided it and when, its context.
     *
     * @param array<string, mixed> $overrides
     */
    protected function review(?User $writer = null, string $name = 'Ali R.', int $rating = 5, string $body = 'سرعت عالی و پشتیبانی خوب.', array $overrides = []): Review
    {
        $review = Review::query()->create(['user_id' => $writer?->id, 'name' => $name, 'rating' => $rating, 'body' => $body]);
        if ($overrides !== []) {
            $review->forceFill($overrides)->save();
        }

        return $review->refresh();
    }

    /** A wallet-paid purchase of the plan on that server: credited, ordered, paid — and delivered, or the test stops here. */
    protected function buy(User $user, Plan $plan, Server $server): Order
    {
        $this->service(WalletService::class)->credit($user, $plan->price, 'test');
        $order = $this->service(OrderService::class)->openPurchase($user, $plan, $server);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);

        return $order;
    }

    /**
     * A wallet-paid renewal of the service on the plan (its own unless another is given): credited, ordered, paid — and
     * delivered by its panel, or the test stops here. The service is read again afterwards.
     */
    protected function renew(Subscription $subscription, ?Plan $plan = null): Order
    {
        $plan ??= $subscription->plan ?? self::fail('The service has no plan to renew on.');
        $this->service(WalletService::class)->credit($subscription->user, $plan->price, 'test');
        $order = $this->service(OrderService::class)->createRenewal($subscription->user, $subscription, $plan);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
        $subscription->refresh();

        return $order;
    }

    /** The name as a host label: its Latin letters and digits, or "fake" for a name without any. */
    private static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return $slug === '' ? 'fake' : $slug;
    }
}
