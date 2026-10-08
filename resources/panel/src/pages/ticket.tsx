import { useState } from 'react'
import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, LockOpen } from 'lucide-react'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { BackLink } from '@/components/back-link'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { Fact, FactList } from '@/components/fact-list'
import { NotFoundPage } from '@/components/not-found'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { StatusBadge } from '@/components/status-badge'
import { searchLink, TextLink } from '@/components/text-link'
import { Conversation } from '@/components/tickets/conversation'
import { ReplyForm } from '@/components/tickets/reply-form'
import { TicketRating } from '@/components/tickets/ticket-cells'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { UserIdentity, userPage } from '@/components/user-identity'
import { api, stateChanged } from '@/lib/api'
import type { TicketDetail, TicketResponse } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { useTitleSubject } from '@/lib/document-title'
import { messageOf } from '@/lib/failure'
import { formatDate, formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { TICKET_STATUS } from '@/lib/statuses'

/** One ticket with its whole conversation — the live updates read it again as the tickets move. */
const ticketQuery = (id: number) => queryOptions({ queryKey: queryKeys.ticket(id), queryFn: () => api.get<TicketResponse>(`/tickets/${id}`).then((data) => data.ticket) })

/** What a write to a ticket changes beside it: the lists it is on (a customer's page's too), the queue's counts on the menu and the dashboard. */
const CHANGES = [queryKeys.tickets, queryKeys.queues, queryKeys.dashboards]

/**
 * One support ticket (`/tickets/:id`, both panels), as support answers it: what it is about and where it stands — since
 * when, once closed —, whose it is and the service it is about, the customer's word on it once rated, the conversation,
 * and the answer — and closing it (the customer told) or opening it again (asked first when that takes the customer's
 * rating with it). Another shop's ticket is not found; another ticket's page starts afresh: nothing of one — an answer
 * half written, a dialog — is left on another.
 */
export function TicketPage() {
  const id = Number(useParams().id)
  if (!Number.isSafeInteger(id) || id <= 0) return <NotFoundPage />

  return <TicketView key={id} id={id} />
}

function TicketView({ id }: { id: number }) {
  const queryClient = useQueryClient()
  const query = ticketQuery(id)
  const detail = useQuery(query)
  const [closing, setClosing] = useState(false)
  const [reopening, setReopening] = useState(false)
  useTitleSubject(detail.data && `تیکت ${idLabel(id)}`)

  /** The ticket as a write answered it: on the page at once. */
  const apply = (ticket: TicketDetail) => queryClient.setQueryData(query.queryKey, ticket)
  /** Refused because the ticket moved on meanwhile — closed, or opened again, elsewhere —: it is read again. */
  const recover = (error: unknown) => {
    if (stateChanged(error)) void detail.refetch()
  }

  // The dialog that asked says why it was refused.
  const close = useMutation({
    mutationFn: () => api.post(`/tickets/${id}/close`),
    meta: { quiet: true, invalidates: CHANGES },
    onSuccess: ({ ticket }) => {
      apply(ticket)
      setClosing(false)
      toast.success('تیکت بسته شد')
    },
    onError: recover,
  })

  // Asked first, the dialog says why it was refused; opened at a press, a toast does.
  const reopen = useMutation({
    mutationFn: () => api.post(`/tickets/${id}/reopen`),
    meta: { quiet: reopening, invalidates: CHANGES },
    onSuccess: ({ ticket }) => {
      apply(ticket)
      setReopening(false)
      toast.success('تیکت دوباره باز شد')
    },
    onError: recover,
  })

  if (detail.isPending) {
    return (
      <Page width="default">
        <div className="grid gap-3" aria-busy="true" aria-label="در حال بارگذاری تیکت">
          <BackLink to="/tickets">تیکت‌ها</BackLink>
          <Skeleton className="h-9 w-72 max-w-full" />
          <Skeleton className="h-4 w-56 max-w-full" />
        </div>
        <div className="grid gap-4 xl:grid-cols-3">
          <Skeleton className="h-96 w-full rounded-xl xl:col-span-2" />
          <Skeleton className="h-48 w-full rounded-xl" />
        </div>
      </Page>
    )
  }

  const failure = detail.error && <ErrorState what="تیکت" error={detail.error} onRetry={() => void detail.refetch()} retrying={detail.isFetching} />

  if (!detail.data) {
    return (
      <Page width="default">
        <div className="grid gap-3">
          <BackLink to="/tickets">تیکت‌ها</BackLink>
          <PageHeader title="تیکت" />
        </div>
        {failure}
      </Page>
    )
  }

  const ticket = detail.data
  const closed = ticket.status === 'closed'

  return (
    <Page width="default">
      <div className="grid gap-3">
        <BackLink to="/tickets">تیکت‌ها</BackLink>
        <PageHeader
          title={
            // The space keeps the subject and its state words apart for assistive tech, not one.
            <span className="flex flex-wrap items-center gap-2">
              <bdi>{ticket.subject}</bdi> <StatusBadge status={TICKET_STATUS[ticket.status]} />
            </span>
          }
          description={
            <>
              تیکت{' '}
              <bdi dir="ltr" className="text-foreground">
                #{ticket.id}
              </bdi>{' '}
              · باز شده در {formatDate(ticket.created_at)}
              {closed && ticket.closed_at !== null && <> · بسته شده در {formatDate(ticket.closed_at)}</>}
            </>
          }
          actions={
            // One button whose work follows the ticket's state, so the focus stays on it as it turns into the other.
            <Button
              variant="secondary"
              icon={closed ? LockOpen : Lock}
              busy={reopen.isPending}
              onClick={() => {
                if (!closed) {
                  close.reset()
                  setClosing(true)
                } else if (ticket.rating !== null) {
                  // Opened again, the ticket leaves the customer's rating behind: that is asked first.
                  reopen.reset()
                  setReopening(true)
                } else {
                  reopen.mutate()
                }
              }}
            >
              {closed ? 'باز کردن دوباره' : 'بستن تیکت'}
            </Button>
          }
        />
      </div>

      {/* A later read that failed leaves the page as it was, under the failure. */}
      {failure}

      {/* On a phone the facts come first, then the conversation and the answer; on a wide screen the facts are beside them. */}
      <div className="grid gap-4 xl:grid-cols-3 xl:items-start">
        <div className="grid min-w-0 content-start gap-4 xl:col-start-3 xl:row-start-1">
          <TicketFacts ticket={ticket} />
        </div>

        <div className="grid min-w-0 content-start gap-4 xl:col-span-2 xl:col-start-1 xl:row-start-1">
          <Card>
            <CardHeader>
              <CardHeading>
                <CardTitle>گفتگو</CardTitle>
                <CardDescription>{`${formatNumber(ticket.messages_count)} پیام · آخرین پیام ${timeAgo(ticket.last_message_at)}`}</CardDescription>
              </CardHeading>
            </CardHeader>
            <CardContent>
              <Conversation ticket={ticket} />
            </CardContent>
          </Card>

          <ReplyForm ticket={ticket} invalidates={CHANGES} onAnswered={apply} />
        </div>
      </div>

      <ConfirmModal
        open={closing}
        onClose={() => setClosing(false)}
        title="بستن تیکت"
        description={`تیکت ${idLabel(ticket.id)} بسته می‌شود و مشتری خبردار می‌شود.`}
        confirmLabel="بستن تیکت"
        pending={close.isPending}
        error={close.error ? messageOf(close.error) : null}
        onConfirm={() => close.mutate()}
      >
        اگر مشتری دوباره پیامی بفرستد، تیکت خودبه‌خود باز می‌شود.
      </ConfirmModal>

      <ConfirmModal
        open={reopening}
        onClose={() => setReopening(false)}
        title="باز کردن دوباره تیکت"
        description="باز کردن دوباره امتیاز مشتری را پاک می‌کند."
        confirmLabel="باز کردن دوباره"
        pending={reopen.isPending}
        error={reopen.error ? messageOf(reopen.error) : null}
        onConfirm={() => reopen.mutate()}
      >
        {`تیکت ${idLabel(ticket.id)} در وضعیت «${TICKET_STATUS.open.label}» قرار می‌گیرد و مشتری از آن خبردار نمی‌شود. اگر تیکت دوباره بسته شود، مشتری می‌تواند باز هم امتیاز بدهد.`}
      </ConfirmModal>
    </Page>
  )
}

/** Whose the ticket is — the customer, their page a press away —, the service it is about, and the customer's word on it once rated. */
function TicketFacts({ ticket }: { ticket: TicketDetail }) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>جزئیات تیکت</CardTitle>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <FactList className="grid-cols-[minmax(5.5rem,auto)_1fr] gap-x-4 gap-y-3">
          <Fact label="مشتری">
            <UserIdentity user={ticket.customer} to={userPage(ticket.customer.id)} />
          </Fact>
          <Fact label="سرویس">
            {ticket.subscription ? (
              <TextLink to={searchLink('/subscriptions', ticket.subscription.id)}>
                <bdi dir="ltr">{ticket.subscription.name}</bdi>
              </TextLink>
            ) : (
              <span className="text-muted-foreground">بدون سرویس</span>
            )}
          </Fact>
          {ticket.rating !== null && (
            <Fact label="امتیاز مشتری">
              <div className="grid gap-1">
                <TicketRating rating={ticket.rating} />
                {ticket.rating_note && (
                  <p dir="auto" className="text-footnote whitespace-pre-wrap text-muted-foreground [unicode-bidi:plaintext]">
                    {ticket.rating_note}
                  </p>
                )}
              </div>
            </Fact>
          )}
        </FactList>
      </CardContent>
    </Card>
  )
}
