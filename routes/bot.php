<?php

declare(strict_types=1);

use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Telegram\Channels\JoinPrompt;
use App\Modules\Telegram\Gates\ChannelGate;
use App\Modules\Telegram\Gates\MaintenanceGate;
use App\Modules\Telegram\Gates\PhoneGate;
use App\Modules\Telegram\Handlers\AgencyHandler;
use App\Modules\Telegram\Handlers\BroadcastHandler;
use App\Modules\Telegram\Handlers\ClientNameHandler;
use App\Modules\Telegram\Handlers\CustomEmojiHandler;
use App\Modules\Telegram\Handlers\CustomServiceHandler;
use App\Modules\Telegram\Handlers\DiscountCodeHandler;
use App\Modules\Telegram\Handlers\ExtrasHandler;
use App\Modules\Telegram\Handlers\FallbackHandler;
use App\Modules\Telegram\Handlers\GiftCodeHandler;
use App\Modules\Telegram\Handlers\JoinHandler;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\OnlinePaymentHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\ReceiptHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\ServiceClaimHandler;
use App\Modules\Telegram\Handlers\ServiceTransferHandler;
use App\Modules\Telegram\Handlers\StartHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TestAccountHandler;
use App\Modules\Telegram\Handlers\TicketHandler;
use App\Modules\Telegram\Handlers\TicketMessageHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Handlers\WalletTransferHandler;
use App\Modules\Telegram\Handlers\WheelHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Reports\AgencyReview;
use App\Modules\Telegram\Reports\DeliveryRetry;
use App\Modules\Telegram\Reports\ReceiptReview;
use App\Modules\Telegram\Reports\ReportGroupConnect;
use App\Modules\Telegram\Reports\ReportGroupUpdates;
use App\Modules\Telegram\Reports\TicketClose;
use App\Modules\Telegram\Update\Dispatcher;

/*
 * The bot's routes: the gates every private update passes first, then which handler answers each command, keyboard
 * label, callback prefix, conversation state and text pattern, then the report group's routes. Dispatcher documents the
 * order.
 */
