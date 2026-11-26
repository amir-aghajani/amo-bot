import { ReportGroupCard } from '@/components/bot/report-group-card'
import { useBotSettingsGroup, useHeldWhile } from '@/components/bot/settings/use-bot-settings-group'
import { useReportGroup } from '@/components/bot/use-report-group'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { HourSelect } from '@/components/hour-select'
import { useReadFailure } from '@/components/page'
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

/**
 * The switches the shop has, a topic's by its key (`report_purchases` …) — the agency's in the main bot's shop only (an
 * agent's group has no such topic) —, and the daily report's hour, as its select edits it.
 */
function switchesOf(settings: BotSettingsData): BotReportsSettingsRequest {
  const {
    report_purchases,
    report_renewals,
    report_tests,
    report_deletions,
    report_wallet,
    report_receipts,
    report_users,
    report_errors,
    report_agency,
    report_tickets,
    report_reviews,
    report_backups,
    report_daily,
    daily_report_hour,
  } = settings
  return {
    report_purchases,
    report_renewals,
    report_tests,
    report_deletions,
    report_wallet,
    report_receipts,
    report_users,
    report_errors,
    report_tickets,
    report_reviews,
    report_backups,
    report_daily,
    daily_report_hour: String(daily_report_hour),
    ...(report_agency === undefined ? {} : { report_agency }),
  }
}

/**
 * Which subjects the report group gets: a switch per topic — the daily report's with the hour it goes from, held while
 * it is off. A topic switched off gets nothing but stays in the group.
 */
function TopicsCard({ settings }: { settings: BotSettingsData }) {
  const read = useReportGroup()
  const { data: group, refetch } = read
  const failure = useReadFailure(read)
  const saved = switchesOf(settings)
  // The group card shows which topics are on; «پشتیبان‌گیری» shows the backups' switch.
  const form = useBotSettingsGroup(saved, (values) => api.put('/bot/settings/reports', values), { invalidates: [queryKeys.reportGroup, queryKeys.backups] })
  const { values, set, error } = form
  // While the daily report is off its hour waits as it is kept, held; one changed stays open, as the save still carries it.
  const held = useHeldWhile(!values.report_daily, form, saved)

  return (
    <SectionCard form={form} title="بخش‌های گزارش" description="هر بخش یک تاپیک در گروه گزارش‌ها است. بخشی که خاموش باشد گزارشی نمی‌فرستد؛ تاپیکش در گروه می‌ماند.">
      {group ? (
        group.topics.map((topic) => {
          const field = `report_${topic.key}` as const
          const checked = values[field]

          if (checked === undefined) return null
          const row = <SwitchRow key={topic.key} label={topic.title} hint={topic.about} checked={checked} onCheckedChange={(on) => set(field, on)} error={error(field)} />
          if (topic.key !== 'daily') return row

          // The hour stays under its switch, on or off: switching never swaps the row, so the switch keeps the focus.
          return (
            <div key={topic.key} className="grid gap-3">
              {row}
              <Field
                id="daily_report_hour"
                label="ساعت گزارش روزانه"
                error={error('daily_report_hour')}
                hint="به وقت منطقه زمانی فروشگاه؛ از این ساعت به بعد، گزارش روزی که گذشت یک بار فرستاده می‌شود. ۰۰:۰۰ یعنی کمی بعد از نیمه‌شب."
                className="ps-4"
              >
                {(control) => (
                  <HourSelect
                    {...control}
                    value={String(values.daily_report_hour)}
                    onChange={(hour) => set('daily_report_hour', hour)}
                    disabled={held('daily_report_hour')}
                    className="w-full sm:max-w-40"
                  />
                )}
              </Field>
            </div>
          )
        })
      ) : failure.error ? (
        <ErrorState what="بخش‌های گزارش" error={failure.error} onRetry={() => void refetch()} retrying={failure.retrying} />
      ) : (
        <Skeleton className="h-64 w-full" />
      )}
    </SectionCard>
  )
}
