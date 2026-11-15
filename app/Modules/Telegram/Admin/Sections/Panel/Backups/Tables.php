<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Backups;

use App\Modules\Telegram\Admin\Ui\Fmt;

/**
 * What a backup holds, as the panel words it (components/backups/table-names.ts): each table by the name the panel gives
 * what it keeps — one a later version adds shown as the database names it —, and a backup's shop in a sentence.
 */
final class Tables
{
    /** Every table a backup holds, by its name in the panel (a shop's own, a shop's by their parent, the owner's). */
    private const NAMES = [
        'settings' => 'تنظیمات',
        'users' => 'کاربران',
        'wallet_transactions' => 'تراکنش‌های کیف پول',
        'wallet_transfers' => 'انتقال‌های موجودی',
        'customer_groups' => 'گروه‌های کاربران',
        'websites' => 'وب‌سایت',
        'mini_apps' => 'مینی‌اپ',
        'account_merges' => 'حساب‌های ادغام‌شده',
        'notifications' => 'اعلان‌های مشتری‌ها',
        'plan_categories' => 'دسته‌بندی‌ها',
        'plans' => 'پلن‌ها',
        'subscriptions' => 'اشتراک‌ها',
        'test_accounts' => 'اکانت‌های تست',
        'payment_methods' => 'روش‌های پرداخت',
        'payment_cards' => 'کارت‌های بانکی',
        'discount_codes' => 'کدهای تخفیف',
        'sales' => 'فروش‌های ویژه',
        'loyalty_tiers' => 'سطوح وفاداری',
        'cashback_rules' => 'قوانین کش‌بک',
        'wheel_prizes' => 'جوایز گردونه',
        'orders' => 'سفارش‌ها',
        'gift_codes' => 'کدهای هدیه',
        'wheel_spins' => 'چرخش‌های گردونه',
        'cashbacks' => 'کش‌بک‌ها',
        'lottery_draws' => 'قرعه‌کشی‌ها',
        'payments' => 'پرداخت‌ها',
        'deposits' => 'واریزهای پیامکی',
        'referral_commissions' => 'پورسانت‌ها',
        'referral_bonuses' => 'هدیه‌های شروع',
        'referral_rewards' => 'جایزه‌های دعوت',
        'referral_alerts' => 'هشدارهای زیرمجموعه فیک',
        'tickets' => 'تیکت‌ها',
        'reviews' => 'نظرات',
        'bot_channels' => 'کانال‌ها',
        'broadcasts' => 'پیام‌های همگانی',
        'report_chats' => 'گروه گزارش‌ها',
        'report_topics' => 'تاپیک‌های گروه گزارش‌ها',
        'custom_emojis' => 'ایموجی‌های پرمیوم',
        'import_links' => 'آنچه از ربات قبلی آمده',
        'customer_group_user' => 'اعضای گروه‌های کاربران',
        'plan_servers' => 'سرورهای پلن‌ها',
        'plan_server_inbounds' => 'اینباندهای پلن‌ها',
        'lottery_winners' => 'برنده‌های قرعه‌کشی',
        'ticket_messages' => 'پیام‌های تیکت',
        'broadcast_pins' => 'پین‌های پیام همگانی',
        'bots' => 'ربات‌ها',
        'sequences' => 'شماره‌گذاری‌ها',
        'agency_levels' => 'سطح‌های نمایندگی',
        'agency_requests' => 'درخواست‌های نمایندگی',
        'servers' => 'سرورها',
        'server_inbounds' => 'اینباندهای سرورها',
        'bot_hidden_servers' => 'سرورهای پنهان از نماینده‌ها',
        'grants' => 'هدیه‌های همگانی',
        'grant_parts' => 'بخش‌های هدیه‌های همگانی',
        'server_migrations' => 'انتقال سرویس‌ها',
        'migration_failures' => 'سرویس‌های منتقل‌نشده',
        'migration_leftovers' => 'باقی‌مانده‌های انتقال',
        'traffic_transactions' => 'تراکنش‌های ترافیک',
        'imports' => 'انتقال‌ها از ربات دیگر',
        'import_problems' => 'آنچه در انتقال‌ها نیامد',
    ];

    /** A table by its name in the panel (HTML); one it has no name for, as the database names it. */
    public static function name(string $table): string
    {
        return isset(self::NAMES[$table]) ? Fmt::e(self::NAMES[$table]) : Fmt::code($table);
    }

    /**
     * A shop a backup is of, in a sentence (HTML): its bot's @username, else its title in quotes, else «این فروشگاه».
     *
     * @param array<string, mixed>|null $shop
     */
    public static function shop(?array $shop): string
    {
        if (is_string($shop['username'] ?? null) && $shop['username'] !== '') {
            return Fmt::ltr('@' . $shop['username']);
        }
        $title = $shop['title'] ?? $shop['name'] ?? null;

        return is_string($title) && $title !== '' ? '«' . Fmt::e($title) . '»' : 'این فروشگاه';
    }

    /**
     * What a backup of this scope holds, as a sentence's end (HTML).
     *
     * @param array<string, mixed> $status GET /backups's `backups` (or an inspection)
     */
    public static function holds(array $status): string
    {
        return ($status['scope'] ?? '') === 'installation'
            ? 'کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها'
            : 'فروشگاه ' . self::shop(is_array($status['shop'] ?? null) ? $status['shop'] : null) . ': فقط داده‌های همین فروشگاه، بدون فروشگاه‌های دیگر و سرورها';
    }
}
