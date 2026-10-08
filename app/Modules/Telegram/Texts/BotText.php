<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/**
 * Every text the bot sends a customer that the admin may reword («متن‌های ربات»): the key the code asks
 * BotTexts for, and the settings key the admin's wording is kept under (`bot.text.<value>`). What each is,
 * its default and its %variables% are TextCatalog's. Not here: the main menu's labels (the keyboards
 * editor's), the words the formatters build values from (units, «۳ روز مانده» — Messages), and what the
 * bot says to its own admins (/broadcast).
 */
enum BotText: string
{
    // general
    case Welcome = 'welcome';
    case WelcomeNameless = 'welcome_nameless';
    case MenuPrompt = 'menu_prompt';
    case Unknown = 'unknown';
    case Error = 'error';
    case Banned = 'banned';
    case BotOff = 'bot_off';
    case Tutorial = 'tutorial';
    case Cancel = 'cancel';
    case Back = 'back';

    // gates: the phone number, the required channels
    case PhonePrompt = 'phone_prompt';
    case PhoneButton = 'phone_button';
    case PhoneNotYours = 'phone_not_yours';
    case PhoneVerified = 'phone_verified';
    case JoinPrompt = 'join_prompt';
    case JoinButton = 'join_button';
    case JoinChannel = 'join_channel';
    case JoinStillMissing = 'join_still_missing';
    case JoinDone = 'join_done';

    // shop
    case CategoriesTitle = 'categories_title';
    case CategoryOther = 'category_other';
    case CategoryEmpty = 'category_empty';
    case PlansTitle = 'plans_title';
    case PlansEmpty = 'plans_empty';
    case CategoryPlans = 'category_plans';
    case PlanButton = 'plan_button';
    case PlanDetails = 'plan_details';
    case PlanPickServer = 'plan_pick_server';
    case ServerButton = 'server_button';
    case PlanNoServer = 'plan_no_server';
    case PlanGone = 'plan_gone';

    // checkout and receipts
    case Checkout = 'checkout';
    case TopupCheckout = 'topup_checkout';
    case CheckoutNoGateway = 'checkout_no_gateway';
    case OrderNotPending = 'order_not_pending';
    case PayInsufficient = 'pay_insufficient';
    case PayFailed = 'pay_failed';
    case CardInstructions = 'card_instructions';
    case PayInstructionsSuffix = 'pay_instructions_suffix';
    case ReceiptWaiting = 'receipt_waiting';
    case ReceiptNotImage = 'receipt_not_image';
    case ReceiptReceived = 'receipt_received';
    case ReceiptReceivedTimed = 'receipt_received_timed';
    case ReceiptOutcomeSubscription = 'receipt_outcome_subscription';
    case ReceiptOutcomeRenewal = 'receipt_outcome_renewal';
    case ReceiptOutcomeTopup = 'receipt_outcome_topup';
    case PayProcessing = 'pay_processing';

    // delivery and what support decided about a payment
    case PaySuccess = 'pay_success';
    case PayProvisionFailed = 'pay_provision_failed';
    case ReceiptRejected = 'receipt_rejected';
    case OrderCancelledByAdmin = 'order_cancelled_by_admin';
    case PaymentRefunded = 'payment_refunded';
    case TopupRefunded = 'topup_refunded';
    case TopupRefundedUndelivered = 'topup_refunded_undelivered';
    case AdminNote = 'admin_note';
    case PaymentReminder = 'payment_reminder';
    case PaymentReminderRejected = 'payment_reminder_rejected';
    case PayAgain = 'pay_again';
    case SendReceipt = 'send_receipt';

    // «سرویس‌های من»
    case SubscriptionsTitle = 'subscriptions_title';
    case SubscriptionsPage = 'subscriptions_page';
    case SubscriptionsEmpty = 'subscriptions_empty';
    case ServiceButton = 'service_button';
    case PageNext = 'page_next';
    case PagePrev = 'page_prev';

    // one service's screen
    case ServiceDetails = 'service_details';
    case ServiceRotateHint = 'service_rotate_hint';
    case ServiceStale = 'service_stale';
    case ServiceStatusActive = 'service_status_active';
    case ServiceStatusDisabled = 'service_status_disabled';
    case ServiceStatusExpired = 'service_status_expired';
    case ServiceStatusDepleted = 'service_status_depleted';
    case ServiceStatusDeleted = 'service_status_deleted';
    case ServiceUnused = 'service_unused';
    case ServiceNeverConnected = 'service_never_connected';
    case ServiceOnline = 'service_online';
    case ServiceOffline = 'service_offline';
    case ServiceUnknown = 'service_unknown';
    case ServiceNotFound = 'service_not_found';
    case ServiceInactive = 'service_inactive';
    case ServiceRefreshed = 'service_refreshed';
    case ServiceRefreshFailed = 'service_refresh_failed';
    case ServiceRefresh = 'service_refresh';
    case ServiceLink = 'service_link';
    case ServiceRenew = 'service_renew';
    case ServiceReport = 'service_report';
    case ServiceRotate = 'service_rotate';
    case ServiceBack = 'service_back';
    case LinkRequested = 'link_requested';

