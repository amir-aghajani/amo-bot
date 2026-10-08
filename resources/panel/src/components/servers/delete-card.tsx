import { useState } from 'react'
import { Trash2 } from 'lucide-react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import type { ServerPage } from '@/components/servers/use-server'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import type { ServerRow } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'

/** Deleting a server nothing was sold on, asked first; refused (it has services), the dialog says why. */
export function DeleteCard({ page, server }: { page: ServerPage; server: ServerRow }) {
  const [asking, setAsking] = useState(false)
  const navigate = useNavigate()
  const { remove } = page

  const close = () => {
    setAsking(false)
    remove.reset()
  }

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>حذف سرور</CardTitle>
          <CardDescription>فقط وقتی ممکن است که هیچ اشتراکی روی این سرور ثبت نشده باشد؛ در غیر این صورت آن را غیرفعال کنید.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <Button variant="destructive" icon={Trash2} onClick={() => setAsking(true)}>
          حذف سرور
        </Button>
      </CardContent>

      <ConfirmModal
        open={asking}
        onClose={close}
        title="حذف سرور"
        description={`«${server.name}» و اینباندهای ذخیره‌شده آن حذف می‌شوند. این کار برگشت‌پذیر نیست.`}
        confirmLabel="حذف"
        destructive
        pending={remove.isPending}
        error={remove.error && messageOf(remove.error)}
        onConfirm={() =>
          remove.mutate(undefined, {
            onSuccess: () => {
              toast.success('سرور حذف شد')
              void navigate('/servers', { replace: true })
            },
          })
        }
      >
        اطلاعات اتصال و توکن این سرور از دیتابیس پاک می‌شود. خود پنل دست نمی‌خورد.
      </ConfirmModal>
    </Card>
  )
}
