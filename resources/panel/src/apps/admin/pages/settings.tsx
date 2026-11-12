import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CircleAlert, FileCog } from 'lucide-react'
import { PANEL_SETTINGS_SECTIONS } from '@/apps/admin/nav'
import { AdvancedSection } from '@/apps/admin/settings/advanced-section'
import { AppSection } from '@/apps/admin/settings/app-section'
import { DatabaseSection } from '@/apps/admin/settings/database-section'
import { LoginSection } from '@/apps/admin/settings/login-section'
import { MailSection, MailTestCard } from '@/apps/admin/settings/mail-section'
import { SCHEDULE_DESCRIPTION, ScheduleSection } from '@/apps/admin/settings/schedule-section'
import { SmsSection, SmsTestCard } from '@/apps/admin/settings/sms-section'
import { TelegramSection } from '@/apps/admin/settings/telegram-section'
import { UPDATE_DESCRIPTION, UpdateSection } from '@/apps/admin/settings/update-section'
import { WebhookCard } from '@/apps/admin/settings/webhook-card'
import { APPEARANCE_DESCRIPTION, AppearanceCard } from '@/components/appearance-card'
import { BackupsDescription, BackupsSection } from '@/components/backups/backups-section'
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
 * The sections that are none of config.php's — the panel's look (this browser's), AmoBot's update, the schedule and the
 * backups (the database's) —, by what the header says of each: the file's path, its read and whether it can be written
 * are not theirs.
 */
const OUTSIDE_THE_FILE: Partial<Record<(typeof PANEL_SETTINGS_SECTIONS)[number]['value'], ReactNode>> = {
  appearance: APPEARANCE_DESCRIPTION,
  update: UPDATE_DESCRIPTION,
  schedule: SCHEDULE_DESCRIPTION,
  backups: <BackupsDescription />,
}

/**
 * «تنظیمات پنل» (the owner's): config.php's groups — kept in a file so they stay editable even when the database is
 * broken —, the owner's login, the panel's look in this browser, AmoBot's update, the scheduled tasks' timings and the
 * backups.
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
        OUTSIDE_THE_FILE[current.value] !== undefined
          ? { title: 'تنظیمات پنل', description: OUTSIDE_THE_FILE[current.value] }
          : {
              title: 'تنظیمات پنل',
              description: (
                <>
                  تنظیمات برنامه، ربات، دیتابیس، ایمیل، پیامک و ورود به پنل. این مقادیر در فایل <code dir="ltr">config.php</code> برنامه ذخیره می‌شوند، نه در دیتابیس.
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
        if (OUTSIDE_THE_FILE[current.value] !== undefined) return null
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
        sms: data && (
          <>
            <SmsSection settings={data.groups.sms} disabled={readOnly} />
            <SmsTestCard driver={data.groups.sms.driver} />
          </>
        ),
        login: <LoginSection disabled={readOnly} />,
        appearance: <AppearanceCard />,
        update: <UpdateSection />,
        schedule: <ScheduleSection />,
        backups: <BackupsSection scheduleSettings="/settings/schedule" />,
        advanced: data && <AdvancedSection settings={data.groups.advanced} meta={data.meta} disabled={readOnly} />,
      }}
    </SectionedPage>
  )
}
