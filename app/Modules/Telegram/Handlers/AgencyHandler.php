<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Exceptions\AgencyException;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencyLevels;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Agency\Services\AgentBots;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;

/**
 * «نمایندگی» in the main bot — MainMenu::AGENCY opens home() (an agent's bot has no agency of its own):
 *  - a customer reads the program's terms and levels and asks to become an agent:
 *      agency:apply           → state `agency.note`: a few words about themselves → the request (AgencyActions)
 *  - an agent sees their account with the shop — level and price per GB, the traffic their bot may still sell, wallet
 *    and credit, their bot and what it sold — and:
 *      agency:traffic         → the amounts offered at their price, or any typed (state `agency.gb`)
 *      agency:tb:{gb}         → the checkout (CheckoutScreen) → agency:tb:{gb}:{method}: ordered now and paid
 *      agency:bot             → their bot: hand its token over (state `agency.token`), or a new token for it (AgentBots)
 *      agency:login           → a one-time link that signs them in to their shop's panel (AgentBots)
 */
final class AgencyHandler implements Handler
{
    public const CALLBACK = 'agency:';
    public const STATE = 'agency';

    /** The traffic screen — the button under the agent's account and under a delivery their pool could not cover. */
    public const TRAFFIC = 'agency:traffic';

    private const APPLY = 'agency:apply';
    private const BOT = 'agency:bot';
    private const LOGIN = 'agency:login';
    private const TRAFFIC_CHECKOUT = 'tb';

    private const NOTE_STATE = 'agency.note';
    private const GB_STATE = 'agency.gb';
    private const TOKEN_STATE = 'agency.token';

