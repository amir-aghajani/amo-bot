/** Unicode's isolates, invisible: U+2068 FIRST STRONG ISOLATE opens one, U+2069 POP DIRECTIONAL ISOLATE closes it. */
const OPEN = String.fromCodePoint(0x2068)
const CLOSE = String.fromCodePoint(0x2069)

/**
 * `text` wrapped in invisible Unicode isolates — `<bdi>` for a plain string (a toast, a dialog's title, an
 * `aria-label`, the tab's title). Without it a Latin run in a Persian sentence loses a leading «@» to its far side
 * («agent_bot@»).
 */
export function isolate(text: string): string {
  return `${OPEN}${text}${CLOSE}`
}

/** A Telegram handle in a plain string, «@username», whole in a Persian sentence. In markup: `<bdi dir="ltr">@{username}</bdi>`. */
export function handleLabel(username: string): string {
  return isolate(`@${username}`)
}

/** A row's number in a plain string, «#12», whole in a Persian sentence. In markup: `<bdi dir="ltr">#{id}</bdi>`. */
export function idLabel(id: number): string {
  return isolate(`#${id}`)
}

/** A row's number in a sentence («#12», in either digits) not isolated yet. */
const ROW_NUMBER = /(?<!\u{2068})#[0-9۰-۹]+/gu

/**
 * Every row's number in a plain sentence isolated (`idLabel`'s way), so it reads «#12»: the server's words — a ledger
 * line's note («شارژ کیف پول (سفارش #4)»), a refusal — carry them bare, and right to left the bidirectional algorithm
 * would show «سفارش #12» as «12# سفارش».
 */
export function isolateIds(text: string): string {
  return text.replace(ROW_NUMBER, (id) => isolate(id))
}

/**
 * A run of markup in a sentence: a tag with what is glued to it (`<tg-emoji emoji-id="1">👍</tg-emoji>`), an entity
 * (`&lt;`), a variable (`%subscription%`), an attribute and its value (`href="…"`), a quoted value, and a bracket or a
 * quote on its own.
 */
const MARKUP = /<[^<>]*>(?:[^\s<>]*<[^<>]*>)*|&#?\w+;|%\w+%|[\w-]+="[^"]*"|"[^"]*"|[<>"']/g

/**
 * Every run of markup in a plain sentence isolated, so it reads as typed: in a Persian sentence the bidirectional
 * algorithm would show «</b>» as «<b/>», «&lt;» as «;lt&», a lone «>» as «<». Used for messages about bot texts.
 */
export function isolateMarkup(text: string): string {
  return text.replace(MARKUP, (run) => isolate(run))
}

/**
 * A Latin name and the version after it («MariaDB 10.3», «PHP 8.2») joined by a no-break space, so a line never breaks
 * between the two: a server's note in a Persian sentence would otherwise put the name at a line's end, its version on
 * the next.
 */
export function unbrokenVersions(text: string): string {
  return text.replace(/([A-Za-z][\w.+-]*) (?=\d)/g, '$1 ')
}

/** Whether the page reads right to left (the panels do; a test page may not). */
export function isRtl(): boolean {
  return document.documentElement.dir === 'rtl'
}

/** The physical side away from the inline-start edge — where tooltips and flyouts of the sidebar open (Radix's `side` is physical). */
export function contentSide(): 'left' | 'right' {
  return isRtl() ? 'left' : 'right'
}
