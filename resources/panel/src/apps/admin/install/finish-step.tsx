import type { ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { CircleCheck, CircleX, Info } from 'lucide-react'
import { useNavigate } from 'react-router'
import { forgetKey, installApi } from '@/apps/admin/install/install-api'
import { databaseDriver } from '@/apps/admin/settings/database-form'
import { Callout } from '@/components/callout'
import { FormError } from '@/components/form-footer'
import { PublicPanel } from '@/components/public-screen'
import { Button } from '@/components/ui/button'
import type { AppInfo, InstallStatus } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { useForm } from '@/lib/use-form'
import { cn } from '@/lib/utils'

/** What the installation did, then its end: the installer closes for good and the panel opens on its sign-in. */
export function FinishStep({ status }: { status: InstallStatus }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  // Refused, the server names what is missing (the requirements, the tables, the login) as the form's error.
  const { formError, busy, submit, handleSubmit } = useForm({})

  const finish = handleSubmit(async () => {
    const result = await submit(() => installApi.post('/finish'))
    if (!result) return
    forgetKey()
    queryClient.setQueryData<AppInfo>(queryKeys.app, (info) => info && { ...info, installed: true })
    void navigate('/login', { replace: true })
  })

  const facts: { label: string; value: ReactNode; ok: boolean }[] = [
    { label: 'پیش‌نیازها', value: status.ready ? 'فراهم است' : 'کامل نیست', ok: status.ready },
    {
      label: 'دیتابیس',
      // One element: the row's value sits beside its icon with a gap, which must not open between the driver and its words.
      value: status.database.tables ? (
        <span>
          <bdi dir="ltr">{databaseDriver(status.database, status.database.driver).label}</bdi>، جدول‌ها ساخته شده
        </span>
      ) : (
        'وصل نشده'
      ),
      ok: status.database.tables,
    },
    {
      label: 'ورود پنل',
      value: status.admin.configured ? <bdi dir="ltr">{status.admin.username}</bdi> : 'تعیین نشده',
      ok: status.admin.configured,
    },
    {
      label: 'ربات',
      value: status.telegram.configured ? <bdi dir="ltr">@{status.telegram.username}</bdi> : 'بعدا از تنظیمات',
      ok: true,
    },
  ]

  return (
    <PublicPanel title="پایان نصب" description="با پایان نصب این صفحه دیگر باز نمی‌شود و با نام کاربری و رمزی که ساختید وارد پنل می‌شوید.">
      <ul className="grid gap-1.5">
        {facts.map((fact) => (
          <li key={fact.label} className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2 text-body">
            <span className="text-muted-foreground">{fact.label}</span>
            <span className={cn('flex items-center gap-1.5', !fact.ok && 'text-danger')}>
              {fact.value}
              {fact.ok ? <CircleCheck className="size-4 text-success" aria-hidden /> : <CircleX className="size-4" aria-hidden />}
            </span>
          </li>
        ))}
      </ul>
      <Callout tone="info" icon={Info}>
        کارهای زمان‌بندی‌شده (تایید خودکار رسید، تمدید خودکار، همگام‌سازی سرویس‌ها و یادآوری‌ها) با Cron اجرا می‌شوند: بعد از ورود، آدرس Cron را از «تنظیمات پنل › پیشرفته» بردارید و در بخش Cron Jobs
        هاست طوری بگذارید که هر دقیقه باز شود.
      </Callout>
      <form onSubmit={finish} className="grid gap-4">
        <FormError message={formError} />
        <Button type="submit" className="w-fit" busy={busy}>
          پایان نصب و ورود به پنل
        </Button>
      </form>
    </PublicPanel>
  )
}
