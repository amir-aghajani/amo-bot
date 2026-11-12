import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, CheckCheck, Clock3, ImageOff, RefreshCw } from 'lucide-react'
import { pictureUrl, problemOf } from '@/apps/app/lib/store'
import { ticketPictures } from '@/apps/app/screens/support/parts/words'
import { tap } from '@/apps/app/telegram/haptics'
import { openLink } from '@/apps/app/telegram/webapp'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * A message of a ticket as a messenger draws it: a bubble — the customer's in the accent on the right (the app reads
 * right to left: the start side), support's in the section's colour on the left —, the last of a run with its corner on
 * its side drawn small; its picture above its words (fetched as it comes into view, tapped to see it whole); its words
 * as plain text, a web address in them a link; and in its bottom corner the time and, on the customer's own, its ticks: a
 * clock while it goes, one tick once the shop has it, two once support has read it.
 */

type Tick = 'sending' | 'sent' | 'seen'

const TICKS: Record<Tick, { icon: typeof Check; label: string }> = {
  sending: { icon: Clock3, label: 'در حال ارسال' },
  sent: { icon: Check, label: 'ارسال شد' },
  seen: { icon: CheckCheck, label: 'دیده شد' },
}

/** A web address in a message's words; a closing mark after it is the sentence's, not the address's. */
const ADDRESS = /https?:\/\/[^\s<>«»"']+/g
const TRAILING = /[.,،؛:!?)\]]+$/

/** The words, each web address in them a link that opens in Telegram's browser. */
function Linked({ text }: { text: string }) {
  const parts: ReactNode[] = []
  let from = 0
  for (const match of text.matchAll(ADDRESS)) {
    const address = match[0].replace(TRAILING, '')
    const at = match.index
    parts.push(text.slice(from, at))
    parts.push(
      <a
        key={at}
        href={address}
        target="_blank"
        rel="noopener noreferrer"
        onClick={(event) => {
          event.preventDefault()
          tap()
          openLink(address)
        }}
        className="ltr underline decoration-current/50 underline-offset-2"
      >
        {address}
      </a>,
    )
    from = at + address.length
  }
  parts.push(text.slice(from))

  return <>{parts}</>
}

/**
 * The time a message was written and, on the customer's own, its ticks — in the bubble's bottom corner, the time on the
 * baseline of the words' last line. `ghost`: the same, unseen and unlabelled, at the end of the words, keeping room for it
 * on their last line (or a line of its own when that is full).
 */
function Meta({ at, tick, mine, ghost = false }: { at: string | null; tick: Tick | null; mine: boolean; ghost?: boolean }) {
  const time = at !== null && <span className="text-caption leading-none">{formatDate(at, { timeStyle: 'short' })}</span>
  if (ghost) {
    return (
      <span aria-hidden className="invisible ms-2 inline-flex items-baseline">
        {time}
        {tick !== null && <span className="ms-1 inline-block size-3.5" />}
      </span>
    )
  }
  const Icon = tick === null ? null : TICKS[tick].icon

  return (
    <span className={cn('absolute end-3 bottom-2 flex h-6 items-baseline', mine ? 'text-on-accent/75' : 'text-hint')}>
      {/* A strut of the words' own size and line: what stands beside it stands on their baseline. */}
      <span aria-hidden className="w-0 text-body">
        {'\u200b'}
      </span>
      {time}
      {Icon !== null && tick !== null && <Icon className={cn('ms-1 size-3.5 self-center', tick === 'seen' && 'text-on-accent')} role="img" aria-label={TICKS[tick].label} />}
    </span>
  )
}

interface BubbleProps {
  mine: boolean
  body: string
  /** When it was written (null: unknown). */
  at: string | null
  /** The customer's own message's state; null for support's. */
  tick: Tick | null
  /** The last of its side's run: its corner on its side drawn small. */
  tail: boolean
  picture?: ReactNode
  /** Under it: why it did not go, and what can be done. */
  after?: ReactNode
}

