import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ImageUp, Undo2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import { FormError } from '@/components/form-footer'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api, mediaUrl } from '@/lib/api'
import type { BotSettingsResponse, QrBackgroundInfo, QrBackgroundResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatBytes } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/** What the picker accepts; the server checks the bytes, not the name, and says what it refuses (a picture too big). */
const ACCEPT = 'image/png,image/jpeg,image/webp'

/**
 * The picture QR codes are drawn on: a preview, an upload that replaces it at once (the size limit said up front) and a
 * way back to the shipped picture, asked first. Not a form: each action applies immediately; a refused upload shows on
 * the card, a refused way back in its question.
 */
export function QrBackgroundCard({ background }: { background: QrBackgroundInfo }) {
  const queryClient = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  const uploadButton = useRef<HTMLButtonElement>(null)

  const apply = (result: QrBackgroundResponse) => queryClient.setQueryData<BotSettingsResponse>(queryKeys.botSettings, (current) => current && { ...current, qr_background: result.qr_background })

  const upload = useMutation({
    mutationFn: (file: File) => api.post('/bot/qr-background', { file }),
    onSuccess: (result) => {
      apply(result)
      toast.success('پس‌زمینه کد QR عوض شد')
    },
    meta: { quiet: true },
  })

  // The uploaded picture is deleted for good: asked first, as the keyboards' and the texts' way back to the default is.
  const [confirming, setConfirming] = useState(false)
  const handBack = useRef(false)
  const reset = useMutation({
    mutationFn: () => api.delete('/bot/qr-background'),
    onSuccess: (result) => {
      handBack.current = true
      setConfirming(false)
      apply(result)
      toast.success('پس‌زمینه به تصویر پیش‌فرض برگشت')
    },
    meta: { quiet: true },
  })

  // The button that asked goes with the uploaded picture: as the question closes, the focus goes to the upload beside it
  // (after the dialog hands it back — the dialog is a child, its effect runs first), never to the page.
  useEffect(() => {
    if (confirming || !handBack.current) return
    handBack.current = false
    uploadButton.current?.focus()
  }, [confirming])

  const busy = upload.isPending || reset.isPending
  // The URL changes with the file, so the browser fetches the new picture instead of showing a cached one.
  const src = mediaUrl('/bot/qr-background', { v: String(background.updated_at) })

  return (
    <Card>
      <CardHeader className="border-b border-border pb-4">
        <CardHeading>
          <CardTitle>پس‌زمینه کد QR</CardTitle>
          <CardDescription>
            تصویری که کد QR روی آن کشیده می‌شود. مربع سفید وسط جای کد است؛ تصویر مربعی (مثلا <bdi dir="ltr">1024×1024</bdi>) بهترین نتیجه را می‌دهد.
          </CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="flex flex-wrap items-start gap-5 pt-5">
        <img src={src} alt="پس‌زمینه فعلی کد QR" className="size-40 shrink-0 rounded-lg border border-border object-cover" />

        <div className="grid min-w-0 flex-1 content-start gap-3">
          <div className="flex flex-wrap items-center gap-2 text-body">
            <Badge variant="outline">{background.custom ? 'آپلودشده' : 'پیش‌فرض'}</Badge>
            <span className="text-muted-foreground">
              <bdi dir="ltr">
                {background.width}×{background.height}
              </bdi>{' '}
              · {formatBytes(background.size)}
            </span>
          </div>

          <div className="flex flex-wrap gap-2">
            <input
              ref={input}
              type="file"
              accept={ACCEPT}
              className="sr-only"
              // The button beside it opens it: no second stop for the keyboard.
              tabIndex={-1}
              aria-label="انتخاب تصویر پس‌زمینه"
              onChange={(e) => {
                const file = e.target.files?.[0]
                e.target.value = ''
                if (!file) return
                reset.reset()
                upload.mutate(file)
              }}
            />
            {/* Held while either runs — held, not disabled, so the one pressed keeps the focus. */}
            <Button ref={uploadButton} variant="secondary" size="sm" icon={ImageUp} busy={upload.isPending} aria-disabled={busy} onClick={() => input.current?.click()}>
              آپلود تصویر جدید
            </Button>
            {background.custom && (
              <Button
                variant="ghost"
                size="sm"
                icon={Undo2}
                aria-disabled={busy}
                onClick={() => {
                  reset.reset()
                  setConfirming(true)
                }}
              >
                بازگشت به پیش‌فرض
              </Button>
            )}
          </div>

          {/* Each format set apart: a run of Latin names in a Persian sentence would read in the wrong order. */}
          <p className="text-footnote leading-relaxed text-muted-foreground">
            <bdi dir="ltr">JPG</bdi>، <bdi dir="ltr">PNG</bdi> یا <bdi dir="ltr">WebP</bdi>، حداکثر {formatBytes(background.max_bytes)}. تصویر همان لحظه جایگزین می‌شود.
          </p>
          <FormError message={upload.error && messageOf(upload.error, 'file')} />
        </div>
      </CardContent>

      <ConfirmModal
        open={confirming}
        onClose={() => setConfirming(false)}
        title="بازگشت به پس‌زمینه پیش‌فرض"
        description="تصویر آپلودشده حذف می‌شود و کدهای QR دوباره روی تصویر پیش‌فرض فروشگاه کشیده می‌شوند."
        confirmLabel="بازگشت به پیش‌فرض"
        destructive
        pending={reset.isPending}
        error={reset.error && messageOf(reset.error)}
        onConfirm={() => {
          upload.reset()
          reset.mutate()
        }}
      >
        این کار برگشت‌پذیر نیست؛ برای استفاده دوباره از همین تصویر باید آن را دوباره آپلود کنید.
      </ConfirmModal>
    </Card>
  )
}
