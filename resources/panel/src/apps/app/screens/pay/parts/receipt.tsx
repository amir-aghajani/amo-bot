import { useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ImagePlus, RefreshCw } from 'lucide-react'
import { keys, queryClient } from '@/apps/app/lib/query'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { fileOf, PICTURE_TYPES, usePicture } from '@/apps/app/parts/picture'
import { Step } from '@/apps/app/screens/pay/parts/step'
import { result, tap } from '@/apps/app/telegram/haptics'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Field, TextArea } from '@/apps/app/ui/fields'
import { Notice } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'
import { IconTile } from '@/apps/app/ui/visuals'

/*
 * A card transfer's receipt, sent from the phone: a picture of it (its preview shown), a note for support if the
 * customer has one, and «ارسال رسید» (`POST /payments/{id}/receipt`). The picture is checked and a large photo drawn
 * smaller as it is picked (parts/picture, as a ticket's). The answer is the order, its payment now with support.
 */

/** The receipt's form — a step of paying (`number`, `title`: «۲. ارسال رسید») —, with the screen's main action: «ارسال رسید». */
export function ReceiptForm({ paymentId, number, title }: { paymentId: number; number: string; title: string }) {
  const input = useRef<HTMLInputElement>(null)
  const picture = usePicture()
  const { picked } = picture
  const [note, setNote] = useState('')
  const send = useMutation({
    mutationFn: (file: Blob) => {
      const written = note.trim()
      return store.post(`/payments/${paymentId}/receipt`, { file, ...(written === '' ? {} : { note: written }) })
    },
    meta: { quiet: true },
    onSuccess: ({ order }) => {
      queryClient.setQueryData(keys.order(order.id), { order })
      told('رسید فرستاده شد')
    },
    onError: () => result('error'),
  })
  const pick = () => {
    tap()
    input.current?.click()
  }
  const problem = picture.problem ?? (send.error === null ? null : (fieldProblem(send.error, 'file') ?? (fieldProblem(send.error, 'note') === undefined ? problemOf(send.error) : null)))

  return (
    <>
      <Step number={number} title={title} footer="تصویر رسید را بفرستید (JPG، PNG یا WebP)؛ پشتیبانی آن را بررسی و سفارش را تایید می‌کند.">
        {picked === null ? (
          <button type="button" onClick={pick} className="press text-accent-text rounded-control flex h-36 w-full flex-col items-center justify-center gap-3 border-2 border-dashed border-current/30">
            <IconTile icon={ImagePlus} size="xl" look="soft" round />
            <span className="text-body font-semibold">انتخاب تصویر رسید</span>
          </button>
        ) : (
          <div className="relative">
            <img src={picked.preview} alt="تصویر رسید" className="rounded-control max-h-80 w-full bg-fill object-contain" />
            <Button size="sm" variant="secondary" icon={<RefreshCw />} onClick={pick} className="glass absolute end-2 top-2">
              تغییر تصویر
            </Button>
          </div>
        )}
        <input
          ref={input}
          type="file"
          accept={PICTURE_TYPES}
          aria-label="تصویر رسید"
          hidden
          onChange={(event) => {
            const file = fileOf(event)
            if (file === undefined) return
            send.reset()
            void picture.pick(file)
          }}
        />
        <Field label="توضیح برای پشتیبانی (اختیاری)" error={fieldProblem(send.error, 'note')}>
          {(id) => (
            <TextArea id={id} value={note} maxLength={1024} onChange={(event) => setNote(event.target.value)} className="min-h-20 bg-fill shadow-none" placeholder="مثلا چهار رقم آخر کارت مبدا" />
          )}
        </Field>
        {problem !== null && (
          <div role="alert">
            <Notice tone="danger">{problem}</Notice>
          </div>
        )}
      </Step>
      <MainAction text="ارسال رسید" enabled={picked !== null} busy={send.isPending} onClick={() => picked !== null && send.mutate(picked.file)} />
    </>
  )
}
