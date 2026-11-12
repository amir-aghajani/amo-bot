/*
 * The settings search's contract (components/settings-search draws it, lib/settings-search/search finds in it): what an
 * entry of the index is, and what a match of a query is.
 */

/** What an entry opens: a page of the menu, a section of a sectioned page (a settings one, mostly), or one setting in it. */
export type SettingKind = 'page' | 'section' | 'setting'

/** One thing the search finds. */
export interface SettingEntry {
  /**
   * Stable across builds — what a favorite is kept by (the server stores these): `page:/plans`, `section:/bot-settings/wallet`,
   * `setting:/bot-settings/wallet#wallet_topup_min`.
   */
  id: string
  kind: SettingKind
  /** As the panel shows it: the page's or section's title, the field's label. */
  title: string
  /** Where it lives: the page's or section's address in the shop, as components/shell/nav names it (`/bot-settings/wallet`). */
  path: string
  /**
   * Where it sits, outermost first, for the line under the title: `['تنظیمات ربات', 'کیف پول']` — a setting's card
   * last, or after its card the titled part of it the setting is in (`['تنظیمات ربات', 'کیف پول', 'دکمه‌های مبلغ تصادفی']`).
   */
  trail: string[]
  /** The field's hint or the section's description, when the panel writes it as plain words. */
  hint?: string
  /** Other words it is looked for by: synonyms, the English term (`backup`, `cron`, `smtp`), how owners write it («بک آپ»). */
  keywords?: string[]
  /**
   * A setting's section's keywords, matched as its trail is: «بکاپ ارسال خودکار» finds the backups' «ارسال خودکار» —
   * the section named the owner's way, the setting by its words.
   */
  sectionKeywords?: string[]
  /** The DOM id of the control to scroll to, focus and mark on arrival (a Field's `id`). */
  anchor?: string
  /** When there is no id: the label's text, found in the shown section on arrival. */
  anchorText?: string
  /** The fold («تنظیمات پیشرفته», a Disclosure's label) the setting sits in, opened on arrival. */
  fold?: string
}

/** Character ranges [start, end) of a string that matched the query, for marking. */
export type Ranges = [number, number][]

/** An entry the query found, best first. */
export interface SettingMatch {
  entry: SettingEntry
  score: number
  /** What of the title — and of the trail's items, by index — matched, to mark. */
  marks: { title: Ranges; trail: Ranges[] }
}

/** What weighs on the order beside the words: the admin's favorites and what they opened lately, by entry id. */
export interface SearchOptions {
  favorites?: readonly string[]
  recent?: readonly string[]
  /** The most matches returned; all by default. */
  limit?: number
}
