/*
 * The settings search (components/settings-search draws it): buildIndex() lists what the panel on screen can open —
 * its pages, sections and single settings —, searchSettings() finds a query in that list, best first, with what to mark.
 */
export { buildIndex } from '@/lib/settings-search/entries'
export { searchSettings } from '@/lib/settings-search/search'
export type { SearchOptions, SettingEntry, SettingKind, SettingMatch, Ranges } from '@/lib/settings-search/types'
