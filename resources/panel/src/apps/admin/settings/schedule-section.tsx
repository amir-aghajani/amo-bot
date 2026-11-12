import { useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CircleAlert, RotateCcw } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { useReadFailure } from '@/components/page'
import { SectionCard } from '@/components/section-card'
import { useSectionShown } from '@/components/sectioned-page'
import { TextLink } from '@/components/text-link'
import { TimeAgo } from '@/components/time-ago'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { Schedule, ScheduleField, ScheduleRequest, ScheduleResponse, ScheduleTask } from '@/lib/api-types'
import { formatNumber, wholeNumberOf } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { asNumbers, asWhole } from '@/lib/use-form'
import { useSettingsGroup } from '@/lib/use-settings-group'

/** The page's words over the section: what it sets, for whom, and where its defaults come from. */
export const SCHEDULE_DESCRIPTION =
  'هر کار زمان‌بندی‌شده هر چند وقت یک بار اجرا شود، و زمان‌هایی که بر اساسشان تصمیم می‌گیرد. برای همه فروشگاه‌ها یکی است و در دیتابیس ذخیره می‌شود؛ پیش‌فرض‌ها همان‌هایی هستند که فروشگاه تا امروز با آن‌ها کار می‌کرد.'

/** The schedule as the form holds it: every field's number as typed, by its name. */
type Draft = Record<string, string>

/** The form's start: each field as the shop runs with it. */
function draftOf(schedule: Schedule): Draft {
  return Object.fromEntries(schedule.tasks.flatMap((task) => task.fields.map((field) => [field.name, String(field.value)])))
}

/** Every field's default, by its name: what «بازگشت به همه پیش‌فرض‌ها» fills in. */
function defaultsOf(fields: ScheduleField[]): Draft {
  return Object.fromEntries(fields.map((field) => [field.name, String(field.default)]))
}

/**
 * The request: every field, whole — a number as its digits read, else the text, for the server to refuse. The names
 * are the server's own (each ScheduleField's), so the request's are all there.
 */
function requestOf(values: Draft): ScheduleRequest {
  return Object.fromEntries(Object.entries(values).map(([name, typed]) => [name, asWhole(typed)])) as unknown as ScheduleRequest
}

/** Whether a field's typed value is its default, read as the server reads it (Persian digits, separators). */
function atDefault(field: ScheduleField, typed: string | undefined): boolean {
  return wholeNumberOf(typed ?? '') === field.default
}

/** A moment still ahead; null for one past, or none — a task that is due already. */
function ahead(moment: string | null): string | null {
  return moment !== null && new Date(moment).getTime() > Date.now() ? moment : null
}

/**
 * «زمان‌بندی کارها»: every scheduled task in the order a run takes them — what it does, when it last ran and is next
 * due — with how often it runs and the times it decides by, each beside its default. One form for the whole page, read
 * only while the section is on screen (and again every minute there, as the tasks run).
 */
export function ScheduleSection() {
  const shown = useSectionShown()
  const read = useQuery({
    queryKey: queryKeys.schedule,
    queryFn: () => api.get<ScheduleResponse>('/schedule'),
    enabled: shown,
    refetchInterval: shown ? 60_000 : false,
  })
  const failure = useReadFailure(read)
  const failed = failure.error && <ErrorState what="زمان‌بندی کارها" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />

  // A later read that fails is said over the form, which keeps its draft.
  if (!read.data) return failed || <Skeleton className="h-96 w-full rounded-xl" />

  return (
    <>
      {failed}
      <ScheduleForm schedule={read.data.schedule} />
    </>
  )
}

/** The tasks as one form: saved whole, the server's answer back on screen; every default filled in by one press. */
function ScheduleForm({ schedule }: { schedule: Schedule }) {
  const group = useSettingsGroup<Draft, ScheduleResponse, ScheduleResponse>({
    save: (values) => api.put('/schedule', requestOf(values)),
    queryKey: queryKeys.schedule,
    apply: (_current, result) => result,
    initial: draftOf(schedule),
    saved: 'زمان‌بندی ذخیره شد',
    reads: asNumbers,
  })
  const { values, set, patch, error } = group
  const fields = schedule.tasks.flatMap((task) => task.fields)
  const allDefault = fields.every((field) => atDefault(field, values[field.name]))

  return (
    <SectionCard
      form={group}
      title="کارهای زمان‌بندی‌شده"
      description={
        <>
          کارها به ترتیبی آمده‌اند که اجرا می‌شوند: Cron هر دقیقه اجرا می‌شود و هر کاری را که موعدش رسیده، به همین ترتیب انجام می‌دهد. کاری که نشان «در هر فروشگاه» دارد، در فروشگاه اصلی و فروشگاه هر
          نماینده یکی پس از دیگری اجرا می‌شود؛ بقیه یک بار برای همه.
          {schedule.last_run_at && (
            <>
              {' '}
              آخرین اجرای Cron: <TimeAgo at={schedule.last_run_at} />.
            </>
          )}
        </>
      }
      actions={
        // Held, not disabled: once every default is in, the focus stays on it.
        <Button variant="secondary" size="sm" icon={RotateCcw} onClick={() => patch(defaultsOf(fields))} aria-disabled={group.busy || allDefault} className="max-md:h-11">
          بازگشت به همه پیش‌فرض‌ها
        </Button>
      }
    >
      {schedule.last_run_at === null && (
        <Callout tone="warning" icon={CircleAlert}>
          Cron هنوز اجرا نشده است؛ تا راه نیفتد، هیچ‌کدام از این کارها انجام نمی‌شود.{' '}
          <TextLink inline to="/settings/advanced">
            راه‌اندازی Cron
          </TextLink>
        </Callout>
      )}
      {schedule.tasks.map((task) => (
        <TaskPart key={task.key} task={task} values={values} error={error} onChange={set} />
      ))}
    </SectionCard>
  )
}

