import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Circle, CircleAlert, CircleCheck, Download, LoaderCircle, RotateCw, Undo2 } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { FormError } from '@/components/form-footer'
import { ProgressBar } from '@/components/progress-bar'
import { useSectionShown } from '@/components/sectioned-page'
import { TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { ReleaseInfo, UpdateResponse, UpdateRun, UpdateStep } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatDate, formatPercent, timeAgo } from '@/lib/format'
import { updateQuery } from '@/lib/queries'
import { reloadForNewBuild } from '@/lib/stale-build'

/** The page's words over the section: what an update here is, and is not. */
export const UPDATE_DESCRIPTION = 'نسخه تازه AmoBot از GitHub، با یک دکمه. فقط نسخه‌ای نصب می‌شود که امضای سازنده AmoBot را دارد؛ تنظیمات و فایل‌های فروشگاه دست نمی‌خورند.'

/** The update by hand — a host the shop cannot update itself on: the guide's page. */
const BY_HAND = 'https://github.com/amir-aghajani/amo-bot/wiki/Upgrading'

/** An update's steps, in the order a run takes them, in the owner's words. */
const STEPS: { step: UpdateStep; title: string }[] = [
  { step: 'download', title: 'دانلود و بررسی امضا' },
  { step: 'extract', title: 'باز کردن بسته' },
  { step: 'preflight', title: 'بررسی هاست' },
  { step: 'install', title: 'نصب' },
]

/** Whether a run is under way: a step of it still to take. */
function underWay(run: UpdateRun | null): boolean {
  return run !== null && run.step !== 'done' && run.step !== 'rolled_back'
}

/** Whether `version` is newer than `than`, part by part — "0.10.0" after "0.9.0". */
function isNewer(version: string, than: string): boolean {
  const a = version.split('.').map(Number)
  const b = than.split('.').map(Number)
  for (let part = 0; part < Math.max(a.length, b.length); part++) {
    const difference = (a[part] ?? 0) - (b[part] ?? 0)
    if (difference !== 0) return difference > 0
  }
  return false
}

/**
 * «تنظیمات پنل › به‌روزرسانی»: AmoBot's own update — the version the shop runs, the newest release GitHub publishes with
 * its notes, and the update to it, one press: begun behind a second look, then driven a step a request (the server
 * takes one at a time: a shared host gives a request half a minute) until it is installed, and the page reloaded on the
 * new build. A step refused is said where the run is, with «تلاش دوباره» and — before the install began — «لغو»; an
 * update installed is taken back while the server says it may be. What keeps the shop from updating itself is said with
 * the way out: an update by hand. Read only while the section is on screen.
 */
