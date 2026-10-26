import { Fragment, useState, type ReactNode } from 'react'
import type { UseMutationResult, UseQueryResult } from '@tanstack/react-query'
import { CircleAlert, Gift, ServerOff } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { giftLabel, outcome, progress, reached } from '@/components/grants/grant-format'
import type { GrantRowBase } from '@/components/grants/use-grants'
import { Modal } from '@/components/modal'
import { useReadFailure } from '@/components/page'
import { ProgressBar } from '@/components/progress-bar'
import { Reviewer } from '@/components/reviewer'
import { StatusBadge } from '@/components/status-badge'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { ServerGrantRow } from '@/lib/api-types'
import { GRANT_STATUS } from '@/lib/statuses'

interface GrantCardProps<Row extends GrantRowBase> {
  title: string
  description: string
  /** What one grant is called: «افزودن زمان و حجم», «هدیه همگانی». */
  noun: string
  /** The button that opens the dialog issuing one. */
  addLabel: string
  /** The list's name, for assistive tech. */
  listLabel: string
  read: UseQueryResult<{ grants: Row[] }>
  running: Row | null
  cancel: UseMutationResult<{ grant: Row }, Error, Row>
  /** One grant in the list, with the way to stop it. */
  item: (grant: Row, stop: () => void) => ReactNode
  /** The dialog issuing one: its title, what it says, its form — `close` once it started. */
  add: { title: string; description?: string; form: (close: () => void) => ReactNode }
  /** What stopping one leaves as it is. */
  stopDetails: string
}

/** A grants card (a server's, or the mass gift): issue one (not while one runs), the latest with their progress, and stop one. */
export function GrantCard<Row extends GrantRowBase>({ title, description, noun, addLabel, listLabel, read, running, cancel, item, add, stopDetails }: GrantCardProps<Row>) {
  const [adding, setAdding] = useState(false)
  const [stopping, setStopping] = useState<Row | null>(null)
  const failure = useReadFailure(read)

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-3">
        <Button variant="secondary" icon={Gift} className="w-fit" onClick={() => setAdding(true)} disabled={!read.data || running !== null}>
          {addLabel}
        </Button>
        {failure.error && <ErrorState what="لیست" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />}
        {read.data
          ? read.data.grants.length > 0 && (
              <ul className="grid divide-y divide-border overflow-hidden rounded-xl border border-border" aria-label={listLabel}>
                {read.data.grants.map((grant) => (
                  <Fragment key={grant.id}>{item(grant, () => setStopping(grant))}</Fragment>
                ))}
              </ul>
            )
          : !failure.error && <Skeleton className="h-20 w-full" />}
      </CardContent>

      <Modal open={adding} onClose={() => setAdding(false)} size="md" title={add.title} description={add.description}>
        {adding && add.form(() => setAdding(false))}
      </Modal>

      <ConfirmModal
        open={stopping !== null}
        onClose={() => setStopping(null)}
        title={`لغو ${noun}`}
        description={stopping ? `${giftLabel(stopping)} به بقیه سرویس‌ها اضافه نمی‌شود.` : undefined}
        confirmLabel="لغو"
        destructive
        pending={cancel.isPending}
        onConfirm={() => stopping && cancel.mutate(stopping, { onSettled: () => setStopping(null) })}
      >
        {stopDetails}
      </ConfirmModal>
    </Card>
  )
}

/**
 * The latest refusal of a job that goes on in the background (a grant, a move of a server's services), in its line: the
 * kit's danger strip — a status, not an alert, as the list is read again every few seconds while the job runs.
 */
export function LastFailure({ failure }: { failure: string }) {
  return (
    <Callout tone="danger" icon={CircleAlert} role="status" className="text-footnote">
      <span className="break-words" dir="auto">
        <span className="sr-only">آخرین خطا: </span>
        {failure}
      </span>
    </Callout>
  )
}

/** What a grant's line shows of it, whichever card lists it. */
type GrantSummary = Pick<
  ServerGrantRow,
  'days' | 'traffic_bytes' | 'reason' | 'status' | 'total' | 'granted' | 'skipped' | 'failed' | 'reviewer' | 'reviewer_name' | 'created_at' | 'include_unstarted' | 'notify'
>

interface GrantItemProps {
  grant: GrantSummary
  /** Whom it is for, after what it gives (a mass gift's audience). */
  audience?: string
  /** How far each part got, under the count (a mass gift's servers). */
  detail?: string
  /** What holds it up — each panel's diagnosis, a line each —; and what waiting means here: tried again by itself. */
  waiting: string[]
  waitingNote: string
  /** The latest service a panel refused, and why. */
  failure: string | null
  /** More about it, after who issued it («بخشی از هدیه همگانی #۳»). */
  notes?: string[]
  onStop: () => void
}

/** One grant: what it gives, the reason, how far it got (a bar while it runs), what holds it up, who issued it and when. */
export function GrantItem({ grant, audience, detail, waiting, waitingNote, failure, notes = [], onStop }: GrantItemProps) {
  const running = grant.status === 'running'
  const done = reached(grant)
  const about = [...notes, grant.include_unstarted && 'با سرویس‌های در انتظار اولین اتصال', !grant.notify && 'بدون پیام به مشتری‌ها'].filter((note) => typeof note === 'string')

  return (
    <li className="grid gap-2 px-3 py-2.5 text-body">
      <div className="flex items-center justify-between gap-2">
        <span className="font-medium">
          {giftLabel(grant)}
          {audience && <span className="font-normal text-muted-foreground"> · {audience}</span>}
        </span>
        <StatusBadge status={GRANT_STATUS[grant.status]} />
      </div>
      {grant.reason && (
        <p className="line-clamp-2 text-footnote text-muted-foreground" dir="auto">
          {grant.reason}
        </p>
      )}
      {running ? (
        <div className="grid gap-1">
          <ProgressBar label="پیشرفت" value={done} max={grant.total} />
          <span className="text-footnote text-muted-foreground tabular">{progress(grant)}</span>
          {detail && <span className="text-footnote text-muted-foreground tabular">{detail}</span>}
        </div>
      ) : (
        <p className="text-footnote text-muted-foreground">{outcome(grant)}</p>
      )}
      {running && waiting.length > 0 && (
        <Callout tone="warning" icon={ServerOff} role="status" className="text-footnote">
          <div className="grid gap-0.5">
            <span>{waitingNote}</span>
            {waiting.map((line) => (
              <span key={line} className="break-words" dir="auto">
                {line}
              </span>
            ))}
          </div>
        </Callout>
      )}
      {failure && <LastFailure failure={failure} />}
      <div className="flex min-h-7 items-center justify-between gap-2 text-footnote text-muted-foreground">
        <span>
          <TimeAgo at={grant.created_at} />
          {grant.reviewer && (
            <>
              {' · '}
              <Reviewer name={grant.reviewer} person={grant.reviewer_name} />
            </>
          )}
          {about.map((note) => ` · ${note}`)}
        </span>
        {running && (
          <Button variant="danger" size="sm" onClick={onStop}>
            لغو
          </Button>
        )}
      </div>
    </li>
  )
}
