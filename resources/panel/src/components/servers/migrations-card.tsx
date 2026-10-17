import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeftRight, CircleSlash, Eraser, ServerOff } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { LastFailure } from '@/components/grants/grant-card'
import { Modal } from '@/components/modal'
import { useReadFailure } from '@/components/page'
import { ProgressBar } from '@/components/progress-bar'
import { Reviewer } from '@/components/reviewer'
import { MigrationForm } from '@/components/servers/migration-form'
import { isOpen, useMigrations, workable, type MigrationAction } from '@/components/servers/use-migrations'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { ServerMigrationDetailResponse, ServerMigrationLeftovers, ServerMigrationRow, ServerRow } from '@/lib/api-types'
import { formatDate, formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { MIGRATION_MODE, MIGRATION_STATUS } from '@/lib/statuses'

/** What the owner's actions ask before they go, when they ask: cancelling, going on as a rescue. */
const CONFIRMS: Partial<Record<MigrationAction, { title: string; label: string; details: string; destructive: boolean }>> = {
  cancel: {
    title: 'لغو انتقال',
    label: 'لغو انتقال',
    details: 'سرویس‌هایی که منتقل شده‌اند روی سرور مقصد می‌مانند و بقیه روی همین سرور.',
    destructive: true,
  },
  rescue: {
    title: 'ادامه با حالت نجات',
    label: 'ادامه با حالت نجات',
    details:
      'از این به بعد پنل این سرور پرسیده نمی‌شود: هر سرویس با آخرین اطلاعاتی که فروشگاه از آن دارد روی سرور مقصد ساخته می‌شود و کلاینت قبلی روی پنل این سرور می‌ماند تا وقتی دوباره جواب بدهد حذف شود.',
    destructive: false,
  },
}

/**
 * «انتقال سرویس‌ها» on a server's page, the owner's: every shop's services on it moved to another server — «مهاجرت» while
 * its panel answers, «نجات» when it does not —, the latest moves with their progress, pausing, resuming, cancelling and
 * the switch to rescue; and the clients moves left on its panel, removed now or forgotten. A move under way is worked
 * from this card while it is open; the scheduler carries on otherwise.
 */
export function MigrationsCard({ server }: { server: ServerRow }) {
  const { read, current, put, act, changes } = useMigrations(server.id)
  const failure = useReadFailure(read)
  const [starting, setStarting] = useState(false)
  const [asking, setAsking] = useState<{ job: ServerMigrationRow; action: MigrationAction } | null>(null)
  const [details, setDetails] = useState<ServerMigrationRow | null>(null)
  const confirm = asking ? CONFIRMS[asking.action] : undefined

  const run = (job: ServerMigrationRow, action: MigrationAction) => (CONFIRMS[action] ? setAsking({ job, action }) : act.mutate({ job, action }))

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>انتقال سرویس‌ها</CardTitle>
          <CardDescription>همه سرویس‌های این سرور، از همه فروشگاه‌ها، به یک سرور دیگر؛ برای خالی کردن سرور، یا نجات سرویس‌ها وقتی پنلش از دسترس خارج شده است.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-3">
        <Button variant="secondary" icon={ArrowLeftRight} className="w-fit" onClick={() => setStarting(true)} disabled={!read.data || current !== null}>
          انتقال سرویس‌ها
        </Button>
        {failure.error && <ErrorState what="لیست" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />}
        {read.data && <Leftovers server={server} leftovers={read.data.leftovers} />}
        {read.data
          ? read.data.migrations.length > 0 && (
              <ul className="grid divide-y divide-border overflow-hidden rounded-xl border border-border" aria-label="انتقال‌های قبلی">
                {read.data.migrations.map((job) => (
                  <MigrationItem key={job.id} job={job} busy={act.isPending} onAction={(action) => run(job, action)} onDetails={() => setDetails(job)} />
                ))}
              </ul>
            )
          : !failure.error && <Skeleton className="h-20 w-full" />}
      </CardContent>

      <Modal open={starting} onClose={() => setStarting(false)} size="md" title="انتقال سرویس‌ها" description={`از «${server.name}» به سرور دیگر`}>
        {starting && (
          <MigrationForm
            server={server}
            invalidates={changes}
            onCancel={() => setStarting(false)}
            onStarted={(job) => {
              put(job)
              setStarting(false)
              toast.success('انتقال سرویس‌ها شروع شد')
            }}
          />
        )}
      </Modal>

      <ConfirmModal
        open={confirm !== undefined}
        onClose={() => setAsking(null)}
        title={confirm?.title ?? ''}
        description={asking ? `انتقال به «${asking.job.target.name}»` : undefined}
        confirmLabel={confirm?.label}
        destructive={confirm?.destructive}
        pending={act.isPending}
        onConfirm={() => asking && act.mutate(asking, { onSettled: () => setAsking(null) })}
      >
        {confirm?.details}
      </ConfirmModal>

      <FailuresModal server={server} job={details} onClose={() => setDetails(null)} />
    </Card>
  )
}

