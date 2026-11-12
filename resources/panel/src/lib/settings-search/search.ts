import { swapLayout } from '@/lib/settings-search/layout'
import { normalize, normalizeMapped, wordsOf, type Normalized } from '@/lib/settings-search/normalize'
import type { Ranges, SearchOptions, SettingEntry, SettingKind, SettingMatch } from '@/lib/settings-search/types'

/*
 * The settings search's matching and ranking. A query is cut at its spaces into terms, and every term must be found
 * (AND) — as typed or, at a small cost, as the same keys type it on the other keyboard layout. A term's words are
 * found in an entry's title, keywords, trail and hint — whole, as a word's start, inside a word or across a half-space,
 * or with a typo —, the title weighing most; the word still being typed (the last) is found by its start even with a
 * typo in it. An index is prepared once (normalised, its words in one vocabulary) and every keystroke only compares.
 */

/**
 * How well a term's word matched a text, worst to best: a word one or two edits away (Damerau–Levenshtein: a letter
 * wrong, missing, extra, or two swapped), inside a word or across a half-space («کیفپول» in «کیف‌پول»), a word's start,
 * the word itself.
 */
const Level = { None: 0, Fuzzy: 1, Infix: 2, Prefix: 3, Exact: 4 } as const

type Level = (typeof Level)[keyof typeof Level]

/**
 * What a match is worth, by where it was found and how well: the title's exact word most. A typo in a hint is worth
 * nothing — a hint is sentences, where a word one letter off («کردن» for «کرون») is found by chance in most of them.
 */
const WEIGHTS = {
  title: [0, 40, 50, 80, 100],
  keyword: [0, 22, 30, 40, 48],
  trail: [0, 13, 18, 26, 30],
  hint: [0, 0, 10, 15, 18],
} as const satisfies Record<string, readonly number[]>

/** The whole query is the title (+), the title starts with it (+), or it is one of the keywords (+). */
const TITLE_IS_QUERY = 120
const TITLE_STARTS_WITH_QUERY = 60
const KEYWORD_IS_QUERY = 50
/**
 * Every term found in the title: the words belong together — and more when they are all the title's words («تمدد خودکار»,
 * «تمدید خودکار»). Neither when a word is in the title only by a typo: a title a letter off the query stays under an
 * exact keyword.
 */
const ALL_IN_TITLE = 20
const TITLE_COVERED = 40
const BY_A_TYPO = 0
/** A term found as typed on the other keyboard layout is worth this share of the same found as typed — less 1. */
const SWAPPED = 0.9
/** What a favorite gains, and the most an entry opened lately gains (less the further back it was opened). */
const FAVORITE = 15
const RECENT = 10
const RECENT_LEAST = 4

/**
 * Equal scores: the section first, then the single setting, then the page. A settings search is mostly asked for a
 * subject, and a section opens the whole of it — the setting looked for among its neighbours, with their context —;
 * a single setting is the exact place but the narrowest; a page (a list, a report) is the least what settings are
 * searched for, yet still found. Then the panel's own order (the index's).
 */
const KIND_ORDER: Record<SettingKind, number> = { section: 0, setting: 1, page: 2 }

/** A searchable text of an entry, normalised. */
interface Text {
  text: string
  /** The text without its spaces, for terms typed without a half-space («کیفپول»). */
  compact: string
  /** Each compact character's place in `text`. */
  compactAt: number[]
  /** Each word's vocabulary id, and where it starts in `text`. */
  words: { id: number; start: number; length: number }[]
  /** Where `text`'s characters came from in the original: for marks (the title's and the trail's). */
  original?: Pick<Normalized, 'starts' | 'ends'>
}

interface PreparedEntry {
  entry: SettingEntry
  order: number
  title: Text
  trail: Text[]
  keywords: Text[]
  /** A setting's section's keywords, matched as its trail is. */
  sectionKeywords: Text[]
  hint: Text | null
}

interface PreparedIndex {
  entries: PreparedEntry[]
  vocabulary: string[]
}

const prepared = new WeakMap<readonly SettingEntry[], PreparedIndex>()

