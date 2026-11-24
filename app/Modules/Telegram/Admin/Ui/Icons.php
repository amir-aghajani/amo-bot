<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

/**
 * One emoji for each thing the management has — on the home, the hubs, a screen's title, a settings card —, so a
 * section looks the same wherever it is reached from. A title or a menu entry is `Icons::X . ' ' . its name`.
 */
final class Icons
{
    public const HOME = '🏠';
    public const DASHBOARD = '📊';
    public const SUPPORT = '🎧';
    public const REVIEWS = '⭐️';
    public const SALES = '🛍';
    public const PAYMENTS = '💳';
    public const ORDERS = '🛒';
    public const CUSTOMERS = '👥';
    /** One customer (a list's row, a card's title), beside CUSTOMERS for the many. */
    public const CUSTOMER = '👤';
    public const SERVICES = '📡';
    public const REFERRALS = '🔗';
    /** The referral program's lists: who brings customers, who was brought, what was earned. */
    public const REFERRERS = '🧲';
    public const INVITEES = '🙋';
    public const COMMISSIONS = '💰';
    public const DEPOSITS = '📩';
    public const SHOP = '📦';
    public const PLANS = '📦';
    public const CATEGORIES = '🗂';
    public const GROUPS = '👫';
    public const TESTS = '🧪';
    public const CUSTOM = '🛠';
    public const METHODS = '🏦';
    public const REWARDS = '🎉';
    public const DISCOUNTS = '🏷';
    public const SPECIAL_SALES = '🔥';
    public const LOYALTY = '🏅';
    public const CASHBACK = '💸';
    public const GIFT_CODES = '🎟';
    public const WHEEL = '🎡';
    public const LOTTERY = '🎲';
    public const BOT = '🤖';
    public const BROADCAST = '📣';
    public const MASS_GIFT = '🎁';
    public const TEXTS = '📝';
    /** The texts' premium emoji: the plain emoji each drawn premium in every text («💎 ایموجی پرمیوم متن‌ها»). */
    public const PREMIUM_TEXTS = '💎';
    public const KEYBOARDS = '⌨️';
    public const CHANNELS = '📢';
    public const REPORT_GROUP = '📋';
    public const SERVERS = '🖥';
    public const AGENTS = '🤝';
    public const AGENT_ACCOUNT = '💼';
    public const SETTINGS = '⚙️';
    public const BOT_ADMINS = '🛡';
    public const WEBSITE = '🌐';
    public const MINI_APP = '📱';
    public const PANEL = '🧰';
    public const OWNER = '👑';
    public const WALLET = '👛';
    public const SEARCH = '🔎';
    public const SHOP_SWITCH = '🏪';

    // The bot settings' own cards («🤖 تنظیمات ربات») and the panel's sections («🧰 تنظیمات پنل»).
    public const CARD_TO_CARD = '🏧';
    public const WALLET_TRANSFER = '💱';
    public const EXTRAS = '🧩';
    public const AUTO_RENEW = '🔂';
    public const EXPIRED_CLEANUP = '🧹';
    public const CLAIMS = '📎';
    public const REPORT_TOPICS = '🧵';
    public const SYSTEM = '🛰';
    public const ADVANCED = '🔧';
    public const IMPORTS = '🚚';
}
