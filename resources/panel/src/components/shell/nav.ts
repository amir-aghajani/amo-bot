import {
  Banknote,
  Bot,
  CreditCard,
  Globe,
  Headset,
  House,
  Keyboard,
  Megaphone,
  MessageSquareText,
  Package,
  Radio,
  Settings,
  ShoppingCart,
  Star,
  Store,
  Tags,
  UserPlus,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { useLocation } from 'react-router'
import type { BotSettingsGroup, QueueCounts } from '@/lib/api-types'
import { pageOf } from '@/lib/config'

/*
 * The navigation a panel's sidebar draws, as the Console's: a few top-level pages, then groups that fold open (an icon
 * and a chevron; their pages indented, text only), and the settings at the foot. Each panel has its own (apps/admin/nav,
 * apps/agent/nav), built from what is here: the shapes, the readings of a tree, and the entries and sections of the
 * shop's pages both panels have.
 *
 * A page made of sections — the settings, the users and their groups, the referrals' three lists… — has a column of
 * its own, as the Console's settings do: on its pages the sidebar lists its sections instead of the menu (`areaFor()`),
 * each section at an address of its own.
 */

export interface NavItem {
  id: string
  title: string
  to: string
  icon: LucideIcon
  /** Match the route exactly (the dashboard is "/" and would otherwise match everything). */
  end?: boolean
  /** The queue whose count rides beside the entry (components/shell/queue-badge): what waits on a human there. */
  badge?: keyof QueueCounts
}

export interface NavGroup {
  id: string
  title: string
  icon: LucideIcon
  items: NavItem[]
}

export type NavEntry = NavItem | NavGroup

export function isGroup(entry: NavEntry): entry is NavGroup {
  return 'items' in entry
}

export interface NavSection<T extends string = string> {
  value: T
  title: string
  /** The section's address — a page's first one is the page's own (`/users`), the settings' all have a segment. */
  to: string
}

/** A heading of a column of sections — a sectioned page, or one page of the settings — with its sections under it. */
export interface NavSectionGroup {
  id: string
  title: string
  icon: LucideIcon
  sections: NavSection[]
}

/** A column of sections in place of the menu. */
export interface NavArea {
  id: string
  /** What the sections are of, for the column's landmark («بخش‌های تنظیمات»). */
  label: string
  groups: NavSectionGroup[]
}

/** A panel's navigation. */
export interface PanelNav {
  /** The menu: top-level pages and the groups that fold open. */
  menu: NavEntry[]
  /** The menu's pages that are made of sections, by the item that opens them; one with a single section has no column. */
  sections: Partial<Record<string, NavSection[]>>
  /** The settings at the foot of the column: their link, and their own column — a group per page of them. */
  settings: { item: NavItem; groups: NavSectionGroup[] }
}

/* The shop's pages both panels have. */

export const DASHBOARD: NavItem = { id: 'dashboard', title: 'داشبورد', to: '/', icon: House, end: true }

/** The support tickets (pages/tickets, a ticket's own page under it), right after the dashboard: what waits on an answer beside it. */
export const SUPPORT: NavItem = { id: 'tickets', title: 'پشتیبانی', to: '/tickets', icon: Headset, badge: 'open_tickets' }

/** The customers' reviews of the shop (pages/reviews), after the support tickets: what waits on support beside it. */
export const REVIEWS: NavItem = { id: 'reviews', title: 'نظرات', to: '/reviews', icon: Star, badge: 'pending_reviews' }

export const SHOP_GROUP: NavGroup = {
  id: 'shop',
  title: 'فروشگاه',
  icon: Store,
  items: [
    { id: 'plans', title: 'پلن‌ها', to: '/plans', icon: Package },
    { id: 'categories', title: 'دسته‌بندی‌ها', to: '/categories', icon: Tags },
    { id: 'payment-methods', title: 'روش‌های پرداخت', to: '/payment-methods', icon: Banknote },
  ],
}

export const SALES_GROUP: NavGroup = {
  id: 'sales',
  title: 'فروش',
  icon: ShoppingCart,
  items: [
    { id: 'users', title: 'کاربران', to: '/users', icon: Users },
    { id: 'orders', title: 'سفارش‌ها', to: '/orders', icon: ShoppingCart, badge: 'stuck_orders' },
    { id: 'payments', title: 'پرداخت‌ها', to: '/payments', icon: CreditCard, badge: 'payments_to_review' },
    { id: 'subscriptions', title: 'اشتراک‌ها', to: '/subscriptions', icon: Radio },
    { id: 'referrals', title: 'زیرمجموعه‌گیری', to: '/referrals', icon: UserPlus },
  ],
}

export const BOT_GROUP: NavGroup = {
  id: 'bot',
  title: 'ربات',
  icon: Bot,
  items: [
    { id: 'keyboards', title: 'کیبوردها', to: '/keyboards', icon: Keyboard },
    { id: 'bot-texts', title: 'متن‌های ربات', to: '/bot-texts', icon: MessageSquareText },
    { id: 'broadcasts', title: 'ارسال همگانی', to: '/broadcasts', icon: Megaphone },
  ],
}

/** The foot of the column: the settings, which open their own. */
export const SETTINGS: NavItem = { id: 'settings', title: 'تنظیمات', to: '/bot-settings', icon: Settings }

export const USER_SECTIONS: NavSection<'users' | 'groups'>[] = [
  { value: 'users', title: 'کاربران', to: '/users' },
  { value: 'groups', title: 'گروه‌ها', to: '/users/groups' },
]

export const REFERRAL_SECTIONS: NavSection<'referrers' | 'invitees' | 'commissions'>[] = [
  { value: 'referrers', title: 'معرف‌ها', to: '/referrals' },
  { value: 'invitees', title: 'زیرمجموعه‌ها', to: '/referrals/invitees' },
  { value: 'commissions', title: 'پورسانت‌ها', to: '/referrals/commissions' },
]

/** The broadcasts page's own section; a panel may add its own beside it (the owner's «هدیه همگانی»). */
export const BROADCAST_MESSAGES: NavSection<'messages'> = { value: 'messages', title: 'پیام همگانی', to: '/broadcasts' }

/** The bot settings' sections — a subject each (the renewal one holds two groups: its rule and «تمدید خودکار»). */
export type BotSettingsSection = Exclude<BotSettingsGroup, 'auto_renew'>

export const BOT_SETTINGS_SECTIONS: NavSection<BotSettingsSection>[] = [
  { value: 'general', title: 'عمومی', to: '/bot-settings/general' },
  { value: 'channels', title: 'کانال‌ها', to: '/bot-settings/channels' },
  { value: 'wallet', title: 'کیف پول', to: '/bot-settings/wallet' },
  { value: 'renewal', title: 'تمدید سرویس', to: '/bot-settings/renewal' },
  { value: 'reminders', title: 'یادآوری', to: '/bot-settings/reminders' },
  { value: 'referral', title: 'زیرمجموعه‌گیری', to: '/bot-settings/referral' },
  { value: 'reports', title: 'گروه گزارش‌ها', to: '/bot-settings/reports' },
  { value: 'qr', title: 'کد QR', to: '/bot-settings/qr' },
]

/** The bot's settings in the settings' column — every panel's first group there. */
export const BOT_SETTINGS: NavSectionGroup = { id: 'bot', title: 'تنظیمات ربات', icon: Bot, sections: BOT_SETTINGS_SECTIONS }

/**
 * The website's settings' sections (pages/website-settings): its connection — the switch, its addresses, the API's own —,
 * then the ways its customers sign in: Telegram, Google, and an email with a password (with the captcha that guards it),
 * and last the shop's admins working it from the website.
 */
export const WEBSITE_SETTINGS_SECTIONS: NavSection<'connection' | 'telegram' | 'google' | 'email' | 'reviews' | 'staff'>[] = [
  { value: 'connection', title: 'اتصال', to: '/website-settings/connection' },
  { value: 'telegram', title: 'ورود با تلگرام', to: '/website-settings/telegram' },
  { value: 'google', title: 'ورود با گوگل', to: '/website-settings/google' },
  { value: 'email', title: 'ورود با ایمیل', to: '/website-settings/email' },
  { value: 'reviews', title: 'نظرات', to: '/website-settings/reviews' },
  { value: 'staff', title: 'مدیران سایت', to: '/website-settings/staff' },
]

/** The shop's website in the settings' column, after the bot's — every panel's: an agent's shop has a website of its own. */
export const WEBSITE_SETTINGS: NavSectionGroup = { id: 'website', title: 'تنظیمات وب‌سایت', icon: Globe, sections: WEBSITE_SETTINGS_SECTIONS }

/** The panel's look in this browser — dark or light —: a section of every panel's own settings (`panelSettings()`). */
export const APPEARANCE_SECTION: NavSection<'appearance'> = { value: 'appearance', title: 'ظاهر', to: '/settings/appearance' }

/**
 * A panel's own settings in the settings' column, «تنظیمات پنل», after the bot's and the website's: the owner's
 * config.php and login, and every panel's look (APPEARANCE_SECTION) — the account row only signs out.
 */
export function panelSettings(sections: NavSection[]): NavSectionGroup {
  return { id: 'panel', title: 'تنظیمات پنل', icon: Settings, sections }
}

/* Reading a panel's tree. */

export function isNavItemActive(item: Pick<NavItem, 'to' | 'end'>, pathname: string): boolean {
  return item.end ? pathname === item.to : pathname === item.to || pathname.startsWith(item.to + '/')
}

/** The settings' group whose page this is, if it is one of theirs. */
function settingsGroupOf(nav: PanelNav, pathname: string): NavSectionGroup | undefined {
  return nav.settings.groups.find((group) => group.sections.some((section) => pageOf(section.to) === pageOf(pathname)))
}

/** True on the pages whose column is the settings'. */
export function inSettings(nav: PanelNav, pathname: string): boolean {
  return settingsGroupOf(nav, pathname) !== undefined
}

/** The column the sidebar shows on this page: its sections' (when it has more than one to offer), else null — the menu. */
export function areaFor(nav: PanelNav, pathname: string): NavArea | null {
  if (inSettings(nav, pathname)) {
    return { id: 'settings', label: nav.settings.item.title, groups: nav.settings.groups }
  }

  for (const entry of nav.menu) {
    for (const item of isGroup(entry) ? entry.items : [entry]) {
      const sections = nav.sections[item.id]
      if (!sections || !isNavItemActive(item, pathname)) continue
      return sections.length > 1 ? { id: item.id, label: item.title, groups: [{ id: item.id, title: item.title, icon: item.icon, sections }] } : null
    }
  }

  return null
}

/**
 * The current page's name, for the phone's topbar and the document's title; null at an address no page of the panel has,
 * where the page on screen is the not-found one (components/not-found), which names itself.
 */
export function pageTitle(nav: PanelNav, pathname: string): string | null {
  const settings = settingsGroupOf(nav, pathname)
  if (settings) return settings.title
  for (const entry of nav.menu) {
    for (const item of isGroup(entry) ? entry.items : [entry]) {
      if (isNavItemActive(item, pathname)) return item.title
    }
  }
  return null
}

/**
 * Whether a section of the column is where the address is: its own address — or, for the section at the page's own
 * address (its first, `/users`), a subject's page under it that no section names (`/users/12`, one customer).
 */
export function isSectionCurrent(sections: NavSection[], section: NavSection, pathname: string): boolean {
  if (section.to === pathname) return true

  return section.to === pageOf(section.to) && pathname.startsWith(`${section.to}/`) && !sections.some((other) => other.to === pathname)
}

/**
 * The section a sectioned page's address names — null for any other address under the page (the page then
 * `Navigate`s to its first section).
 */
export function useSection<T extends string>(sections: NavSection<T>[]): NavSection<T> | null {
  const { pathname } = useLocation()
  return sections.find((section) => section.to === pathname) ?? null
}
