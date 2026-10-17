import { Fragment, useMemo, type ReactNode } from 'react'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { VARIABLE } from '@/components/bot-texts/tokens'
import { visibleText } from '@/components/bot-texts/wording-problem'
import type { BotTextVariable } from '@/lib/api-types'

function escapeHtml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

/** U+200E LEFT-TO-RIGHT MARK, invisible. */
const LRM = '\u{200E}'

/** A value that starts with a handle or a row's number («@my_shop_bot», «#12»). */
const LEFT_TO_RIGHT = /^(?:@|#[0-9۰-۹])/u

/** Whether the last line of `before` has a word in it — a letter outside markup (a tag, an entity, a variable). An emoji is no word. */
function wordsBefore(before: string): boolean {
  const line = before.slice(before.lastIndexOf('\n') + 1)
  return /\p{L}/u.test(line.replace(/<[^<>]*>|&#?\w+;|%[A-Za-z_][A-Za-z0-9_]*%/g, ''))
}

/**
 * The wording with each variable replaced by its sample in one pass, as the bot fills a real value: in a text Telegram
 * reads as HTML, a plain one escaped and one the bot fills with HTML (another text's wording, `html`) as it is; one that
 * starts with a handle or a row's number after a left-to-right mark where words come before it on its line, unless one
 * is there — right to left, «ربات شما: @my_shop_bot» would read «my_shop_bot@».
 */
export function fillSamples(template: string, samples: Record<string, Pick<BotTextVariable, 'sample' | 'html'>>, html: boolean): string {
  return template.replace(VARIABLE, (token, name: string, at: number) => {
    const variable = samples[name]
    if (variable === undefined) return token
    const value = html && !variable.html ? escapeHtml(variable.sample) : variable.sample
    const before = template.slice(0, at)
    return LEFT_TO_RIGHT.test(value) && !before.endsWith(LRM) && wordsBefore(before) ? LRM + value : value
  })
}

/** A document of its own to read wordings in — never shown: nothing in it loads or runs. */
let reader: Document | undefined

/** Parses a wording as a <body>'s content (so a part's leading line breaks survive), in one shared inert document. */
function parse(html: string): HTMLElement {
  reader ??= document.implementation.createHTMLDocument('')
  const body = reader.createElement('body')
  body.innerHTML = html
  return body
}

/**
 * A «&» Telegram reads as itself: any but a numeric entity or one of the four names it knows (`&lt;`, `&gt;`, `&amp;`,
 * `&quot;`, the «;» optional) — `&nbsp;` or `&copy;` reach the customer as typed, where a browser would read them.
 */
const LITERAL_AMPERSAND = /&(?!#|(?:lt|gt|amp|quot)(?![A-Za-z]))/g

/** Parses a wording as Telegram reads it: its entities as Telegram's, not a browser's. */
const parseAsTelegram = (html: string): HTMLElement => parse(html.replace(LITERAL_AMPERSAND, '&amp;'))

/** The wording as plain text for a list, as the customer reads it: tags out, entities read, blank lines folded. */
export function plainText(html: string): string {
  const text = parseAsTelegram(html).textContent ?? ''
  return text.replace(/\n{2,}/g, '\n').trim()
}

/**
 * The visible length of a wording as the server counts it (TelegramHtml::visibleLength(), which the save checks): UTF-16
 * units of the text without its markup, its entities read as Telegram reads them — `&nbsp;` is six, as the customer
 * sees it — (visibleText()), never as a browser would.
 */
export function visibleLength(html: string): number {
  return visibleText(html).length
}

/**
 * Each paragraph of a block (the lines a line break sets apart) in the direction of its own first letter, as Telegram
 * lays a message out. It holds for text that is the block's own: text beside another block (a quote) is laid out in an
 * anonymous block, which keeps the surrounding direction.
 */
const PARAGRAPHS = '[unicode-bidi:plaintext]'

/**
 * Telegram HTML rendered as Telegram shows it, built as React elements from the parsed tree (never injected), so the
 * admin's text is inert. Unknown tags show as text; links are not followed.
 */
export function TelegramHtml({ html }: { html: string }) {
  const nodes = useMemo(() => Array.from(parseAsTelegram(html).childNodes), [html])

  return <>{nodes.map(render)}</>
}

/**
 * A message's text as Telegram lays it out: each paragraph in the direction of its first letter — a link, or a line that
 * starts in Latin letters, reads left to right from the left edge; a Persian one from the right.
 */
export function TelegramText({ html }: { html: string }) {
  return (
    <div className={PARAGRAPHS}>
      <TelegramHtml html={html} />
    </div>
  )
}

function render(node: ChildNode, key: number): ReactNode {
  if (node.nodeType === Node.TEXT_NODE) return node.textContent
  if (!(node instanceof Element)) return null

  const children = Array.from(node.childNodes).map(render)
  switch (node.tagName.toLowerCase()) {
    case 'b':
    case 'strong':
      return <strong key={key}>{children}</strong>
    case 'i':
    case 'em':
      return <em key={key}>{children}</em>
    case 'u':
    case 'ins':
      return <u key={key}>{children}</u>
    case 's':
    case 'strike':
    case 'del':
      return <s key={key}>{children}</s>
    case 'code':
      return (
        <code key={key} className="tg-code">
          {children}
        </code>
      )
    case 'pre':
      return (
        <pre key={key} className={`tg-pre ${PARAGRAPHS}`}>
          {children}
        </pre>
      )
    case 'a':
      return (
        <span key={key} className="tg-link" title={node.getAttribute('href') ?? undefined}>
          {children}
        </span>
      )
    case 'tg-spoiler':
      return (
        <span key={key} className="tg-spoiler">
          {children}
        </span>
      )
    case 'span':
      return node.getAttribute('class') === 'tg-spoiler' ? (
        <span key={key} className="tg-spoiler">
          {children}
        </span>
      ) : (
        <Fragment key={key}>{children}</Fragment>
      )
    case 'blockquote':
      return (
        <span key={key} className={`tg-quote ${PARAGRAPHS}`}>
          {children}
        </span>
      )
    case 'tg-emoji': {
      const id = node.getAttribute('emoji-id')
      return id ? <PremiumEmoji key={key} id={id} fallback={node.textContent ?? ''} /> : <Fragment key={key}>{children}</Fragment>
    }
    // A date and time: its words, as the customer reads them where their app cannot write the time its own way.
    case 'tg-time':
    default:
      return <Fragment key={key}>{children}</Fragment>
  }
}
