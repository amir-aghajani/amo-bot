import { describe, expect, it } from 'vitest'
import { ADMIN_NAV, AGENCY_SECTIONS, PANEL_SETTINGS_SECTIONS } from '@/apps/admin/nav'
import { AGENT_NAV } from '@/apps/agent/nav'
import {
  APPEARANCE_SECTION,
  areaFor,
  BOT_SETTINGS_SECTIONS,
  DASHBOARD,
  inSettings,
  isGroup,
  isNavItemActive,
  isSectionCurrent,
  pageTitle,
  REFERRAL_SECTIONS,
  REVIEWS,
  SUPPORT,
  USER_SECTIONS,
  WEBSITE_SETTINGS_SECTIONS,
  type PanelNav,
} from '@/components/shell/nav'
import { pageOf } from '@/lib/config'

/*
 * Each panel's navigation (components/shell/nav, apps/admin/nav, apps/agent/nav): what the sidebar shows on a page —
 * the menu, or the page's own column of sections, or the settings' —, what the page is called, and that an agent's
 * panel names none of the owner's own pages.
 */

/** Every address a panel's navigation links to. */
function addresses(nav: PanelNav): string[] {
  return [
    ...nav.menu.flatMap((entry) => (isGroup(entry) ? entry.items : [entry]).map((item) => item.to)),
    ...Object.values(nav.sections).flatMap((sections) => sections?.map((section) => section.to) ?? []),
    nav.settings.item.to,
    ...nav.settings.groups.flatMap((group) => group.sections.map((section) => section.to)),
  ]
}

/** The sections of a panel's own settings, «تنظیمات پنل». */
function panelSettingsOf(nav: PanelNav): string[] | undefined {
  return nav.settings.groups.find((group) => group.id === 'panel')?.sections.map((section) => section.value)
}

describe('the sidebar’s column', () => {
  it('lists a sectioned page’s sections on any address of it', () => {
    expect(areaFor(ADMIN_NAV, '/users')?.groups[0]?.sections).toEqual(USER_SECTIONS)
    expect(areaFor(ADMIN_NAV, '/users/groups')?.id).toBe('users')
    expect(areaFor(AGENT_NAV, '/referrals/commissions')?.groups[0]?.sections).toEqual(REFERRAL_SECTIONS)
    expect(areaFor(ADMIN_NAV, '/agents/levels')?.groups[0]?.sections).toEqual(AGENCY_SECTIONS)
  })

  it('is the menu on a page of one section — an agent’s broadcasts are one', () => {
    expect(areaFor(ADMIN_NAV, '/')).toBeNull()
    expect(areaFor(ADMIN_NAV, '/plans')).toBeNull()
    expect(areaFor(ADMIN_NAV, '/broadcasts/gifts')?.id).toBe('broadcasts')
    expect(areaFor(AGENT_NAV, '/broadcasts')).toBeNull()
  })

  it('is the settings’ own on every settings page: the bot’s, the website’s, and the panel’s own beside them', () => {
    const owner = areaFor(ADMIN_NAV, '/settings/database')
    expect(owner?.id).toBe('settings')
    expect(owner?.groups.map((group) => group.id)).toEqual(['bot', 'website', 'panel'])
    expect(areaFor(AGENT_NAV, '/bot-settings/qr')?.groups.map((group) => group.id)).toEqual(['bot', 'website', 'panel'])
    expect(inSettings(ADMIN_NAV, '/bot-settings/general')).toBe(true)
    expect(inSettings(AGENT_NAV, '/settings/appearance')).toBe(true)
  })

  it('holds the shop’s website in both panels — an agent’s shop has one too —, its connection, each way in, then its admins', () => {
    expect(WEBSITE_SETTINGS_SECTIONS.map((section) => section.value)).toEqual(['connection', 'telegram', 'google', 'email', 'reviews', 'staff'])
    for (const nav of [ADMIN_NAV, AGENT_NAV]) {
      expect(nav.settings.groups.find((group) => group.id === 'website')?.sections).toEqual(WEBSITE_SETTINGS_SECTIONS)
      for (const section of WEBSITE_SETTINGS_SECTIONS) {
        expect(areaFor(nav, section.to)?.id).toBe('settings')
        expect(inSettings(nav, section.to)).toBe(true)
      }
    }
  })

  it('holds the panel’s look among every panel’s own settings, and the owner’s email, login and AmoBot’s update among the owner’s', () => {
    expect(panelSettingsOf(ADMIN_NAV)).toEqual(['telegram', 'database', 'app', 'mail', 'login', 'appearance', 'update', 'advanced'])
    expect(panelSettingsOf(AGENT_NAV)).toEqual(['appearance'])
  })
})

describe('a subject’s page under a sectioned page', () => {
  it('is in the page’s column, its first section the one on screen — a customer’s page is the users’ «کاربران»', () => {
    const [users, groups] = USER_SECTIONS
    if (!users || !groups) throw new Error('The users page has two sections.')

    for (const nav of [ADMIN_NAV, AGENT_NAV]) expect(areaFor(nav, '/users/12')?.id).toBe('users')
    expect(isSectionCurrent(USER_SECTIONS, users, '/users/12')).toBe(true)
    expect(isSectionCurrent(USER_SECTIONS, groups, '/users/12')).toBe(false)
    expect(isSectionCurrent(USER_SECTIONS, users, '/users/groups')).toBe(false)
    expect(isSectionCurrent(USER_SECTIONS, groups, '/users/groups')).toBe(true)
    expect(isSectionCurrent(USER_SECTIONS, users, '/users')).toBe(true)
  })

  it('is no settings section’s: their first one has an address of its own, not the page’s', () => {
    const [general] = BOT_SETTINGS_SECTIONS
    if (!general) throw new Error('The bot settings have sections.')

    expect(isSectionCurrent(BOT_SETTINGS_SECTIONS, general, '/bot-settings/general/7')).toBe(false)
  })
})