/** The index ready to search: normalised once per index array. */
function prepare(index: readonly SettingEntry[]): PreparedIndex {
  const cached = prepared.get(index)
  if (cached) return cached

  const vocabulary: string[] = []
  const ids = new Map<string, number>()
  const textOf = (original: string, marks: boolean): Text => {
    const normalized = normalizeMapped(original)
    const { text } = normalized
    const words: Text['words'] = []
    let start = 0
    for (const word of wordsOf(text)) {
      let id = ids.get(word)
      if (id === undefined) {
        id = vocabulary.length
        vocabulary.push(word)
        ids.set(word, id)
      }
      words.push({ id, start, length: word.length })
      start += word.length + 1
    }
    let compact = ''
    const compactAt: number[] = []
    for (let i = 0; i < text.length; i++) {
      if (text[i] === ' ') continue
      compact += text[i]
      compactAt.push(i)
    }
    return { text, compact, compactAt, words, original: marks ? normalized : undefined }
  }

  const result: PreparedIndex = {
    vocabulary,
    entries: index.map((entry, order) => ({
      entry,
      order,
      title: textOf(entry.title, true),
      trail: entry.trail.map((part) => textOf(part, true)),
      keywords: (entry.keywords ?? []).map((keyword) => textOf(keyword, false)),
      sectionKeywords: (entry.sectionKeywords ?? []).map((keyword) => textOf(keyword, false)),
      hint: entry.hint ? textOf(entry.hint, false) : null,
    })),
  }
  prepared.set(index, result)

  return result
}

/* Typos. */

/**
 * The fewest edits between `a` and the start of `b` that is about as long (when `prefix`), or all of `b`: restricted
 * Damerau–Levenshtein (a transposition of neighbours is one edit) — more than `most`, as `most + 1`.
 */
function distance(a: string, b: string, most: number, prefix: boolean): number {
  const width = prefix ? Math.min(b.length, a.length + most) : b.length
  if (!prefix && Math.abs(a.length - b.length) > most) return most + 1
  let before = new Int32Array(width + 1)
  let previous = Int32Array.from({ length: width + 1 }, (_, j) => j)
  let current = new Int32Array(width + 1)
  for (let i = 1; i <= a.length; i++) {
    current[0] = i
    let rowBest = i
    for (let j = 1; j <= width; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1
      let value = Math.min(previous[j]! + 1, current[j - 1]! + 1, previous[j - 1]! + cost)
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) value = Math.min(value, before[j - 2]! + 1)
      current[j] = value
      if (value < rowBest) rowBest = value
    }
    if (rowBest > most) return most + 1
    ;[before, previous, current] = [previous, current, before]
  }
  if (!prefix) return previous[width]!
  // As a start of `b`: the best of the lengths about as long as `a`.
  let best = most + 1
  for (let j = Math.max(0, a.length - most); j <= width; j++) best = Math.min(best, previous[j]!)
  return best
}

/** The edits a word of this length may be off by: none under 4 letters, 1 from 4, 2 from 8. */
function typosAllowed(length: number): number {
  return length >= 8 ? 2 : length >= 4 ? 1 : 0
}

/**
 * Whether a typo may relate two words of these lengths. A 4-letter word may only have missed a letter («تمدد» for
 * «تمدید» — and, being typed, for a word's start one letter longer): Persian's 4-letter words are a letter apart from
 * each other everywhere («کرون», «کردن», «کرده»).
 */
function typoFits(word: number, other: number): boolean {
  return word >= 5 || other > word
}

/* Queries. */

/** A word of the query, and how each word of the index relates to it. */
interface Word {
  text: string
  /** Still being typed: a typo is forgiven in a word's start too, not only in the whole word. */
  last: boolean
  relations: Uint8Array
}

/** One way to read a term: its words as typed, or as typed on the other layout. */
interface Reading {
  words: Word[]
  swapped: boolean
}

/** A term of the query (between spaces) and its readings. */
interface Term {
  readings: Reading[]
}

/** The query whole, normalised, as typed and as swapped: for the phrase bonuses. */
interface Phrase {
  text: string
  compact: string
  swapped: boolean
}

function relationsOf(word: string, last: boolean, vocabulary: string[]): Uint8Array {
  const relations = new Uint8Array(vocabulary.length)
  const most = typosAllowed(word.length)
  for (let i = 0; i < vocabulary.length; i++) {
    const other = vocabulary[i]!
    if (other === word) relations[i] = Level.Exact
    else if (other.startsWith(word)) relations[i] = Level.Prefix
    else if (most > 0 && Math.abs(other.length - word.length) <= most && typoFits(word.length, other.length) && distance(word, other, most, false) <= most) relations[i] = Level.Fuzzy
    else if (most > 0 && last && other.length > word.length && (word.length >= 5 ? distance(word, other, most, true) : distance(word, other.slice(0, word.length + 1), most, false)) <= most)
      relations[i] = Level.Fuzzy
  }
  return relations
}

function letters(words: string[]): number {
  return words.reduce((sum, word) => sum + word.length, 0)
}

