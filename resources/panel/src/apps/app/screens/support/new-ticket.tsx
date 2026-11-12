import { useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ImagePlus, RefreshCw, X } from 'lucide-react'
import { useNavigate, useSearchParams } from 'react-router'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { fileOf, PICTURE_TYPES, usePicture } from '@/apps/app/parts/picture'
import { serviceTitle, useService } from '@/apps/app/parts/service'
import { ServicePicker } from '@/apps/app/screens/support/parts/service-picker'
import { backTo, idOf, keepTicket } from '@/apps/app/screens/support/parts/words'
import { result, tap } from '@/apps/app/telegram/haptics'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Field, TextArea, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Notice } from '@/apps/app/ui/states'
import { ApiError } from '@/lib/api'
import type { NamedRef } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'

/*
 * «تیکت تازه»: a subject, the service it is about (the one the service's page came from, `?subscription=<id>`, picked
 * already), the message and, if the customer has one, a picture — sent as a form then (`POST /tickets`). The shop's
 * refusals stand under their fields; one of no field (too many tickets an hour, no room for pictures today) above the
 * main button. Opened, the ticket's conversation takes this screen's place.
 */

/** The shop's bounds (Support\Services\Tickets): a subject of 3 to 120 characters, a message of up to 4000. */
const SUBJECT_LEAST = 3
const SUBJECT_MOST = 120
const BODY_MOST = 4000
/** The message's count shows once it nears its bound. */
const BODY_COUNTED = 3500

/** The fields the shop refuses by name; any other refusal is said apart. */
const FIELDS = ['subject', 'body', 'subscription_id', 'file'] as const

export default function NewTicketScreen() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const preset = idOf(params.get('subscription'))
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  // Undefined until the customer picks: the service the address named, once read.
  const [picked, setPicked] = useState<NamedRef | null | undefined>(undefined)
  const picture = usePicture()
  const picker = useRef<HTMLInputElement>(null)
  const named = useService(preset ?? 0, picked === undefined)
  const service = picked !== undefined ? picked : named.data === undefined ? null : { id: named.data.subscription.id, name: serviceTitle(named.data.subscription) }
  // The service the address named, until it is read (or the customer picks): no ticket goes without it meanwhile. One
  // not theirs (a 404) is none.
  const naming = preset !== null && picked === undefined && named.data === undefined && !(named.error instanceof ApiError && named.error.status === 404)
  const namingFailed = naming && named.isError

  const open = useMutation({
    mutationFn: () => {
      const fields = { subject: subject.trim(), body: body.trim(), ...(service === null ? {} : { subscription_id: service.id }) }
      const file = picture.picked?.file

      return store.post('/tickets', file === undefined ? fields : { ...fields, file })
    },
    meta: { quiet: true },
    onSuccess: ({ ticket }) => {
      result('success')
      keepTicket(ticket)
      navigate(`/support/${ticket.id}`, { replace: true })
    },
    onError: () => result('error'),
  })
  const problem = (field: (typeof FIELDS)[number]) => fieldProblem(open.error, field)
  const general = open.error !== null && FIELDS.every((field) => problem(field) === undefined) ? problemOf(open.error) : null
  const pictureProblem = picture.problem ?? problem('file') ?? null
  const ready = [...subject.trim()].length >= SUBJECT_LEAST && body.trim() !== '' && !naming
  // A refusal is of what was sent: anything changed, it says nothing of it any more.
  const edit = <T,>(set: (value: T) => void, value: T) => {
    open.reset()
    set(value)
  }
  const pick = () => {
    tap()
    picker.current?.click()
  }

  return (
    <Screen back={backTo('/support')} title="تیکت تازه" subtitle="پشتیبانی پاسخ را همین‌جا و در ربات می‌فرستد" bottom="action">
      <Field label="موضوع" hint={`کوتاه و روشن؛ ${formatNumber(SUBJECT_LEAST)} تا ${formatNumber(SUBJECT_MOST)} کاراکتر`} error={problem('subject')}>
        {(id) => (
          <TextInput
            id={id}
            value={subject}
            maxLength={SUBJECT_MOST}
            onChange={(event) => edit(setSubject, event.target.value)}
            placeholder="مثلا قطعی اتصال"
            invalid={problem('subject') !== undefined}
          />
        )}
      </Field>

      <ServicePicker
        value={service}
        onChange={(next) => edit(setPicked, next)}
        pending={naming && !namingFailed}
        error={problem('subscription_id') ?? (namingFailed ? 'سرویس این تیکت خوانده نشد؛ آن را از فهرست انتخاب کنید.' : undefined)}
      />

      <Field
        label="پیام"
        hint={[...body].length >= BODY_COUNTED ? `${formatNumber([...body].length)} از ${formatNumber(BODY_MOST)} کاراکتر` : 'مشکل را با جزئیات بنویسید: از کی، روی چه دستگاهی، چه خطایی.'}
        error={problem('body')}
      >
        {(id) => <TextArea id={id} value={body} maxLength={BODY_MOST} onChange={(event) => edit(setBody, event.target.value)} className="min-h-36" invalid={problem('body') !== undefined} />}
      </Field>

      {/* Laid out as a Field is: its label and its note 16px in, as a section's title is. */}
      <div className="flex flex-col gap-2">
        <span className="text-section-header px-4 text-footnote font-semibold">تصویر (اختیاری)</span>
        {picture.picked === null ? (
          <button
            type="button"
            onClick={pick}
            className="press text-accent-text rounded-control flex h-16 items-center justify-center gap-2 border-2 border-dashed border-current/30 text-body leading-none font-semibold"
          >
            <ImagePlus aria-hidden className="size-6" />
            افزودن تصویر
          </button>
        ) : (
          <div className="animate-rise rounded-control bg-section relative overflow-hidden shadow-card">
            <img src={picture.picked.preview} alt="تصویر انتخاب‌شده" className="max-h-64 w-full object-contain" />
            <div className="absolute end-2 top-2 flex gap-2">
              <Button size="sm" variant="secondary" icon={<RefreshCw />} onClick={pick} className="glass">
                تغییر
              </Button>
              {/* Square: the icon alone, at its centre. */}
              <Button
                size="sm"
                variant="secondary"
                icon={<X />}
                onClick={() => {
                  open.reset()
                  picture.clear()
                }}
                className="glass w-9 px-0"
                aria-label="حذف تصویر"
              />
            </div>
          </div>
        )}
        <input
          ref={picker}
          type="file"
          accept={PICTURE_TYPES}
          aria-label="انتخاب تصویر"
          hidden
          onChange={(event) => {
            const file = fileOf(event)
            if (file === undefined) return
            open.reset()
            void picture.pick(file)
          }}
        />
        {pictureProblem !== null ? (
          <p className="px-4 text-caption text-danger" role="alert">
            {pictureProblem}
          </p>
        ) : (
          <p className="text-hint px-4 text-caption">یک تصویر JPG، PNG یا WebP تا ۱۰ مگابایت؛ مثلا عکس صفحه خطا.</p>
        )}
      </div>

      {general !== null && (
        <div role="alert">
          <Notice tone="danger">{general}</Notice>
        </div>
      )}

      <MainAction text="ارسال تیکت" enabled={ready} busy={open.isPending} onClick={() => open.mutate()} />
    </Screen>
  )
}
