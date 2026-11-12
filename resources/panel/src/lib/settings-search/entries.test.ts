import { describe, expect, it } from 'vitest'
import { ADMIN_NAV } from '@/apps/admin/nav'
import { AGENT_NAV } from '@/apps/agent/nav'
import { isGroup, navOfShop, type PanelNav } from '@/components/shell/nav'
import { buildIndex, searchSettings, type SettingEntry, type SettingKind } from '@/lib/settings-search'
import { KEYWORDS } from '@/lib/settings-search/keywords'
import { GENERATED_SETTINGS } from '@/lib/settings-search/settings.generated'

/** The settings search's index of a panel (lib/settings-search/entries), from the panels' own navs and the generated settings. */

const ADMIN = buildIndex(ADMIN_NAV)
const AGENT = buildIndex(AGENT_NAV)

function entryOf(index: SettingEntry[], id: string): SettingEntry | undefined {
  return index.find((entry) => entry.id === id)
}

/** Every address a panel's nav opens: its menu's pages, their sections and its settings' sections. */
function addressesOf(nav: PanelNav): Set<string> {
  const addresses = new Set<string>()
  for (const entry of nav.menu) for (const item of isGroup(entry) ? entry.items : [entry]) addresses.add(item.to)
  for (const sections of Object.values(nav.sections)) for (const section of sections ?? []) addresses.add(section.to)
  for (const group of nav.settings.groups) for (const section of group.sections) addresses.add(section.to)
  return addresses
}

describe('buildIndex', () => {
  it('lists the menu’s pages, the sections, each settings group as a page, and the single settings', () => {
    const kinds: SettingKind[] = ['page', 'section', 'setting']
    for (const kind of kinds) expect(ADMIN.some((entry) => entry.kind === kind)).toBe(true)
    expect(entryOf(ADMIN, 'page:/plans')).toMatchObject({ kind: 'page', title: 'پلن‌ها', trail: ['فروشگاه'] })
    expect(entryOf(ADMIN, 'section:/users/groups')).toMatchObject({ kind: 'section', trail: ['فروش', 'کاربران'] })
    expect(entryOf(ADMIN, 'section:/bot-settings/wallet')).toMatchObject({ kind: 'section', title: 'کیف پول', trail: ['تنظیمات ربات'] })
    expect(entryOf(ADMIN, 'page:/website-settings')).toMatchObject({ kind: 'page', title: 'تنظیمات وب‌سایت' })
    expect(entryOf(ADMIN, 'setting:/bot-settings/wallet#wallet_topup_random_count')).toMatchObject({
      kind: 'setting',
      title: 'تعداد دکمه‌ها',
      path: '/bot-settings/wallet',
      trail: ['تنظیمات ربات', 'کیف پول', 'دکمه‌های مبلغ تصادفی'],
      anchor: 'wallet_topup_random_count',
    })
  })

  it('keeps a page’s first section — the page itself — and a card that is its section in the page’s or section’s entry', () => {
    expect(entryOf(ADMIN, 'section:/users')).toBeUndefined()
    expect(entryOf(ADMIN, 'page:/discounts')?.keywords).toContain('کدها')
    expect(entryOf(ADMIN, 'setting:/bot-settings/wallet#کیف-پول')).toBeUndefined()
    // The header's own title is another name of the section.
    expect(entryOf(ADMIN, 'section:/tests/settings')?.keywords).toContain('تنظیمات اکانت تست')
  })

  it('finds a setting without an id by its label, in the fold it sits in', () => {
    expect(entryOf(ADMIN, 'setting:/bot-settings/general#ربات-فعال-باشد')).toMatchObject({ anchorText: 'ربات فعال باشد' })
    expect(entryOf(ADMIN, 'setting:/settings/telegram#telegram_api_url')).toMatchObject({ anchor: 'telegram_api_url', fold: 'تنظیمات پیشرفته' })
  })

  it('gives an agent’s panel nothing of the owner’s own', () => {
    for (const path of ['/settings/telegram', '/settings/database', '/settings/schedule', '/agents/settings', '/servers']) {
      expect(AGENT.filter((entry) => entry.path === path)).toEqual([])
    }
    expect(entryOf(AGENT, 'section:/settings/backups')).toBeDefined()
    expect(entryOf(AGENT, 'setting:/bot-settings/wallet#wallet_topup_min')).toBeDefined()
  })

  it('gives an agent’s shop none of the main shop’s own sections and pages, in either panel', () => {
    const shop = buildIndex(navOfShop(ADMIN_NAV, false))
    expect(entryOf(ADMIN, 'section:/bot-settings/service-claims')).toBeDefined()
    for (const path of ['/bot-settings/service-claims', '/custom-services']) expect(shop.filter((entry) => entry.path === path)).toEqual([])
    expect(buildIndex(navOfShop(AGENT_NAV, false)).filter((entry) => entry.path === '/bot-settings/service-claims')).toEqual([])
  })

  it('gives every entry an id the server keeps favorites by, once', () => {
    for (const index of [ADMIN, AGENT]) {
      const ids = index.map((entry) => entry.id)
      expect(new Set(ids).size).toBe(ids.length)
      for (const id of ids) {
        expect(id).toMatch(/^(page|section|setting):\/\S*$/)
        expect(id.length).toBeLessThanOrEqual(200)
      }
    }
  })

  it('generated settings only for addresses some panel’s nav has', () => {
    const addresses = new Set([...addressesOf(ADMIN_NAV), ...addressesOf(AGENT_NAV)])
    for (const path of Object.keys(GENERATED_SETTINGS)) expect(addresses, path).toContain(path)
  })

  it('keeps keywords only for entries some panel’s index has', () => {
    const ids = new Set([...ADMIN, ...AGENT].map((entry) => entry.id))
    for (const id of Object.keys(KEYWORDS)) expect(ids, id).toContain(id)
  })
})

