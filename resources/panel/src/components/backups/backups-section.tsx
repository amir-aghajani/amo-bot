import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleAlert, Download, History, Info } from 'lucide-react'
import { RestoreCard } from '@/components/backups/restore-card'
import { ShopName } from '@/components/backups/shop-name'
import { keptPath, useSaveBackup } from '@/components/backups/use-save-backup'
import { Callout } from '@/components/callout'
import { EmptyState } from '@/components/empty-state'
import { ErrorState } from '@/components/error-state'
import { useReadFailure } from '@/components/page'
import { useSectionShown } from '@/components/sectioned-page'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { BackupKept, BackupsResponse, BackupsStatus } from '@/lib/api-types'
import { useMainShop, useSession } from '@/lib/auth'
import { formatBytes, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/** The name a backup made now is saved as, should the server give none. */
const MADE_NOW = 'amobot-backup.json.gz'

/** The report group's settings, which a backup sent to the group needs connected first. */
const REPORT_GROUP = '/bot-settings/reports'

/**
 * The page's words over the section: what this panel's backups hold — the whole installation for the owner in the main
 * shop, the shop on screen alone in any other panel —, and what can be done with one.
 */
export function BackupsDescription() {
  const { shop } = useSession()

  return useMainShop() ? (
    <>پشتیبان کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها. فایل پشتیبان را دریافت کنید، خودکار به گروه گزارش‌ها بفرستید یا نصب را با آن به حالت همان زمان برگردانید.</>
  ) : (
    <>
      پشتیبان فروشگاه <ShopName shop={shop} />: فقط داده‌های همین فروشگاه. فایل پشتیبان را دریافت کنید، خودکار به گروه گزارش‌ها بفرستید یا فروشگاه را با آن به حالت همان زمان برگردانید.
    </>
  )
}

/** How often the backup goes, in words: «هر ۲۴ ساعت», «هر ۲ روز», «هر ۹۰ دقیقه». */
function everyWords(minutes: number): string {
  if (minutes >= 2 * 1440 && minutes % 1440 === 0) return `هر ${formatNumber(minutes / 1440)} روز`
  if (minutes >= 60 && minutes % 60 === 0) return `هر ${formatNumber(minutes / 60)} ساعت`
  return `هر ${formatNumber(minutes)} دقیقه`
}

/** What a backup of this scope holds, as a sentence's end. */
function Holds({ status }: { status: BackupsStatus }) {
  return status.scope === 'installation' ? (
    <>کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها</>
  ) : (
    <>
      فروشگاه <ShopName shop={status.shop} />: فقط داده‌های همین فروشگاه، بدون فروشگاه‌های دیگر و سرورها
    </>
  )
}

interface BackupsSectionProps {
  /** The owner's «زمان‌بندی کارها», where how often the backup goes is set; none in an agent's panel (the owner sets it). */
  scheduleSettings?: string
}

/**
 * «پشتیبان‌گیری», both panels': a backup made now to save, its sending to the report group, a restore from a file, and
 * the states kept from before a restore. Of the whole installation for the owner in the main shop, of the shop alone in
 * any other panel — the server says which. Read only while the section is on screen.
 */
export function BackupsSection({ scheduleSettings }: BackupsSectionProps) {
  const shown = useSectionShown()
  const read = useQuery({ queryKey: queryKeys.backups, queryFn: () => api.get<BackupsResponse>('/backups'), enabled: shown })
  const failure = useReadFailure(read)
  const failed = failure.error && <ErrorState what="پشتیبان‌گیری" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />

  // A later read that fails is said over the cards, which keep a file picked.
  if (!read.data) return failed || <Skeleton className="h-96 w-full rounded-xl" />
  const status = read.data.backups

  return (
    <>
      {failed}
      <DownloadCard status={status} />
      <AutoCard status={status} scheduleSettings={scheduleSettings} />
      <RestoreCard status={status} />
      <KeptCard kept={status.kept} />
    </>
  )
}

/** «دریافت پشتیبان»: a backup made now — which takes a few seconds —, saved by the name the server gives it. */
function DownloadCard({ status }: { status: BackupsStatus }) {
  const { save, saving } = useSaveBackup()
  const busy = saving('/backups/download')

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">دریافت پشتیبان</CardTitle>
          <CardDescription>
            یک فایل پشتیبان همین حالا ساخته می‌شود، از <Holds status={status} />. ساختنش ممکن است چند ثانیه طول بکشد.
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-3">
        <div>
          <Button icon={Download} busy={busy} onClick={() => save({ path: '/backups/download', fallback: MADE_NOW })}>
            دریافت فایل پشتیبان
          </Button>
        </div>
        <p role="status" className="text-footnote text-muted-foreground empty:hidden">
          {busy ? 'فایل پشتیبان در حال ساخت است؛ چند لحظه صبر کنید.' : ''}
        </p>
        <p className="text-footnote leading-relaxed text-muted-foreground">فایل فقط در همین نصب AmoBot بازگردانی می‌شود. آن را جای امنی نگه دارید؛ هرکس آن را داشته باشد، داده‌های مشتری‌ها را دارد.</p>
      </CardContent>
    </Card>
  )
}

