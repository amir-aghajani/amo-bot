import type { CSSProperties } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { BookOpen, LifeBuoy, MessageCircle, Star } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { problemOf, store } from '@/apps/app/lib/store'
import { backTo, TICKET_STATE, ticketPages } from '@/apps/app/screens/support/parts/words'
import { tap } from '@/apps/app/telegram/haptics'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Row, Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { ACCENT_FILL, Badge, IconTile } from '@/apps/app/ui/visuals'
import type { StoreTicket, StoreTicketsResponse } from '@/lib/api-types'
import { formatNumber, timeAgo } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * «پشتیبانی»: the customer's tickets, the latest activity first (`GET /tickets`, a page at a time) — each with where it
 * stands, the service it is about, when it last moved and a dot while support's answer waits unread —, opening its
 * conversation; «تیکت تازه» on the main button, and the tutorial a tap away for what it already answers.
 */

export default function TicketsScreen() {
  const navigate = useNavigate()
  const list = useInfiniteQuery({
    queryKey: ticketPages,
    queryFn: ({ pageParam }) => store.get<StoreTicketsResponse>('/tickets', new URLSearchParams({ page: String(pageParam) })),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  })
  const tickets = list.data?.pages.flatMap((page) => page.tickets) ?? []
  const unread = list.data?.pages[0]?.meta.unread ?? 0

  return (
    <Screen back={backTo('/')} title="پشتیبانی" subtitle={unread > 0 ? `${formatNumber(unread)} تیکت با پاسخ تازه` : undefined} bottom="action">
      <Section>
        <Row icon={BookOpen} tint="#34c759" title="آموزش و راهنمای اتصال" subtitle="جواب بیشتر سوال‌ها همین‌جاست" to="/tutorial" />
      </Section>

      {list.data === undefined ? (
        list.isError ? (
          <ErrorView error={list.error} retry={() => void list.refetch()} />
        ) : (
          <div className="flex flex-col gap-2" role="status" aria-label="در حال بارگذاری">
            {[0, 1, 2].map((index) => (
              <Skeleton key={index} className="h-19" />
            ))}
          </div>
        )
      ) : tickets.length === 0 ? (
        <Empty icon={LifeBuoy} title="هنوز تیکتی ندارید" text="سوال یا مشکلی دارید؟ تیکت تازه‌ای باز کنید تا پشتیبانی همین‌جا پاسخ دهد." />
      ) : (
        <Section title="تیکت‌های شما">
          {tickets.map((ticket) => (
            <TicketRow key={ticket.id} ticket={ticket} />
          ))}
        </Section>
      )}
      {list.isFetchNextPageError && <Notice tone="danger">{problemOf(list.error)}</Notice>}
      {list.hasNextPage && (
        <Button variant="secondary" block busy={list.isFetchingNextPage} onClick={() => void list.fetchNextPage()}>
          تیکت‌های بیشتر
        </Button>
      )}

      <MainAction text="تیکت تازه" onClick={() => navigate('/support/new')} />
    </Screen>
  )
}

/** A ticket's circle: in the shop's colour while it waits on support, green once answered, grey once closed. */
const TILE_COLOR = { open: ACCENT_FILL, answered: 'var(--app-success)', closed: 'var(--app-hint)' } as const

/** The hairline under a ticket starts where its words do: past the 40px circle and its 12px gap. */
const ROW_INSET = { '--row-inset': '4.25rem' } as CSSProperties

/**
 * A ticket in the list, as a chat list shows a chat: its subject and when it last moved, where it stands, and — on the
 * row's centre line at its end — a dot while support's answer waits unread (its place kept on every row, so the times line up).
 */
function TicketRow({ ticket }: { ticket: StoreTicket }) {
  const state = TICKET_STATE[ticket.status]

  return (
    <Link to={`/support/${ticket.id}`} onClick={() => tap()} className="list-row flex items-center gap-3 px-4 py-3 active:bg-fill" style={ROW_INSET}>
      <IconTile icon={MessageCircle} size="lg" color={TILE_COLOR[ticket.status]} round />
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex items-baseline gap-2">
          <span className={cn('min-w-0 flex-1 truncate text-body', ticket.unread ? 'font-bold' : 'font-medium')}>{ticket.subject}</span>
          <span className={cn('shrink-0 text-caption', ticket.unread ? 'text-accent-text font-semibold' : 'text-hint')}>{timeAgo(ticket.last_message_at ?? ticket.created_at)}</span>
        </span>
        <span className="flex h-6 min-w-0 items-center gap-2">
          <Badge tone={state.tone}>{state.label}</Badge>
          {ticket.subscription !== null && <span className="text-hint ltr min-w-0 truncate text-footnote">{ticket.subscription.name}</span>}
          {ticket.rating !== null && (
            <span className="text-hint inline-flex shrink-0 items-center gap-1 text-footnote leading-none" aria-label={`امتیاز شما: ${formatNumber(ticket.rating)} از ۵`}>
              <Star aria-hidden className="size-3.5 fill-current text-warning" />
              {formatNumber(ticket.rating)}
            </span>
          )}
        </span>
      </span>
      {ticket.unread ? <span className="bg-accent size-2.5 shrink-0 rounded-full" role="img" aria-label="پاسخ تازه" /> : <span aria-hidden className="size-2.5 shrink-0" />}
    </Link>
  )
}
