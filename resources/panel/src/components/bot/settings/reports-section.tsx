import { ReportGroupCard } from '@/components/bot/report-group-card'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { useReportGroup } from '@/components/bot/use-report-group'
import { ErrorState } from '@/components/error-state'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { BotReportsSettingsRequest, BotSettingsData } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** The report group itself, then which of its topics get reports. */
export function ReportsSection({ settings }: { settings: BotSettingsData }) {
  return (
    <>
      <ReportGroupCard />
      <TopicsCard settings={settings} />
    </>
  )
}

/** The switches the shop has, a topic's by its key (`report_purchases` …) — the agency's in the main bot's shop only (an agent's group has no such topic). */
function switchesOf(settings: BotSettingsData): BotReportsSettingsRequest {
  const { report_purchases, report_renewals, report_wallet, report_receipts, report_users, report_errors, report_agency, report_tickets, report_reviews } = settings
  return { report_purchases, report_renewals, report_wallet, report_receipts, report_users, report_errors, report_tickets, report_reviews, ...(report_agency === undefined ? {} : { report_agency }) }
}

/**
 * Which subjects the report group gets — a switch per topic, labelled as the group names it (the group's read: its
 * failure said here too, with a way to ask again). A topic switched off gets nothing; it stays in the group.
 */
function TopicsCard({ settings }: { settings: BotSettingsData }) {
  const { data: group, error: failure, refetch } = useReportGroup()
  // The group card shows which topics are on.
  const form = useBotSettingsGroup(switchesOf(settings), (values) => api.put('/bot/settings/reports', values), { invalidates: [queryKeys.reportGroup] })
  const { values, set, error } = form

  return (
    <SectionCard form={form} title="بخش‌های گزارش" description="هر بخش یک تاپیک در گروه گزارش‌ها است. بخشی که خاموش باشد گزارشی نمی‌فرستد؛ تاپیکش در گروه می‌ماند.">
      {group ? (
        group.topics.map((topic) => {
          const field = `report_${topic.key}` as const
          const checked = values[field]

          return checked === undefined ? null : <SwitchRow key={topic.key} label={topic.title} hint={topic.about} checked={checked} onCheckedChange={(on) => set(field, on)} error={error(field)} />
        })
      ) : failure ? (
        <ErrorState what="بخش‌های گزارش" error={failure} onRetry={() => void refetch()} />
      ) : (
        <Skeleton className="h-64 w-full" />
      )}
    </SectionCard>
  )
}
