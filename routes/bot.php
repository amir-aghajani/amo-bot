<?php

declare(strict_types=1);

use App\Modules\Telegram\Channels\JoinPrompt;
use App\Modules\Telegram\Gates\ChannelGate;
use App\Modules\Telegram\Gates\MaintenanceGate;
use App\Modules\Telegram\Gates\PhoneGate;
use App\Modules\Telegram\Handlers\AgencyHandler;
use App\Modules\Telegram\Handlers\BroadcastHandler;
use App\Modules\Telegram\Handlers\CustomEmojiHandler;
use App\Modules\Telegram\Handlers\FallbackHandler;
use App\Modules\Telegram\Handlers\JoinHandler;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\ReceiptHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\StartHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TicketHandler;
use App\Modules\Telegram\Handlers\TicketMessageHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Reports\AgencyReview;
use App\Modules\Telegram\Reports\DeliveryRetry;
use App\Modules\Telegram\Reports\ReceiptReview;
use App\Modules\Telegram\Reports\ReportGroupConnect;
use App\Modules\Telegram\Reports\ReportGroupUpdates;
use App\Modules\Telegram\Reports\TicketClose;
use App\Modules\Telegram\Update\Dispatcher;

/*
 * Telegram bot "routes": the gates every update passes first (the admin's bot settings), then which
 * Handler answers which command / callback prefix / conversation state. See Dispatcher for the rules.
 */
return static function (Dispatcher $bot): void {
    $bot->gate(MaintenanceGate::class);
    $bot->gate(PhoneGate::class);
    $bot->gate(ChannelGate::class);

    $bot->command('start', StartHandler::class);
    $bot->command('menu', StartHandler::class);
    // A shared phone number that made it through the gates (the phone gate just accepted it) lands on the menu.
    $bot->contact(StartHandler::class);
    $bot->callback(JoinPrompt::CHECK, JoinHandler::class);

    // The home screen: the reply keyboard's buttons (whatever the admin labelled them) and the inline
    // `menu:*` taps — the menu's buttons and every "back" to it — navigation like a command.
    $bot->text(static fn(MainMenu $menu): array => $menu->labels(), MenuHandler::class);
    $bot->callback(MainMenu::PREFIX, MenuHandler::class, navigation: true);
    $bot->callback(MenuHandler::CATEGORY, MenuHandler::class);
    $bot->callback(SubscriptionHandler::PREFIX, SubscriptionHandler::class);
    $bot->callback(RenewalHandler::PREFIX, RenewalHandler::class);
    $bot->callback(PurchaseHandler::PLAN, PurchaseHandler::class);
    $bot->callback(PurchaseHandler::CHECKOUT, PurchaseHandler::class);
    $bot->state(ReceiptHandler::PREFIX, ReceiptHandler::class);
    $bot->callback(ReceiptHandler::PREFIX, ReceiptHandler::class);
    $bot->callback(TopUpHandler::CALLBACK, TopUpHandler::class);
    $bot->state(TopUpHandler::STATE, TopUpHandler::class);

    // «پشتیبانی»'s tickets (the screen itself is MenuHandler's MainMenu::SUPPORT): a new one, the customer's own, one of
    // them — every tap navigation, so a message half written is left —, and what the customer writes in one.
    $bot->callback(TicketHandler::PREFIX, TicketHandler::class, navigation: true);
    $bot->state(TicketMessageHandler::STATE, TicketMessageHandler::class);

    // «نمایندگی» (the main bot's): the request, and an agent's traffic, bot and panel login (the screen itself is MenuHandler's MainMenu::AGENCY).
    $bot->callback(AgencyHandler::CALLBACK, AgencyHandler::class);
    $bot->state(AgencyHandler::STATE, AgencyHandler::class);

    // The bot's admins only — anyone else gets no answer at all: send a message to every customer.
    $bot->command(BroadcastHandler::COMMAND, BroadcastHandler::class, adminOnly: true);
    $bot->callback(BroadcastHandler::CALLBACK, BroadcastHandler::class, adminOnly: true);
    $bot->state(BroadcastHandler::STATE, BroadcastHandler::class, adminOnly: true);

    // The bot's admins only: show the bot premium emoji for its texts (kept for the panel's picker, the message back as a template).
    $bot->command(CustomEmojiHandler::COMMAND, CustomEmojiHandler::class, adminOnly: true);
    $bot->state(CustomEmojiHandler::STATE, CustomEmojiHandler::class, adminOnly: true);

    $bot->fallback(FallbackHandler::class);

    // Groups: only the admins' report group concerns the bot — the command its connect link sends, the buttons under its
    // reports (each handler lets the bot's admins alone press them), and the rest: the bot's membership there, the
    // group's title, a reply giving a receipt's rejection its reason, a reply answering a ticket.
    $bot->groupCommand('start', ReportGroupConnect::class);
    $bot->groupCallback(ReceiptReview::PREFIX, ReceiptReview::class);
    $bot->groupCallback(DeliveryRetry::PREFIX, DeliveryRetry::class);
    $bot->groupCallback(AgencyReview::PREFIX, AgencyReview::class);
    $bot->groupCallback(TicketClose::PREFIX, TicketClose::class);
    $bot->group(ReportGroupUpdates::class);
};
