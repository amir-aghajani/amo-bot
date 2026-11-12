/*
 * Text as the settings search compares it: Persian as one Persian however it was typed — Arabic letters, harakat and
 * tanwin, tatweel, half-spaces, Persian or Arabic digits —, Latin in lower case without accents, and punctuation a space
 * between words. Each character of the result remembers where in the original it came from, so a match is marked on
 * the text as shown, half-spaces and diacritics and all.
 */

/** Letters typed in their Arabic forms (or with a hamza or madda), as Persian writes them. */
const LETTERS: Record<string, string> = {
  '\u064a': '\u06cc', // ي → ی
  '\u0649': '\u06cc', // ى → ی
  '\u0626': '\u06cc', // ئ → ی
  '\u06d0': '\u06cc', // ې → ی
  '\u0643': '\u06a9', // ك → ک
  '\u06aa': '\u06a9', // ڪ → ک
  '\u0629': '\u0647', // ة → ه
  '\u06c0': '\u0647', // ۀ → ه
  '\u06be': '\u0647', // ھ → ه
  '\u06c1': '\u0647', // ہ → ه
  '\u0623': '\u0627', // أ → ا
  '\u0625': '\u0627', // إ → ا
  '\u0671': '\u0627', // ٱ → ا
  '\u0622': '\u0627', // آ → ا
  '\u0624': '\u0648', // ؤ → و
}

/** Dropped without a trace: harakat and tanwin, the superscript alef, tatweel, a hamza on its own, and the invisible direction marks. */
const DROPPED = /[\u064b-\u065f\u0670\u0640\u0621\u00ad\u200b\u200e\u200f\u202a-\u202e\u2066-\u2069\ufeff]/

/** Between words: the half-space (ZWNJ) and its joiner, like any space or punctuation. */
const WORD_CHARACTER = /[\p{L}\p{N}]/u
const MARK = /\p{M}/u

/** A normalised text, and for each of its characters the range of the original it came from. */
export interface Normalized {
  text: string
  /** Where each character of `text` starts in the original. */
  starts: number[]
  /** Where each character of `text` ends in the original (after a letter typed as two code units, or a ligature). */
  ends: number[]
}

/** One character of the original, as the characters it normalises to (none, one, or a ligature's few); a space for a separator. */
function charactersOf(character: string): string {
  const code = character.codePointAt(0) ?? 0
  if (code < 0x80) {
    if ((code >= 0x30 && code <= 0x39) || (code >= 0x61 && code <= 0x7a)) return character
    if (code >= 0x41 && code <= 0x5a) return character.toLowerCase()
    return ' '
  }
  if (code >= 0x6f0 && code <= 0x6f9) return String(code - 0x6f0) // Persian digits
  if (code >= 0x660 && code <= 0x669) return String(code - 0x660) // Arabic-Indic digits
  const letter = LETTERS[character]
  if (letter !== undefined) return letter
  if (DROPPED.test(character)) return ''
  if (code >= 0x600 && code <= 0x6ff) return WORD_CHARACTER.test(character) ? character : MARK.test(character) ? '' : ' '
  // Elsewhere: accents off (é → e), compatibility forms unfolded (ﻻ → لا, ﬁ → fi, full-width Latin), lower case.
  let result = ''
  for (const part of character.normalize('NFKD').toLowerCase()) {
    if (MARK.test(part) || DROPPED.test(part)) continue
    const mapped = LETTERS[part] ?? part
    result += WORD_CHARACTER.test(mapped) ? mapped : ' '
  }
  return result
}

/** The text normalised, with where each of its characters came from. */
export function normalizeMapped(original: string): Normalized {
  let text = ''
  const starts: number[] = []
  const ends: number[] = []
  let at = 0
  let space = false
  for (const character of original) {
    const end = at + character.length
    const parts = charactersOf(character)
    // A dropped mark (a haraka, tatweel) belongs to the letter before it: a mark on that letter covers it too.
    if (parts === '' && !space && ends.length > 0) ends[ends.length - 1] = end
    for (const part of parts) {
      if (part === ' ') {
        space = text.length > 0
        continue
      }
      if (space) {
        text += ' '
        starts.push(at)
        ends.push(at)
        space = false
      }
      text += part
      starts.push(at)
      ends.push(end)
    }
    at = end
  }

  return { text, starts, ends }
}

/** The text as the search compares it: «كيف‌پول ٢» → «کیف پول 2». */
export function normalize(text: string): string {
  return normalizeMapped(text).text
}

/** The words of a normalised text. */
export function wordsOf(normalized: string): string[] {
  return normalized === '' ? [] : normalized.split(' ')
}
