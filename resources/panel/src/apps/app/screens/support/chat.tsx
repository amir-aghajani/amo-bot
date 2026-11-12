import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CircleAlert, LifeBuoy, Lock, SearchX } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { Bubble, GonePicture, LocalPicture, RemotePicture } from '@/apps/app/screens/support/parts/bubble'
import { Composer } from '@/apps/app/screens/support/parts/composer'
import { useOutbox, type Outgoing } from '@/apps/app/screens/support/parts/outbox'
import { StarPicker, Stars } from '@/apps/app/screens/support/parts/stars'
import { dayLabel, threadOf } from '@/apps/app/screens/support/parts/thread'
import { backTo, idOf, keepTicket, readTicket, TICKET_STATE, ticketPictures } from '@/apps/app/screens/support/parts/words'
import { useAppActive } from '@/apps/app/telegram/active'
import { result } from '@/apps/app/telegram/haptics'
import { confirmBox, inPreview } from '@/apps/app/telegram/webapp'
import { Button } from '@/apps/app/ui/button'
import { Field, TextArea } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Sheet } from '@/apps/app/ui/sheet'
import { Empty, ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'
import { IconTile } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { StoreTicketDetail, StoreTicketMessage, StoreTicketResponse } from '@/lib/api-types'
import { formatDate } from '@/lib/format'

/*
 * A ticket's conversation (`/support/:id`), as a messenger shows a chat: its messages as bubbles under their days, the
 * newest at the bottom where the screen opens, and the message box pinned under them. Opening it marks it read
 * (`POST /tickets/{id}/read`, which answers the ticket); while it is on screen and the app in front it is read again every
 * few seconds (`GET`), marked read once more when support's answer came meanwhile — a panel's preview only reads it —,
 * and a new message scrolls into view when the customer was at the bottom. What the customer sends shows at once and goes in turn (parts/outbox). It can be
 * closed (asked first); closed, it asks for the customer's stars once and shows them after. A ticket at its 200 messages
 * takes no more: a new ticket is the way on.
 */

/** How often an open conversation is read again. */
const POLL_MS = 8_000

/** The most messages a ticket holds (Support\Services\Tickets::MESSAGES_MAX). */
const MESSAGES_MOST = 200

/** How close to the bottom still counts as there: a new message then scrolls into view. */
const NEAR_BOTTOM_PX = 160

/** The longest note a rating takes. */
const NOTE_MOST = 500

function scrollToBottom(behavior: ScrollBehavior): void {
  window.scrollTo({ top: document.documentElement.scrollHeight, behavior })
}

export default function ChatScreen() {
  const { id } = useParams()
  const ticketId = idOf(id)

  return ticketId === null ? <Missing /> : <Conversation key={ticketId} id={ticketId} />
}

/** A ticket that is not the customer's, or none at all. */
function Missing() {
  const navigate = useNavigate()

  return (
    <Screen back={backTo('/support')} title="پشتیبانی">
      <Empty
        icon={SearchX}
        title="این تیکت پیدا نشد"
        text="شاید نشانی آن اشتباه است، یا تیکت حساب دیگری است."
        action={<Button onClick={() => navigate('/support', { replace: true })}>تیکت‌های من</Button>}
      />
    </Screen>
  )
}

/** One thing in the thread: a message the shop has, or one being sent from here. */
type Entry = { kind: 'message'; message: StoreTicketMessage } | { kind: 'outgoing'; item: Outgoing }

const entryAt = (entry: Entry) => (entry.kind === 'message' ? entry.message.created_at : entry.item.at)
const entryMine = (entry: Entry) => entry.kind === 'outgoing' || entry.message.author === 'customer'

function Conversation({ id }: { id: number }) {
  const active = useAppActive()
  const outbox = useOutbox(id)
  const [room, setRoom] = useState(0)
  const [viewing, setViewing] = useState<string | null>(null)
  // The first read of this visit marks the ticket read; the ones after only read it. A panel's preview only reads: the
  // ticket stays as the customer left it.
  const marks = !inPreview()
  const opened = useRef(false)
  const ticket = useQuery({
    queryKey: keys.ticket(id),
    queryFn: async () => {
      if (opened.current || !marks) return store.get<StoreTicketResponse>(`/tickets/${id}`)
      const answer = await store.post(`/tickets/${id}/read`)
      opened.current = true
      readTicket(id)
      return answer
    },
    refetchOnMount: 'always',
    // Not while a message is on its way: its answer brings the ticket.
    refetchInterval: active && !outbox.sending ? POLL_MS : false,
  })

  // Support's answer came while the customer has the conversation in front of them: it is read.
  const reading = useRef(false)
  const { mutate: markRead } = useMutation({
    mutationFn: () => store.post(`/tickets/${id}/read`),
    meta: { quiet: true },
    // When the conversation was last kept as the mark went: its answer is the ticket as of then.
    onMutate: () => queryClient.getQueryState(keys.ticket(id))?.dataUpdatedAt ?? 0,
    onSuccess: ({ ticket: read }, _variables, kept) => {
      // A newer ticket kept meanwhile (a message's answer, a read) stands: the older one would drop what it brought.
      if ((queryClient.getQueryState(keys.ticket(id))?.dataUpdatedAt ?? 0) === kept) queryClient.setQueryData<StoreTicketResponse>(keys.ticket(id), { ticket: read })
      readTicket(id)
    },
    onSettled: () => {
      reading.current = false
    },
  })
  const unread = ticket.data?.ticket.unread ?? false
  useEffect(() => {
    if (!marks || !unread || !active || !opened.current || reading.current) return
    reading.current = true
    markRead()
  }, [marks, unread, active, markRead])

  // The pictures read for this conversation are let go with it.
  useEffect(
    () => () => {
      for (const query of queryClient.getQueryCache().findAll({ queryKey: ticketPictures(id) })) {
        const address = query.state.data
        if (typeof address === 'string') URL.revokeObjectURL(address)
      }
      queryClient.removeQueries({ queryKey: ticketPictures(id) })
    },
    [id],
  )

  const detail = ticket.data?.ticket
  const entries: Entry[] = [...(detail?.messages ?? []).map((message): Entry => ({ kind: 'message', message })), ...outbox.items.map((item): Entry => ({ kind: 'outgoing', item }))]
  const newest = entries.at(-1)
  const newestMine = newest !== undefined && entryMine(newest)

  // At the bottom where it opens; a new message scrolls into view when the customer was there, or wrote it.
  const atBottom = useRef(true)
  const shown = useRef(0)
  useEffect(() => {
    const measure = () => {
      atBottom.current = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - NEAR_BOTTOM_PX
    }
    // The keyboard coming up, the box growing: the bottom stays in view for one who was there.
    const keep = () => atBottom.current && scrollToBottom('instant')
    window.addEventListener('scroll', measure, { passive: true })
    window.visualViewport?.addEventListener('resize', keep)

    return () => {
      window.removeEventListener('scroll', measure)
      window.visualViewport?.removeEventListener('resize', keep)
    }
  }, [])
  useLayoutEffect(() => {
    const count = entries.length
    const first = shown.current === 0
    const grew = count > shown.current
    shown.current = count
    if (count > 0 && grew && (first || atBottom.current || newestMine)) scrollToBottom(first ? 'instant' : 'smooth')
  }, [entries.length, newestMine])
  useEffect(() => {
    if (room > 0 && atBottom.current) scrollToBottom('instant')
  }, [room])

  const close = useMutation({
    mutationFn: () => store.post(`/tickets/${id}/close`),
    onSuccess: ({ ticket: closed }) => {
      keepTicket(closed)
      told('تیکت بسته شد')
    },
    onError: () => void ticket.refetch(),
  })
  const askClose = async () => {
    if (await confirmBox('این تیکت بسته شود؟ اگر بعدا پیامی در آن بنویسید، دوباره باز می‌شود.')) close.mutate()
  }
  const onHeight = useCallback((height: number) => setRoom(height), [])

  if (ticket.error instanceof ApiError && ticket.error.status === 404) return <Missing />
  // The conversation stays while there is one: a read again that failed (the next comes in a few seconds) takes neither
  // it nor the message being written away.
  if (detail === undefined) {
    return ticket.isError ? (
      <Screen back={backTo('/support')} title="پشتیبانی">
        <ErrorView error={ticket.error} retry={() => void ticket.refetch()} />
      </Screen>
    ) : (
      <Screen back={backTo('/support')}>
        <div className="flex flex-col gap-2" role="status" aria-label="در حال بارگذاری">
          <Skeleton className="h-14" />
          <Skeleton className="ms-auto h-16 w-3/5" />
          <Skeleton className="h-12 w-2/3" />
          <Skeleton className="ms-auto h-10 w-1/2" />
        </div>
      </Screen>
    )
  }

  const state = TICKET_STATE[detail.status]
  const closed = detail.status === 'closed'
  const full = detail.messages.length >= MESSAGES_MOST

  return (
    <Screen back={backTo('/support')} className="gap-0 pt-0 pb-0">
      {/* Its hairline drawn as a list's is (app.css list-row): 1px, halved. */}
      <header className="glass after:bg-separator sticky top-0 z-20 -mx-4 flex items-center gap-3 px-4 pt-[calc(var(--app-top)+8px)] pb-2 after:absolute after:inset-x-0 after:bottom-0 after:h-px after:origin-bottom after:scale-y-50">
        <IconTile icon={LifeBuoy} size="lg" round />
        <div className="flex min-w-0 flex-1 flex-col">
          <h1 className="truncate text-heading font-bold">{detail.subject}</h1>
          <p className="text-hint flex min-w-0 items-center gap-1.5 text-caption">
            <span className="shrink-0 font-medium" style={{ color: `var(--app-${state.tone})` }}>
              {state.label}
            </span>
            {detail.subscription !== null && (
              <>
                <span aria-hidden>·</span>
                <span className="ltr truncate">{detail.subscription.name}</span>
              </>
            )}
          </p>
        </div>
        {!closed && (
          <Button variant="secondary" size="sm" busy={close.isPending} onClick={() => void askClose()}>
            بستن تیکت
          </Button>
        )}
      </header>

      <div className="flex flex-1 flex-col justify-end pt-3" aria-live="polite">
        {threadOf(entries, entryAt, entryMine).map(({ day, items }) => (
          <section key={day} aria-label={dayLabel(day)}>
            <div className="my-3 flex justify-center">
              <span className="text-hint inline-flex h-6 items-center rounded-full bg-fill px-3 text-caption leading-none font-medium">{dayLabel(day)}</span>
            </div>
            {/* A message sent from here and the one it became are one bubble — one key, one element — so it is said once. */}
            {items.map(({ item: entry, last }) =>
              entry.kind === 'message' ? (
                <Bubble
                  key={outbox.adopted.get(entry.message.id)?.key ?? `message-${entry.message.id}`}
                  {...messageBubble(id, entry.message, outbox.adopted.get(entry.message.id)?.preview ?? null, setViewing)}
                  tail={last}
                />
              ) : (
                <Bubble
                  key={entry.item.key}
                  mine
                  body={entry.item.body}
                  at={entry.item.at}
                  tick={entry.item.state === 'failed' ? null : 'sending'}
                  tail={last}
                  picture={entry.item.preview === null ? undefined : <LocalPicture url={entry.item.preview} onOpen={setViewing} />}
                  after={entry.item.state === 'failed' && <Failed item={entry.item} onRetry={outbox.retry} onDiscard={outbox.discard} />}
                />
              ),
            )}
          </section>
        ))}

        {closed && (
          <div className="flex flex-col items-center gap-3 py-3">
            <span className="text-hint inline-flex h-6 items-center gap-1.5 rounded-full bg-fill px-3 text-caption leading-none font-medium">
              <Lock aria-hidden className="size-3.5" />
              {detail.closed_at === null ? 'تیکت بسته شد' : `تیکت بسته شد · ${formatDate(detail.closed_at)}`}
            </span>
            <Rating ticket={detail} />
          </div>
        )}
        {full && (
          <div className="py-3">
            <Notice tone="warning">این تیکت به سقف ۲۰۰ پیام رسیده؛ برای ادامه، تیکت تازه‌ای باز کنید.</Notice>
          </div>
        )}
      </div>

      {full ? <NewTicketBar onHeight={onHeight} /> : <Composer onSend={outbox.add} onHeight={onHeight} placeholder={closed ? 'پیام تازه، تیکت را دوباره باز می‌کند' : 'پیام بنویسید'} />}
      <div aria-hidden style={{ height: room + 12 }} className="shrink-0" />

      <Sheet open={viewing !== null} onClose={() => setViewing(null)}>
        {viewing !== null && <img src={viewing} alt="تصویر پیوست" className="rounded-card max-h-[75vh] w-full object-contain" />}
      </Sheet>
    </Screen>
  )
}

/** A message the shop has, as its bubble draws it: the customer's with its ticks, support's without; its picture from the device when sent from here. */
function messageBubble(ticketId: number, message: StoreTicketMessage, preview: string | null, onOpen: (url: string) => void) {
  const mine = message.author === 'customer'
  const { attachment } = message
  const picture =
    attachment === null ? undefined : preview !== null ? (
      <LocalPicture url={preview} onOpen={onOpen} />
    ) : attachment.kept ? (
      <RemotePicture ticketId={ticketId} messageId={message.id} onOpen={onOpen} />
    ) : (
      <GonePicture name={attachment.name} />
    )

  return { mine, body: message.body, at: message.created_at, tick: mine ? (message.seen === true ? ('seen' as const) : ('sent' as const)) : null, picture }
}

/** Under a message that did not go: why, and sending it again (without its picture, when that was refused) or letting it go. */
function Failed({ item, onRetry, onDiscard }: { item: Outgoing; onRetry: (key: string, withoutPicture?: boolean) => void; onDiscard: (key: string) => void }) {
  return (
    <div className="mt-1 flex max-w-[82%] flex-col items-start gap-0.5" role="alert">
      <span className="flex items-start gap-1 px-1 text-caption text-danger">
        <CircleAlert aria-hidden className="mt-0.5 size-3.5 shrink-0" />
        {item.problem ?? 'فرستاده نشد'}
      </span>
      <span className="flex flex-wrap">
        <Button variant="plain" size="sm" onClick={() => onRetry(item.key)}>
          تلاش دوباره
        </Button>
        {item.pictureRefused && (
          <Button variant="plain" size="sm" onClick={() => onRetry(item.key, true)}>
            ارسال بدون تصویر
          </Button>
        )}
        <Button variant="plain" size="sm" className="text-danger" onClick={() => onDiscard(item.key)}>
          حذف
        </Button>
      </span>
    </div>
  )
}

/** In place of the message box once the ticket is full: the way to a new ticket. */
function NewTicketBar({ onHeight }: { onHeight: (height: number) => void }) {
  const navigate = useNavigate()
  const bar = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (bar.current !== null) onHeight(bar.current.offsetHeight)
  }, [onHeight])

  return (
    <div ref={bar} className="glass fixed inset-x-0 bottom-0 z-30 px-4 pt-3 pb-[calc(var(--app-bottom)+0.75rem)]">
      <div className="mx-auto max-w-xl">
        <Button block onClick={() => navigate('/support/new')}>
          تیکت تازه
        </Button>
      </div>
    </div>
  )
}

