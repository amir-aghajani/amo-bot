<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * What a notice the shop sent a customer was (`notifications.type`) — one for each thing the bot tells them on its own
 * (Services\CustomerNotifier): what the website files it under, and the line an email of it carries as its subject
 * (subject()). Its words are the bot text's, never these.
 */
enum NoticeType: string
{
    /** A payment was approved: the service delivered, the wallet charged, the traffic added — or the delivery failed and support finishes it. */
    case PaymentSettled = 'payment_settled';

    /** Support refused the receipt, with their reason. */
    case PaymentRejected = 'payment_rejected';

    /** Support cancelled the order, with their note. */
    case OrderCancelled = 'order_cancelled';

    /** A payment came back to the wallet. */
    case PaymentRefunded = 'payment_refunded';

    /** A wallet top-up was given back, its amount taken out of the wallet again. */
    case TopUpRefunded = 'topup_refunded';

    /** Support reminded them of a card payment they never finished. */
    case PaymentReminder = 'payment_reminder';

    /** Support switched a service off. */
    case ServiceDisabled = 'service_disabled';

    /** Support switched a service back on. */
    case ServiceEnabled = 'service_enabled';

    /** Support deleted a service that was running. */
    case ServiceDeleted = 'service_deleted';

    /** A service moved to another server: its new link. */
    case ServiceMoved = 'service_moved';

    /** A service got days or traffic: a grant's share, or support's extension. */
    case ServiceGranted = 'service_granted';

    /** «یادآوری»: a service ends soon. */
    case ExpiryReminder = 'expiry_reminder';

    /** «یادآوری»: most of a service's traffic is used. */
    case TrafficReminder = 'traffic_reminder';

    /** «تمدید خودکار» renewed a service from the wallet. */
    case AutoRenewed = 'auto_renewed';

    /** «تمدید خودکار» found the wallet short of the renewal's price. */
    case AutoRenewShort = 'auto_renew_short';

    /** A renewal was paid but not delivered: support finishes it. */
    case RenewalFailed = 'renewal_failed';

    /** Their invite link brought a newcomer. */
    case ReferralJoined = 'referral_joined';

    /** A referral's payment earned them a commission. */
    case ReferralCommission = 'referral_commission';

    /** Their request to become an agent was approved. */
    case AgencyApproved = 'agency_approved';

    /** Their request to become an agent was rejected. */
    case AgencyRejected = 'agency_rejected';

    /** Their level or credit as an agent changed. */
    case AgencyChanged = 'agency_changed';

    /** Their agency ended. */
    case AgencyRevoked = 'agency_revoked';

    /** Their bot could not deliver an order: its traffic does not cover it. */
    case AgencyTrafficShort = 'agency_traffic_short';

    /** The two-factor sign-in of their account on the website was turned off — by support, or by them. */
    case TwoFactorDisabled = 'two_factor_disabled';

    /** A way into their account on the website was added: a Telegram account, a Google account, an email. */
    case WayInAdded = 'way_in_added';

    /** A way into their account on the website was taken away. */
    case WayInRemoved = 'way_in_removed';

    /** Their account's password on the website was set or changed. */
    case PasswordChanged = 'password_changed';

    /** They turned the two-factor sign-in of their account on the website on. */
    case TwoFactorEnabled = 'two_factor_enabled';

    /** Another account of theirs in the shop and this one were made one. */
    case AccountMerged = 'account_merged';

    /** The second step of their password sign-in was failed so often that it waits a while. */
    case SecondStepLocked = 'second_step_locked';

    /** The codes emailed to their account's address were failed so often that no new one goes to it for a while. */
    case EmailCodesFailed = 'email_codes_failed';

    /** Support answered their ticket. */
    case TicketAnswered = 'ticket_answered';

    /** Support closed their ticket. */
    case TicketClosed = 'ticket_closed';

    /** What an email of the notice says it is about, in one line — its subject, and the heading over its words. */
    public function subject(): string
    {
        return match ($this) {
            self::PaymentSettled => 'پرداخت شما تایید شد',
            self::PaymentRejected => 'رسید پرداخت شما تایید نشد',
            self::OrderCancelled => 'سفارش شما لغو شد',
            self::PaymentRefunded => 'مبلغ پرداخت شما به کیف پول برگشت داده شد',
            self::TopUpRefunded => 'پرداخت شارژ کیف پول شما برگشت داده شد',
            self::PaymentReminder => 'پرداخت سفارش شما هنوز کامل نشده است',
            self::ServiceDisabled => 'سرویس شما غیرفعال شد',
            self::ServiceEnabled => 'سرویس شما دوباره فعال شد',
            self::ServiceDeleted => 'سرویس شما حذف شد',
            self::ServiceMoved => 'سرویس شما به سرور دیگری منتقل شد',
            self::ServiceGranted => 'زمان یا حجم به سرویس شما اضافه شد',
            self::ExpiryReminder => 'سرویس شما رو به پایان است',
            self::TrafficReminder => 'حجم سرویس شما رو به پایان است',
            self::AutoRenewed => 'سرویس شما خودکار تمدید شد',
            self::AutoRenewShort => 'موجودی کیف پول برای تمدید خودکار کافی نیست',
            self::RenewalFailed => 'تمدید سرویس شما روی سرور انجام نشد',
            self::ReferralJoined => 'زیرمجموعه جدید با لینک دعوت شما',
            self::ReferralCommission => 'پورسانت زیرمجموعه به کیف پول شما اضافه شد',
            self::AgencyApproved => 'درخواست نمایندگی شما تایید شد',
            self::AgencyRejected => 'درخواست نمایندگی شما تایید نشد',
            self::AgencyChanged => 'نمایندگی شما به‌روز شد',
            self::AgencyRevoked => 'نمایندگی شما لغو شد',
            self::AgencyTrafficShort => 'حجم ربات شما برای تحویل سفارش کافی نیست',
            self::TwoFactorDisabled => 'ورود دو مرحله‌ای حساب شما خاموش شد',
            self::WayInAdded => 'روش ورود تازه‌ای به حساب شما اضافه شد',
            self::WayInRemoved => 'یکی از روش‌های ورود حساب شما برداشته شد',
            self::PasswordChanged => 'رمز عبور حساب شما عوض شد',
            self::TwoFactorEnabled => 'ورود دو مرحله‌ای حساب شما روشن شد',
            self::AccountMerged => 'حساب دیگری با حساب شما یکی شد',
            self::SecondStepLocked => 'کد ورود دو مرحله‌ای حساب شما چند بار اشتباه وارد شد',
            self::EmailCodesFailed => 'کد ایمیل حساب شما چند بار اشتباه وارد شد',
            self::TicketAnswered => 'پاسخ پشتیبانی به تیکت شما',
            self::TicketClosed => 'تیکت پشتیبانی شما بسته شد',
        };
    }
}