describe('searching a panel’s index', () => {
  const first = (query: string, index = ADMIN) => searchSettings(index, query)[0]?.entry.id

  it('finds the backups by the ways owners write it', () => {
    for (const query of ['بک آپ', 'بکاپ', 'بک‌آپ', 'backup', 'ریستور']) expect(first(query), query).toBe('section:/settings/backups')
    expect(first('بکاپ', AGENT)).toBe('section:/settings/backups')
  })

  it('finds the wallet typed on an English keyboard («;dt» is «کیف»)', () => {
    expect(first(';dt')).toBe('section:/bot-settings/wallet')
    expect(first(';dt m,g')).toBe('section:/bot-settings/wallet')
  })

  it('finds the main bot’s token, the least top-up and the schedule', () => {
    expect(first('توکن')).toBe('setting:/settings/telegram#telegram_token')
    expect(first('حداقل شارژ')).toBe('setting:/bot-settings/wallet#wallet_topup_min')
    expect(first('کرون')).toBe('section:/settings/schedule')
  })

  it('finds an exact keyword, keeping a 4-letter word’s typo out of the titles and the hints («کرون» is no «کردن»)', () => {
    const found = searchSettings(ADMIN, 'کرون').map((match) => match.entry.id)
    expect(found).toContain('setting:/settings/advanced#adv_cron_token')
    // «گرد کردن (تومان)» is in the index, a letter off «کرون» — no typo of a 4-letter word (lib/settings-search/search: typoFits).
    expect(entryOf(ADMIN, 'setting:/bot-settings/wallet#wallet_topup_random_round')).toMatchObject({ title: 'گرد کردن (تومان)' })
    expect(found).not.toContain('setting:/bot-settings/wallet#wallet_topup_random_round')
    expect(found).not.toContain('section:/bot-settings/general')
  })

  it('finds a schedule task the server names, and a report topic', () => {
    expect(first('همگام سازی سرویس ها')).toBe('section:/settings/schedule')
    expect(searchSettings(ADMIN, 'تاپیک').map((match) => match.entry.id)).toContain('section:/bot-settings/reports')
  })

  it('finds a setting by its section the owner’s way and its own words', () => {
    expect(first('بکاپ ارسال خودکار')).toBe('setting:/settings/backups#ارسال-خودکار-به-گروه-گزارش-ها')
  })
})
