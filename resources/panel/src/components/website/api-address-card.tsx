import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Copy, Info, RefreshCw } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import type { Website, WebsiteResponse } from '@/lib/api-types'
import { copyText } from '@/lib/clipboard'
import { messageOf } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'

/**
 * What the site's developer is handed: the API's base address — the store key in it, which names the shop and is no
 * secret — to copy, and a new key when the old address must stop working: asked first, since the site loses the API
 * until it takes the new one, the website it answers put in place of the page's read. Not a form — a new key applies at
 * once.
 */
export function ApiAddressCard({ website }: { website: Website }) {
  const queryClient = useQueryClient()
  const [asking, setAsking] = useState(false)
  const rotate = useMutation({
    mutationFn: () => api.post('/website/key'),
    meta: { quiet: true },
    onSuccess: (result) => {
      queryClient.setQueryData<WebsiteResponse>(queryKeys.website, result)
      setAsking(false)
      toast.success('کلید جدید ساخته شد؛ آدرس تازه را به وب‌سایت بدهید')
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">آدرس API</CardTitle>
          <CardDescription>این آدرس را به برنامه‌نویس وب‌سایت بدهید؛ همه مسیرهای API زیر آن است.</CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="grid gap-4">
        <div className="flex items-center gap-2 rounded-lg border border-border bg-fill p-2 ps-3">
          <span dir="ltr" className="min-w-0 flex-1 text-footnote break-all select-all">
            {website.base_url}
          </span>
          <Button size="sm" variant="secondary" icon={Copy} onClick={() => void copyText(website.base_url, 'آدرس API کپی شد')}>
            کپی
          </Button>
        </div>

        <div className="flex flex-wrap items-end justify-between gap-3">
          <div className="grid gap-0.5">
            <span className="text-footnote text-muted-foreground">کلید فروشگاه</span>
            <code dir="ltr" className="w-fit text-body">
              {website.key}
            </code>
            <span className="text-footnote text-muted-foreground">فروشگاه را در آدرس مشخص می‌کند و رمز نیست.</span>
          </div>
          <Button
            variant="danger-outline"
            size="sm"
            icon={RefreshCw}
            onClick={() => {
              rotate.reset()
              setAsking(true)
            }}
          >
            ساخت کلید جدید
          </Button>
        </div>

        <Callout tone="info" icon={Info}>
          وب‌سایت درخواست‌هایش را به همین آدرس می‌فرستد؛ برای مشتری واردشده، توکنی را که ورودش برگردانده در هدر <code dir="ltr">Authorization: Bearer …</code> می‌گذارد.
        </Callout>
      </CardContent>

      <ConfirmModal
        open={asking}
        onClose={() => setAsking(false)}
        title="ساخت کلید جدید"
        confirmLabel="ساخت کلید جدید"
        destructive
        pending={rotate.isPending}
        error={rotate.error ? messageOf(rotate.error) : null}
        onConfirm={() => rotate.mutate()}
      >
        آدرس API فعلی بلافاصله از کار می‌افتد و وب‌سایت باید آدرس جدید را بگیرد.
      </ConfirmModal>
    </Card>
  )
}