/** The query's terms, each read as typed and as swapped (when the swap changes it), words related to the index's. */
function termsOf(query: string, vocabulary: string[]): { terms: Term[]; phrases: Phrase[] } {
  const chunks = query.split(/\s+/).filter((chunk) => chunk !== '')
  const readings = chunks.map((chunk) => {
    const typed = wordsOf(normalize(chunk))
    const swapped = wordsOf(normalize(swapLayout(chunk)))
    const ways: { words: string[]; swapped: boolean }[] = []
    if (typed.length > 0) ways.push({ words: typed, swapped: false })
    // Swapped, a word that loses letters (to keys that type punctuation: «بک» → "f;") was not typed on the other layout.
    if (swapped.length > 0 && swapped.join(' ') !== typed.join(' ') && letters(swapped) >= letters(typed)) ways.push({ words: swapped, swapped: true })
    return ways
  })
  const terms: Term[] = []
  readings.forEach((ways, index) => {
    if (ways.length === 0) return
    const lastTerm = readings.slice(index + 1).every((rest) => rest.length === 0)
    terms.push({
      readings: ways.map(({ words, swapped }) => ({
        swapped,
        words: words.map((text, at) => {
          const last = lastTerm && at === words.length - 1
          return { text, last, relations: relationsOf(text, last, vocabulary) }
        }),
      })),
    })
  })

  const phrases: Phrase[] = []
  for (const swapped of [false, true]) {
    const text = readings
      .map((ways) => ways.find((way) => way.swapped === swapped) ?? ways.find((way) => !way.swapped))
      .filter((way) => way !== undefined)
      .flatMap((way) => way.words)
      .join(' ')
    if (text !== '' && !phrases.some((phrase) => phrase.text === text)) phrases.push({ text, compact: text.replaceAll(' ', ''), swapped })
  }

  return { terms, phrases }
}

/* Matching. */

/**
 * How well a word of the query is in a text: its best word, else inside a word (two letters at least), or across a
 * half-space (four at least: shorter, it is found by chance across any two words), else nothing.
 */
function levelIn(text: Text, word: Word): Level {
  let best: Level = Level.None
  for (const { id } of text.words) {
    const relation = word.relations[id]! as Level
    if (relation > best) {
      best = relation
      if (best === Level.Exact) return best
    }
  }
  if (best < Level.Infix && word.text.length >= 2 && (text.text.includes(word.text) || (word.text.length >= 4 && text.compact.includes(word.text)))) return Level.Infix

  return best
}

/** The best a word of the query does in the entry, by place: what it scores, and whether it was in the title. */
function wordScore(entry: PreparedEntry, word: Word): { score: number; inTitle: boolean; typo: boolean } {
  const titleLevel = levelIn(entry.title, word)
  const title = WEIGHTS.title[titleLevel]
  const scores = [
    title,
    Math.max(0, ...entry.keywords.map((keyword) => WEIGHTS.keyword[levelIn(keyword, word)])),
    Math.max(0, ...entry.trail.map((part) => WEIGHTS.trail[levelIn(part, word)])),
    entry.hint ? WEIGHTS.hint[levelIn(entry.hint, word)] : 0,
  ]
  // The section's keywords count as the trail does, and add nothing to the bonus of being found in several places.
  const context = Math.max(0, ...entry.sectionKeywords.map((keyword) => WEIGHTS.trail[levelIn(keyword, word)]))
  const best = Math.max(...scores)
  if (best === 0) return { score: context, inTitle: false, typo: false }
  // Found in more places than one, a little more: a keyword beside the title tells two equal titles apart.
  const rest = scores.reduce((sum, score) => sum + score, 0) - best

  return { score: Math.max(best, context) + rest / 10, inTitle: title > 0, typo: titleLevel === Level.Fuzzy }
}

/** The best reading of a term in the entry; null when no reading finds every word of it. */
function termScore(entry: PreparedEntry, term: Term): { score: number; inTitle: boolean; typo: boolean; reading: Reading } | null {
  let best: { score: number; inTitle: boolean; typo: boolean; reading: Reading } | null = null
  for (const reading of term.readings) {
    let score = 0
    let inTitle = true
    let typo = false
    for (const word of reading.words) {
      const found = wordScore(entry, word)
      if (found.score === 0) {
        score = -1
        break
      }
      score += found.score
      inTitle &&= found.inTitle
      typo ||= found.typo
    }
    if (score < 0) continue
    if (reading.swapped) score = score * SWAPPED - 1
    if (!best || score > best.score) best = { score, inTitle, typo, reading }
  }

  return best
}

