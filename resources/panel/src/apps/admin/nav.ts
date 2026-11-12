import { Handshake, Server } from 'lucide-react'
import {
  APPEARANCE_SECTION,
  BACKUPS_SECTION,
  BOT_GROUP,
  BOT_SETTINGS,
  BROADCAST_MESSAGES,
  DASHBOARD,
  DISCOUNT_SECTIONS,
  MARKETING_GROUP,
  MINI_APP_SETTINGS,
  panelSettings,
  REFERRAL_SECTIONS,
  REVIEWS,
  SALES_GROUP,
  SETTINGS,
  SHOP_GROUP,
  SUPPORT,
  TEST_SECTIONS,
  USER_SECTIONS,
  WEBSITE_SETTINGS,
  WHEEL_SECTIONS,
  type NavSection,
  type PanelNav,
} from '@/components/shell/nav'
import type { ConfigGroup } from '@/lib/api-types'

/*
 * The owner's menu: the shared shop pages plus what only the owner has — the servers, the agency (requests, agents,
 * levels, rules), the mass gift beside the broadcasts, and the panel's own settings (config.php, the login, the look,
 * the update, the schedule, the backups).
 */

export const AGENCY_SECTIONS: NavSection<'requests' | 'agents' | 'levels' | 'settings'>[] = [
  { value: 'requests', title: 'درخواست‌ها', to: '/agents' },
  { value: 'agents', title: 'نماینده‌ها', to: '/agents/list' },
  { value: 'levels', title: 'سطح‌ها', to: '/agents/levels' },
  { value: 'settings', title: 'تنظیمات', to: '/agents/settings' },
]

/** «هدیه همگانی»: days and traffic for every bot's services, beside the broadcasts. */
export const GIFTS_SECTION: NavSection<'gifts'> = { value: 'gifts', title: 'هدیه همگانی', to: '/broadcasts/gifts' }

/**
 * «تنظیمات پنل»: config.php's groups — the shop's email among them, which the websites' sign-up codes go by, and its
 * SMS, which their sign-in codes by phone number go by —, the owner's login — config.php keeps it too —, the panel's look in this browser, AmoBot's own update, the scheduled
 * tasks' timings (the database's, for the whole installation) and the backups (the whole installation's in the main
 * shop, an agent's shop's alone in theirs); the advanced settings last.
 */
export const PANEL_SETTINGS_SECTIONS: NavSection<ConfigGroup | 'login' | 'appearance' | 'update' | 'schedule' | 'backups'>[] = [
  { value: 'telegram', title: 'ربات تلگرام', to: '/settings/telegram' },
  { value: 'database', title: 'دیتابیس', to: '/settings/database' },
  { value: 'app', title: 'برنامه', to: '/settings/app' },
  { value: 'mail', title: 'ایمیل', to: '/settings/mail' },
  { value: 'sms', title: 'پیامک', to: '/settings/sms' },
  { value: 'login', title: 'ورود به پنل', to: '/settings/login' },
  APPEARANCE_SECTION,
  { value: 'update', title: 'به‌روزرسانی', to: '/settings/update' },
  { value: 'schedule', title: 'زمان‌بندی کارها', to: '/settings/schedule' },
  BACKUPS_SECTION,
  { value: 'advanced', title: 'پیشرفته', to: '/settings/advanced' },
]

export const ADMIN_NAV: PanelNav = {
  menu: [
    DASHBOARD,
    SUPPORT,
    REVIEWS,
    { id: 'servers', title: 'سرورها', to: '/servers', icon: Server },
    SHOP_GROUP,
    { ...SALES_GROUP, items: [...SALES_GROUP.items, { id: 'agents', title: 'نمایندگان', to: '/agents', icon: Handshake }] },
    MARKETING_GROUP,
    BOT_GROUP,
  ],
  sections: {
    users: USER_SECTIONS,
    referrals: REFERRAL_SECTIONS,
    agents: AGENCY_SECTIONS,
    broadcasts: [BROADCAST_MESSAGES, GIFTS_SECTION],
    wheel: WHEEL_SECTIONS,
    discounts: DISCOUNT_SECTIONS,
    tests: TEST_SECTIONS,
  },
  settings: {
    item: SETTINGS,
    groups: [BOT_SETTINGS, WEBSITE_SETTINGS, MINI_APP_SETTINGS, panelSettings(PANEL_SETTINGS_SECTIONS)],
  },
}