    // «تغییر لینک»
    case ServiceRotateConfirm = 'service_rotate_confirm';
    case ServiceRotateYes = 'service_rotate_yes';
    case ServiceRotateExpired = 'service_rotate_expired';
    case ServiceRotated = 'service_rotated';
    case ServiceRotateFailed = 'service_rotate_failed';
    case ServiceRotatedUnsent = 'service_rotated_unsent';
    case LinkRotated = 'link_rotated';

    // what support did to a service
    case ServiceDisabledBySupport = 'service_disabled_by_support';
    case ServiceEnabledBySupport = 'service_enabled_by_support';
    case ServiceMoved = 'service_moved';
    case ServiceDeletedBySupport = 'service_deleted_by_support';
    case ServiceGranted = 'service_granted';

    // renewal and «تمدید خودکار»
    case RenewChoose = 'renew_choose';
    case RenewNothing = 'renew_nothing';
    case RenewCheckout = 'renew_checkout';
    case RenewUnavailable = 'renew_unavailable';
    case RenewUnderWay = 'renew_under_way';
    case ServiceAutoRenewOn = 'service_auto_renew_on';
    case ServiceAutoRenewOff = 'service_auto_renew_off';
    case AutoRenewEnabled = 'auto_renew_enabled';
    case AutoRenewDisabled = 'auto_renew_disabled';
    case AutoRenewUnavailable = 'auto_renew_unavailable';
    case AutoRenewed = 'auto_renewed';
    case AutoRenewShort = 'auto_renew_short';
    case Renewed = 'renewed';
    case RenewalLeftoverUntil = 'renewal_leftover_until';
    case RenewalLeftoverCarried = 'renewal_leftover_carried';
    case RenewFailed = 'renew_failed';

    // «یادآوری»
    case ExpiryReminder = 'expiry_reminder';
    case TrafficReminder = 'traffic_reminder';
    case ReminderAutoRenewHint = 'reminder_auto_renew_hint';
    case ReminderOpenService = 'reminder_open_service';

    // the wallet
    case WalletBalance = 'wallet_balance';
    case WalletHistory = 'wallet_history';
    case WalletHistoryCredit = 'wallet_history_credit';
    case WalletHistoryDebit = 'wallet_history_debit';
    case WalletNoHistory = 'wallet_no_history';
    case WalletTopup = 'wallet_topup';
    case WalletCharged = 'wallet_charged';
    case TopupPick = 'topup_pick';
    case TopupCustom = 'topup_custom';
    case TopupAsk = 'topup_ask';
    case TopupInvalid = 'topup_invalid';
    case TopupFailed = 'topup_failed';
    case WalletCredit = 'wallet_credit';

    // «زیرمجموعه‌گیری»
    case ReferralScreen = 'referral_screen';
    case ReferralTermsEvery = 'referral_terms_every';
    case ReferralTermsFirst = 'referral_terms_first';
    case ReferralShare = 'referral_share';
    case ReferralOff = 'referral_off';
    case ReferralJoined = 'referral_joined';
    case ReferralCommission = 'referral_commission';

