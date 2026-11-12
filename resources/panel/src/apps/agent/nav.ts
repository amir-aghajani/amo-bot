import { BadgeCheck } from 'lucide-react'
import {
  APPEARANCE_SECTION,
  BACKUPS_SECTION,
  BOT_GROUP,
  BOT_SETTINGS,
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
  type PanelNav,
} from '@/components/shell/nav'

/*
 * An agent's panel: their bot's shop — the shop's pages, its bot's settings, its website's and its mini app's —, their
 * account with the main bot, and their panel's own settings: their shop's backups and the panel's look.
 */
export const AGENT_NAV: PanelNav = {
  menu: [DASHBOARD, SUPPORT, REVIEWS, { id: 'account', title: 'حساب نمایندگی', to: '/account', icon: BadgeCheck }, SHOP_GROUP, SALES_GROUP, MARKETING_GROUP, BOT_GROUP],
  sections: { users: USER_SECTIONS, referrals: REFERRAL_SECTIONS, wheel: WHEEL_SECTIONS, discounts: DISCOUNT_SECTIONS, tests: TEST_SECTIONS },
  settings: { item: SETTINGS, groups: [BOT_SETTINGS, WEBSITE_SETTINGS, MINI_APP_SETTINGS, panelSettings([BACKUPS_SECTION, APPEARANCE_SECTION])] },
}
