import { ApiPicture, PictureNote } from '@/components/api-picture'
import { Reviewer } from '@/components/reviewer'
import { Badge } from '@/components/ui/badge'
import { UserLabel } from '@/components/user-identity'
import type { TicketDetail, TicketMessageRow, UserRef } from '@/lib/api-types'
import { isolate } from '@/lib/direction'
import { formatDate } from '@/lib/format'
import { TICKET_CHANNEL } from '@/lib/statuses'
import { cn } from '@/lib/utils'

/** Support's word for itself — and how an agent's shop names the owner's answers (Auth\Services\Reviewers::SUPPORT). */
const SUPPORT = 'پشتیبانی'

/**
 * A ticket's conversation, oldest first: the customer's messages at the start, support's at the end on a quiet fill (no
 * coloured bubbles). Each says who wrote it, where and when, and shows its picture — or that it had one the shop no
 * longer keeps (uploads go 30 days after a ticket closes).
 */
export function Conversation({ ticket }: { ticket: TicketDetail }) {
  return (
    <ol className="grid gap-3" aria-label="پیام‌های تیکت">
      {ticket.messages.map((message) => (
        <Message key={message.id} ticket={ticket.id} customer={ticket.customer} message={message} />
      ))}
    </ol>
  )
}

function Message({ ticket, customer, message }: { ticket: number; customer: UserRef; message: TicketMessageRow }) {
  const support = message.author === 'support'

  return (
    <li className={cn('flex min-w-0', support ? 'justify-end' : 'justify-start')}>
      <article
        aria-label={support ? 'پاسخ پشتیبانی' : 'پیام مشتری'}
        className={cn('grid w-fit max-w-[92%] min-w-0 gap-2 rounded-xl px-3.5 py-3 sm:max-w-[85%]', support ? 'bg-fill' : 'border border-border')}
      >
        <header className="flex flex-wrap items-center gap-x-2 gap-y-1 text-footnote">
          <span className="min-w-0 font-medium wrap-anywhere text-foreground">
            {support ? <SupportAuthor reviewer={message.reviewer} person={message.reviewer_name} /> : <UserLabel user={customer} />}
          </span>
          <Badge variant="outline">{TICKET_CHANNEL[message.channel]}</Badge>
          <time dateTime={message.created_at} className="ms-auto text-muted-foreground">
            {formatDate(message.created_at)}
          </time>
        </header>
        {/* Breaks anywhere: a long link or a pasted config has nowhere to break else, and would stretch the message past
            its column (`wrap-break-word` breaks too, but leaves the message as wide as the longest run). */}
        <p dir="auto" className="text-body wrap-anywhere whitespace-pre-wrap [unicode-bidi:plaintext]">
          {message.body}
        </p>
        {message.attachment &&
          (message.attachment.kept ? (
            <ApiPicture path={`/tickets/${ticket}/messages/${message.id}/attachment`} alt={`تصویر ${isolate(message.attachment.name)}`} name={message.attachment.name} what="تصویر" place="thumbnail" />
          ) : (
            <PictureNote place="thumbnail">تصویر این پیام دیگر نگه داشته نمی‌شود؛ تصویرهای آپلودشده ۳۰ روز بعد از بسته شدن تیکت پاک می‌شوند.</PictureNote>
          ))}
      </article>
    </li>
  )
}

/**
 * Who of support wrote it: «پشتیبانی» and its writer — by their name where the server read one (`person`), else as kept —,
 * once where the writer is support itself (the owner, in an agent's shop).
 */
function SupportAuthor({ reviewer, person }: { reviewer: string | null; person: string | null }) {
  if (reviewer === null || reviewer === SUPPORT) return SUPPORT

  return (
    <>
      {SUPPORT}{' '}
      <span className="font-normal text-muted-foreground">
        · <Reviewer name={reviewer} person={person} />
      </span>
    </>
  )
}