    public function __construct(
        private readonly AgencyActions $agency,
        private readonly AgentBots $bots,
        private readonly AgencySettings $settings,
        private readonly AgencyLevels $levels,
        private readonly OrderService $orders,
        private readonly PaymentMethods $methods,
        private readonly CheckoutScreen $screen,
        private readonly MainMenu $menu,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** The checkout of `$gb` GB of traffic. */
    public static function checkoutCallback(int $gb): string
    {
        return CallbackData::build(self::CALLBACK, self::TRAFFIC_CHECKOUT, $gb);
    }

    public function handle(Context $ctx): void
    {
        if (!$ctx->update->isCallback()) {
            match (true) {
                $ctx->session->inState(self::NOTE_STATE) => $this->typedNote($ctx),
                $ctx->session->inState(self::GB_STATE) => $this->typedGb($ctx),
                $ctx->session->inState(self::TOKEN_STATE) => $this->typedToken($ctx),
                default => $this->home($ctx),
            };

            return;
        }

        $data = (string) $ctx->update->callbackData();
        $traffic = $ctx->update->callbackArgs(self::CALLBACK) ?? [];
        $checkout = ($traffic[0] ?? null) === self::TRAFFIC_CHECKOUT;

        match (true) {
            $data === self::APPLY => $this->apply($ctx),
            $data === self::TRAFFIC => $this->traffic($ctx),
            $data === self::BOT => $this->bot($ctx),
            $data === self::LOGIN => $this->login($ctx),
            $checkout && count($traffic) === 2 => $this->checkout($ctx, (int) $traffic[1]),
            $checkout && count($traffic) === 3 => $this->buy($ctx, (int) $traffic[1], (int) $traffic[2]),
            default => $this->home($ctx),
        };
    }

    /** MainMenu::AGENCY: an agent's account; for a customer the terms and the request — or that theirs waits. */
    public function home(Context $ctx): void
    {
        $user = $ctx->user;

        match (true) {
            !CurrentBot::isMain() => $this->menu->screen($ctx, $this->texts->get(BotText::AgencyOff)),
            $user->isAgent() => $this->account($ctx, $user),
            !$this->settings->enabled() => $this->menu->screen($ctx, $this->texts->get(BotText::AgencyOff)),
            $this->agency->pendingRequest($user) !== null => $this->menu->screen($ctx, $this->texts->get(BotText::AgencyPending)),
            default => $this->menu->screen(
                $ctx,
                $this->texts->render(BotText::AgencyTerms, ['levels' => $this->levelList()]),
                InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::AgencyApply), self::APPLY, 'success')),
            ),
        };
    }

    /**
     * The checkout of `$gb` GB at the agent's price — the traffic screen instead, when it is outside the program's bounds
     * (they changed since the button was made).
     */
    public function checkout(Context $ctx, int $gb): void
    {
        $agent = $this->agent($ctx);
        if ($agent === null) {
            return;
        }
        if (!$this->orders->trafficAllowed($gb)) {
            $this->traffic($ctx);

            return;
        }

        $this->screen->show($ctx, $this->texts->render(BotText::AgencyTrafficCheckout, [
            'traffic' => Messages::bytes(Traffic::bytesOfGb($gb)),
            'amount' => Money::format(self::level($agent)->priceOf($gb)),
        ]), OrderType::Traffic, static fn(PaymentMethod $method): string => CallbackData::build(self::CALLBACK, self::TRAFFIC_CHECKOUT, $gb, $method->id), self::TRAFFIC);
    }

    /** The agent's account: level and price per GB, traffic left, wallet and credit, their bot and what it sold. */
    private function account(Context $ctx, User $agent): void
    {
        $level = self::level($agent);
        $bot = $agent->ownBot;
        $stats = $bot?->stats() ?? ['customers' => 0, 'sold' => 0, 'active' => 0];
        $username = $bot->username ?? '';

        $text = $this->texts->render(BotText::AgencyPanel, [
            'level' => $level->name,
            'price' => Money::format($level->price_per_gb),
            'traffic' => Messages::bytes(max(0, $bot?->trafficBalance() ?? 0)),
            'balance' => Messages::balance($agent->balance()),
            'credit' => Messages::credit($agent->credit_limit),
            'bot' => $username !== '' ? '@' . $username : $this->texts->part(BotText::AgencyNoBot),
            'customers' => Persian::number($stats['customers']),
            'sold' => Persian::number($stats['sold']),
            'active' => Persian::number($stats['active']),
        ]);
        if ($bot?->problem !== null) {
            $text = BotTexts::paragraphs($text, $this->texts->part(BotText::AgencyBotProblem, ['reason' => $bot->problem]));
        }

        $keyboard = InlineKeyboard::make()
            ->row($this->buttons->buyTraffic(), InlineKeyboard::callback($this->texts->get(BotText::AgencyMyBot), self::BOT, 'primary'))
            ->row(InlineKeyboard::callback($this->texts->get(BotText::AgencyPanelLogin), self::LOGIN), $this->buttons->topUp());

        $this->menu->screen($ctx, $text, $keyboard);
    }

    /** «درخواست نمایندگی»: a few words about themselves first. */
    private function apply(Context $ctx): void
    {
        if (!$this->agency->mayRequest($ctx->user)) {
            $this->home($ctx);

            return;
        }

        $ctx->session->enter(self::NOTE_STATE);
        $ctx->edit($this->texts->get(BotText::AgencyAskNote), ['reply_markup' => $this->buttons->backOnly(MainMenu::AGENCY)]);
    }

    /** State `agency.note`: what they wrote goes with the request — words, not a picture. */
    private function typedNote(Context $ctx): void
    {
        try {
            $request = $this->agency->request($ctx->user, (string) $ctx->update->text());
        } catch (ValidationException) {
            $ctx->reply($this->texts->get(BotText::AgencyNoteInvalid), ['reply_markup' => $this->buttons->backOnly(MainMenu::AGENCY)]);

            return;
        }

        $ctx->session->clear();
        if ($request === null) {
            // The program went off, or a request or an agency came meanwhile: the screen says how things stand.
            $this->home($ctx);

            return;
        }
        $this->menu->screen($ctx, $this->texts->get(BotText::AgencyRequested));
    }

    /** «خرید حجم»: the amounts offered, at the agent's price — or any amount typed. */
    private function traffic(Context $ctx): void
    {
        $agent = $this->agent($ctx);
        if ($agent === null) {
            return;
        }
        $level = self::level($agent);

        $presets = array_map(fn(int $gb): array => InlineKeyboard::callback($this->texts->render(BotText::AgencyTrafficButton, [
            'traffic' => Messages::bytes(Traffic::bytesOfGb($gb)),
            'amount' => Money::format($level->priceOf($gb)),
        ]), self::checkoutCallback($gb)), $this->settings->trafficPresets());
        $keyboard = InlineKeyboard::make()->grid($presets, 2)->row($this->buttons->back(MainMenu::AGENCY));

        $ctx->session->enter(self::GB_STATE);
        $ctx->edit($this->texts->render(BotText::AgencyTraffic, [
            'price' => Money::format($level->price_per_gb),
            'traffic' => Messages::bytes(max(0, $agent->ownBot?->trafficBalance() ?? 0)),
            'min' => Persian::number($this->settings->trafficMin()),
        ]), ['reply_markup' => $keyboard->build()]);
    }

    /** State `agency.gb`: the GB the agent typed («۲۰ گیگ» is fine). */
    private function typedGb(Context $ctx): void
    {
        $gb = Input::amountOf($ctx->update->text());
        if ($gb === null || !$this->orders->trafficAllowed($gb)) {
            $ctx->reply($this->texts->render(BotText::AgencyTrafficInvalid, ['min' => Persian::number($this->settings->trafficMin())]), ['reply_markup' => $this->buttons->backOnly(self::TRAFFIC)]);

            return;
        }

        $this->checkout($ctx, $gb);
    }

    /** A way to pay picked: the traffic ordered — or the open order of that much found — and paid. */
    private function buy(Context $ctx, int $gb, int $methodId): void
    {
        $agent = $this->agent($ctx);
        if ($agent === null) {
            return;
        }
        $method = $this->methods->payable($methodId, OrderType::Traffic);
        if ($method === null || !$this->orders->trafficAllowed($gb)) {
            // Switched off since the checkout was shown, or the bounds moved: the checkout as it stands now.
            $this->checkout($ctx, $gb);

            return;
        }

        $this->screen->buy($ctx, $method, self::level($agent)->priceOf($gb), fn(): Order => $this->orders->openTraffic($agent, $gb), self::checkoutCallback($gb));
    }

    /** «ربات من»: the bot the agent handed over — or how to make one — and its token taken here. */
    private function bot(Context $ctx): void
    {
        $agent = $this->agent($ctx);
        if ($agent === null) {
            return;
        }

        $bot = $agent->ownBot;
        $text = $bot !== null && ($bot->token ?? '') !== ''
            ? $this->texts->render(BotText::AgencyBotInfo, [
                'bot' => '@' . $bot->username,
                'status' => $bot->problem !== null ? $this->texts->part(BotText::AgencyBotProblem, ['reason' => $bot->problem]) : $this->texts->part(BotText::AgencyBotRunning),
            ])
            : $this->texts->get(BotText::AgencyBotNone);

        $ctx->session->enter(self::TOKEN_STATE);
        $ctx->edit($text, ['reply_markup' => $this->buttons->backOnly(MainMenu::AGENCY)]);
    }

    /** State `agency.token`: the token the agent sent — off the chat at once, whoever sent it — and their bot connected with it. */
    private function typedToken(Context $ctx): void
    {
        $ctx->deleteIncoming();
        $agent = $this->agent($ctx);
        if ($agent === null) {
            return;
        }
        $token = (string) $ctx->update->text();

        try {
            $bot = $this->bots->connect($agent, $token);
        } catch (AgencyException $e) {
            $ctx->reply($this->texts->render(BotText::AgencyBotRefused, ['reason' => $e->getMessage()]), ['reply_markup' => $this->buttons->backOnly(MainMenu::AGENCY)]);

            return;
        }

        $ctx->session->clear();
        $keyboard = InlineKeyboard::make()
            ->row(InlineKeyboard::callback($this->texts->get(BotText::AgencyPanelLogin), self::LOGIN, 'primary'))
            ->row($this->buttons->back(MainMenu::AGENCY));
        $ctx->reply($this->texts->render(BotText::AgencyBotSaved, ['bot' => '@' . $bot->username]), ['reply_markup' => $keyboard->build()]);
    }

    /** «ورود به پنل»: a fresh one-time link — and a button that opens it, when the shop's address is a public one. */
    private function login(Context $ctx): void
    {
        $agent = $this->agent($ctx);
        if ($agent === null) {
            return;
        }
        $link = $this->bots->loginLink($agent);
        if ($link === null) {
            $this->home($ctx);

            return;
        }

        $keyboard = InlineKeyboard::make();
        if (str_starts_with($link, 'https://')) {
            $keyboard->row(InlineKeyboard::url($this->texts->get(BotText::AgencyPanelLogin), $link));
        }
        $keyboard->row($this->buttons->back(MainMenu::AGENCY));

        $ctx->edit($this->texts->render(BotText::AgencyLogin, [
            'login' => $link,
            'minutes' => Persian::number(AgentBots::LOGIN_MINUTES),
        ]), ['reply_markup' => $keyboard->build()] + BotApi::NO_LINK_PREVIEW);
    }

    /** The agent behind the update, in the main bot — anyone else is shown how things stand (home()) and gets null. */
    private function agent(Context $ctx): ?User
    {
        if (CurrentBot::isMain() && $ctx->user->isAgent()) {
            return $ctx->user;
        }

        $ctx->session->clear();
        $this->home($ctx);

        return null;
    }

    private static function level(User $agent): AgencyLevel
    {
        return $agent->agencyLevel ?? throw new \LogicException("User #{$agent->id} is not an agent.");
    }

    /** The levels on the terms, one a line, with their price per GB. */
    private function levelList(): Html
    {
        $lines = $this->levels->ordered()->map(fn(AgencyLevel $level): Html => $this->texts->part(BotText::AgencyLevelLine, [
            'level' => $level->name,
            'price' => Money::format($level->price_per_gb),
        ]))->all();

        return $lines === [] ? $this->texts->part(BotText::AgencyNoLevels) : Html::lines(...$lines);
    }
}
