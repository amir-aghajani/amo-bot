import { Fragment, useMemo, type ReactNode } from 'react'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { VARIABLE } from '@/components/bot-texts/tokens'

function escapeHtml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

/**
 * The wording with each variable's sample in its place, in one pass (a sample is never read again for
 * variables) — escaped for a text Telegram reads as HTML, the way the bot escapes a real value.
 */
export function fillSamples(template: string, samples: Record<string, string>, html: boolean): string {
  return template.replace(VARIABLE, (token, name: string) => {
    const sample = samples[name]
    if (sample === undefined) return token
    return html ? escapeHtml(sample) : sample
  })
}

/** A document of its own to read wordings in — never shown: nothing in it loads or runs. */
let reader: Document | undefined

/**
 * The text's body as the browser parses it — as a <body>'s content, so the line breaks a part starts with stay its own
 * — in one inert document for every wording: a list of them reads each as it draws its row.
 */
function parse(html: string): HTMLElement {
  reader ??= document.implementation.createHTMLDocument('')
  const body = reader.createElement('body')
  body.innerHTML = html
  return body
}

/** The wording as plain text for a list: tags out, entities read, blank lines folded. */
export function plainText(html: string): string {
  const text = parse(html).textContent ?? ''
  return text.replace(/\n{2,}/g, '\n').trim()
}

/**
 * How long what the customer sees is — tags out, entities read — as Telegram counts it, and the server with it: in
 * UTF-16 units (a string's own length), an emoji beyond the basic plane two. Markup, a premium emoji's long tag above
 * all, costs nothing.
 */
export function visibleLength(html: string): number {
  return (parse(html).textContent ?? '').length
}

/**
 * Telegram HTML as Telegram shows it: the tags it knows become their look, anything else is only its
 * text — built as React elements from the parsed tree, never injected, so the admin's text is inert.
 * A link is not followed from the preview.
 */
export function TelegramHtml({ html }: { html: string }) {
  const nodes = useMemo(() => Array.from(parse(html).childNodes), [html])

  return <>{nodes.map(render)}</>
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
        <pre key={key} className="tg-pre">
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
        <span key={key} className="tg-quote">
          {children}
        </span>
      )
    case 'tg-emoji': {
      const id = node.getAttribute('emoji-id')
      return id ? <PremiumEmoji key={key} id={id} fallback={node.textContent ?? ''} /> : <Fragment key={key}>{children}</Fragment>
    }
    default:
      return <Fragment key={key}>{children}</Fragment>
  }
}
