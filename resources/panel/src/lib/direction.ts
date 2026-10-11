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

/** Whether the page reads right to left (the panels do; a test page may not). */
export function isRtl(): boolean {
  return document.documentElement.dir === 'rtl'
}

/** The physical side away from the inline-start edge — where tooltips and flyouts of the sidebar open (Radix's `side` is physical). */
export function contentSide(): 'left' | 'right' {
  return isRtl() ? 'left' : 'right'
}