export function UpdateSection() {
  const queryClient = useQueryClient()
  const shown = useSectionShown()
  const read = useQuery({ ...updateQuery, enabled: shown })
  const [asking, setAsking] = useState<'start' | 'rollback' | null>(null)
  const put = (answer: UpdateResponse) => queryClient.setQueryData(updateQuery.queryKey, answer.update)

  // The run taken a step a request until it is over — or a step is refused: what the refusal left is read again then.
  const drive = useMutation({
    mutationFn: async () => {
      for (;;) {
        const answer = await api.post('/system/update/step')
        put(answer)
        if (!underWay(answer.update.run)) return answer.update.run
      }
    },
    meta: { quiet: true },
    onSuccess: (run) => {
      if (run?.step === 'done') reloadForNewBuild()
    },
    onError: () => void queryClient.invalidateQueries({ queryKey: updateQuery.queryKey }),
  })
  const check = useMutation({ mutationFn: () => api.post('/system/update/check'), onSuccess: put })
  const start = useMutation({
    mutationFn: (version: string) => api.post('/system/update/start', { version }),
    meta: { quiet: true },
    onSuccess: (answer) => {
      put(answer)
      setAsking(null)
      drive.mutate()
    },
  })
  const cancel = useMutation({ mutationFn: () => api.post('/system/update/cancel'), onSuccess: put })
  const rollback = useMutation({
    mutationFn: () => api.post('/system/update/rollback'),
    meta: { quiet: true },
    onSuccess: (answer) => {
      put(answer)
      setAsking(null)
      reloadForNewBuild()
    },
  })
  const ask = (what: 'start' | 'rollback') => {
    start.reset()
    rollback.reset()
    setAsking(what)
  }

  const update = read.data
  if (!update) {
    return read.error ? <ErrorState what="وضعیت به‌روزرسانی" error={read.error} onRetry={() => void read.refetch()} retrying={read.isFetching} /> : <Skeleton className="h-48 w-full rounded-xl" />
  }
  const { latest, run } = update

  return (
    <>
      <Card>
        <CardHeader>
          <CardHeading>
            <CardTitle className="text-heading">به‌روزرسانی</CardTitle>
            <CardDescription>
              نسخه <bdi dir="ltr">{update.current}</bdi> روی این فروشگاه است. {update.checked_at ? `آخرین بررسی: ${timeAgo(update.checked_at)}` : 'هنوز بررسی نشده است.'}
            </CardDescription>
          </CardHeading>
          <CardAction>
            <Button variant="secondary" icon={RotateCw} busy={check.isPending} onClick={() => check.mutate()}>
              بررسی دوباره
            </Button>
          </CardAction>
        </CardHeader>
        <CardContent className="grid gap-4">
          <NewestRelease latest={latest} current={update.current} />
          {update.blocker && (
            <Callout tone="warning" icon={CircleAlert}>
              {update.blocker}{' '}
              <TextLink inline href={BY_HAND}>
                راهنمای به‌روزرسانی دستی
              </TextLink>
            </Callout>
          )}
          {update.available && latest && !update.blocker && (
            <div>
              <Button icon={Download} onClick={() => ask('start')}>
                به‌روزرسانی به نسخه <bdi dir="ltr">{latest.version}</bdi>
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      {run && (
        <RunCard
          run={run}
          driving={drive.isPending}
          error={drive.error}
          onDrive={() => drive.mutate()}
          cancelling={cancel.isPending}
          onCancel={() => cancel.mutate()}
          onRollback={() => ask('rollback')}
        />
      )}

      <ConfirmModal
        open={asking === 'start' && latest !== null}
        onClose={() => setAsking(null)}
        title={`به‌روزرسانی به نسخه ${latest?.version ?? ''}`}
        confirmLabel="به‌روزرسانی"
        pending={start.isPending}
        error={start.error ? messageOf(start.error) : null}
        onConfirm={() => latest && start.mutate(latest.version)}
      >
        پیش از به‌روزرسانی از دیتابیس نسخه پشتیبان بگیرید. فایل‌های نسخه تازه از GitHub دانلود و امضایشان بررسی می‌شود، بعد فایل‌های برنامه عوض می‌شوند؛ در این چند لحظه فروشگاه به همه درخواست‌ها جواب
        «در حال به‌روزرسانی» می‌دهد. تنظیمات (config.php) و فایل‌های پوشه storage دست نمی‌خورند.
      </ConfirmModal>
      <ConfirmModal
        open={asking === 'rollback' && run !== null}
        onClose={() => setAsking(null)}
        title={`بازگرداندن نسخه ${run?.from ?? ''}`}
        confirmLabel="بازگرداندن"
        destructive
        pending={rollback.isPending}
        error={rollback.error ? messageOf(rollback.error) : null}
        onConfirm={() => rollback.mutate()}
      >
        {`نسخه ${run?.version ?? ''} برداشته می‌شود و نسخه ${run?.from ?? ''} که پیش از به‌روزرسانی روی فروشگاه بود برمی‌گردد؛ در این چند لحظه فروشگاه به همه درخواست‌ها جواب «در حال به‌روزرسانی» می‌دهد.`}
      </ConfirmModal>
    </>
  )
}

/** The newest release as last read: its version, when it came out and its notes (plain text, as written) — or that there is none newer. */
function NewestRelease({ latest, current }: { latest: ReleaseInfo | null; current: string }) {
  if (latest === null) return <p className="text-body text-muted-foreground">نسخه‌ای منتشر نشده است.</p>
  if (!isNewer(latest.version, current)) return <p className="text-body text-muted-foreground">این تازه‌ترین نسخه است.</p>

  return (
    <div className="grid gap-2">
      <p className="text-body">
        نسخه <bdi dir="ltr">{latest.version}</bdi> منتشر شده است{latest.published_at && <span className="text-muted-foreground"> · {formatDate(latest.published_at, { dateStyle: 'medium' })}</span>}.
      </p>
      {latest.notes && (
        <div className="max-h-64 overflow-y-auto rounded-lg border border-border bg-fill px-3 py-2.5 text-footnote whitespace-pre-line" aria-label="یادداشت‌های نسخه" tabIndex={0}>
          {latest.notes}
        </div>
      )}
      <TextLink href={latest.url} className="w-fit text-footnote">
        این نسخه در GitHub
      </TextLink>
    </div>
  )
}

interface RunCardProps {
  run: UpdateRun
  /** A step of it is being taken now. */
  driving: boolean
  /** Why the last step this screen asked for was refused. */
  error: unknown
  onDrive: () => void
  cancelling: boolean
  onCancel: () => void
  onRollback: () => void
}

/** The update under way — its steps, how far the one at hand is, a refusal and the ways on — or the last one, over. */
function RunCard({ run, driving, error, onDrive, cancelling, onCancel, onRollback }: RunCardProps) {
  const refusal = error ? messageOf(error) : run.error

  if (run.step === 'rolled_back') {
    return (
      <Callout tone="info" role="status">
        نسخه <bdi dir="ltr">{run.version}</bdi> برداشته شد و نسخه <bdi dir="ltr">{run.from}</bdi> برگشت ({formatDate(run.finished_at)}).
      </Callout>
    )
  }
  if (run.step === 'done') {
    return (
      <Callout tone="success" icon={CircleCheck} role="status">
        <div className="grid gap-2">
          <span>
            فروشگاه از نسخه <bdi dir="ltr">{run.from}</bdi> به نسخه <bdi dir="ltr">{run.version}</bdi> به‌روز شد ({formatDate(run.finished_at)}).
          </span>
          {run.rollback && (
            <div>
              <Button variant="secondary" size="sm" icon={Undo2} onClick={onRollback}>
                بازگرداندن نسخه قبلی
              </Button>
            </div>
          )}
        </div>
      </Callout>
    )
  }

  const at = STEPS.findIndex(({ step }) => step === run.step)

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">
            به‌روزرسانی به نسخه <bdi dir="ltr">{run.version}</bdi>
          </CardTitle>
          <CardDescription>تا پایان کار این صفحه را باز نگه دارید؛ هر مرحله یک درخواست است و اگر بسته شود، از همان‌جا ادامه می‌دهید.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-4">
        <ol className="grid gap-2" aria-label="مرحله‌های به‌روزرسانی">
          {STEPS.map(({ step, title }, index) => (
            <li key={step} className="flex items-center gap-2.5 text-body" aria-current={index === at ? 'step' : undefined}>
              <StepMark state={index < at ? 'done' : index > at ? 'waiting' : driving ? 'working' : refusal ? 'refused' : 'waiting'} />
              <span className={index === at ? 'font-medium' : 'text-muted-foreground'}>{title}</span>
              {index === at && step === 'extract' && run.progress > 0 && <span className="text-footnote text-muted-foreground">{formatPercent(run.progress)}</span>}
            </li>
          ))}
        </ol>
        {run.step === 'extract' && run.progress > 0 && <ProgressBar label="باز کردن بسته" value={run.progress} max={100} />}
        {!driving && <FormError message={refusal} failure={error ?? undefined} />}
        <div className="flex flex-wrap gap-2">
          <Button icon={RotateCw} busy={driving} onClick={onDrive}>
            {refusal ? 'تلاش دوباره' : 'ادامه به‌روزرسانی'}
          </Button>
          {run.cancel && !driving && (
            <Button variant="secondary" busy={cancelling} onClick={onCancel}>
              لغو به‌روزرسانی
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

/** A step's mark: done, being taken, refused, or still to come. */
function StepMark({ state }: { state: 'done' | 'working' | 'refused' | 'waiting' }) {
  switch (state) {
    case 'done':
      return <CircleCheck className="size-4 shrink-0 text-success" aria-label="انجام شد" />
    case 'working':
      return <LoaderCircle className="size-4 shrink-0 text-selected motion-safe:animate-spin" aria-label="در حال انجام" />
    case 'refused':
      return <CircleAlert className="size-4 shrink-0 text-danger" aria-label="متوقف شد" />
    default:
      return <Circle className="size-4 shrink-0 text-faint" aria-label="مانده" />
  }
}