/** A bubble: 16px round, 12px in from its sides and 8px from its top and bottom, so a line of words is 40px high. */
export function Bubble({ mine, body, at, tick, tail, picture, after }: BubbleProps) {
  return (
    <div className={cn('flex flex-col', mine ? 'items-start' : 'items-end', tail ? 'mb-2' : 'mb-0.5')}>
      <div
        className={cn(
          'relative max-w-[min(82%,448px)] rounded-2xl px-3 py-2 text-body shadow-card',
          mine ? 'bg-accent text-on-accent' : 'bg-section text-text',
          tail && (mine ? 'rounded-es-[6px]' : 'rounded-ee-[6px]'),
        )}
      >
        {picture}
        <p className="break-words whitespace-pre-line">
          <span className="[unicode-bidi:plaintext]">
            <Linked text={body} />
          </span>
          <Meta at={at} tick={tick} mine={mine} ghost />
        </p>
        <Meta at={at} tick={tick} mine={mine} />
      </div>
      {after}
    </div>
  )
}

/** The frame a picture shows in, at the bubble's top, 4px in from its edges (12px round inside the bubble's 16); a tap shows it whole. */
function Frame({ onOpen, children, label }: { onOpen?: () => void; children: ReactNode; label: string }) {
  return (
    <button type="button" onClick={onOpen} disabled={onOpen === undefined} aria-label={label} className="-mx-2 -mt-1 mb-2 block w-[calc(100%+16px)] min-w-48 overflow-hidden rounded-xl bg-fill">
      {children}
    </button>
  )
}

/** A picture the customer just sent, from their own device. */
export function LocalPicture({ url, onOpen }: { url: string; onOpen: (url: string) => void }) {
  return (
    <Frame label="دیدن تصویر" onOpen={() => onOpen(url)}>
      <img src={url} alt="تصویر پیوست" className="max-h-72 w-full object-cover" />
    </Frame>
  )
}

/** A picture the shop no longer keeps: its name, and that it is gone. */
export function GonePicture({ name }: { name: string }) {
  return (
    <span className="-mx-1 mb-2 flex items-center gap-2 rounded-lg bg-fill px-3 py-2 text-footnote">
      <ImageOff aria-hidden className="size-4 shrink-0 opacity-70" />
      <span className="min-w-0">
        <span className="block">تصویر دیگر در دسترس نیست</span>
        <span className="ltr block truncate opacity-70">{name}</span>
      </span>
    </span>
  )
}

/**
 * A message's picture from the shop (`GET /tickets/{id}/messages/{message}/attachment`, the customer's alone: fetched with
 * their token and shown by an object address), asked once it comes into view — every read counts against the customer's
 * hourly pictures — and kept while the conversation is open (screens/support/chat lets the addresses go).
 */
export function RemotePicture({ ticketId, messageId, onOpen }: { ticketId: number; messageId: number; onOpen: (url: string) => void }) {
  const frame = useRef<HTMLDivElement>(null)
  // Without a way to watch it come into view, it is asked at once.
  const [near, setNear] = useState(() => typeof IntersectionObserver === 'undefined')
  useEffect(() => {
    const element = frame.current
    if (near || element === null) return
    const observer = new IntersectionObserver((entries) => entries.some((entry) => entry.isIntersecting) && setNear(true), { rootMargin: '300px 0px' })
    observer.observe(element)

    return () => observer.disconnect()
  }, [near])
  const picture = useQuery({
    queryKey: [...ticketPictures(ticketId), messageId],
    queryFn: () => pictureUrl(`/tickets/${ticketId}/messages/${messageId}/attachment`),
    enabled: near,
    staleTime: Infinity,
    retry: false,
    refetchOnWindowFocus: false,
  })

  return (
    <div ref={frame}>
      {picture.isSuccess ? (
        <Frame label="دیدن تصویر" onOpen={() => onOpen(picture.data)}>
          <img src={picture.data} alt="تصویر پیوست" className="max-h-72 w-full object-cover" />
        </Frame>
      ) : picture.isError ? (
        <Frame label="دوباره خواندن تصویر" onOpen={() => void picture.refetch()}>
          <span className="flex h-28 flex-col items-center justify-center gap-2 px-3 text-footnote">
            <RefreshCw aria-hidden className="size-5 opacity-70" />
            <span className="opacity-80">{problemOf(picture.error)}</span>
          </span>
        </Frame>
      ) : (
        <Frame label="در حال بارگذاری تصویر">
          <span className="block h-44 animate-pulse" />
        </Frame>
      )}
    </div>
  )
}
