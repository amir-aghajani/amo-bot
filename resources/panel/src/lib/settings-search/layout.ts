/*
 * A query typed with the keyboard on the other language: the same keys on the standard Persian layout (ISIRI 9147) and
 * on QWERTY. «;dt» is «کیف» typed on an English layout, «سثففهدلس» is "settings" typed on a Persian one.
 */

/** Each QWERTY key (unshifted) and the Persian letter the same key types. */
const KEYS: [string, string][] = [
  ['q', 'ض'],
  ['w', 'ص'],
  ['e', 'ث'],
  ['r', 'ق'],
  ['t', 'ف'],
  ['y', 'غ'],
  ['u', 'ع'],
  ['i', 'ه'],
  ['o', 'خ'],
  ['p', 'ح'],
  ['[', 'ج'],
  [']', 'چ'],
  ['a', 'ش'],
  ['s', 'س'],
  ['d', 'ی'],
  ['f', 'ب'],
  ['g', 'ل'],
  ['h', 'ا'],
  ['j', 'ت'],
  ['k', 'ن'],
  ['l', 'م'],
  [';', 'ک'],
  ["'", 'گ'],
  ['z', 'ظ'],
  ['x', 'ط'],
  ['c', 'ز'],
  ['v', 'ر'],
  ['b', 'ذ'],
  ['n', 'د'],
  ['m', 'پ'],
  [',', 'و'],
]

/** Latin → Persian: every key above, its capital too, and the shifted keys a Persian word needs (Shift+C ژ, Shift+H آ) — the old layout's پ on `\` as well. */
const TO_PERSIAN = new Map<string, string>([...KEYS, ...KEYS.map(([key, letter]): [string, string] => [key.toUpperCase(), letter]), ['C', 'ژ'], ['H', 'آ'], ['\\', 'پ']])

/** Persian → Latin: each letter's key, the Arabic forms of ی and ک on theirs, ژ and آ on their shifted keys (lower case: the search ignores case). */
const TO_LATIN = new Map<string, string>([...KEYS.map(([key, letter]): [string, string] => [letter, key]), ['ي', 'd'], ['ك', ';'], ['ژ', 'c'], ['آ', 'h']])

/** The text as the same keys type it on the other layout: Latin to Persian and Persian to Latin, anything else as it is. */
export function swapLayout(text: string): string {
  let swapped = ''
  for (const character of text) swapped += TO_PERSIAN.get(character) ?? TO_LATIN.get(character) ?? character
  return swapped
}
