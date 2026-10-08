import { Fragment, useState, type ReactNode } from 'react'
import type { UseMutationResult, UseQueryResult } from '@tanstack/react-query'
import { CircleAlert, Gift, ServerOff } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { giftLabel, outcome, progress, reached } from '@/components/grants/grant-format'
import type { GrantRowBase } from '@/components/grants/use-grants'
import { Modal } from '@/components/modal'
import { ProgressBar } from '@/components/progress-bar'
import { Reviewer } from '@/components/reviewer'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { ServerGrantRow } from '@/lib/api-types'
import { formatDate, timeAgo } from '@/lib/format'
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

/**
 * A grants card — a server's «افزودن زمان و حجم», the mass gift: the button that issues one (none while one is under
 * way), the latest ones with how far each got, and stopping one, asked first.
 */
export function GrantCard<Row extends GrantRowBase>({ title, description, noun, addLabel, listLabel, read, running, cancel, item, add, stopDetails }: GrantCardProps<Row>) {
  const [adding, setAdding] = useState(false)
  const [stopping, setStopping] = useState<Row | null>(null)

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
        {read.error && <ErrorState what="لیست" error={read.error} onRetry={() => void read.refetch()} />}
        {read.data
          ? read.data.grants.length > 0 && (
              <ul className="grid divide-y divide-border overflow-hidden rounded-xl border border-border" aria-label={listLabel}>
                {read.data.grants.map((grant) => (
                  <Fragment key={grant.id}>{item(grant, () => setStopping(grant))}</Fragment>
                ))}
              </ul>
            )
          : !read.error && <Skeleton className="h-20 w-full" />}
      </CardContent>

      <Modal open={adding} onClose={() => setAdding(false)} size="md" title={add.title} description={add.description}>
        {adding && add.form(() => setAdding(false))}
      </Modal>

      <ConfirmModal
        open={stopping !== null}
        onClose={() => setStopping(null)}
        title={`توقف ${noun}`}
        description={stopping ? `${giftLabel(stopping)} به بقیه سرویس‌ها اضافه نمی‌شود.` : undefined}
        confirmLabel="توقف"
        destructive
        pending={cancel.isPending}
        onConfirm={() => stopping && cancel.mutate(stopping, { onSettled: () => setStopping(null) })}
      >
        {stopDetails}
      </ConfirmModal>
    </Card>
  )
}

/** What a grant's line shows of it, whichever card lists it. */
type GrantSummary = Pick<ServerGrantRow, 'days' | 'traffic_bytes' | 'reason' | 'status' | 'total' | 'granted' | 'skipped' | 'failed' | 'reviewer' | 'created_at' | 'include_unstarted' | 'notify'>

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
      {failure && (
        <p className="flex items-start gap-1.5 text-footnote text-danger">
          <CircleAlert className="mt-px size-3.5 shrink-0" aria-hidden />
          <span className="min-w-0 break-words" dir="auto">
            <span className="sr-only">آخرین خطا: </span>
            {failure}
          </span>
        </p>
      )}
      <div className="flex min-h-7 items-center justify-between gap-2 text-footnote text-muted-foreground">
        <span>
          <time dateTime={grant.created_at} title={formatDate(grant.created_at)}>
            {timeAgo(grant.created_at)}
          </time>
          {grant.reviewer && (
            <>
              {' · '}
              <Reviewer name={grant.reviewer} />
            </>
          )}
          {about.map((note) => ` · ${note}`)}
        </span>
        {running && (
          <Button variant="danger" size="sm" onClick={onStop}>
            توقف
          </Button>
        )}
      </div>
    </li>
  )
}
