<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/**
 * Every text the bot sends a customer that the admin may reword («متن‌های ربات»). The value is stored (settings key
 * `bot.text.<value>`), so it never changes. What each text is, its default and its variables are TextCatalog's.
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
    // An agent's shop whose selling the main shop suspended: purchases, renewals, extras and tests refused (agents' bots only)
    case SellingPaused = 'selling_paused';
    case CategoryPlans = 'category_plans';
    case PlanButton = 'plan_button';
    // «🛠 ساخت سرویس دلخواه»: a service the customer builds (the main bot only)
    case CustomButton = 'custom_button';
    case CustomTraffic = 'custom_traffic';
    case CustomTrafficButton = 'custom_traffic_button';
    case CustomDays = 'custom_days';
    case CustomDaysButton = 'custom_days_button';
    case CustomTier = 'custom_tier';
    case CustomServer = 'custom_server';
    case CustomCheckout = 'custom_checkout';
    case CustomTrafficInvalid = 'custom_traffic_invalid';
    case CustomDaysInvalid = 'custom_days_invalid';
    case CustomUnavailable = 'custom_unavailable';
    case CustomPlanName = 'custom_plan_name';
    // … and a service without a plan renewed with one it builds
    case CustomRenewButton = 'custom_renew_button';
    case CustomRenewTraffic = 'custom_renew_traffic';
    case CustomRenewDays = 'custom_renew_days';
    case PlanDetails = 'plan_details';
    case PlanFirstPurchase = 'plan_first_purchase';
    case PlanAgentPrice = 'plan_agent_price';
    case PlanSale = 'plan_sale';
    case PlanPersonalDiscount = 'plan_personal_discount';
    case PlanTierDiscount = 'plan_tier_discount';
    case PlanPickServer = 'plan_pick_server';
    case ServerButton = 'server_button';
    case PlanNoServer = 'plan_no_server';
    case PlanGone = 'plan_gone';

    // checkout and receipts
    case Checkout = 'checkout';
    case TopupCheckout = 'topup_checkout';
    case CheckoutNoGateway = 'checkout_no_gateway';
    case CheckoutNoMethodForAmount = 'checkout_no_method_for_amount';
    case CheckoutFree = 'checkout_free';
    case CheckoutPlanNote = 'checkout_plan_note';
    case CheckoutDiscount = 'checkout_discount';
    case CheckoutBeforeDiscount = 'checkout_before_discount';
    case CheckoutName = 'checkout_name';
    case CheckoutNamePattern = 'checkout_name_pattern';
    case CheckoutNameSequential = 'checkout_name_sequential';
    case CheckoutNameRandom = 'checkout_name_random';
    case CheckoutNameCustom = 'checkout_name_custom';
    case CheckoutNameAsk = 'checkout_name_ask';
    case CheckoutNameRefused = 'checkout_name_refused';
    case DiscountCodeButton = 'discount_code_button';
    case DiscountCodeRemove = 'discount_code_remove';
    case DiscountCodeAsk = 'discount_code_ask';
    case DiscountCodeRefused = 'discount_code_refused';
    case DiscountCodeDropped = 'discount_code_dropped';
    case DiscountCodeLost = 'discount_code_lost';
    case OrderNotPending = 'order_not_pending';
    case PayInsufficient = 'pay_insufficient';
    case PayFailed = 'pay_failed';
    case CardInstructions = 'card_instructions';
    case CardBank = 'card_bank';
    case CardCopyNumber = 'card_copy_number';
    case CardCopyAmount = 'card_copy_amount';
    case CardToSupport = 'card_to_support';
    case CardToSupportButton = 'card_to_support_button';
    case PayInstructionsSuffix = 'pay_instructions_suffix';
    case ReceiptInReview = 'receipt_in_review';
    case ReceiptWaiting = 'receipt_waiting';
    case ReceiptNotImage = 'receipt_not_image';
    case ReceiptReceived = 'receipt_received';
    case ReceiptReceivedTimed = 'receipt_received_timed';
    case ReceiptOutcomeSubscription = 'receipt_outcome_subscription';
    case ReceiptOutcomeRenewal = 'receipt_outcome_renewal';
    case ReceiptOutcomeTopup = 'receipt_outcome_topup';
    case ReceiptOutcomeExtraTraffic = 'receipt_outcome_extra_traffic';
    case ReceiptOutcomeExtraTime = 'receipt_outcome_extra_time';
    case ReceiptOutcomeExtraDevices = 'receipt_outcome_extra_devices';
    case PayProcessing = 'pay_processing';

    // delivery and what support decided about a payment
    case PaySuccess = 'pay_success';
    case PayProvisionFailed = 'pay_provision_failed';
    case ReceiptRejected = 'receipt_rejected';
    case OrderCancelledByAdmin = 'order_cancelled_by_admin';
    case PaymentRefunded = 'payment_refunded';
    case PaymentRefundedService = 'payment_refunded_service';
    case PaymentRefundedTakenBack = 'payment_refunded_taken_back';
    case PaymentRefundedDevicesTakenBack = 'payment_refunded_devices_taken_back';
    case TopupRefunded = 'topup_refunded';
    case TopupRefundedUndelivered = 'topup_refunded_undelivered';
    case AdminNote = 'admin_note';
    case PaymentReminder = 'payment_reminder';
    case PaymentReminderToSupport = 'payment_reminder_to_support';
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

    // «➕ افزودن سرویس»: a service added by its link (the main bot only)
    case ClaimButton = 'claim_button';
    case ClaimPrompt = 'claim_prompt';
    case ClaimAdded = 'claim_added';
    case ClaimOff = 'claim_off';
    case ClaimNotALink = 'claim_not_a_link';
    case ClaimNotFound = 'claim_not_found';
    case ClaimTaken = 'claim_taken';
    case ClaimYours = 'claim_yours';
    case ClaimNoLink = 'claim_no_link';
    case ClaimUnavailable = 'claim_unavailable';
    case ClaimWait = 'claim_wait';

    // one service's screen
    case ServiceDetails = 'service_details';
    case ServiceRotateHint = 'service_rotate_hint';
    case ServiceStale = 'service_stale';
    case ServiceRemoval = 'service_removal';
    case ServiceTestRemoval = 'service_test_removal';
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
    case ServiceTransfer = 'service_transfer';
    case TransferAsk = 'transfer_ask';
    case TransferRecipientRefused = 'transfer_recipient_refused';
    case TransferRefused = 'transfer_refused';
    case TransferConfirm = 'transfer_confirm';
    case TransferYes = 'transfer_yes';
    case TransferExpired = 'transfer_expired';
    case TransferDone = 'transfer_done';
    case ServiceReceived = 'service_received';
    case ServiceReceivedLinkChange = 'service_received_link_change';
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
    case ServiceTransferredBySupport = 'service_transferred_by_support';
    case ServiceTransferredOrders = 'service_transferred_orders';
    case ServiceGranted = 'service_granted';

    // renewal and «تمدید خودکار»
    case RenewChoose = 'renew_choose';
    case RenewNothing = 'renew_nothing';
    case RenewPickPlan = 'renew_pick_plan';
    case RenewNoPlans = 'renew_no_plans';
    case RenewCheckout = 'renew_checkout';
    case RenewMethodButton = 'renew_method_button';
    case RenewMethodChosen = 'renew_method_chosen';
    case RenewUnavailable = 'renew_unavailable';
    case RenewNotYet = 'renew_not_yet';
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
    case RenewalDevicesFrom = 'renewal_devices_from';
    case RenewFailed = 'renew_failed';

    // «حجم، زمان و کاربر اضافه»
    case ServiceExtraTraffic = 'service_extra_traffic';
    case ServiceExtraTime = 'service_extra_time';
    case ServiceExtraDevices = 'service_extra_devices';
    case ExtraTrafficMenu = 'extra_traffic_menu';
    case ExtraTrafficTyped = 'extra_traffic_typed';
    case ExtraTimeMenu = 'extra_time_menu';
    case ExtraTimeRestart = 'extra_time_restart';
    case ExtraTimeTyped = 'extra_time_typed';
    case ExtraDevicesMenu = 'extra_devices_menu';
    case ExtraDevicesTyped = 'extra_devices_typed';
    case ExtraOption = 'extra_option';
    case ExtraInvalid = 'extra_invalid';
    case ExtraTrafficCheckout = 'extra_traffic_checkout';
    case ExtraTimeCheckout = 'extra_time_checkout';
    case ExtraDevicesCheckout = 'extra_devices_checkout';
    case ExtraUnavailable = 'extra_unavailable';
    case ExtraTrafficAdded = 'extra_traffic_added';
    case ExtraTimeAdded = 'extra_time_added';
    case ExtraDevicesAdded = 'extra_devices_added';
    case ExtraFailed = 'extra_failed';

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
    case WalletSend = 'wallet_send';
    case WalletSendAsk = 'wallet_send_ask';
    case WalletSendRecipientRefused = 'wallet_send_recipient_refused';
    case WalletSendRefused = 'wallet_send_refused';
    case WalletSendAmount = 'wallet_send_amount';
    case WalletSendAmountInvalid = 'wallet_send_amount_invalid';
    case WalletSendConfirm = 'wallet_send_confirm';
    case WalletSendYes = 'wallet_send_yes';
    case WalletSendExpired = 'wallet_send_expired';
    case WalletSendDone = 'wallet_send_done';
    case WalletSent = 'wallet_sent';
    case WalletReceived = 'wallet_received';
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
    case ReferralTermsRenewal = 'referral_terms_renewal';
    case ReferralTermsNoRenewal = 'referral_terms_no_renewal';
    case ReferralTermsGiftBoth = 'referral_terms_gift_both';
    case ReferralTermsGiftYou = 'referral_terms_gift_you';
    case ReferralTermsGiftFriend = 'referral_terms_gift_friend';
    case ReferralTermsReward = 'referral_terms_reward';
    case ReferralShare = 'referral_share';
    case ReferralOff = 'referral_off';
    case ReferralJoined = 'referral_joined';
    case ReferralCommission = 'referral_commission';
    case ReferralStartGiftReferrer = 'referral_start_gift_referrer';
    case ReferralStartGiftInvitee = 'referral_start_gift_invitee';
    case ReferralRewarded = 'referral_rewarded';

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
    // An agent's terms of selling: their agency's end, their monthly minimum, their bot's selling suspended and resumed
    case AgencyExpiryLine = 'agency_expiry_line';
    case AgencyMinimumLine = 'agency_minimum_line';
    case AgencyMonthLine = 'agency_month_line';
    case AgencySellingLine = 'agency_selling_line';
    case AgencyExpiring = 'agency_expiring';
    case AgencyMinimumShort = 'agency_minimum_short';
    case AgencySuspended = 'agency_suspended';
    case AgencyResumed = 'agency_resumed';
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

    // gift codes («🎁 کد هدیه»)
    case GiftCodeAsk = 'gift_code_ask';
    case GiftCodeOff = 'gift_code_off';
    case GiftCodeWallet = 'gift_code_wallet';
    case GiftCodePlan = 'gift_code_plan';
    case GiftCodePlanPending = 'gift_code_plan_pending';
    case GiftCodeUnknown = 'gift_code_unknown';
    case GiftCodeTaken = 'gift_code_taken';
    case GiftCodeYours = 'gift_code_yours';
    case GiftCodeExpired = 'gift_code_expired';
    case GiftCodeUnavailable = 'gift_code_unavailable';
    case GiftCodeWait = 'gift_code_wait';

    // the lucky wheel («🎡 گردونه شانس»)
    case WheelScreen = 'wheel_screen';
    case WheelPrizeLine = 'wheel_prize_line';
    case WheelCost = 'wheel_cost';
    case WheelFree = 'wheel_free';
    case WheelNext = 'wheel_next';
    case WheelSpinButton = 'wheel_spin_button';
    case WheelOff = 'wheel_off';
    case WheelBuyersOnly = 'wheel_buyers_only';
    case WheelEmpty = 'wheel_empty';
    case WheelTooSoon = 'wheel_too_soon';
    case WheelShort = 'wheel_short';
    case WheelSlice = 'wheel_slice';
    case WheelSliceCurrent = 'wheel_slice_current';
    case WheelWon = 'wheel_won';
    case WheelGivenWallet = 'wheel_given_wallet';
    case WheelGivenService = 'wheel_given_service';
    case WheelGivenPlan = 'wheel_given_plan';
    case WheelGivenPlanPending = 'wheel_given_plan_pending';
    case WheelNothing = 'wheel_nothing';
    case WheelFailed = 'wheel_failed';
    case WheelRefunded = 'wheel_refunded';

    // free test accounts («🧪 اکانت تست»)
    case TestScreen = 'test_screen';
    case TestServerLine = 'test_server_line';
    case TestOnHold = 'test_on_hold';
    case TestOff = 'test_off';
    case TestNewcomersOnly = 'test_newcomers_only';
    case TestUsed = 'test_used';
    case TestUsedUntil = 'test_used_until';
    case TestDailyLimit = 'test_daily_limit';
    case TestNoServer = 'test_no_server';
    case TestBuyButton = 'test_buy_button';
    case TestDelivered = 'test_delivered';
    case TestUnsent = 'test_unsent';
    case TestFailed = 'test_failed';
    case TestPlanName = 'test_plan_name';
    case NoPlanName = 'no_plan_name';

    // loyalty tiers, cashback and the nightly draw («سطح وفاداری»، «کش‌بک»، «قرعه‌کشی شبانه»)
    case LoyaltyTierLine = 'loyalty_tier_line';
    case LoyaltyTierClimb = 'loyalty_tier_climb';
    case LoyaltyNoTier = 'loyalty_no_tier';
    case LoyaltyTierUp = 'loyalty_tier_up';
    case LoyaltyPerkDiscount = 'loyalty_perk_discount';
    case LoyaltyPerkCashback = 'loyalty_perk_cashback';
    case CashbackEarned = 'cashback_earned';
    case CashbackTakenBack = 'cashback_taken_back';
    case LotteryWon = 'lottery_won';

    public function spec(): TextSpec
    {
        return TextCatalog::spec($this);
    }
}
