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
 * A ticket's conversation, the first message first: the customer's at the start, support's at the end on a quiet fill —
 * the two told apart by their place and their surface, as the Console sets a conversation, no coloured bubbles —, each
 * saying who wrote it (support's writer as the decision's reviewer is shown), where it was written and when, its words as
 * typed (each line in its own direction), and its picture, which opens at full size — or, once the shop no longer keeps
 * it (an upload of a ticket closed 30 days), a word that it had one.
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
          <span className="font-medium text-foreground">{support ? <SupportAuthor reviewer={message.reviewer} /> : <UserLabel user={customer} />}</span>
          <Badge variant="outline">{TICKET_CHANNEL[message.channel]}</Badge>
          <time dateTime={message.created_at} className="ms-auto text-muted-foreground">
            {formatDate(message.created_at)}
          </time>
        </header>
        <p dir="auto" className="text-body wrap-break-word whitespace-pre-wrap [unicode-bidi:plaintext]">
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

/** Who of support wrote it: «پشتیبانی» and its writer — once, where the writer is support itself (the owner, in an agent's shop). */
function SupportAuthor({ reviewer }: { reviewer: string | null }) {
  if (reviewer === null || reviewer === SUPPORT) return SUPPORT

  return (
    <>
      {SUPPORT}{' '}
      <span className="font-normal text-muted-foreground">
        · <Reviewer name={reviewer} />
      </span>
    </>
  )
}
