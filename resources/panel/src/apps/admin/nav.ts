import { Handshake, Server } from 'lucide-react'
import {
  APPEARANCE_SECTION,
  BOT_GROUP,
  BOT_SETTINGS,
  BROADCAST_MESSAGES,
  DASHBOARD,
  DISCOUNT_SECTIONS,
  MARKETING_GROUP,
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
 * the update).
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
 * «تنظیمات پنل»: config.php's groups — the shop's email among them, which the websites' sign-up codes go by —, the
 * owner's login — config.php keeps it too —, the panel's look in this browser and AmoBot's own update; the advanced
 * settings last.
 */
export const PANEL_SETTINGS_SECTIONS: NavSection<ConfigGroup | 'login' | 'appearance' | 'update'>[] = [
  { value: 'telegram', title: 'ربات تلگرام', to: '/settings/telegram' },
  { value: 'database', title: 'دیتابیس', to: '/settings/database' },
  { value: 'app', title: 'برنامه', to: '/settings/app' },
  { value: 'mail', title: 'ایمیل', to: '/settings/mail' },
  { value: 'login', title: 'ورود به پنل', to: '/settings/login' },
  APPEARANCE_SECTION,
  { value: 'update', title: 'به‌روزرسانی', to: '/settings/update' },
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
    groups: [BOT_SETTINGS, WEBSITE_SETTINGS, panelSettings(PANEL_SETTINGS_SECTIONS)],
  },
}