return static function (Dispatcher $bot): void {
    $bot->gate(MaintenanceGate::class);
    $bot->gate(PhoneGate::class);
    $bot->gate(ChannelGate::class);

    $bot->command('start', StartHandler::class);
    $bot->command('menu', StartHandler::class);
    // A contact card that passed the gates (the phone gate just took it) lands on the menu.
    $bot->contact(StartHandler::class);
    $bot->callback(JoinPrompt::CHECK, JoinHandler::class);

    // The main menu: the reply keyboard's labels (read per update: the admin edits them) and the inline `menu:*` taps.
    $bot->text(static fn(MainMenu $menu): array => $menu->labels(), MenuHandler::class);
    $bot->callback(MainMenu::PREFIX, MenuHandler::class, navigation: true);
    $bot->callback(MenuHandler::CATEGORY, MenuHandler::class);
    // «🛠 ساخت سرویس دلخواه» under the plans: its steps and checkout, and an amount typed.
    $bot->callback(CustomServiceHandler::PREFIX, CustomServiceHandler::class);
    $bot->state(CustomServiceHandler::STATE, CustomServiceHandler::class);
    $bot->callback(SubscriptionHandler::PREFIX, SubscriptionHandler::class);
    // «🔁 انتقال سرویس»: the recipient's Telegram id asked for, typed, and the handover confirmed.
    $bot->callback(ServiceTransferHandler::PREFIX, ServiceTransferHandler::class);
    $bot->state(ServiceTransferHandler::STATE, ServiceTransferHandler::class);
    $bot->callback(RenewalHandler::PREFIX, RenewalHandler::class);
    // «حجم، زمان و کاربر اضافه»: their screens and checkouts, and an amount typed.
    $bot->callback(ExtrasHandler::PREFIX, ExtrasHandler::class);
    $bot->state(ExtrasHandler::STATE, ExtrasHandler::class);
    $bot->callback(PurchaseHandler::PLAN, PurchaseHandler::class);
    $bot->callback(PurchaseHandler::CHECKOUT, PurchaseHandler::class);
    // «نام سرویس» under a purchase's checkout: the way chosen, a name typed.
    $bot->callback(ClientNameHandler::PREFIX, ClientNameHandler::class);
    $bot->state(ClientNameHandler::STATE, ClientNameHandler::class);
    // «🏷 کد تخفیف دارم» under a discountable checkout: the code awaited, typed, removed.
    $bot->callback(DiscountCodeHandler::PREFIX, DiscountCodeHandler::class);
    $bot->state(DiscountCodeHandler::STATE, DiscountCodeHandler::class);
    $bot->state(ReceiptHandler::PREFIX, ReceiptHandler::class);
    $bot->callback(ReceiptHandler::PREFIX, ReceiptHandler::class);
    // «🔄 بررسی پرداخت» under an online gateway's page.
    $bot->callback(OnlinePaymentHandler::PREFIX, OnlinePaymentHandler::class);
    $bot->callback(TopUpHandler::CALLBACK, TopUpHandler::class);
    $bot->state(TopUpHandler::STATE, TopUpHandler::class);
    // «💸 انتقال موجودی» on the wallet: the recipient's Telegram id and the amount typed, and the transfer confirmed.
    $bot->callback(WalletTransferHandler::PREFIX, WalletTransferHandler::class);
    $bot->state(WalletTransferHandler::STATE, WalletTransferHandler::class);

    // Support tickets: every tap is navigation (a message half written is left), and what the customer writes in one.
    $bot->callback(TicketHandler::PREFIX, TicketHandler::class, navigation: true);
    $bot->state(TicketMessageHandler::STATE, TicketMessageHandler::class);

    // «نمایندگی» (main bot only): the request, and an agent's traffic, bot and panel login.
    $bot->callback(AgencyHandler::CALLBACK, AgencyHandler::class);
    $bot->state(AgencyHandler::STATE, AgencyHandler::class);

    // «🎁 کد هدیه»: the code typed; «🎡 گردونه شانس»: a spin.
    $bot->state(GiftCodeHandler::STATE, GiftCodeHandler::class);
    $bot->callback(WheelHandler::PREFIX, WheelHandler::class);

    // «🧪 اکانت تست»: /free opens its screen as the menu's button does; a server tapped takes a test there.
    $bot->command(TestAccountHandler::COMMAND, TestAccountHandler::class);
    $bot->callback(TestAccountHandler::PREFIX, TestAccountHandler::class);

    // «➕ افزودن سرویس»: the link asked for and sent there — or sent at no step that takes it, from anywhere.
    $bot->callback(ServiceClaimHandler::PREFIX, ServiceClaimHandler::class, navigation: true);
    $bot->state(ServiceClaimHandler::STATE, ServiceClaimHandler::class);
    $bot->pattern(ClientLink::PATTERN, ServiceClaimHandler::class);

    // Bot admins only (anyone else gets no answer): /broadcast.
    $bot->command(BroadcastHandler::COMMAND, BroadcastHandler::class, adminOnly: true);
    $bot->callback(BroadcastHandler::CALLBACK, BroadcastHandler::class, adminOnly: true);
    $bot->state(BroadcastHandler::STATE, BroadcastHandler::class, adminOnly: true);

    // Bot admins only: /emoji, premium emoji for the texts.
    $bot->command(CustomEmojiHandler::COMMAND, CustomEmojiHandler::class, adminOnly: true);
    $bot->state(CustomEmojiHandler::STATE, CustomEmojiHandler::class, adminOnly: true);

    $bot->fallback(FallbackHandler::class);

    // The report group: the connect command, the buttons under its reports (bot admins only, each handler checks), and
    // the rest — the bot's membership, the title, replies answering a ticket or giving a rejection its reason.
    $bot->groupCommand('start', ReportGroupConnect::class);
    $bot->groupCallback(ReceiptReview::PREFIX, ReceiptReview::class);
    $bot->groupCallback(DeliveryRetry::PREFIX, DeliveryRetry::class);
    $bot->groupCallback(AgencyReview::PREFIX, AgencyReview::class);
    $bot->groupCallback(TicketClose::PREFIX, TicketClose::class);
    $bot->group(ReportGroupUpdates::class);
};
