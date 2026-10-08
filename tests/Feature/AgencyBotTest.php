<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Enums\TrafficTransactionType;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Agency\Services\AgentBots;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\Handlers\AgencyHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * «نمایندگی» in the main bot: the terms and the request for a customer — one at a time, none for an agent, none once the
 * program went off; for an agent their account with the shop — level and price per GB, the traffic their bot may still
 * sell, wallet and credit, their bot, what it sold and what keeps it from running —, the traffic they buy (a preset or a
 * typed amount within the program's bounds, through the usual checkout as it stands when they pay; the wallet pays from
 * their credit too), their bot's token handed over (the message carrying it taken off the chat first, whatever comes of
 * it; the same bot only, never one another agent has), and the one-time link to their panel. With the program off,
 * customers are told so and lose the menu's button; agents keep their account.
 */
final class AgencyBotTest extends BotTestCase
{
    private Server $server;
    private Plan $plan;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePanel();
        $this->withoutQr();
        $this->server = $this->fakeServer();
        $this->inbound($this->server, '1');
        $this->plan = $this->plan(on: $this->server);
        $this->wallet = $this->walletMethod();
    }

    public function testACustomerReadsTheTermsAndAsksToBecomeAnAgentOnce(): void
    {
        $this->agencyProgram();
        $this->agencyLevel(['name' => 'برنزی <1>', 'price_per_gb' => '4000']);
        $this->agencyLevel(['name' => 'طلایی', 'price_per_gb' => '3000']);
        $customer = $this->customer();

        $this->send($this->tap(MainMenu::AGENCY));
        $lines = self::text(BotText::AgencyLevelLine, ['level' => 'برنزی &lt;1&gt;', 'price' => '۴٬۰۰۰ تومان']) . "\n" . self::text(BotText::AgencyLevelLine, ['level' => 'طلایی', 'price' => '۳٬۰۰۰ تومان']);
        self::assertSame([self::text(BotText::AgencyTerms, ['levels' => $lines])], $this->said(), 'a level a line in their order, its name as text, escaped once');
        self::assertContains('agency:apply', $this->callbacks(0));

        $this->send($this->tap('agency:apply'));
        self::assertSame([self::text(BotText::AgencyAskNote)], $this->said());

        $this->send($this->message('کانالی با ۲ هزار عضو دارم'));
        $request = AgencyRequest::query()->sole();
        self::assertSame([$customer->id, AgencyRequestStatus::Pending, 'کانالی با ۲ هزار عضو دارم'], [$request->user_id, $request->status, $request->note]);
        self::assertSame([self::text(BotText::AgencyRequested)], $this->said());

        $this->send($this->tap(MainMenu::AGENCY));
        self::assertSame([self::text(BotText::AgencyPending)], $this->said());

        $this->send($this->tap('agency:apply'));
        self::assertSame([self::text(BotText::AgencyPending)], $this->said(), 'one request at a time');
        self::assertSame(1, AgencyRequest::query()->count());
    }

    public function testTheNoteIsShortText(): void
    {
        $this->agencyProgram();
        $this->customer();
        $this->send($this->tap('agency:apply'));

        $this->send($this->message(null, ['photo' => [['file_id' => 'photo-1', 'width' => 90, 'height' => 90]]]));
        self::assertSame([self::text(BotText::AgencyNoteInvalid)], $this->said());

        $this->send($this->message(str_repeat('ب', AgencyActions::NOTE_MAX + 1)));
        self::assertSame([self::text(BotText::AgencyNoteInvalid)], $this->said());
        self::assertSame(0, AgencyRequest::query()->count());

        $this->send($this->message(str_repeat('ب', AgencyActions::NOTE_MAX)));
        self::assertSame(1, AgencyRequest::query()->count(), 'as long as it may be');
    }

    public function testANoteSentAfterTheProgramWentOffMakesNoRequest(): void
    {
        $this->agencyProgram();
        $this->customer();
        $this->send($this->tap('agency:apply'));
        $this->agencyProgram(enabled: false);

        $this->send($this->message('کانال فروش دارم'));

        self::assertSame([self::text(BotText::AgencyOff)], $this->said(), 'how things stand now');
        self::assertSame(0, AgencyRequest::query()->count());
    }

    public function testAnAgentDoesNotAskToBecomeOne(): void
    {
        $this->agent();

        $this->send($this->tap('agency:apply'));

        self::assertContains(AgencyHandler::TRAFFIC, $this->callbacks(0), 'their account instead');
        self::assertSame(0, AgencyRequest::query()->count());
    }

    public function testWhileTheProgramIsOffCustomersAreToldSoAndAgentsKeepTheirAccount(): void
    {
        $agent = $this->agent();
        $customer = $this->customer(['telegram_id' => 900_001]);
        $this->agencyProgram(enabled: false);
        $menu = $this->service(MainMenu::class);
        $card = $this->cardMethod();

        $this->send($this->tap(MainMenu::AGENCY, 900_001));
        self::assertSame([self::text(BotText::AgencyOff)], $this->said());
        self::assertStringNotContainsString(Messages::MENU_AGENCY, (string) json_encode($menu->markup($customer), JSON_UNESCAPED_UNICODE));

        $this->send($this->tap(MainMenu::AGENCY));
        self::assertContains(AgencyHandler::TRAFFIC, $this->callbacks(0), 'an agent keeps their account: it is their bot and their traffic');
        self::assertStringContainsString(Messages::MENU_AGENCY, (string) json_encode($menu->markup($agent), JSON_UNESCAPED_UNICODE));

        $this->send($this->tap(PurchaseHandler::serverCallback($this->plan->id, $this->server->id)));
        $this->send($this->tap(CallbackData::build(PurchaseHandler::serverCallback($this->plan->id, $this->server->id), $card->id)));
        self::assertSame('120000.00', Order::query()->sole()->amount, "an agent buys their own services at the plan's price, no name asked");
    }

    public function testAnAgentsAccountShowsTheirBotItsTrafficAndWhatItSold(): void
    {
        $agent = $this->agent(credit: '500000');
        $bot = $this->agentBot($agent, traffic: 40);
        CurrentBot::run($bot, function (): void {
            $this->walletMethod();
            $plan = $this->plan(['traffic_gb' => 30], $this->server);
            $this->buy($this->customer(['telegram_id' => 31]), $plan, $this->server);
        });

        $this->send($this->tap(MainMenu::AGENCY));

        self::assertSame(self::text(BotText::AgencyPanel, [
            'level' => 'طلایی',
            'price' => '۳٬۰۰۰ تومان',
            'traffic' => Messages::bytes(Traffic::bytesOfGb(10)),
            'balance' => Messages::balance('0'),
            'credit' => '۵۰۰٬۰۰۰ تومان',
            'bot' => '@agent_shop_bot',
            'customers' => '۱',
            'sold' => '۱',
            'active' => '۱',
        ]), $this->params(0)['text'], 'the sale drew its 30 GB from the 40');
        self::assertSame([AgencyHandler::TRAFFIC, 'agency:bot', 'agency:login', 'topup:start'], $this->callbacks(0));
    }

    public function testAnAgentsAccountSaysWhetherTheirBotIsThereAndWhatKeepsItFromRunning(): void
    {
        $agent = $this->agent();

        $this->send($this->tap(MainMenu::AGENCY));
        self::assertStringContainsString(self::text(BotText::AgencyNoBot), $this->said()[0], 'not handed over yet');

        $this->agentBot($agent, overrides: ['problem' => 'تلگرام <توکن> را نپذیرفت']);
        $this->send($this->tap(MainMenu::AGENCY));
        self::assertStringEndsWith(self::text(BotText::AgencyBotProblem, ['reason' => 'تلگرام &lt;توکن&gt; را نپذیرفت']), $this->said()[0], 'the problem under it, as text');
    }

    public function testAnAgentBuysTrafficAndTheWalletPaysFromTheirCredit(): void
    {
        $agent = $this->agent(credit: '500000');
        $bot = $this->agentBot($agent, traffic: 10);

        $this->send($this->tap(AgencyHandler::TRAFFIC));
        self::assertSame(self::text(BotText::AgencyTraffic, ['price' => '۳٬۰۰۰ تومان', 'traffic' => Messages::bytes(Traffic::bytesOfGb(10)), 'min' => '۱۰']), $this->params(0)['text']);
        self::assertStringContainsString(self::text(BotText::AgencyTrafficButton, ['traffic' => Messages::bytes(Traffic::bytesOfGb(50)), 'amount' => '۱۵۰٬۰۰۰ تومان']), $this->params(0)['reply_markup']);

        $this->send($this->tap(AgencyHandler::checkoutCallback(50)));
        self::assertSame([self::text(BotText::AgencyTrafficCheckout, ['traffic' => Messages::bytes(Traffic::bytesOfGb(50)), 'amount' => '۱۵۰٬۰۰۰ تومان'])], $this->said());
        self::assertSame([$this->payFor(50, $this->wallet), AgencyHandler::TRAFFIC], $this->callbacks(0), 'the wallet pays for traffic; back to the amounts');
        self::assertSame(0, Order::query()->count());

        $this->send($this->tap($this->payFor(50, $this->wallet)));

        $order = Order::query()->sole();
        self::assertSame([OrderType::Traffic, Traffic::bytesOfGb(50), '150000.00', OrderStatus::Fulfilled], [$order->type, $order->traffic_bytes, $order->amount, $order->status]);
        self::assertSame(Traffic::bytesOfGb(60), $bot->trafficBalance());
        self::assertSame('-150000.00', $agent->balance(), 'paid from their credit');
        $line = TrafficTransaction::query()->latest('id')->firstOrFail();
        self::assertSame([TrafficTransactionType::Purchase, Traffic::bytesOfGb(50), $order->id], [$line->type, $line->bytes, $line->order_id]);
        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'the checkout goes, the outcome comes as a message of its own');
        self::assertSame([self::text(BotText::AgencyTrafficAdded, ['traffic' => Messages::bytes(Traffic::bytesOfGb(50)), 'remaining' => Messages::bytes(Traffic::bytesOfGb(60))])], $this->said());
        self::assertSame([MainMenu::AGENCY], $this->callbacks(1), 'back to their account');
    }

    public function testAButtonFromBeforeTheRulesMovedShowsTheScreenAsItStandsNow(): void
    {
        $this->agentBot($this->agent());
        $card = $this->cardMethod(overrides: ['enabled' => false]);

        // An amount the program no longer offers: the amounts it does.
        $this->send($this->tap(AgencyHandler::checkoutCallback(5)));
        self::assertSame([self::text(BotText::AgencyTraffic, ['price' => '۳٬۰۰۰ تومان', 'traffic' => Messages::bytes(Traffic::bytesOfGb(100)), 'min' => '۱۰'])], $this->said());

        // A way of paying switched off since the checkout was shown: the checkout without it.
        $this->send($this->tap($this->payFor(50, $card)));
        self::assertSame([self::text(BotText::AgencyTrafficCheckout, ['traffic' => Messages::bytes(Traffic::bytesOfGb(50)), 'amount' => '۱۵۰٬۰۰۰ تومان'])], $this->said());
        self::assertNotContains($this->payFor(50, $card), $this->callbacks(0));
        self::assertSame(0, Order::query()->count());
    }

    public function testATypedAmountOfTrafficIsWithinTheProgramsBounds(): void
    {
        $this->agentBot($this->agent());
        $this->send($this->tap(AgencyHandler::TRAFFIC));

        foreach (['۵', (string) (AgencySettings::TRAFFIC_MAX_GB + 1)] as $typed) {
            $this->send($this->message($typed));
            self::assertSame([self::text(BotText::AgencyTrafficInvalid, ['min' => '۱۰'])], $this->said(), $typed);
        }

        $this->send($this->message('۲۰ گیگ'));
        self::assertSame([self::text(BotText::AgencyTrafficCheckout, ['traffic' => Messages::bytes(Traffic::bytesOfGb(20)), 'amount' => '۶۰٬۰۰۰ تومان'])], $this->said());
        self::assertContains($this->payFor(20, $this->wallet), $this->callbacks(0));
        self::assertSame(0, Order::query()->count(), 'nothing is ordered before a way to pay is picked');
    }

    public function testAnAgentHandsTheirBotsTokenOverAndTheMessageCarryingItGoes(): void
    {
        $agent = $this->agent();
        $this->telegram()->on('getMe', static fn(): array => ['id' => 777000, 'is_bot' => true, 'first_name' => 'فروشگاه <رضا>', 'username' => 'reza_shop_bot']);

        $this->send($this->tap('agency:bot'));
        self::assertSame([self::text(BotText::AgencyBotNone)], $this->said());

        $this->send($this->message(FakeTelegram::AGENT_TOKEN));

        self::assertSame(['deleteMessage', 'getMe', 'deleteWebhook', 'sendMessage'], $this->calls(), 'the token off the chat first; the bot asked who it is, and taken off any webhook (the shop polls)');
        self::assertSame([FakeTelegram::TOKEN, FakeTelegram::AGENT_TOKEN, FakeTelegram::AGENT_TOKEN, FakeTelegram::TOKEN], array_map($this->telegram()->tokenOf(...), [0, 1, 2, 3]));
        $bot = Bot::query()->where('user_id', $agent->id)->sole();
        self::assertSame([FakeTelegram::AGENT_TOKEN, 777000, 'reza_shop_bot', 'فروشگاه <رضا>'], [$bot->token, $bot->telegram_id, $bot->username, $bot->title]);
        self::assertNotNull($bot->connected_at);
        self::assertStringNotContainsString(FakeTelegram::AGENT_TOKEN, (string) $this->db()->table('bots')->where('id', $bot->id)->value('token'), 'kept encrypted');
        self::assertSame([self::text(BotText::AgencyBotSaved, ['bot' => '@reza_shop_bot'])], $this->said());

        $this->send($this->tap('agency:bot'));
        self::assertSame([self::text(BotText::AgencyBotInfo, ['bot' => '@reza_shop_bot', 'status' => self::text(BotText::AgencyBotRunning)])], $this->said());

        // Telegram turned its token down since.
        $bot->forceFill(['problem' => 'تلگرام <توکن> را نپذیرفت'])->save();
        $this->send($this->tap('agency:bot'));
        self::assertSame([self::text(BotText::AgencyBotInfo, ['bot' => '@reza_shop_bot', 'status' => self::text(BotText::AgencyBotProblem, ['reason' => 'تلگرام &lt;توکن&gt; را نپذیرفت'])])], $this->said(), 'the problem as text, escaped once');
    }

    public function testATokenIsRefusedWhenItIsNoneTelegramRefusesItOrTheBotIsNotTheirs(): void
    {
        $agent = $this->agent();
        $this->send($this->tap('agency:bot'));

        $this->sendToken('hello');
        self::assertSame([self::text(BotText::AgencyBotRefused, ['reason' => BotToken::MALFORMED])], $this->said());

        $this->telegram()->on('getMe', static fn() => FakeTelegram::error(401, 'Unauthorized'));
        $this->sendToken(FakeTelegram::AGENT_TOKEN);
        self::assertSame([self::text(BotText::AgencyBotRefused, ['reason' => BotToken::REFUSED])], $this->said());

        // The shop's own bot is never an agent's.
        $this->sendToken(FakeTelegram::TOKEN);
        self::assertSame([self::text(BotText::AgencyBotRefused, ['reason' => AgentBots::TOKEN_MAIN])], $this->said());

        // Another agent's bot already…
        $other = $this->agent(overrides: ['telegram_id' => 900_002]);
        Bot::query()->where('user_id', $other->id)->update(['telegram_id' => 777000]);
        $this->telegram()->on('getMe', static fn(): array => ['id' => 777000, 'is_bot' => true, 'first_name' => 'X', 'username' => 'x_bot']);
        $this->sendToken(FakeTelegram::AGENT_TOKEN);
        self::assertSame([self::text(BotText::AgencyBotRefused, ['reason' => AgentBots::TOKEN_TAKEN])], $this->said());

        // …and their own bot is the one they keep: another bot's token in its place is not taken.
        Bot::query()->where('user_id', $other->id)->update(['telegram_id' => null]);
        Bot::query()->where('user_id', $agent->id)->update(['telegram_id' => 888000, 'username' => 'old_bot']);
        $this->sendToken(FakeTelegram::AGENT_TOKEN);
        self::assertSame([self::text(BotText::AgencyBotRefused, ['reason' => sprintf(AgentBots::TOKEN_OTHER_BOT, 'old_bot')])], $this->said());
        self::assertNull(Bot::query()->where('user_id', $agent->id)->sole()->token);
    }

    public function testANewTokenOfTheSameBotTakesTheOldOnesPlace(): void
    {
        $bot = $this->agentBot($this->agent());
        $handedOver = $bot->connected_at?->getTimestamp();
        $rotated = '777000:BBrotated-token-of-the-same-bot-0123456';
        $this->telegram()->on('getMe', static fn(): array => ['id' => 777000, 'is_bot' => true, 'first_name' => 'فروشگاه رضا', 'username' => 'agent_shop_bot']);
        $this->send($this->tap('agency:bot'));

        $this->sendToken($rotated);

        $bot->refresh();
        self::assertSame([$rotated, 'فروشگاه رضا', $handedOver], [$bot->token, $bot->title, $bot->connected_at?->getTimestamp()], 'the new token and the name Telegram gives the bot now; handed over when it first was');
        self::assertSame([self::text(BotText::AgencyBotSaved, ['bot' => '@agent_shop_bot'])], $this->said());
    }

    public function testABotAnotherAgentHandedOverInTheSameMomentIsRefusedAsTaken(): void
    {
        $agent = $this->agent();
        $other = $this->agent(overrides: ['telegram_id' => 900_002]);
        $this->telegram()->on('getMe', static fn(): array => ['id' => 777000, 'is_bot' => true, 'first_name' => 'X', 'username' => 'x_bot']);
        $this->send($this->tap('agency:bot'));
        // The other agent's save of the same bot lands between this one's check and its write: the unique index decides.
        $this->whileListening(
            'eloquent.saving: ' . Bot::class,
            static function (Bot $saving) use ($other): void {
                if ($saving->user_id !== $other->id && $saving->telegram_id === 777000) {
                    Bot::query()->where('user_id', $other->id)->update(['telegram_id' => 777000]);
                }
            },
            fn() => $this->sendToken(FakeTelegram::AGENT_TOKEN),
        );

        self::assertSame([self::text(BotText::AgencyBotRefused, ['reason' => AgentBots::TOKEN_TAKEN])], $this->said());
        self::assertNull(Bot::query()->where('user_id', $agent->id)->sole()->token);
    }

    public function testATokenSentOnceTheAgencyEndedStillGoesOffTheChat(): void
    {
        $agent = $this->agent();
        $this->send($this->tap('agency:bot'));
        $this->service(AgencyActions::class)->revoke($agent, null);

        $this->sendToken(FakeTelegram::AGENT_TOKEN);

        self::assertNotContains('getMe', $this->calls(), 'not taken from someone who is no agent');
        self::assertNull(Bot::query()->where('user_id', $agent->id)->sole()->token);
    }

    public function testAnAgentGetsAOneTimeLinkToTheirPanel(): void
    {
        $agent = $this->agent();

        $this->send($this->tap('agency:login'));

        self::assertSame(1, preg_match('~\S+/agent/login\S*~', $this->said()[0], $link), $this->said()[0]);
        // The code is in the fragment, which no browser sends: it never reaches a server's access log.
        self::assertNull(parse_url($link[0], PHP_URL_QUERY));
        parse_str((string) parse_url($link[0], PHP_URL_FRAGMENT), $fragment);
        $bot = Bot::query()->where('user_id', $agent->id)->sole();
        self::assertSame(hash('sha256', (string) ($fragment['code'] ?? '')), $bot->login_code);
        self::assertEqualsWithDelta(now()->addMinutes(AgentBots::LOGIN_MINUTES)->getTimestamp(), $bot->login_code_expires_at?->getTimestamp(), 2);
        self::assertSame(['is_disabled' => true], json_decode($this->params(0)['link_preview_options'] ?? '', true), 'no preview of the link');
        self::assertSame([], array_filter(array_column(array_merge(...$this->inlineKeyboard(0)), 'url')), 'no button opens a link that is not https');

        $this->config(['app.url' => 'https://shop.example.com']);
        $this->send($this->tap('agency:login'));
        self::assertNotSame($bot->login_code, $bot->refresh()->login_code, 'a new link replaces the last');
        self::assertSame(1, preg_match('~https://shop\.example\.com/agent/login#code=\S+~', $this->said()[0], $secure));
        self::assertSame([$secure[0]], array_values(array_filter(array_column(array_merge(...$this->inlineKeyboard(0)), 'url'))), 'a button that opens it, on the https address');
    }

    public function testAnAgentWhoseShopIsNotOpenGetsTheirAccountInsteadOfALink(): void
    {
        $this->agencyProgram();
        $this->customer(['agency_level_id' => $this->agencyLevel()->id]);

        $this->send($this->tap('agency:login'));

        self::assertContains(AgencyHandler::TRAFFIC, $this->callbacks(0));
        self::assertSame(0, Bot::agents()->count());
    }

    public function testACustomerWhoIsNoAgentGetsNoneOfIt(): void
    {
        $this->agencyProgram();
        $this->customer();
        $terms = self::text(BotText::AgencyTerms, ['levels' => self::text(BotText::AgencyNoLevels)]);

        foreach ([AgencyHandler::TRAFFIC, AgencyHandler::checkoutCallback(50), $this->payFor(50, $this->wallet), 'agency:bot', 'agency:login'] as $data) {
            $this->send($this->tap($data));
            self::assertSame([$terms], $this->said(), "{$data}: the terms instead");
        }
        self::assertSame(0, Order::query()->count());
        self::assertSame(0, Bot::agents()->count());
        self::assertSame(0, User::query()->whereNotNull('agency_level_id')->count());
    }

    /** The button that pays for `$gb` GB of traffic that way. */
    private function payFor(int $gb, PaymentMethod $method): string
    {
        return CallbackData::build(AgencyHandler::checkoutCallback($gb), $method->id);
    }

    /** The agent sends a token — and, whatever comes of it, the message carrying it is the first thing to go. */
    private function sendToken(string $token): void
    {
        $this->send($this->message($token));

        self::assertSame('deleteMessage', $this->calls()[0] ?? null, 'the token off the chat first');
    }
}