/** A closed ticket's stars: asked once, shown after. */
function Rating({ ticket }: { ticket: StoreTicketDetail }) {
  if (ticket.rating === null) return <RatingForm ticketId={ticket.id} />

  return (
    <div className="card flex w-full flex-col items-center gap-2 p-4 text-center">
      <span className="text-hint text-footnote">امتیاز شما به این گفتگو</span>
      <Stars value={ticket.rating} size="md" />
      {ticket.rating_note !== null && ticket.rating_note !== '' && <p className="text-footnote whitespace-pre-line">{ticket.rating_note}</p>}
    </div>
  )
}

function RatingForm({ ticketId }: { ticketId: number }) {
  const [stars, setStars] = useState(0)
  const [note, setNote] = useState('')
  const rate = useMutation({
    mutationFn: () => {
      const written = note.trim()
      return store.post(`/tickets/${ticketId}/rating`, written === '' ? { rating: stars } : { rating: stars, note: written })
    },
    meta: { quiet: true },
    onSuccess: ({ ticket }) => {
      keepTicket(ticket)
      told('ممنون از امتیاز شما')
    },
    onError: () => result('error'),
  })
  const general = rate.error !== null && fieldProblem(rate.error, 'rating') === undefined && fieldProblem(rate.error, 'note') === undefined ? problemOf(rate.error) : null
  // A refusal is of what was sent: changed, it says nothing of it any more.
  const pick = (value: number) => {
    rate.reset()
    setStars(value)
  }
  const write = (value: string) => {
    rate.reset()
    setNote(value)
  }

  return (
    <div className="card animate-rise flex w-full flex-col gap-4 p-4">
      <div className="flex flex-col gap-1 text-center">
        <p className="text-heading font-bold">از پاسخ پشتیبانی راضی بودید؟</p>
        <p className="text-hint text-footnote">با ستاره‌ها به این گفتگو امتیاز دهید.</p>
      </div>
      <StarPicker value={stars} onChange={pick} />
      {fieldProblem(rate.error, 'rating') !== undefined && (
        <p className="text-center text-caption text-danger" role="alert">
          {fieldProblem(rate.error, 'rating')}
        </p>
      )}
      {stars > 0 && (
        <div className="animate-rise flex flex-col gap-4">
          <Field label="توضیح (اختیاری)" error={fieldProblem(rate.error, 'note')}>
            {(fieldId) => (
              <TextArea
                id={fieldId}
                value={note}
                maxLength={NOTE_MOST}
                onChange={(event) => write(event.target.value)}
                className="min-h-20 bg-fill shadow-none"
                placeholder="هر چه دوست دارید بگویید"
              />
            )}
          </Field>
          {general !== null && <Notice tone="danger">{general}</Notice>}
          <Button block busy={rate.isPending} onClick={() => rate.mutate()}>
            ثبت امتیاز
          </Button>
        </div>
      )}
    </div>
  )
}
