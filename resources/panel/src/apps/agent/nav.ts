import { BadgeCheck } from 'lucide-react'
import {
  APPEARANCE_SECTION,
  BOT_GROUP,
  BOT_SETTINGS,
  DASHBOARD,
  panelSettings,
  REFERRAL_SECTIONS,
  REVIEWS,
  SALES_GROUP,
  SETTINGS,
  SHOP_GROUP,
  SUPPORT,
  USER_SECTIONS,
  WEBSITE_SETTINGS,
  type PanelNav,
} from '@/components/shell/nav'

/*
 * An agent's panel: their bot's shop — the shop's pages, its bot's settings and its website's —, their account with the
 * main bot, and the panel's look.
 */
export const AGENT_NAV: PanelNav = {
  menu: [DASHBOARD, SUPPORT, REVIEWS, { id: 'account', title: 'حساب نمایندگی', to: '/account', icon: BadgeCheck }, SHOP_GROUP, SALES_GROUP, BOT_GROUP],
  sections: { users: USER_SECTIONS, referrals: REFERRAL_SECTIONS },
  settings: { item: SETTINGS, groups: [BOT_SETTINGS, WEBSITE_SETTINGS, panelSettings([APPEARANCE_SECTION])] },
}