interface TaskPartProps {
  task: ScheduleTask
  values: Draft
  error: (name: string) => string | undefined
  onChange: (name: string, value: string) => void
}

/** One task, framed: its name and what it does, its last and next run, its interval, then the times it decides by. */
function TaskPart({ task, values, error, onChange }: TaskPartProps) {
  const [interval, ...timings] = task.fields
  const titleId = `schedule_${task.key}_title`
  const field = (each: ScheduleField, every = false) => (
    <NumberField key={each.name} field={each} every={every} value={values[each.name] ?? ''} error={error(each.name)} onChange={(value) => onChange(each.name, value)} />
  )

  return (
    <div role="group" aria-labelledby={titleId} className="grid content-start gap-4 rounded-xl border border-border p-4">
      <div className="grid gap-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <h3 id={titleId} className="text-body font-medium">
            {task.title}
          </h3>
          {task.each_shop && <Badge variant="outline">در هر فروشگاه</Badge>}
        </div>
        <p className="text-footnote leading-relaxed text-muted-foreground">{task.description}</p>
        <Runs task={task} />
      </div>
      {interval && field(interval, true)}
      {timings.length > 0 && <div className="grid gap-4 border-t border-border pt-4">{timings.map((timing) => field(timing))}</div>}
    </div>
  )
}

/** When the task last ran and is next due — a moment past, or none, being the next run of Cron's. */
function Runs({ task }: { task: ScheduleTask }) {
  const next = ahead(task.next_run_at)

  return (
    <p className="flex flex-wrap gap-x-4 gap-y-0.5 text-footnote text-faint">
      <span>
        {task.last_ran_at ? (
          <>
            آخرین اجرا: <TimeAgo at={task.last_ran_at} />
          </>
        ) : (
          'هنوز اجرا نشده'
        )}
      </span>
      <span>اجرای بعدی: {next ? <TimeAgo at={next} /> : 'در نوبت بعدی Cron'}</span>
    </p>
  )
}

interface NumberFieldProps {
  field: ScheduleField
  value: string
  error?: string
  onChange: (value: string) => void
  /** The task's interval: «هر [ ] دقیقه». */
  every: boolean
}

/**
 * One number: its label, the input with its unit, its default beside it — and, while the value is another, a press that
 * puts the default back (and the focus on the input, the press gone with it) —, then its hint or the server's refusal.
 */
function NumberField({ field, value, error, onChange, every }: NumberFieldProps) {
  const input = useRef<HTMLInputElement>(null)
  const id = `schedule_${field.name}`
  const unit = `${id}-unit`
  const usual = `${id}-default`

  return (
    <Field id={id} label={field.label} hint={field.hint ?? undefined} error={error}>
      {(control) => (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
          {every && <span className="text-body">هر</span>}
          <Input
            {...control}
            ref={input}
            aria-describedby={[unit, usual, control['aria-describedby']].filter(Boolean).join(' ')}
            dir="ltr"
            inputMode="numeric"
            autoComplete="off"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className="w-24"
          />
          <span id={unit} className="text-body">
            {field.unit}
          </span>
          <span id={usual} className="ms-1 text-footnote text-faint">
            پیش‌فرض: {formatNumber(field.default)} {field.unit}
          </span>
          {!atDefault(field, value) && (
            <Button
              variant="ghost"
              size="sm"
              icon={RotateCcw}
              aria-label={`بازگرداندن ${field.label} به پیش‌فرض`}
              onClick={() => {
                onChange(String(field.default))
                input.current?.focus()
              }}
            >
              پیش‌فرض
            </Button>
          )}
        </div>
      )}
    </Field>
  )
}
