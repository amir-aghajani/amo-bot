import { useId, useRef } from 'react'
import type { QueryKey } from '@tanstack/react-query'
import { ImageIcon, ImagePlus, Info, X } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { Field, FieldGroup } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { IconButton } from '@/components/icon-button'
import { NoteInput } from '@/components/note-input'
import { SendIcon } from '@/components/send-icon'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import type { TicketDetail, TicketMessageRequest } from '@/lib/api-types'
import { formatBytes } from '@/lib/format'
import { TICKET_STATUS } from '@/lib/statuses'
import { useForm } from '@/lib/use-form'
import { useRequestKey } from '@/lib/use-request-key'

/** The longest answer the server takes (Support\Services\Tickets::BODY_MAX). */
const BODY_MAX = 4000

/** The largest picture the server takes (Users\Services\CustomerPictures::MAX_BYTES) — one past it is refused as it is picked. */
const PICTURE_MAX_BYTES = 10 * 1024 * 1024

/** What the picker offers; the server judges the picture by its bytes, not its name. */
const ACCEPT = 'image/jpeg,image/png,image/webp'

/** The server's own words for a picture past its limit. */
const TOO_LARGE = `حجم تصویر حداکثر ${formatBytes(PICTURE_MAX_BYTES)} می‌تواند باشد.`

/** The answer as support writes it: the request's words, and its picture once one is picked — a form then (lib/api). */
type Draft = TicketMessageRequest & { file: File | null }

const BLANK: Draft = { body: '', file: null }

interface ReplyFormProps {
  ticket: TicketDetail
  /** What the answer changes beside the ticket, read again once it went: the lists it is on, the queue's counts. */
  invalidates: readonly QueryKey[]
  /** It went: the ticket as it stands now, the answer last in its conversation. */
  onAnswered: (ticket: TicketDetail) => void
}

/**
 * Support's answer: words (required — a picture alone is no answer) and an optional picture, refused at once when too
 * large. Answering a closed ticket reopens it, which the form says first. Once sent the form starts afresh, focused.
 */
export function ReplyForm({ ticket, invalidates, onAnswered }: ReplyFormProps) {
  const id = useId()
  const picker = useRef<HTMLInputElement>(null)
  const choose = useRef<HTMLButtonElement>(null)
  const { values, set, reset, errors, setErrors, error, formError, busy, dirty, submit, handleSubmit } = useForm<Draft>(BLANK)
  const requestKey = useRequestKey()

  const send = handleSubmit(async () => {
    const { body, file } = values
    // Sent again after no answer, the same words and picture go under the same key: the customer gets one answer.
    const idempotencyKey = requestKey.keyFor([body.trim(), file])
    const answer = await submit(() => api.post(`/tickets/${ticket.id}/messages`, file ? { body, file } : { body }, { idempotencyKey }), { invalidates })
    if (!answer) return
    requestKey.done()
    onAnswered(answer.ticket)
    reset(BLANK)
    // Emptied, the form's submit waits for words: the focus goes back to them, never to the document.
    document.getElementById(`${id}-body`)?.focus()
    toast.success('پاسخ فرستاده شد')
  })

  /** Keeps a picked picture for the answer, or refuses one past the server's limit at once (with the server's message). */
  const pick = (file: File | undefined) => {
    if (!file) return
    if (file.size > PICTURE_MAX_BYTES) {
      set('file', null)
      setErrors({ ...errors, file: [TOO_LARGE] })
      return
    }
    set('file', file)
  }

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>پاسخ به مشتری</CardTitle>
          <CardDescription>مشتری از پاسخ شما خبردار می‌شود: در تلگرام، یا اگر تلگرام ندارد با ایمیل؛ پاسخ در وب‌سایت فروشگاه هم دیده می‌شود.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <form onSubmit={send} noValidate className="grid gap-4">
          {ticket.status === 'closed' && (
            <Callout tone="info" icon={Info}>
              این تیکت بسته شده است؛ پاسخ شما دوباره بازش می‌کند و در وضعیت «{TICKET_STATUS.answered.label}» می‌گذارد.
              {ticket.rating !== null && ' امتیازی که مشتری به آن داده بود کنار گذاشته می‌شود.'}
            </Callout>
          )}

          {/* Held while the answer goes: what is typed meanwhile would go with the form, emptied once it went. */}
          <Field id={`${id}-body`} label="متن پاسخ" error={error('body')}>
            <NoteInput max={BODY_MAX} rows={4} value={values.body} onChange={(event) => set('body', event.target.value)} readOnly={busy} placeholder="پاسخ خود را بنویسید" />
          </Field>

          {/* Its control is a button that opens the hidden file input: refused, the group takes the focus as a whole. */}
          <FieldGroup
            label="تصویر"
            optional
            error={error('file')}
            hint={
              // Each type set apart: bare, the three Latin words would run left to right as one, last first.
              <>
                <bdi dir="ltr">JPG</bdi>، <bdi dir="ltr">PNG</bdi> یا <bdi dir="ltr">WebP</bdi>، حداکثر {formatBytes(PICTURE_MAX_BYTES)}؛ همراه متن پاسخ فرستاده می‌شود.
              </>
            }
          >
            <input
              ref={picker}
              type="file"
              accept={ACCEPT}
              className="sr-only"
              // The button beside it opens it: no second stop for the keyboard.
              tabIndex={-1}
              aria-label="انتخاب تصویر"
              onChange={(event) => {
                const file = event.target.files?.[0]
                event.target.value = ''
                pick(file)
              }}
            />
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <Button ref={choose} variant="secondary" size="sm" icon={ImagePlus} aria-disabled={busy} onClick={() => picker.current?.click()}>
                {values.file ? 'تغییر تصویر' : 'افزودن تصویر'}
              </Button>
              {values.file && (
                <>
                  <span className="flex min-w-0 items-center gap-1.5 rounded-md border border-border px-2 py-0.5 text-footnote">
                    <ImageIcon className="size-3.5 shrink-0 text-faint" aria-hidden />
                    <bdi dir="ltr" className="min-w-0 truncate">
                      {values.file.name}
                    </bdi>
                    <span className="shrink-0 text-muted-foreground">{formatBytes(values.file.size)}</span>
                  </span>
                  <IconButton
                    icon={X}
                    aria-label="برداشتن تصویر"
                    aria-disabled={busy}
                    className="size-7 rounded-md"
                    onClick={() => {
                      set('file', null)
                      // Its button goes with the picture: the focus stays in the field.
                      choose.current?.focus()
                    }}
                  />
                </>
              )}
            </div>
          </FieldGroup>

          <FormActions error={formError} submitLabel="ارسال پاسخ" icon={SendIcon} busy={busy} disabled={values.body.trim() === ''} dirty={dirty} />
        </form>
      </CardContent>
    </Card>
  )
}
