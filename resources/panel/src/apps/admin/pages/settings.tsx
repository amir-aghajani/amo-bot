import { useQuery } from '@tanstack/react-query'
import { CircleAlert, FileCog } from 'lucide-react'
import { PANEL_SETTINGS_SECTIONS } from '@/apps/admin/nav'
import { AdvancedSection } from '@/apps/admin/settings/advanced-section'
import { AppSection } from '@/apps/admin/settings/app-section'
import { DatabaseSection } from '@/apps/admin/settings/database-section'
import { LoginSection } from '@/apps/admin/settings/login-section'
import { MailSection, MailTestCard } from '@/apps/admin/settings/mail-section'
import { TelegramSection } from '@/apps/admin/settings/telegram-section'
import { UPDATE_DESCRIPTION, UpdateSection } from '@/apps/admin/settings/update-section'
import { WebhookCard } from '@/apps/admin/settings/webhook-card'
import { APPEARANCE_DESCRIPTION, AppearanceCard } from '@/components/appearance-card'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { useReadFailure } from '@/components/page'
import { SectionedPage } from '@/components/sectioned-page'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { ConfigSettingsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * «تنظیمات پنل» (the owner's): config.php's groups — kept in a file so they stay editable even when the database is
 * broken —, the owner's login, the panel's look in this browser, and AmoBot's update.
 */
export function SettingsPage() {
  const read = useQuery({ queryKey: queryKeys.configSettings, queryFn: () => api.get<ConfigSettingsResponse>('/settings/config'), staleTime: 60_000 })
  const { data, refetch } = read
  const failure = useReadFailure(read)
  const readOnly = data ? !data.file.writable : false

  return (
    <SectionedPage
      sections={PANEL_SETTINGS_SECTIONS}
      width="narrow"
      header={(current) =>
        current.value === 'appearance' || current.value === 'update'
          ? { title: 'تنظیمات پنل', description: current.value === 'update' ? UPDATE_DESCRIPTION : APPEARANCE_DESCRIPTION }
          : {
              title: 'تنظیمات پنل',
              description: (
                <>
                  تنظیمات برنامه، ربات، دیتابیس، ایمیل و ورود به پنل. این مقادیر در فایل <code dir="ltr">config.php</code> برنامه ذخیره می‌شوند، نه در دیتابیس.
                </>
              ),
              // The file's whole path — where the host's File Manager finds it —, wrapped on a phone rather than past its edge.
              actions: data && (
                <Badge variant="outline" className="h-auto min-h-6 max-w-full gap-1.5 px-2 py-0.5 font-normal whitespace-normal">
                  <FileCog aria-hidden />
                  <code dir="ltr" className="min-w-0 break-all">
                    {data.file.path}
                  </code>
                  <span aria-hidden>·</span>
                  {data.file.writable ? 'قابل ویرایش' : 'فقط خواندنی'}
                </Badge>
              ),
            }
      }
      above={(current) => {
        if (current.value === 'appearance' || current.value === 'update') return null
        // The login's section reads nothing: what the file's read did not answer is the other sections' to say.
        const reads = current.value !== 'login'

        return (
          <>
            {reads && failure.error && <ErrorState what="تنظیمات" error={failure.error} onRetry={() => void refetch()} retrying={failure.retrying} />}
            {readOnly && (
              <Callout tone="danger" icon={CircleAlert}>
                <span className="font-medium">فایل config.php قابل نوشتن نیست.</span> در File Manager هاست به این فایل و پوشه برنامه اجازه نوشتن بدهید تا تنظیمات از این‌جا ذخیره شوند؛ تا آن موقع
                می‌توانید خود فایل را همان‌جا ویرایش کنید.
              </Callout>
            )}
            {reads && !data && !failure.error && <Skeleton className="h-80 w-full rounded-xl" />}
          </>
        )
      }}
    >
      {{
        telegram: data && (
          <>
            <TelegramSection settings={data.groups.telegram} meta={data.meta} disabled={readOnly} />
            <WebhookCard appUrl={data.groups.app.url} webhookUrl={data.meta.webhook_url} />
          </>
        ),
        database: data && <DatabaseSection settings={data.groups.database} disabled={readOnly} />,
        app: data && <AppSection settings={data.groups.app} meta={data.meta} disabled={readOnly} />,
        mail: data && (
          <>
            <MailSection settings={data.groups.mail} disabled={readOnly} />
            <MailTestCard />
          </>
        ),
        login: <LoginSection disabled={readOnly} />,
        appearance: <AppearanceCard />,
        update: <UpdateSection />,
        advanced: data && <AdvancedSection settings={data.groups.advanced} meta={data.meta} disabled={readOnly} />,
      }}
    </SectionedPage>
  )
}
