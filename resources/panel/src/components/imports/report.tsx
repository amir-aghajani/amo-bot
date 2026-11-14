import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleAlert, CircleCheck, Info, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { EmptyState } from '@/components/empty-state'
import { ErrorState } from '@/components/error-state'
import { FilterSelect } from '@/components/filter-select'
import { SourceWords } from '@/components/imports/progress-card'
import { COUNT_KINDS, COUNTED_REASONS, PHASE, thingOf } from '@/components/imports/words'
import { useReadFailure } from '@/components/page'
import { Pagination } from '@/components/pagination'
import { Reviewer } from '@/components/reviewer'
import { StatusBadge } from '@/components/status-badge'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { ImportProblemsResponse, ImportsResponse, ImportState } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/** How an ended import's report is headed. */
const TITLES: Record<'done' | 'failed' | 'cancelled', string> = {
  done: 'انتقال تمام شد',
  failed: 'انتقال انجام نشد',
  cancelled: 'انتقال لغو شد',
}

interface OutcomeProps {
  state: ImportState & { phase: 'done' | 'failed' | 'cancelled' }
  /** «انتقال تازه»: back to the start. */
  onAgain: () => void
}

/**
 * An import that ended — done, failed or cancelled —: how it ended (a failure's words), what it did of each kind and,
 * below, what it did not bring and why. «انتقال تازه» goes back to the start; its record can be deleted (what it
 * brought stays, and a later import still knows it).
 */
export function Outcome({ state, onAgain }: OutcomeProps) {
  const queryClient = useQueryClient()
  const [deleting, setDeleting] = useState(false)
  const remove = useMutation({
    mutationFn: () => api.delete(`/imports/${state.id}`),
    meta: { invalidates: [queryKeys.imports] },
    onSuccess: () => {
      // Gone from the history at once, not when the imports are read again.
      queryClient.setQueryData<ImportsResponse>(queryKeys.imports, (data) => data && { ...data, history: data.history.filter((each) => each.id !== state.id) })
      toast.success('گزارش انتقال حذف شد')
      onAgain()
    },
  })
  const started = state.started_at !== null

  return (
    <>
      <Card>
        <CardHeader>
          <CardHeading>
            <CardTitle tabIndex={-1} data-phase-heading="" className="text-heading outline-none">
              {TITLES[state.phase]}
            </CardTitle>
            <CardDescription>
              داده‌های <bdi dir="ltr">{state.migrator_label}</bdi> از <SourceWords state={state} />
              {' · '}
              <TimeAgo at={state.finished_at ?? state.updated_at} />
              {state.reviewer && (
                <>
                  {' · '}
                  <Reviewer name={state.reviewer} />
                </>
              )}
            </CardDescription>
          </CardHeading>
          <StatusBadge status={PHASE[state.phase]} />
        </CardHeader>
        <CardContent className="grid gap-4">
          {state.phase === 'done' && (
            <Callout tone="success" icon={CircleCheck}>
              داده‌های ربات قبلی در فروشگاه نوشته شد. اگر پشتیبان تازه‌تری از ربات قبلی دارید (مثلا با مشتری‌هایی که بعد از این پشتیبان آمده‌اند)، می‌توانید با آن دوباره منتقل کنید؛ آنچه این بار آمده
              دوباره نمی‌آید و موجودی‌ها دوباره اضافه نمی‌شوند. آخرین فایل پشتیبان ربات قبلی را نگه دارید: اگر نسخه‌ای بعدی از AmoBot چیزی از «آنچه نیامد» را آورد، با همان فایل دوباره منتقل کنید.
            </Callout>
          )}
          {state.phase === 'failed' && (
            <Callout tone="danger" icon={CircleAlert}>
              {state.error ?? 'انتقال ناتمام ماند.'} {started ? 'آنچه پیش از آن منتقل شده بود در فروشگاه مانده است.' : 'چیزی در فروشگاه نوشته نشد.'}
            </Callout>
          )}
          {state.phase === 'cancelled' && (
            <Callout tone="info" icon={Info}>
              {started ? 'آنچه پیش از لغو منتقل شده بود در فروشگاه مانده است. هر وقت خواستید دوباره منتقل کنید؛ آنچه آمده دوباره نمی‌آید.' : 'پیش از نوشتن لغو شد؛ چیزی در فروشگاه نوشته نشد.'}
            </Callout>
          )}

          {state.counts !== null && <Counts counts={state.counts} />}

          <div className="flex flex-wrap items-center gap-2">
            <Button icon={Plus} onClick={onAgain}>
              انتقال تازه
            </Button>
            <Button variant="danger" icon={Trash2} onClick={() => setDeleting(true)}>
              حذف گزارش
            </Button>
          </div>
        </CardContent>
      </Card>

      {state.problems.by_reason.length > 0 && <ProblemsCard state={state} />}

      <ConfirmModal
        open={deleting}
        onClose={() => setDeleting(false)}
        title="حذف گزارش انتقال"
        confirmLabel="حذف گزارش"
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutate(undefined, { onSettled: () => setDeleting(false) })}
      >
        فقط گزارش این انتقال حذف می‌شود. آنچه منتقل شده در فروشگاه می‌ماند و انتقال بعدی هم آن را دوباره نمی‌آورد.
      </ConfirmModal>
    </>
  )
}

