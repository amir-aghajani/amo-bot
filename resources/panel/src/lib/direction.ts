/** Unicode's isolates, invisible: U+2068 FIRST STRONG ISOLATE opens one, U+2069 POP DIRECTIONAL ISOLATE closes it. */
const OPEN = String.fromCodePoint(0x2068)
const CLOSE = String.fromCodePoint(0x2069)

/**
 * `text` kept whole inside the words around it, in the direction of its own first letter — what `<bdi>` is in markup,
 * for a plain string: a toast, a dialog's title, a confirm's sentence, an `aria-label`, the tab's name. Without it a
 * Latin run in a Persian sentence loses a leading «@» to its far side («agent_bot@»). The isolates are invisible, and
 * nothing to the text's letters.
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
 * Every run of markup in a plain sentence kept whole, as typed (`isolate()` each): in a Persian sentence the
 * bidirectional algorithm mirrors a bare tag's brackets and moves its «/» — «</b>» reads «<b/>», «&lt;» «;lt&», a lone
 * «>» shows as «<». What a bot text's wording is told about it says tags (components/bot-texts).
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
