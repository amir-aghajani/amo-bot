import { Fragment, useMemo, useState, type KeyboardEvent, type ReactNode } from 'react'
import { selection, tap } from '@/apps/app/telegram/haptics'
import { openLink, openTelegramLink } from '@/apps/app/telegram/webapp'
import { copyText } from '@/apps/app/ui/toaster'
import { cn } from '@/lib/utils'

/*
 * The shop's text in Telegram's HTML (the bot's «آموزش»), shown as the bot shows it — and safely: the text is parsed
 * into an inert document (DOMParser: nothing in it runs or loads) and only Telegram's own tags are built again, as React
 * elements, with none of their attributes but a checked link. Bold, italic, underline, struck, inline code and a block of
 * it, a quote (an expandable one folded), a spoiler (tapped to show), a custom emoji's own character; a link opens in
 * Telegram (a t.me one inside it) when it is a web address — or a tg:// address that has a t.me form —, else it is just
 * its words. Anything else is dropped with what it holds (a script, a picture, a frame…) or, for a plain container, kept as
 * its words alone. Each paragraph runs in the direction of its own first letter, as in Telegram.
 */

/** Elements dropped with all they hold: what runs, loads, draws or asks for input — none of it is Telegram's. */
const DROPPED = new Set([
  'script',
  'style',
  'iframe',
  'frame',
  'frameset',
  'object',
  'embed',
  'applet',
  'img',
  'picture',
  'video',
  'audio',
  'source',
  'track',
  'svg',
  'math',
  'canvas',
  'template',
  'noscript',
  'form',
  'input',
  'button',
  'select',
  'textarea',
  'link',
  'meta',
  'base',
  'title',
  'head',
])

/** Elements laid out as blocks: a line break right beside one is the block's own edge, not an empty line. */
const BLOCKS = new Set(['pre', 'blockquote'])

/**
 * A «&» Telegram reads as itself: any but a numeric entity or one of the four names it knows (`&lt;`, `&gt;`, `&amp;`,
 * `&quot;`) — `&nbsp;` or `&copy;` reach the customer as typed, where a browser would read them.
 */
