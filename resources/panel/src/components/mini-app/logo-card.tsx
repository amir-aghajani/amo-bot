import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ImageUp, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import { FormError } from '@/components/form-footer'
import { useApplyMiniApp } from '@/components/mini-app/mini-app-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api, mediaUrl } from '@/lib/api'
import type { MiniAppSettings, MiniAppSettingsResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatBytes } from '@/lib/format'

/** What the picker accepts; the server checks the bytes, not the name, and says what it refuses (a picture too big). */
const ACCEPT = 'image/png,image/jpeg,image/webp'

/** The largest logo the server takes (Store\Services\MiniApps::LOGO_MAX_BYTES). */
const LOGO_MAX_BYTES = 2 * 1024 * 1024

/** The colour the app's initial stands on while the shop sets none: Telegram's own blue. */
export const TELEGRAM_BLUE = '#2481cc'

/**
 * The mini app's logo: a preview — the shop's initial on its colour while there is none, as the app shows it —, an
 * upload that replaces it at once (the size limit said up front) and a way to remove it, asked first. Not a form: each
 * action applies immediately; a refused upload shows on the card, a refused removal in its question.
 */
export function LogoCard({ miniApp }: { miniApp: MiniAppSettings }) {
  const applyAnswer = useApplyMiniApp()
  const input = useRef<HTMLInputElement>(null)
  const uploadButton = useRef<HTMLButtonElement>(null)
  // The picture's address changes with every upload, so the browser shows the new one instead of the one it holds.
  const [version, setVersion] = useState(0)

  // The answer is the whole app, in the read's place; a word about the menu button stays with the save that said it.
  const apply = (result: MiniAppSettingsResponse) => applyAnswer((current) => (current ? { ...current, mini_app: result.mini_app } : result))

  const upload = useMutation({
    mutationFn: (file: File) => api.post('/mini-app/logo', { file }),
    onSuccess: (result) => {
      setVersion((current) => current + 1)
      apply(result)
      toast.success('لوگوی مینی‌اپ ذخیره شد')
    },
    meta: { quiet: true },
  })

  // The uploaded file is deleted for good: asked first, as the QR background's way back is.
  const [confirming, setConfirming] = useState(false)
  const handBack = useRef(false)
  const remove = useMutation({
    mutationFn: () => api.delete('/mini-app/logo'),
    onSuccess: (result) => {
      handBack.current = true
      setConfirming(false)
      apply(result)
      toast.success('لوگوی مینی‌اپ حذف شد')
    },
    meta: { quiet: true },
  })

  // The button that asked goes with the logo: as the question closes, the focus goes to the upload beside it, never to
  // the page.
  useEffect(() => {
    if (confirming || !handBack.current) return
    handBack.current = false
    uploadButton.current?.focus()
  }, [confirming])

  const busy = upload.isPending || remove.isPending

  return (
    <Card>
      <CardHeader className="border-b border-border pb-4">
        <CardHeading>
          <CardTitle>لوگو</CardTitle>
          <CardDescription>نشان فروشگاه در مینی‌اپ. تصویر مربعی بهترین نتیجه را می‌دهد؛ بدون لوگو، مینی‌اپ حرف اول نامش را نشان می‌دهد.</CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="flex flex-wrap items-start gap-5 pt-5">
        {miniApp.logo ? (
          <img src={mediaUrl('/mini-app/logo', { v: String(version) })} alt="لوگوی فعلی مینی‌اپ" className="size-24 shrink-0 rounded-2xl border border-border object-cover" />
        ) : (
          <Initial miniApp={miniApp} />
        )}

        <div className="grid min-w-0 flex-1 content-start gap-3">
          <div className="flex flex-wrap gap-2">
            <input
              ref={input}
              type="file"
              accept={ACCEPT}
              className="sr-only"
              // The button beside it opens it: no second stop for the keyboard.
              tabIndex={-1}
              aria-label="انتخاب تصویر لوگو"
              onChange={(e) => {
                const file = e.target.files?.[0]
                e.target.value = ''
                if (!file) return
                remove.reset()
                upload.mutate(file)
              }}
            />
            {/* Held while either runs — held, not disabled, so the one pressed keeps the focus. */}
            <Button ref={uploadButton} variant="secondary" size="sm" icon={ImageUp} busy={upload.isPending} aria-disabled={busy} onClick={() => input.current?.click()}>
              {miniApp.logo ? 'آپلود لوگوی جدید' : 'آپلود لوگو'}
            </Button>
            {miniApp.logo && (
              <Button
                variant="ghost"
                size="sm"
                icon={Trash2}
                aria-disabled={busy}
                onClick={() => {
                  remove.reset()
                  setConfirming(true)
                }}
              >
                حذف لوگو
              </Button>
            )}
          </div>

          {/* Each format set apart: a run of Latin names in a Persian sentence would read in the wrong order. */}
          <p className="text-footnote leading-relaxed text-muted-foreground">
            <bdi dir="ltr">JPG</bdi>، <bdi dir="ltr">PNG</bdi> یا <bdi dir="ltr">WebP</bdi>، حداکثر {formatBytes(LOGO_MAX_BYTES)}؛ تصویر بزرگ‌تر از ۵۱۲ پیکسل کوچک می‌شود.
          </p>
          <FormError message={upload.error && messageOf(upload.error, 'file')} />
        </div>
      </CardContent>

      <ConfirmModal
        open={confirming}
        onClose={() => setConfirming(false)}
        title="حذف لوگوی مینی‌اپ"
        description="لوگو حذف می‌شود و مینی‌اپ به‌جای آن حرف اول نامش را نشان می‌دهد."
        confirmLabel="حذف لوگو"
        destructive
        pending={remove.isPending}
        error={remove.error && messageOf(remove.error)}
        onConfirm={() => {
          upload.reset()
          remove.mutate()
        }}
      >
        این کار برگشت‌پذیر نیست؛ برای استفاده دوباره از همین تصویر باید آن را دوباره آپلود کنید.
      </ConfirmModal>
    </Card>
  )
}

/** No logo: the first letter of the app's name on its colour, as the app shows it. */
function Initial({ miniApp }: { miniApp: MiniAppSettings }) {
  const name = (miniApp.title ?? miniApp.shop_name).trim()

  return (
    <div className="grid shrink-0 justify-items-center gap-1.5">
      <span aria-hidden className="grid size-24 place-items-center rounded-2xl text-display font-medium text-white" style={{ backgroundColor: miniApp.accent_color ?? TELEGRAM_BLUE }}>
        {Array.from(name)[0] ?? ''}
      </span>
      <span className="text-caption text-muted-foreground">بدون لوگو</span>
    </div>
  )
}