/** How far a move got: the services it has been through so far. */
function reached(job: ServerMigrationRow): number {
  return Math.min(job.moved + job.skipped + job.failed, job.total)
}

/** What a move did — "۴ سرویس منتقل شد · ۱ سرویس رد شد · ۱ ناموفق · ۴ کلاینت روی پنل مانده است" (those still there). */
function outcome(job: ServerMigrationRow): string {
  const parts = [job.moved > 0 ? `${formatNumber(job.moved)} سرویس منتقل شد` : 'سرویسی منتقل نشد']
  if (job.skipped > 0) parts.push(`${formatNumber(job.skipped)} سرویس رد شد`)
  if (job.failed > 0) parts.push(`${formatNumber(job.failed)} ناموفق`)
  if (job.left_behind > 0) parts.push(`${formatNumber(job.left_behind)} کلاینت روی پنل مانده است`)
  return parts.join(' · ')
}

/** A move's line: where to and how, how far it got (a bar while it is open), what holds it up or ended it, and its actions. */
function MigrationItem({ job, busy, onAction, onDetails }: { job: ServerMigrationRow; busy: boolean; onAction: (action: MigrationAction) => void; onDetails: () => void }) {
  const open = isOpen(job)
  const notes = [job.include_disabled && 'با سرویس‌های غیرفعال‌شده', job.plans !== null && `فقط ${job.plans.map((plan) => plan.name).join('، ')}`, !job.notify && 'بدون پیام به مشتری‌ها'].filter(
    (note) => typeof note === 'string',
  )

  return (
    <li className="grid gap-2 px-3 py-2.5 text-body">
      <div className="flex items-center justify-between gap-2">
        <span className="font-medium">
          به «{job.target.name}»<span className="font-normal text-muted-foreground"> · {MIGRATION_MODE[job.mode]}</span>
        </span>
        <StatusBadge status={MIGRATION_STATUS[job.status]} />
      </div>
      {open ? (
        <div className="grid gap-1">
          <ProgressBar label="پیشرفت" value={reached(job)} max={job.total} tone={job.status === 'paused' ? 'warning' : 'info'} />
          <span className="text-footnote text-muted-foreground tabular">{`${formatNumber(reached(job))} از ${formatNumber(job.total)} سرویس بررسی شد · ${outcome(job)}`}</span>
        </div>
      ) : (
        <p className="text-footnote text-muted-foreground">{outcome(job)}</p>
      )}
      {open && job.waiting_reason && (
        <Callout tone="warning" icon={ServerOff} role="status" className="text-footnote">
          <div className="grid gap-0.5">
            <span>
              {job.waiting_on === 'source'
                ? 'انتقال تا جواب دادن این پنل منتظر می‌ماند و خودکار دوباره تلاش می‌شود. اگر پنل این سرور از دسترس خارج شده است، با حالت نجات ادامه دهید.'
                : 'انتقال تا جواب دادن این پنل منتظر می‌ماند و خودکار دوباره تلاش می‌شود.'}
            </span>
            <span className="break-words" dir="auto">
              {job.waiting_reason}
            </span>
          </div>
        </Callout>
      )}
      {job.status === 'failed' && job.reason && (
        <Callout tone="danger" icon={CircleSlash} className="text-footnote">
          <span className="break-words" dir="auto">
            {job.reason}
          </span>{' '}
          سرویس‌های باقی‌مانده روی همین سرور هستند؛ بعد از رفع مشکل، انتقال تازه‌ای شروع کنید.
        </Callout>
      )}
      {job.last_failure && <LastFailure failure={job.last_failure} />}
      <div className="flex min-h-7 flex-wrap items-center justify-between gap-2 text-footnote text-muted-foreground">
        <span>
          <time dateTime={job.created_at} title={formatDate(job.created_at)}>
            {timeAgo(job.created_at)}
          </time>
          {job.reviewer && (
            <>
              {' · '}
              <Reviewer name={job.reviewer} person={job.reviewer_name} />
            </>
          )}
          {notes.map((note) => ` · ${note}`)}
        </span>
        <div className="flex flex-wrap items-center gap-1">
          {job.failed > 0 && (
            <Button variant="ghost" size="sm" onClick={onDetails}>
              سرویس‌های ناموفق
            </Button>
          )}
          {/* A rescue goes on without the source alone: a target out of reach holds it as well. */}
          {open && job.waiting_on === 'source' && (
            <Button variant="secondary" size="sm" aria-disabled={busy} onClick={() => onAction('rescue')}>
              ادامه با حالت نجات
            </Button>
          )}
          {workable(job) && (
            <Button variant="secondary" size="sm" aria-disabled={busy} onClick={() => onAction('pause')}>
              توقف موقت
            </Button>
          )}
          {job.status === 'paused' && (
            <Button variant="secondary" size="sm" aria-disabled={busy} onClick={() => onAction('resume')}>
              ادامه
            </Button>
          )}
          {open && (
            <Button variant="danger" size="sm" aria-disabled={busy} onClick={() => onAction('cancel')}>
              لغو
            </Button>
          )}
        </div>
      </div>
    </li>
  )
}

