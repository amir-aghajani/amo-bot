import { useRef } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ImageUp, Undo2 } from 'lucide-react'
import { toast } from 'sonner'
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
 * The picture QR codes are drawn on: a preview of the one in use, an upload that replaces it at once — the largest it
 * takes said up front, the server's own limit —, and a way back to the shipped picture. Not a form — each action applies
 * as it happens; a refusal is said on the card. A picture's size in pixels is a measure of the file, written as such:
 * Latin digits, no separators («1024×1024»).
 */
export function QrBackgroundCard({ background }: { background: QrBackgroundInfo }) {
  const queryClient = useQueryClient()
  const input = useRef<HTMLInputElement>(null)

  const apply = (result: QrBackgroundResponse) => queryClient.setQueryData<BotSettingsResponse>(queryKeys.botSettings, (current) => current && { ...current, qr_background: result.qr_background })

  const upload = useMutation({
    mutationFn: (file: File) => api.post('/bot/qr-background', { file }),
    onSuccess: (result) => {
      apply(result)
      toast.success('پس‌زمینه کد QR عوض شد')
    },
    meta: { quiet: true },
  })

  const reset = useMutation({
    mutationFn: () => api.delete('/bot/qr-background'),
    onSuccess: (result) => {
      apply(result)
      toast.success('پس‌زمینه به تصویر پیش‌فرض برگشت')
    },
    meta: { quiet: true },
  })

  const busy = upload.isPending || reset.isPending
  const failure = upload.error ?? reset.error
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
            <Button variant="secondary" size="sm" icon={ImageUp} busy={upload.isPending} disabled={busy} onClick={() => input.current?.click()}>
              آپلود تصویر جدید
            </Button>
            {background.custom && (
              <Button
                variant="ghost"
                size="sm"
                icon={Undo2}
                busy={reset.isPending}
                disabled={busy}
                onClick={() => {
                  upload.reset()
                  reset.mutate()
                }}
              >
                بازگشت به پیش‌فرض
              </Button>
            )}
          </div>

          <p className="text-footnote leading-relaxed text-muted-foreground">PNG، JPG یا WebP، حداکثر {formatBytes(background.max_bytes)}. تصویر همان لحظه جایگزین می‌شود.</p>
          <FormError message={failure && messageOf(failure, 'file')} />
        </div>
      </CardContent>
    </Card>
  )
}