/**
 * «ارسال خودکار به گروه گزارش‌ها»: the switch of the group's «پشتیبان‌گیری» topic — held while it saves —, how often the
 * backup goes and where that is set, when it last went or why it did not, and what it needs first: a report group.
 */
function AutoCard({ status, scheduleSettings }: BackupsSectionProps & { status: BackupsStatus }) {
  const queryClient = useQueryClient()
  const toggle = useMutation({
    mutationFn: (enabled: boolean) => api.put('/backups/auto', { enabled }),
    onSuccess: (answer) => queryClient.setQueryData<BackupsResponse>(queryKeys.backups, answer),
    // The same switch is the report group's topic's, on the bot settings.
    meta: { invalidates: [queryKeys.botSettings, queryKeys.reportGroup] },
  })
  const { auto } = status
  const checked = toggle.isPending ? (toggle.variables ?? auto.enabled) : auto.enabled

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">ارسال خودکار به گروه گزارش‌ها</CardTitle>
          <CardDescription>فایل پشتیبان به تاپیک «پشتیبان‌گیری» گروه گزارش‌ها فرستاده می‌شود. گروه را خصوصی نگه دارید؛ هرکس در آن باشد، فایل را دارد.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-3">
        <SwitchRow
          label="ارسال خودکار"
          hint={`فایل پشتیبان ${everyWords(auto.every_minutes)} یک بار فرستاده می‌شود.`}
          checked={checked}
          held={toggle.isPending}
          onCheckedChange={(on) => toggle.mutate(on)}
        />
        <p className="text-footnote leading-relaxed text-muted-foreground">
          {scheduleSettings === undefined ? (
            'فاصله ارسال را مالک فروشگاه اصلی تعیین می‌کند.'
          ) : (
            <>
              فاصله ارسال در{' '}
              <TextLink inline to={scheduleSettings}>
                تنظیمات پنل › زمان‌بندی کارها
              </TextLink>{' '}
              تعیین می‌شود.
            </>
          )}{' '}
          {auto.last_problem === null &&
            (auto.last_sent_at ? (
              <>
                آخرین ارسال: <TimeAgo at={auto.last_sent_at} />.
              </>
            ) : (
              'هنوز فایلی فرستاده نشده است.'
            ))}
        </p>
        {!auto.group && (
          <Callout tone={checked ? 'warning' : 'info'} icon={checked ? CircleAlert : Info}>
            برای ارسال خودکار، اول باید گروه گزارش‌ها به ربات وصل شود.{' '}
            <TextLink inline to={REPORT_GROUP}>
              اتصال گروه گزارش‌ها
            </TextLink>
          </Callout>
        )}
        {auto.last_problem !== null && (
          <Callout tone="warning" icon={CircleAlert}>
            آخرین ارسال
            {auto.last_sent_at && (
              <>
                {' '}
                (<TimeAgo at={auto.last_sent_at} />)
              </>
            )}{' '}
            انجام نشد: {auto.last_problem}
          </Callout>
        )}
      </CardContent>
    </Card>
  )
}

/** «پیش از بازگردانی‌ها»: the states kept from just before each restore, newest first, each to save. */
function KeptCard({ kept }: { kept: BackupKept[] }) {
  const { save, saving, busy } = useSaveBackup()

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">پیش از بازگردانی‌ها</CardTitle>
          <CardDescription>پیش از هر بازگردانی، حالت همان لحظه این‌جا نگه داشته می‌شود (سه مورد آخر)، تا اگر فایل اشتباهی بازگردانی شد، بتوانید با آن به حالت قبل برگردید.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        {kept.length === 0 ? (
          <EmptyState compact framed icon={History} title="هنوز بازگردانی‌ای انجام نشده است" description="پیش از اولین بازگردانی، حالت همان لحظه این‌جا نگه داشته می‌شود." />
        ) : (
          <ul aria-label="حالت‌های پیش از بازگردانی" className="divide-y divide-border rounded-lg border border-border">
            {kept.map((each) => {
              const path = keptPath(each.name)

              return (
                <li key={each.name} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5">
                  <div className="grid min-w-0 flex-1 gap-0.5">
                    <span className="truncate text-footnote" title={each.name}>
                      <bdi dir="ltr">{each.name}</bdi>
                    </span>
                    <span className="text-footnote text-muted-foreground">
                      <TimeAgo at={each.made_at} /> · {formatBytes(each.size)}
                    </span>
                  </div>
                  {/* Held while any of them is fetched — held, not disabled, so the one pressed keeps the focus. */}
                  <Button variant="secondary" size="sm" icon={Download} busy={saving(path)} aria-disabled={busy} aria-label={`دریافت ${each.name}`} onClick={() => save({ path, fallback: each.name })}>
                    دریافت
                  </Button>
                </li>
              )
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