describe('a page’s name', () => {
  it('is its menu item’s or its settings group’s — and none at an address no page has, whose not-found page names itself', () => {
    expect(pageTitle(ADMIN_NAV, '/')).toBe('داشبورد')
    expect(pageTitle(ADMIN_NAV, '/servers/4')).toBe('سرورها')
    expect(pageTitle(ADMIN_NAV, '/users/groups')).toBe('کاربران')
    expect(pageTitle(AGENT_NAV, '/users/12')).toBe('کاربران')
    expect(pageTitle(ADMIN_NAV, '/settings/telegram')).toBe('تنظیمات پنل')
    expect(pageTitle(AGENT_NAV, '/bot-settings/general')).toBe('تنظیمات ربات')
    expect(pageTitle(ADMIN_NAV, '/website-settings/connection')).toBe('تنظیمات وب‌سایت')
    expect(pageTitle(AGENT_NAV, '/website-settings/telegram')).toBe('تنظیمات وب‌سایت')
    expect(pageTitle(AGENT_NAV, '/website-settings/email')).toBe('تنظیمات وب‌سایت')
    expect(pageTitle(ADMIN_NAV, '/website-settings/staff')).toBe('تنظیمات وب‌سایت')
    expect(pageTitle(ADMIN_NAV, '/settings/mail')).toBe('تنظیمات پنل')
    expect(pageTitle(AGENT_NAV, '/settings/appearance')).toBe('تنظیمات پنل')
    expect(pageTitle(AGENT_NAV, '/account')).toBe('حساب نمایندگی')
    expect(pageTitle(AGENT_NAV, '/nowhere')).toBeNull()
    expect(pageTitle(ADMIN_NAV, '/nowhere/12')).toBeNull()
  })
})

describe('the support tickets', () => {
  it('are a page of their own right after the dashboard in both panels, the tickets waiting on an answer counted beside it', () => {
    for (const nav of [ADMIN_NAV, AGENT_NAV]) {
      expect(nav.menu.slice(0, 2)).toEqual([DASHBOARD, SUPPORT])
      expect(pageTitle(nav, '/tickets')).toBe('پشتیبانی')
      // A ticket's own page is the support page's, with the menu beside it.
      expect(pageTitle(nav, '/tickets/12')).toBe('پشتیبانی')
      expect(areaFor(nav, '/tickets/12')).toBeNull()
    }
    expect(SUPPORT).toMatchObject({ to: '/tickets', badge: 'open_tickets' })
  })
})

describe('the customers’ reviews', () => {
  it('are a page of their own after the support tickets in both panels, the reviews waiting on support counted beside it', () => {
    for (const nav of [ADMIN_NAV, AGENT_NAV]) {
      expect(nav.menu.slice(0, 3)).toEqual([DASHBOARD, SUPPORT, REVIEWS])
      expect(pageTitle(nav, '/reviews')).toBe('نظرات')
      expect(areaFor(nav, '/reviews')).toBeNull()
    }
    expect(REVIEWS).toMatchObject({ to: '/reviews', badge: 'pending_reviews' })
  })
})

describe('a menu item', () => {
  it('is on screen for its page and the addresses under it — the dashboard for its own alone', () => {
    const plans = { to: '/plans' }
    expect(isNavItemActive(plans, '/plans')).toBe(true)
    expect(isNavItemActive(plans, '/plans/7')).toBe(true)
    expect(isNavItemActive(plans, '/plans-archive')).toBe(false)
    expect(isNavItemActive({ to: '/', end: true }, '/')).toBe(true)
    expect(isNavItemActive({ to: '/', end: true }, '/plans')).toBe(false)
  })
})

describe('the panels’ trees', () => {
  it('give an agent no link to the owner’s own pages — of the panel’s settings, the look alone is theirs', () => {
    const linked = addresses(AGENT_NAV)
    const ownersSettings = PANEL_SETTINGS_SECTIONS.filter((section) => section !== APPEARANCE_SECTION).map((section) => section.to)
    for (const owners of ['/servers', '/agents', '/broadcasts/gifts', ...ownersSettings]) {
      expect(linked.filter((to) => to === owners || to.startsWith(`${owners}/`))).toEqual([])
    }
    expect(linked).toContain('/account')
    expect(linked).toContain(APPEARANCE_SECTION.to)
  })

  it('put each sectioned page’s first section at the page’s own address, and every section under that page', () => {
    for (const nav of [ADMIN_NAV, AGENT_NAV]) {
      for (const entry of nav.menu) {
        for (const item of isGroup(entry) ? entry.items : [entry]) {
          const sections = nav.sections[item.id]
          if (!sections) continue
          expect(sections[0]?.to, item.id).toBe(item.to)
          expect(new Set(sections.map((section) => pageOf(section.to))), item.id).toEqual(new Set([item.to]))
        }
      }
    }
  })

  it('give every settings section an address of its own', () => {
    for (const nav of [ADMIN_NAV, AGENT_NAV]) {
      const sections = nav.settings.groups.flatMap((group) => group.sections.map((section) => section.to))
      expect(new Set(sections).size).toBe(sections.length)
      for (const to of sections) expect(to).not.toBe(pageOf(to))
    }
  })
})