/**
 * The clients moves left on this server's panel (a rescue's old ones, mostly): they would work again once it answers, so
 * they are removed then by themselves — or now, by the owner's button, which asks the panel even while it is left
 * alone —, or forgotten for a panel that is gone for good.
 */
function Leftovers({ server, leftovers }: { server: ServerRow; leftovers: ServerMigrationLeftovers }) {
  const [forgetting, setForgetting] = useState(false)
  const invalidates = [queryKeys.serverMigrations(server.id)]
  const clean = useMutation({
    mutationFn: () => api.post(`/servers/${server.id}/leftovers/clean`),
    meta: { invalidates },
    onSuccess: ({ leftovers: left }) => (left.pending === 0 ? toast.success('کلاینت‌های مانده از پنل این سرور حذف شدند') : toast.error(`${formatNumber(left.pending)} کلاینت هنوز روی پنل مانده است`)),
  })
  const forget = useMutation({ mutationFn: () => api.delete(`/servers/${server.id}/leftovers`), meta: { invalidates } })

  if (leftovers.pending === 0) return null

  return (
    <Callout tone="warning" icon={Eraser} role="status">
      <div className="grid gap-2">
        <span>{formatNumber(leftovers.pending)} کلاینت از انتقال سرویس‌ها روی پنل این سرور مانده است. اگر پنل دوباره جواب بدهد این کلاینت‌ها کار می‌کنند، پس همان موقع خودکار حذف می‌شوند.</span>
        {leftovers.last_error && (
          <span className="text-footnote break-words" dir="auto">
            آخرین تلاش: {leftovers.last_error}
          </span>
        )}
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" size="sm" busy={clean.isPending} disabled={forget.isPending} onClick={() => clean.mutate()}>
            حذف همین حالا
          </Button>
          <Button variant="ghost" size="sm" disabled={clean.isPending} onClick={() => setForgetting(true)}>
            فراموش کردن
          </Button>
        </div>
      </div>
      <ConfirmModal
        open={forgetting}
        onClose={() => setForgetting(false)}
        title="فراموش کردن کلاینت‌های مانده"
        confirmLabel="فراموش کن"
        destructive
        pending={forget.isPending}
        onConfirm={() => forget.mutate(undefined, { onSettled: () => setForgetting(false) })}
      >
        فقط برای پنلی که دیگر برنمی‌گردد: این کلاینت‌ها دیگر حذف نمی‌شوند و اگر پنل دوباره جواب بدهد، کار می‌کنند.
      </ConfirmModal>
    </Callout>
  )
}

/** The services a move could not carry, the latest first: which, of which shop, and why. */
function FailuresModal({ server, job, onClose }: { server: ServerRow; job: ServerMigrationRow | null; onClose: () => void }) {
  const detail = useQuery({
    queryKey: queryKeys.serverMigration(job?.id ?? 0),
    queryFn: () => api.get<ServerMigrationDetailResponse>(`/servers/${server.id}/migrations/${job?.id ?? 0}`),
    enabled: job !== null,
  })
  const readFailure = useReadFailure(detail, job?.id)

  return (
    <Modal open={job !== null} onClose={onClose} size="lg" title="سرویس‌های ناموفق" description={job ? `انتقال به «${job.target.name}»` : undefined}>
      {readFailure.error ? (
        <ErrorState what="لیست" error={readFailure.error} onRetry={() => void detail.refetch()} retrying={readFailure.retrying} />
      ) : detail.data ? (
        <ul className="grid divide-y divide-border overflow-hidden rounded-xl border border-border text-body" aria-label="سرویس‌های ناموفق">
          {detail.data.failures.map((failure) => (
            <li key={failure.id} className="grid gap-0.5 px-3 py-2">
              <span className="flex flex-wrap items-center gap-2">
                <bdi dir="ltr" className="font-medium">
                  {failure.client}
                </bdi>
                {failure.shop && <span className="text-footnote text-muted-foreground">{failure.shop.name}</span>}
              </span>
              <span className="text-footnote break-words text-danger" dir="auto">
                {failure.reason}
              </span>
            </li>
          ))}
        </ul>
      ) : (
        <Skeleton className="h-24 w-full" />
      )}
    </Modal>
  )
}