/** What the import did of each kind: made new («تازه»), matched to what the shop had («از قبل بود»), left out («نیامد»). */
function Counts({ counts }: { counts: NonNullable<ImportState['counts']> }) {
  const known = new Set(COUNT_KINDS.map(({ kind }) => kind))
  const rows = [
    ...COUNT_KINDS,
    ...Object.keys(counts)
      .filter((kind) => !known.has(kind))
      .map((kind) => ({ kind, label: thingOf(kind) })),
  ]
    .map(({ kind, label }) => ({ kind, label, count: counts[kind] }))
    .filter((row) => row.count !== undefined && row.count.created + row.count.linked + row.count.skipped > 0)

  if (rows.length === 0) return <p className="text-footnote text-muted-foreground">چیزی منتقل نشد.</p>

  return (
    <ul aria-label="آنچه منتقل شد" className="grid divide-y divide-border rounded-xl border border-border text-body">
      {rows.map(({ kind, label, count }) => (
        <li key={kind} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 px-3 py-2">
          <span className="min-w-0 font-medium">{label}</span>
          <span className="text-footnote text-muted-foreground tabular">
            {[`${formatNumber(count?.created ?? 0)} تازه`, `${formatNumber(count?.linked ?? 0)} از قبل بود`, `${formatNumber(count?.skipped ?? 0)} نیامد`].join(' · ')}
          </span>
        </li>
      ))}
    </ul>
  )
}

/**
 * «آنچه نیامد»: the reasons with how many each — those only counted (the owner's choice, nothing to act on) among them —
 * and the things themselves, a page at a time, narrowed to a reason: which thing, and what finds it.
 */
function ProblemsCard({ state }: { state: ImportState }) {
  const [reason, setReason] = useState('')
  const [page, setPage] = useState(1)
  const listed = state.problems.by_reason.filter((each) => !COUNTED_REASONS.has(each.reason))
  const rows = listed.reduce((sum, each) => sum + each.count, 0)
  const view = { reason, page }
  const read = useQuery({
    queryKey: queryKeys.importProblems(state.id, view),
    queryFn: () => api.get<ImportProblemsResponse>(`/imports/${state.id}/problems`, new URLSearchParams({ ...(reason === '' ? {} : { reason }), page: String(page) })),
    enabled: rows > 0,
    placeholderData: keepPreviousData,
  })
  const failure = useReadFailure(read, state.id)
  const narrow = (to: string) => {
    setReason(to)
    setPage(1)
  }

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">آنچه نیامد</CardTitle>
          <CardDescription>هر مورد با دلیلش. مواردی که پنلشان جواب نداد، با اجرای دوباره انتقال از همان پشتیبان یا پشتیبان تازه‌تر منتقل می‌شوند.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-4">
        <ul aria-label="دلیل‌ها" className="grid divide-y divide-border rounded-xl border border-border text-body">
          {state.problems.by_reason.map((each) => (
            <li key={each.reason} className="flex items-baseline justify-between gap-3 px-3 py-2">
              <span className="min-w-0">{each.label}</span>
              <span className="shrink-0 text-footnote text-muted-foreground tabular">{formatNumber(each.count)}</span>
            </li>
          ))}
        </ul>

        {rows > 0 && (
          <div className="grid gap-3">
            <FilterSelect
              label="دلیل"
              value={reason}
              onChange={narrow}
              options={[{ value: '', label: 'همه' }, ...listed.map((each) => ({ value: each.reason, label: `${each.label} (${formatNumber(each.count)})` }))]}
              className="w-full sm:w-auto"
            />
            {failure.error ? (
              <ErrorState what="لیست" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />
            ) : read.data ? (
              read.data.problems.length === 0 ? (
                <EmptyState compact framed title="موردی نیست" />
              ) : (
                <>
                  <ul aria-label="موارد" className="grid divide-y divide-border overflow-hidden rounded-xl border border-border text-body">
                    {read.data.problems.map((problem, place) => (
                      <li key={`${problem.kind}-${problem.source_key}-${place}`} className="grid gap-0.5 px-3 py-2">
                        <span className="flex flex-wrap items-baseline gap-x-2">
                          <span className="font-medium">{thingOf(problem.kind)}</span>
                          <bdi dir="ltr" className="min-w-0 break-all text-muted-foreground">
                            {problem.source_key}
                          </bdi>
                        </span>
                        {reason === '' && <span className="text-footnote text-muted-foreground">{problem.label}</span>}
                        {problem.detail && (
                          <span className="text-footnote wrap-anywhere" dir="auto">
                            {problem.detail}
                          </span>
                        )}
                      </li>
                    ))}
                  </ul>
                  <Pagination
                    page={read.data.page}
                    lastPage={Math.max(1, Math.ceil(read.data.total / read.data.per_page))}
                    total={read.data.total}
                    unit="مورد"
                    onChange={setPage}
                    disabled={read.isPlaceholderData}
                  />
                </>
              )
            ) : (
              <Skeleton className="h-32 w-full rounded-xl" />
            )}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
