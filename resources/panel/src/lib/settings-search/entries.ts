import { isGroup, type NavItem, type NavSection, type PanelNav } from '@/components/shell/nav'
import { pageOf } from '@/lib/config'
import { KEYWORDS } from '@/lib/settings-search/keywords'
import { normalize } from '@/lib/settings-search/normalize'
import { GENERATED_SETTINGS } from '@/lib/settings-search/settings.generated'
import type { SettingEntry } from '@/lib/settings-search/types'

/*
 * The settings search's index for the panel on screen, drawn from its nav — so it holds what that panel and that shop
 * have, and nothing else: the menu's pages and their sections, each settings group as a page and its sections, and the
 * single settings of every section (settings.generated, read from the sections' source), in the panel's order.
 */

/** A page or section's place: its title, and the trail its entries' trails start with. */
interface Place {
  title: string
  trail: string[]
}

/** An entry with its keywords (keywords.ts, and the page header's own title for a section) and its section's for a setting. */
function entryWith(entry: SettingEntry, extra: readonly string[] = [], sectionKeywords: readonly string[] = []): SettingEntry {
  const keywords = [...(KEYWORDS[entry.id] ?? []), ...extra]
  const result: SettingEntry = { ...entry }
  if (keywords.length > 0) result.keywords = keywords
  if (sectionKeywords.length > 0) result.sectionKeywords = [...sectionKeywords]
  for (const key of Object.keys(result) as (keyof SettingEntry)[]) if (result[key] === undefined) delete result[key]

  return result
}

/** Two titles the same as the search reads them («کیف پول», «کیف‌پول»). */
function same(a: string, b: string): boolean {
  return normalize(a) === normalize(b)
}

/** Everything the panel on screen can open: its menu's pages, its sectioned pages' sections, its settings' sections and settings. */
export function buildIndex(nav: PanelNav): SettingEntry[] {
  const entries: SettingEntry[] = []
  const ids = new Set<string>()
  const add = (entry: SettingEntry) => {
    if (ids.has(entry.id)) return
    ids.add(entry.id)
    entries.push(entry)
  }

  /** A section's entry, then its settings'. */
  const addSection = (section: NavSection, place: Place) => {
    const generated = GENERATED_SETTINGS[section.to]
    const entry = entryWith(
      { id: `section:${section.to}`, kind: 'section', title: section.title, path: section.to, trail: place.trail, hint: generated?.hint },
      generated?.alias && !same(generated.alias, section.title) ? [generated.alias] : [],
    )
    add(entry)
    addSettings(section.to, { title: section.title, trail: place.trail }, entry.keywords ?? [])
  }

  /** A page's entry — with its first section's words when that section is the page itself —, then its settings'. */
  const addPage = (item: NavItem, trail: string[], first: NavSection | undefined) => {
    const generated = GENERATED_SETTINGS[item.to]
    const extra = [first?.title, generated?.alias].filter((title): title is string => title !== undefined && !same(title, item.title))
    const entry = entryWith({ id: `page:${item.to}`, kind: 'page', title: item.title, path: item.to, trail, hint: generated?.hint }, extra)
    add(entry)
    addSettings(item.to, { title: item.title, trail }, entry.keywords ?? [])
  }

  /** The single settings of a section (or of a page with a settings card), under it. */
  const addSettings = (path: string, place: Place, sectionKeywords: readonly string[]) => {
    for (const setting of GENERATED_SETTINGS[path]?.settings ?? []) {
      // The card that is the section itself (its title the section's) is the section's entry already.
      if (!setting.card && same(setting.title, place.title)) continue
      const trail = [...place.trail, place.title]
      if (setting.card && !same(setting.card, place.title)) trail.push(setting.card)
      if (setting.part) trail.push(setting.part)
      add(
        entryWith(
          {
            id: `setting:${path}#${setting.key}`,
            kind: 'setting',
            title: setting.title,
            path,
            trail,
            hint: setting.hint,
            anchor: setting.anchor,
            anchorText: setting.anchor ? undefined : setting.title,
            fold: setting.fold,
          },
          [],
          sectionKeywords,
        ),
      )
    }
  }

  for (const entry of nav.menu) {
    const items = isGroup(entry) ? entry.items : [entry]
    const trail = isGroup(entry) ? [entry.title] : []
    for (const item of items) {
      const sections = nav.sections[item.id] ?? []
      const first = sections[0]?.to === item.to ? sections[0] : undefined
      addPage(item, trail, first)
      for (const section of sections) if (section !== first) addSection(section, { title: section.title, trail: [...trail, item.title] })
    }
  }

  for (const group of nav.settings.groups) {
    const first = group.sections[0]
    if (!first) continue
    // The group opens at its first section: a page of its own («تنظیمات وب‌سایت»), the settings' link's words on the bot's.
    const page = pageOf(first.to)
    add(entryWith({ id: `page:${page}`, kind: 'page', title: group.title, path: page, trail: [] }, page === nav.settings.item.to ? [nav.settings.item.title] : []))
    for (const section of group.sections) addSection(section, { title: section.title, trail: [group.title] })
  }

  return entries
}