const LITERAL_AMPERSAND = /&(?!#|(?:lt|gt|amp|quot)(?![A-Za-z]))/g

/** The text's nodes, parsed as a <body>'s content in a document of its own (its leading line breaks kept). */
function parse(html: string): ChildNode[] {
  const document = new DOMParser().parseFromString(`<!doctype html><body>${html.replace(LITERAL_AMPERSAND, '&amp;')}`, 'text/html')

  return Array.from(document.body.childNodes)
}

/** A Telegram username in an address: 4 to 32 Latin letters, digits and «_». */
const USERNAME = /^[A-Za-z0-9_]{4,32}$/

/** A tg:// address in its t.me form — a chat or bot by its username (`resolve`), an invite (`join`) —; null for any other. */
function telegramForm(url: URL): string | null {
  const query = url.searchParams
  if (url.hostname === 'resolve') {
    const domain = query.get('domain') ?? ''
    if (!USERNAME.test(domain)) return null
    const post = query.get('post')
    const path = post !== null && /^\d+$/.test(post) ? `${domain}/${post}` : domain
    const start = ['start', 'startapp', 'startgroup'].flatMap((name) => {
      const value = query.get(name)
      return value === null ? [] : [[name, value]]
    })
    return `https://t.me/${path}${start.length === 0 ? '' : `?${new URLSearchParams(start).toString()}`}`
  }
  if (url.hostname === 'join') {
    const invite = query.get('invite') ?? ''
    return /^[A-Za-z0-9_-]+$/.test(invite) ? `https://t.me/+${invite}` : null
  }

  return null
}

/**
 * Where a link may lead: a web address (http, https — telegram.me and http t.me as https://t.me), or a tg:// one by its
 * t.me form. Null for anything else (javascript:, data:, a path of the page), which then shows as words alone.
 */
export function linkTarget(href: string | null): string | null {
  if (href === null) return null
  let url: URL
  try {
    url = new URL(href.trim())
  } catch {
    return null
  }
  if (url.protocol === 'tg:') return telegramForm(url)
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return null
  if (url.username !== '' || url.password !== '') return null
  if (['t.me', 'www.t.me', 'telegram.me', 'www.telegram.me'].includes(url.hostname)) return `https://t.me${url.pathname}${url.search}${url.hash}`

  return url.href
}

/** Open a link: a t.me one inside Telegram, any other in Telegram's browser. */
function follow(target: string): void {
  tap()
  if (target.startsWith('https://t.me/')) openTelegramLink(target)
  else openLink(target)
}

/** The keys that press a button: Enter and Space. */
function pressed(event: { key: string }): boolean {
  return event.key === 'Enter' || event.key === ' '
}

/** A spoiler: hidden behind a blur until tapped. A link inside it is not followed by the tap that shows it. */
function Spoiler({ children }: { children: ReactNode }) {
  const [shown, setShown] = useState(false)
  const show = () => {
    selection()
    setShown(true)
  }
  if (shown) return <span className="rounded-sm bg-fill transition-colors">{children}</span>

  return (
    <span
      role="button"
      tabIndex={0}
      aria-label="نمایش متن پنهان"
      onClickCapture={(event) => {
        event.preventDefault()
        event.stopPropagation()
        show()
      }}
      onKeyDown={(event) => {
        if (!pressed(event)) return
        // Space would scroll the page as well.
        event.preventDefault()
        show()
      }}
      className="bg-fill-strong cursor-pointer rounded-sm text-transparent select-none [text-shadow:0_0_8px_var(--app-hint)]"
    >
      <span aria-hidden>{children}</span>
    </span>
  )
}

/** A quote; an expandable one folded to three lines until tapped — or opened from the keyboard, as a button is. */
function Quote({ expandable, children }: { expandable: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(!expandable)
  const folded = open
    ? {}
    : {
        role: 'button',
        tabIndex: 0,
        'aria-expanded': false,
        onClick: () => setOpen(true),
        onKeyDown: (event: KeyboardEvent<HTMLElement>) => {
          // Its own keys only: Enter on a link inside it follows the link.
          if (event.target !== event.currentTarget || !pressed(event)) return
          event.preventDefault()
          setOpen(true)
        },
      }

  return (
    <blockquote
      {...folded}
      className={cn('border-accent-text my-2 rounded-e-lg border-s-[3px] bg-[color-mix(in_srgb,var(--app-accent-text)_12%,transparent)] px-3 py-2 [unicode-bidi:plaintext]', !open && 'cursor-pointer')}
    >
      <span className={cn('block', !open && 'line-clamp-3')}>{children}</span>
    </blockquote>
  )
}

/** The nodes in order, a line break right beside a block left out (the block's edge breaks the line already). */
function renderAll(nodes: readonly ChildNode[], inPre: boolean): ReactNode[] {
  return nodes.map((node, index) => {
    if (node.nodeType !== Node.TEXT_NODE) return render(node, index, inPre)
    let text = node.textContent ?? ''
    const before = nodes[index - 1]
    const after = nodes[index + 1]
    if (before instanceof Element && BLOCKS.has(before.localName)) text = text.replace(/^\n/, '')
    if (after instanceof Element && BLOCKS.has(after.localName)) text = text.replace(/\n$/, '')
    return text
  })
}

function render(node: ChildNode, key: number, inPre: boolean): ReactNode {
  if (!(node instanceof Element)) return null
  const tag = node.localName
  if (DROPPED.has(tag)) return null
  const children = () => renderAll(Array.from(node.childNodes), inPre || tag === 'pre')

  switch (tag) {
    case 'b':
    case 'strong':
      return <strong key={key}>{children()}</strong>
    case 'i':
    case 'em':
      return <em key={key}>{children()}</em>
    case 'u':
    case 'ins':
      return <u key={key}>{children()}</u>
    case 's':
    case 'strike':
    case 'del':
      return <s key={key}>{children()}</s>
    case 'code': {
      // A block's code is the block's text; inline code is copied with a tap, as in Telegram. Both read left to right in
      // a monospace font, as Telegram shows code: a config's columns stay lined up.
      if (inPre) return <Fragment key={key}>{children()}</Fragment>
      const text = node.textContent ?? ''
      const copy = () => {
        tap()
        void copyText(text, 'کپی شد')
      }
      return (
        <code
          key={key}
          role="button"
          tabIndex={0}
          onClick={copy}
          onKeyDown={(event) => {
            if (!pressed(event)) return
            event.preventDefault()
            copy()
          }}
          className="ltr font-mono cursor-pointer rounded-sm bg-fill px-1 py-0.5 text-footnote"
        >
          {children()}
        </code>
      )
    }
    case 'pre':
      return (
        <pre key={key} className="ltr font-mono my-2 overflow-x-auto rounded-lg bg-fill px-3 py-2 text-left text-footnote whitespace-pre">
          {children()}
        </pre>
      )
    case 'a': {
      const target = linkTarget(node.getAttribute('href'))
      if (target === null) return <Fragment key={key}>{children()}</Fragment>
      return (
        <a
          key={key}
          href={target}
          target="_blank"
          rel="noopener noreferrer"
          onClick={(event) => {
            event.preventDefault()
            follow(target)
          }}
          className="text-link underline decoration-current/40 underline-offset-2"
        >
          {children()}
        </a>
      )
    }
    case 'blockquote':
      return (
        <Quote key={key} expandable={node.hasAttribute('expandable')}>
          {children()}
        </Quote>
      )
    case 'tg-spoiler':
      return <Spoiler key={key}>{children()}</Spoiler>
    case 'span':
      return node.classList.contains('tg-spoiler') ? <Spoiler key={key}>{children()}</Spoiler> : <Fragment key={key}>{children()}</Fragment>
    // A custom emoji is its plain character: the one written inside it.
    case 'tg-emoji':
      return <Fragment key={key}>{node.textContent}</Fragment>
    case 'br':
      return '\n'
    default:
      return <Fragment key={key}>{children()}</Fragment>
  }
}

/** The text in Telegram's HTML, laid out as the bot's message is: only Telegram's own tags, line breaks kept. */
export function TelegramHtml({ html, className }: { html: string; className?: string }) {
  const nodes = useMemo(() => renderAll(parse(html), false), [html])

  return <div className={cn('break-words whitespace-pre-wrap [unicode-bidi:plaintext]', className)}>{nodes}</div>
}