    // «نمایندگی»: the request, support's decisions, the agent's account — their traffic, their bot, their panel
    case AgencyOff = 'agency_off';
    case AgencyTerms = 'agency_terms';
    case AgencyLevelLine = 'agency_level_line';
    case AgencyNoLevels = 'agency_no_levels';
    case AgencyApply = 'agency_apply';
    case AgencyAskNote = 'agency_ask_note';
    case AgencyNoteInvalid = 'agency_note_invalid';
    case AgencyRequested = 'agency_requested';
    case AgencyPending = 'agency_pending';
    case AgencyApproved = 'agency_approved';
    case AgencyRejected = 'agency_rejected';
    case AgencyChanged = 'agency_changed';
    case AgencyRevoked = 'agency_revoked';
    case AgencyPanel = 'agency_panel';
    case AgencyBuyTraffic = 'agency_buy_traffic';
    case AgencyMyBot = 'agency_my_bot';
    case AgencyPanelLogin = 'agency_panel_login';
    case AgencyTraffic = 'agency_traffic';
    case AgencyTrafficButton = 'agency_traffic_button';
    case AgencyTrafficInvalid = 'agency_traffic_invalid';
    case AgencyTrafficCheckout = 'agency_traffic_checkout';
    case AgencyTrafficAdded = 'agency_traffic_added';
    case AgencyTrafficFailed = 'agency_traffic_failed';
    case AgencyTrafficShort = 'agency_traffic_short';
    case ReceiptOutcomeTraffic = 'receipt_outcome_traffic';
    case AgencyNoBot = 'agency_no_bot';
    case AgencyBotNone = 'agency_bot_none';
    case AgencyBotInfo = 'agency_bot_info';
    case AgencyBotRunning = 'agency_bot_running';
    case AgencyBotProblem = 'agency_bot_problem';
    case AgencyBotSaved = 'agency_bot_saved';
    case AgencyBotRefused = 'agency_bot_refused';
    case AgencyLogin = 'agency_login';

    // the customer's account on the shop's website
    case TwoFactorDisabled = 'two_factor_disabled';
    case TwoFactorTurnedOn = 'two_factor_turned_on';
    case TwoFactorTurnedOff = 'two_factor_turned_off';
    case WayInAdded = 'way_in_added';
    case WayInRemoved = 'way_in_removed';
    case PasswordChanged = 'password_changed';
    case AccountMerged = 'account_merged';
    case SecondStepLocked = 'second_step_locked';
    case EmailCodesFailed = 'email_codes_failed';

    // support: its contact, and the tickets — a new one, the customer's own, one of them, and what support told them
    case SupportContact = 'support_contact';
    case SupportUnavailable = 'support_unavailable';
    case TicketNew = 'ticket_new';
    case TicketMine = 'ticket_mine';
    case TicketPickService = 'ticket_pick_service';
    case TicketNoService = 'ticket_no_service';
    case TicketAsk = 'ticket_ask';
    case TicketReportAsk = 'ticket_report_ask';
    case TicketService = 'ticket_service';
    case TicketPictureNeedsWords = 'ticket_picture_needs_words';
    case TicketTextOnly = 'ticket_text_only';
    case TicketTooShort = 'ticket_too_short';
    case TicketRefused = 'ticket_refused';
    case TicketOpened = 'ticket_opened';
    case TicketMessageSent = 'ticket_message_sent';
    case TicketView = 'ticket_view';
    case TicketsTitle = 'tickets_title';
    case TicketsEmpty = 'tickets_empty';
    case TicketButtonOpen = 'ticket_button_open';
    case TicketButtonAnswered = 'ticket_button_answered';
    case TicketButtonClosed = 'ticket_button_closed';
    case TicketScreen = 'ticket_screen';
    case TicketStatusOpen = 'ticket_status_open';
    case TicketStatusAnswered = 'ticket_status_answered';
    case TicketStatusClosed = 'ticket_status_closed';
    case TicketRating = 'ticket_rating';
    case TicketEarlier = 'ticket_earlier';
    case TicketFromCustomer = 'ticket_from_customer';
    case TicketFromSupport = 'ticket_from_support';
    case TicketMessagePicture = 'ticket_message_picture';
    case TicketPictureRemoved = 'ticket_picture_removed';
    case TicketPicture = 'ticket_picture';
    case TicketPictureCaption = 'ticket_picture_caption';
    case TicketPictureGone = 'ticket_picture_gone';
    case TicketPictureKept = 'ticket_picture_kept';
    case TicketNotFound = 'ticket_not_found';
    case TicketReply = 'ticket_reply';
    case TicketReplyAsk = 'ticket_reply_ask';
    case TicketReplyReopens = 'ticket_reply_reopens';
    case TicketClose = 'ticket_close';
    case TicketClosedByCustomer = 'ticket_closed_by_customer';
    case TicketAlreadyClosed = 'ticket_already_closed';
    case TicketRate = 'ticket_rate';
    case TicketRateAsk = 'ticket_rate_ask';
    case TicketStar = 'ticket_star';
    case TicketRatedThanks = 'ticket_rated_thanks';
    case TicketRateUnavailable = 'ticket_rate_unavailable';
    case TicketAnswered = 'ticket_answered';
    case TicketAnswerPicture = 'ticket_answer_picture';
    case TicketClosed = 'ticket_closed';

    public function spec(): TextSpec
    {
        return TextCatalog::spec($this);
    }
}
