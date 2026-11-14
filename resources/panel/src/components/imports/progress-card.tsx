import { Check, CloudOff, X } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import type { ImportRunner } from '@/components/imports/use-import-runner'
import { STAGES } from '@/components/imports/words'
import { ProgressBar } from '@/components/progress-bar'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import type { ImportState } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatBytes, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** Where the data is read from, as a sentence's end: «فایل backup.zip», «دیتابیس ربات». */
export function SourceWords({ state }: { state: Pick<ImportState, 'source' | 'file_name'> }) {
  if (state.source === 'database') return <>دیتابیس ربات</>

  return state.file_name === null ? (
    <>فایل پشتیبان</>
  ) : (
    <>
      فایل{' '}
      <bdi dir="ltr" className="break-all">
        {state.file_name}
      </bdi>
    </>
  )
}

interface ProgressCardProps {
  state: ImportState
  runner: ImportRunner
  /** «لغو»: asks first. */
  onCancel: () => void
  cancelling: boolean
}

/**
 * An import going on a step at a time, the panel asking for each: reading — how much of the file (bytes) or the database
 * (rows) is read — or importing — every stage in order, the one under way with how far it got. A step that failed is
 * said with its retry, which goes by itself too while it may help; one the runner stopped asking for is said as a
 * failure (its tracking code in its details), the retry the owner's. «لغو» asks first. Its heading takes the focus as
 * the phase begins (the section moves it there).
 */
export function ProgressCard({ state, runner, onCancel, cancelling }: ProgressCardProps) {
  const reading = state.phase === 'reading'

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle tabIndex={-1} data-phase-heading="" className="text-heading outline-none">
            {reading ? 'در حال خواندن داده‌ها' : 'در حال انتقال'}
          </CardTitle>
          <CardDescription>
            {reading ? (
              <>
                داده‌های <bdi dir="ltr">{state.migrator_label}</bdi> از <SourceWords state={state} /> خوانده می‌شود. هنوز چیزی در فروشگاه نوشته نشده است.
              </>
            ) : (
              <>
                داده‌های <bdi dir="ltr">{state.migrator_label}</bdi> در فروشگاه نوشته می‌شود. روی پنل‌ها و ربات قبلی چیزی تغییر نمی‌کند و به مشتری‌ها پیامی فرستاده نمی‌شود.
              </>
            )}
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-4">
        {reading ? <ReadingProgress state={state} /> : <Stages state={state} />}

        <p className="text-footnote leading-relaxed text-muted-foreground">
          تا وقتی این صفحه باز است، کار خودکار ادامه پیدا می‌کند. اگر صفحه را ببندید، با برگشتن به همین‌جا از همان‌جایی که مانده ادامه پیدا می‌کند.
        </p>

        {runner.trouble?.again && (
          <Callout tone="warning" icon={CloudOff} role="status">
            <div className="grid gap-2">
              <span>آخرین قدم انجام نشد؛ کمی بعد خودکار دوباره تلاش می‌شود.</span>
              <span className="text-footnote text-muted-foreground">{messageOf(runner.trouble.error)}</span>
              <div>
                <Button variant="secondary" size="sm" onClick={runner.retry}>
                  تلاش دوباره
                </Button>
              </div>
            </div>
          </Callout>
        )}
        {runner.trouble && !runner.trouble.again && (
          <div className="grid gap-2">
            <ErrorState error={runner.trouble.error} title="انتقال متوقف ماند" />
            <div>
              <Button variant="secondary" size="sm" onClick={runner.retry}>
                تلاش دوباره
              </Button>
            </div>
          </div>
        )}

        <div>
          <Button variant="danger-outline" icon={X} busy={cancelling} onClick={onCancel}>
            لغو انتقال
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

/** How much is read: a file's bytes, a database's rows — a bar once the whole is known. */
function ReadingProgress({ state }: { state: ImportState }) {
  const { done, total } = state.progress
  const count = (value: number) => (state.source === 'backup' ? formatBytes(value) : `${formatNumber(value)} ردیف`)

  return (
    <div className="grid gap-1.5">
      {total !== null && <ProgressBar label="پیشرفت خواندن" value={done} max={total} />}
      <span className="text-footnote text-muted-foreground tabular">{total === null ? `${count(done)} خوانده شد` : `${count(done)} از ${count(total)} خوانده شد`}</span>
    </div>
  )
}

/** The stages in order: those behind ticked, the one under way with its bar, those ahead quiet. */
function Stages({ state }: { state: ImportState }) {
  const { stage, done, total } = state.progress
  const at = STAGES.findIndex((each) => each.stage === stage)

  return (
    <ol aria-label="مرحله‌های انتقال" className="grid gap-2">
      {STAGES.map((each, place) => {
        const passed = at === -1 ? false : place < at
        const current = place === at

        return (
          <li key={each.stage} aria-current={current ? 'step' : undefined} className="grid gap-1.5">
            <span className={cn('flex items-center gap-2 text-body', current ? 'font-medium text-foreground' : passed ? 'text-foreground' : 'text-faint')}>
              <span
                aria-hidden
                className={cn(
                  'flex size-5 shrink-0 items-center justify-center rounded-full border text-caption',
                  passed ? 'border-success-line bg-success-soft text-success' : current ? 'border-selected text-selected' : 'border-border',
                )}
              >
                {passed ? <Check className="size-3" /> : formatNumber(place + 1)}
              </span>
              {each.label}
              {passed && <span className="sr-only">(انجام شد)</span>}
            </span>
            {current && (
              <div className="grid gap-1 ps-7">
                {total !== null && total > 0 && <ProgressBar label={`پیشرفت ${each.label}`} value={done} max={total} />}
                <span className="text-footnote text-muted-foreground tabular">
                  {total === null ? `${formatNumber(done)} مورد` : `${formatNumber(Math.min(done, total))} از ${formatNumber(total)}`}
                </span>
              </div>
            )}
          </li>
        )
      })}
    </ol>
  )
}
