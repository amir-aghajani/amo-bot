/*
 * The server's bounds on what the panels' forms take, where a form words them (a hint, «حداکثر …», a row editor that
 * stops adding) — each under the name of the PHP class and constant it mirrors, or of the field whose bound it is where
 * the server names none. The server refuses past them whatever these say; they only keep the panels' words true.
 */

/** A sale by the unit — an extra, a service the customer builds (Catalog\DTO\Package): the most GB and days it gives at once. */
export const PACKAGE = { MAX_GB: 10_000, MAX_DAYS: 365 } as const

/** The amounts a price list offers as buttons — an extra's, the builder's (Subscriptions\Forms\ExtraOptions): how many. */
export const EXTRA_OPTIONS = { MOST: 8 } as const

/** «سرویس دلخواه» (Catalog\Services\CustomServiceSettings): the most devices a built service may be set to (`custom_devices`). */
export const CUSTOM_SERVICE_SETTINGS = { MAX_DEVICES: 100 } as const

/** A price list by tiers — the builder's GB and days (Catalog\Forms\TierPrices): how many tiers it holds. */
export const TIER_PRICES = { MOST: 10 } as const

/** «حجم، زمان و کاربر اضافه» (Subscriptions\Services\ExtrasSettings): the most devices one extra may bring. */
export const EXTRAS_SETTINGS = { MAX_DEVICES: 20 } as const

/** «کاربر اضافه» is priced for a period (Subscriptions\DTO\ExtraOffer::PERIOD_DAYS); a service pays its share for the days it has left. */
export const EXTRA_OFFER = { PERIOD_DAYS: 30 } as const

/** «آستانه مجاز تمدید» (Subscriptions\Services\RenewalSettings): days before the end (`renew_window_days`), percent of the traffic left (`renew_window_percent`). */
export const RENEWAL_SETTINGS = { WINDOW_DAYS_MAX: 365, WINDOW_PERCENT_MAX: 100 } as const

/** «سرویس‌های منقضی‌شده» (Subscriptions\Services\ExpiredServicesSettings): the longest an ended service is kept, in hours (a year). */
export const EXPIRED_SERVICES_SETTINGS = { HOURS_MAX: 8760 } as const

/** The test accounts' rules (Subscriptions\Services\TestAccountSettings): tests a customer (`test_per_user`), the longest an ended test is kept in hours (`test_cleanup_hours`, 30 days). */
export const TEST_ACCOUNT_SETTINGS = { PER_USER_MAX: 100, CLEANUP_HOURS_MAX: 720 } as const

/** A server's test account (Subscriptions\Services\TestServers): its traffic in MB and its hours. */
export const TEST_SERVERS = { TRAFFIC_MAX_MB: 102_400, HOURS_MAX: 720 } as const

/** Discount codes' rules (Discounts\Services\DiscountSettings::HOLD_MAX): the longest an unpaid order with a code is held, in minutes. */
export const DISCOUNT_SETTINGS = { HOLD_MAX: 1440 } as const

/** Gift codes made at once (Rewards\Services\GiftCodes): how many, and their prefix's length. */
export const GIFT_CODES = { COUNT_MAX: 500, PREFIX_MAX: 12 } as const

/** The nightly draw (Rewards\Services\LotterySettings): its winners (`lottery_winners`). */
export const LOTTERY_SETTINGS = { WINNERS_MAX: 20 } as const

/** The lucky wheel (Rewards\Services\WheelSettings): hours between two spins (`wheel_cooldown_hours`). */
export const WHEEL_SETTINGS = { COOLDOWN_HOURS_MAX: 720 } as const

/** A bulk price change (Catalog\Services\PlanService::PERCENT_MAX): the largest raise by percent. */
export const PLAN_SERVICE = { PERCENT_MAX: 1000 } as const
