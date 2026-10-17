<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Subscriptions\Enums\TestRefusal;
use App\Modules\Subscriptions\Exceptions\TestAccountRefusedException;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Persian;
use Psr\Log\LoggerInterface;

/**
 * «🧪 اکانت تست»: home() — from the menu, /free or the `?start=usertest` link — lists the servers giving a free test now,
 * each with its traffic and term, and a button for each (`menu:test:{page}` past one page). `test:{server}` takes one
 * there (TestAccounts) and sends it
 * like a delivery, in place of the screen. Whatever the quota refuses is said in its own words, with «خرید اشتراک» under
 * it where buying is the way on, and a refused tap redraws the screen as it is now. A panel that fails is logged and
 * said in a word; a test Telegram would not take still counts, and a word with a button to its screen says so.
 */
final class TestAccountHandler implements Handler
{
    public const PREFIX = 'test:';

    /** /free: the screen, as the menu's button opens it. */
    public const COMMAND = 'free';

    /** What the `t.me/<bot>?start=usertest` link carries after /start. */
    public const START_LINK = 'usertest';

    /**
     * Servers a page of the screen holds: each is a line of the message as well as a button, so the message's length
     * (Limits::MESSAGE) comes long before the keyboard's buttons do.
     */
    public const PER_PAGE = 20;

    public function __construct(
        private readonly TestAccounts $tests,
        private readonly MainMenu $menu,
        private readonly Buttons $buttons,
        private readonly ServiceCard $card,
        private readonly BotTexts $texts,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Context $ctx): void
    {
        $args = $ctx->update->callbackArgs(self::PREFIX);
        if ($args === null) {
            $this->home($ctx);

            return;
        }

        $this->take($ctx, (int) ($args[0] ?? 0));
    }

    /** The test screen at that page. */
    public static function pageCallback(int $page): string
    {
        return $page > 1 ? CallbackData::build(MainMenu::TEST, $page) : MainMenu::TEST;
    }

    /**
     * The servers giving a test now, PER_PAGE a page: a line each with its traffic and term, and a button each in its
     * server's colour and row (as the purchase's locations, Buttons::server()) — or why the customer cannot take one.
     * `$fresh` sends it as a new message instead of in place of a tapped one.
     */
    public function home(Context $ctx, bool $fresh = false, int $page = 1): void
    {
        try {
            $choices = $this->tests->offer($ctx->user);
        } catch (TestAccountRefusedException $e) {
            $this->refused($ctx, $e, $fresh);

            return;
        }

        [$shown, $page, $pages] = Buttons::slice($choices, $page, self::PER_PAGE);
        $lines = [];
        $buttons = [];
        foreach ($shown as ['server' => $server, 'terms' => $terms]) {
            $lines[] = $this->texts->part(BotText::TestServerLine, [
                'server' => $server->name,
                'traffic' => Messages::bytes($terms->bytes),
                'duration' => Messages::duration($terms->hours),
                'hold' => $terms->onHold ? $this->texts->part(BotText::TestOnHold) : '',
            ]);
            $buttons[] = $this->buttons->server($server, CallbackData::build(self::PREFIX, $server->id));
        }

        $keyboard = InlineKeyboard::make()->flow($buttons);
        $text = $this->buttons->paged($keyboard, $this->texts->render(BotText::TestScreen, ['servers' => Html::lines(...$lines)]), $page, $pages, self::pageCallback(...));
        $this->menu->screen($ctx, $text, $keyboard, fresh: $fresh);
    }

    /**
     * `test:{server}`: the test made there and sent as its card in place of the screen, with a button to its own screen.
     * A refusal — the quota, or a server that gives no test now (an old screen's, a forged one) — redraws the screen
     * as home() judges it now. A panel's failure is logged, and nothing of the quota is spent; a test made that
     * Telegram would not take still counts, and the customer gets a word with the same button.
     */
    private function take(Context $ctx, int $serverId): void
    {
        try {
            $subscription = $this->tests->issue($ctx->user, $serverId);
        } catch (TestAccountRefusedException) {
            $this->home($ctx);

            return;
        } catch (ProviderException | NoServerAvailableException $e) {
            $this->logger->warning('Could not make a test account for user {user} on server {server}: {message}', ['user' => $ctx->user->id, 'server' => $serverId, 'message' => $e->getMessage()]);
            $ctx->edit($this->texts->get(BotText::TestFailed), ['reply_markup' => $this->buttons->backOnly(MainMenu::TEST)]);

            return;
        }

        $ctx->delete();
        $open = $this->buttons->serviceOnly($subscription->id);
        try {
            $this->card->send($ctx->chatId(), $subscription, BotText::TestDelivered, ['reply_markup' => $open]);
        } catch (TelegramApiException $e) {
            $this->logger->warning('Made test account {id} but could not send it: {message}', ['id' => $subscription->id, 'message' => $e->getMessage()]);
            $ctx->reply($this->texts->get(BotText::TestUnsent), ['reply_markup' => $open]);
        }
    }

    /** Why no test now, with «خرید اشتراک» under it where buying is the way on. */
    private function refused(Context $ctx, TestAccountRefusedException $e, bool $fresh): void
    {
        $text = match ($e->reason) {
            TestRefusal::Off => $this->texts->get(BotText::TestOff),
            TestRefusal::NewcomersOnly => $this->texts->get(BotText::TestNewcomersOnly),
            TestRefusal::Used => $e->nextAt === null
                ? $this->texts->get(BotText::TestUsed)
                : $this->texts->render(BotText::TestUsedUntil, ['next_test' => Persian::date($e->nextAt, withTime: true)]),
            TestRefusal::DailyLimit => $this->texts->get(BotText::TestDailyLimit),
            TestRefusal::NoServer => $this->texts->get(BotText::TestNoServer),
            TestRefusal::Suspended => $this->texts->get(BotText::SellingPaused),
        };
        $buy = in_array($e->reason, [TestRefusal::NewcomersOnly, TestRefusal::Used, TestRefusal::DailyLimit], true)
            ? InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::TestBuyButton), MainMenu::PLANS, 'success'))
            : null;

        $this->menu->screen($ctx, $text, $buy, fresh: $fresh);
    }
}
