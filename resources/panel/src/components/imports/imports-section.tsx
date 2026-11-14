import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { History } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import { EmptyState } from '@/components/empty-state'
import { ErrorState } from '@/components/error-state'
import { ProgressCard, SourceWords } from '@/components/imports/progress-card'
import { Outcome } from '@/components/imports/report'
import { Review } from '@/components/imports/review'
import { StartCard } from '@/components/imports/start-card'
import { useImportRunner } from '@/components/imports/use-import-runner'
import { isOpen, isStepping, PHASE } from '@/components/imports/words'
import { useReadFailure } from '@/components/page'
import { useSectionShown } from '@/components/sectioned-page'
import { StatusBadge } from '@/components/status-badge'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { ImportsResponse, ImportState } from '@/lib/api-types'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/** The page's words over the section: what an import brings, and what it never touches. */
export const IMPORTS_DESCRIPTION =
  'مشتری‌ها، موجودی کیف پول‌ها، سرویس‌ها، پلن‌ها، کدهای تخفیف و هدیه، کانال‌ها و کارت‌های ربات قبلی‌تان را به فروشگاه اصلی بیاورید. روی پنل‌های VPN و ربات قبلی چیزی تغییر نمی‌کند.'

/** The imports with `state` in its place: the one under way while it is open, else the newest of the history. */
function placed(data: ImportsResponse, state: ImportState): ImportsResponse {
  if (isOpen(state)) return { ...data, current: state }
  return { ...data, current: data.current?.id === state.id ? null : data.current, history: [state, ...data.history.filter((each) => each.id !== state.id)] }
}

/** What the live region says of where the import stands: said as it changes (a phase, a stage), never every number. */
function announcement(current: ImportState | null, report: ImportState | undefined): string {
  if (current?.phase === 'reading') return 'در حال خواندن داده‌ها'
  if (current?.phase === 'review') return 'خواندن تمام شد؛ آنچه پیدا شد را بررسی کنید.'
  if (current?.phase === 'importing') return `در حال انتقال: ${current.progress.stage_label ?? ''}`
  if (report?.phase === 'done') return 'انتقال تمام شد'
  if (report?.phase === 'failed') return 'انتقال انجام نشد'
  if (report?.phase === 'cancelled') return 'انتقال لغو شد'
  return ''
}

/** An import ended under the panel's steps: its toast. */
function ended(state: ImportState) {
  if (state.phase === 'done') toast.success('انتقال تمام شد')
  if (state.phase === 'failed') toast.error('انتقال انجام نشد', { description: state.error ?? undefined })
}

/**
 * «انتقال از ربات دیگر» (the owner's, in the main shop): another Telegram VPN bot's data brought into the shop. With no
 * import under way, the bots it reads and where their data comes from, and the earlier imports; an import under way is
 * read, reviewed and written in turn — its steps asked for one after another while the panel is open (useImportRunner),
 * so coming back resumes it —, and once it ends its report is on screen until a new one starts. As the view changes
 * (a phase begins, the report opens) its heading takes the focus, and one live region says where the import stands.
 * Read only while the section is on screen.
 */
