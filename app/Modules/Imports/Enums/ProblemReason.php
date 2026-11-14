<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

/**
 * Why an import did not bring something, for its report. A reason that is the owner's own choice, or nothing to act
 * on, is only counted (counted()); every other is a row of `import_problems` naming the thing, to look into.
 */
enum ProblemReason: string
{
    // Counted only.
    case Reseller = 'reseller';
    case PanelSkipped = 'panel_skipped';
    case NotChosen = 'not_chosen';
    case UsedUp = 'used_up';
    case Expired = 'expired';
    case Ended = 'ended';
    /** Brought by an earlier import and deleted from the shop since: the owner's to remove, never brought back. */
    case Removed = 'removed';

    // Each one a row.
    case Unreadable = 'unreadable';
    case NotATelegramAccount = 'not_telegram';
    case NoCustomer = 'no_customer';
    case NotOnPanel = 'not_on_panel';
    case PanelUnreachable = 'panel_unreachable';
    case NoLink = 'no_link';
    case KeptAlready = 'kept_already';
    case ServerNotAdded = 'server_not_added';
    case PlanWithoutServer = 'plan_no_server';
    case AgentsOnlyPlan = 'agents_only_plan';
    case CodeTaken = 'code_taken';
    case ScopeLost = 'scope_lost';
    case MultiUseGift = 'multi_use_gift';
    case ChannelRefused = 'channel_refused';
    case CardInvalid = 'card_invalid';
    /** A gift code the other bot kept for one customer: the shop's are anyone's who has them. */
    case OneCustomer = 'one_customer';
    /** The shop's own check of it refused it (a plan's form, a code's), in `detail`. */
    case Refused = 'refused';
    /** Writing it failed for a reason the shop did not expect; the app's log has it. */
    case Failed = 'failed';

    /** Only counted, never a row of its own. */
    public function counted(): bool
    {
        return in_array($this, [self::Reseller, self::PanelSkipped, self::NotChosen, self::UsedUp, self::Expired, self::Ended, self::Removed], true);
    }

    /** In the owner's words, as the report shows it. */
    public function label(): string
    {
        return match ($this) {
            self::Reseller => 'از ربات‌های نماینده؛ انتخاب نشده بود',
            self::PanelSkipped => 'پنلش منتقل نشد',
            self::NotChosen => 'انتخاب نشده بود',
            self::UsedUp => 'استفاده‌هایش تمام شده بود',
            self::Expired => 'مهلتش گذشته بود',
            self::Ended => 'تمام شده بود',
            self::Removed => 'بعد از انتقال قبلی از فروشگاه حذف شده بود؛ دوباره آورده نشد',
            self::Unreadable => 'خوانده نشد',
            self::NotATelegramAccount => 'شناسه تلگرام معتبر ندارد',
            self::NoCustomer => 'مشتری‌اش منتقل نشده بود',
            self::NotOnPanel => 'روی پنلش پیدا نشد',
            self::PanelUnreachable => 'پنلش جواب نداد',
            self::NoLink => 'پنل لینک اشتراکی برایش نمی‌دهد',
            self::KeptAlready => 'این سرویس از قبل برای مشتری دیگری در فروشگاه هست',
            self::ServerNotAdded => 'پنلش به‌عنوان سرور اضافه نشد',
            self::PlanWithoutServer => 'هیچ‌کدام از پنل‌هایش منتقل نشد؛ پلن بدون سرور آمد',
            self::AgentsOnlyPlan => 'فقط برای نماینده‌ها بود؛ خاموش آمد',
            self::CodeTaken => 'کدی با همین نام از قبل در فروشگاه هست',
            self::ScopeLost => 'پلن‌ها یا پنل‌هایی که برایشان بود منتقل نشد؛ خاموش آمد',
            self::MultiUseGift => 'کد هدیه چندبارمصرف است و کدهای هدیه اینجا یک‌بارمصرف‌اند',
            self::ChannelRefused => 'کانال اضافه نشد',
            self::CardInvalid => 'شماره کارت معتبر نیست',
            self::OneCustomer => 'فقط برای یک مشتری بود و کدهای هدیه اینجا مال هر کسی است که کد را دارد',
            self::Refused => 'فروشگاه آن را نپذیرفت',
            self::Failed => 'نوشتنش ناموفق بود؛ جزئیات در لاگ برنامه است',
        };
    }
}