/** The phrase bonuses: the query is the title, starts it, or is a keyword. */
function phraseScore(entry: PreparedEntry, phrases: Phrase[]): number {
  let best = 0
  for (const phrase of phrases) {
    const { title } = entry
    let score = 0
    if (title.text === phrase.text || title.compact === phrase.compact) score += TITLE_IS_QUERY
    else if (title.text.startsWith(phrase.text) || title.compact.startsWith(phrase.compact)) score += TITLE_STARTS_WITH_QUERY
    if (entry.keywords.some((keyword) => keyword.text === phrase.text || keyword.compact === phrase.compact)) score += KEYWORD_IS_QUERY
    if (phrase.swapped) score *= SWAPPED
    best = Math.max(best, score)
  }

  return best
}

/* Marks. */

/** Where a word of the query is in a text, in the text's own characters; null when it is not. */
function rangeIn(text: Text, word: Word): [number, number] | null {
  let best: { level: Level; range: [number, number] } | null = null
  for (const { id, start, length } of text.words) {
    const relation = word.relations[id]! as Level
    if (relation === Level.None || (best && best.level >= relation)) continue
    const end = relation === Level.Exact ? start + length : start + Math.min(length, word.text.length)
    best = { level: relation, range: [start, relation === Level.Fuzzy ? start + length : end] }
    if (relation === Level.Exact) break
  }
  if (best && best.level >= Level.Prefix) return best.range
  if (word.text.length >= 2) {
    const at = text.text.indexOf(word.text)
    if (at >= 0) return [at, at + word.text.length]
    const compactAt = text.compact.indexOf(word.text)
    if (compactAt >= 0) return [text.compactAt[compactAt]!, text.compactAt[compactAt + word.text.length - 1]! + 1]
  }

  return best ? best.range : null
}

/** The ranges of the original string the words matched, merged and in order. */
function marksIn(text: Text, words: Word[]): Ranges {
  const original = text.original
  if (!original) return []
  const ranges: Ranges = []
  for (const word of words) {
    const range = rangeIn(text, word)
    if (!range || range[1] <= range[0]) continue
    ranges.push([original.starts[range[0]]!, original.ends[range[1] - 1]!])
  }
  ranges.sort((a, b) => a[0] - b[0])
  const merged: Ranges = []
  for (const range of ranges) {
    const last = merged[merged.length - 1]
    if (last && range[0] <= last[1]) last[1] = Math.max(last[1], range[1])
    else merged.push([range[0], range[1]])
  }

  return merged
}

/** What a favorite or an entry opened lately gains. */
function boostOf(id: string, favorites: ReadonlySet<string>, recent: readonly string[]): number {
  let boost = favorites.has(id) ? FAVORITE : 0
  const at = recent.indexOf(id)
  if (at >= 0) boost += Math.max(RECENT_LEAST, RECENT - at)

  return boost
}

/** The entries the query finds, best first; an empty query finds none. */
export function searchSettings(index: readonly SettingEntry[], query: string, options: SearchOptions = {}): SettingMatch[] {
  const { entries, vocabulary } = prepare(index)
  const { terms, phrases } = termsOf(query, vocabulary)
  if (terms.length === 0) return []
  const favorites = new Set(options.favorites ?? [])
  const recent = options.recent ?? []

  const found: { match: SettingMatch; kind: number; order: number }[] = []
  for (const entry of entries) {
    let score = 0
    let allInTitle = true
    let typo = false
    let wordCount = 0
    const words: Word[] = []
    for (const term of terms) {
      const best = termScore(entry, term)
      if (!best) {
        score = -1
        break
      }
      score += best.score
      allInTitle &&= best.inTitle
      typo ||= best.typo
      wordCount += best.reading.words.length
      words.push(...best.reading.words)
    }
    if (score < 0) continue
    const covered = allInTitle && wordCount >= entry.title.words.length
    const together = (allInTitle ? ALL_IN_TITLE : 0) + (covered ? TITLE_COVERED : 0)
    score += phraseScore(entry, phrases) + together * (typo ? BY_A_TYPO : 1) + boostOf(entry.entry.id, favorites, recent)
    found.push({
      match: { entry: entry.entry, score, marks: { title: marksIn(entry.title, words), trail: entry.trail.map((part) => marksIn(part, words)) } },
      kind: KIND_ORDER[entry.entry.kind],
      order: entry.order,
    })
  }
  found.sort((a, b) => b.match.score - a.match.score || a.kind - b.kind || a.order - b.order)
  const matches = found.map(({ match }) => match)

  return options.limit === undefined ? matches : matches.slice(0, options.limit)
}