export function ImportsSection() {
  const shown = useSectionShown()
  const queryClient = useQueryClient()
  const read = useQuery({ queryKey: queryKeys.imports, queryFn: () => api.get<ImportsResponse>('/imports'), enabled: shown })
  const failure = useReadFailure(read)
  // An import that ended on screen — just now, or opened from the history —, its report shown in place of the start.
  const [opened, setOpened] = useState<number | null>(null)
  const [cancelling, setCancelling] = useState<ImportState | null>(null)
  const current = read.data?.current ?? null

  const put = (state: ImportState) => {
    queryClient.setQueryData<ImportsResponse>(queryKeys.imports, (data) => data && placed(data, state))
    if (isOpen(state)) return
    setOpened(state.id)
    ended(state)
    // A write ends having changed much of the shop: every read on screen is asked again.
    if (state.started_at !== null) void queryClient.invalidateQueries()
  }

  const cancel = useMutation({
    mutationFn: (state: ImportState) => api.delete(`/imports/${state.id}`),
    meta: { invalidates: [queryKeys.imports] },
    onSuccess: () => {
      queryClient.setQueryData<ImportsResponse>(queryKeys.imports, (data) => data && { ...data, current: null })
      toast.success('انتقال لغو شد')
    },
    // Refused (it ended meanwhile), the imports are read again; the toast says why.
    onError: () => void queryClient.invalidateQueries({ queryKey: queryKeys.imports }),
    onSettled: () => setCancelling(null),
  })

  // Stepped on while it reads or writes — not once the owner cancels it: a step's answer would put it back on screen.
  const runner = useImportRunner(current !== null && isStepping(current) && !cancel.isPending ? current.id : null, put)

  const report = current === null && opened !== null ? read.data?.history.find((each) => each.id === opened) : undefined
  // The view on screen: an open import's phase, an ended one's report, or the start — null until the imports are read.
  const view = read.data === undefined ? null : current !== null ? `${current.id} ${current.phase}` : report !== undefined ? `report ${report.id}` : 'start'
  const root = useRef<HTMLDivElement>(null)
  const viewBefore = useRef(view)
  useEffect(() => {
    const before = viewBefore.current
    viewBefore.current = view
    // The first view drawn keeps the focus where it is; a later one takes it to its heading.
    if (before === null || view === null || before === view || !shown) return
    root.current?.querySelector<HTMLElement>('[data-phase-heading]')?.focus()
  }, [view, shown])

  const failed = failure.error && <ErrorState what="انتقال" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />

  // A later read that fails is said over what is on screen.
  if (!read.data) return failed || <Skeleton className="h-96 w-full rounded-xl" />
  const { migrators, history } = read.data

  return (
    <div ref={root} className="grid gap-4">
      <p role="status" className="sr-only">
        {announcement(current, report)}
      </p>
      {failed}
      {current !== null ? (
        current.phase === 'review' && current.survey !== null ? (
          <Review key={current.id} state={{ ...current, survey: current.survey }} onStarted={put} onCancel={() => setCancelling(current)} cancelling={cancel.isPending} />
        ) : (
          <ProgressCard state={current} runner={runner} onCancel={() => setCancelling(current)} cancelling={cancel.isPending} />
        )
      ) : report !== undefined && (report.phase === 'done' || report.phase === 'failed' || report.phase === 'cancelled') ? (
        <Outcome key={report.id} state={{ ...report, phase: report.phase }} onAgain={() => setOpened(null)} />
      ) : (
        <>
          <StartCard migrators={migrators} uploadLimit={read.data.upload_max_bytes} onStarted={put} />
          <HistoryCard history={history} onOpen={setOpened} />
        </>
      )}

      <ConfirmModal
        open={cancelling !== null}
        onClose={() => setCancelling(null)}
        title="لغو انتقال"
        confirmLabel="لغو انتقال"
        cancelLabel="ادامه انتقال"
        destructive
        pending={cancel.isPending}
        onConfirm={() => cancelling && cancel.mutate(cancelling)}
      >
        <p className="text-body text-muted-foreground">
          {cancelling?.phase === 'importing' ? 'آنچه تا این لحظه منتقل شده در فروشگاه می‌ماند و بقیه منتقل نمی‌شود. ' : 'هنوز چیزی در فروشگاه نوشته نشده است. '}
          {cancelling?.source === 'database' ? 'داده‌هایی که از دیتابیس ربات قبلی خوانده شده پاک می‌شود' : 'فایل پشتیبان و داده‌هایی که از آن خوانده شده پاک می‌شود'}؛ هر وقت خواستید دوباره منتقل کنید،
          آنچه آمده دوباره نمی‌آید.
        </p>
      </ConfirmModal>
    </div>
  )
}

/** «انتقال‌های قبلی»: the imports that ended, newest first — when, from which bot, how it ended — each with its report. */
function HistoryCard({ history, onOpen }: { history: ImportState[]; onOpen: (id: number) => void }) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">انتقال‌های قبلی</CardTitle>
          <CardDescription>گزارش هر انتقال، با آنچه آمد و آنچه نیامد.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        {history.length === 0 ? (
          <EmptyState compact framed icon={History} title="هنوز انتقالی انجام نشده است" description="گزارش هر انتقال این‌جا نگه داشته می‌شود." />
        ) : (
          <ul aria-label="انتقال‌های قبلی" className="divide-y divide-border rounded-lg border border-border">
            {history.map((each) => (
              <li key={each.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5">
                <div className="grid min-w-0 flex-1 gap-0.5">
                  <span className="flex flex-wrap items-center gap-2 text-body">
                    <bdi dir="ltr" className="font-medium">
                      {each.migrator_label}
                    </bdi>
                    <StatusBadge status={PHASE[each.phase]} />
                  </span>
                  <span className="text-footnote wrap-anywhere text-muted-foreground">
                    <TimeAgo at={each.finished_at ?? each.updated_at} /> · از <SourceWords state={each} />
                  </span>
                </div>
                <Button variant="secondary" size="sm" aria-label={`گزارش انتقال ${formatDate(each.created_at)}`} onClick={() => onOpen(each.id)}>
                  گزارش
                </Button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
