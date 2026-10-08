import { useEffect, useRef, type ReactNode } from 'react'
import { ArrowLeftRight, Check, Circle, CircleSlash, LoaderCircle, TriangleAlert, X } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { Modal, useHoldOpen } from '@/components/modal'
import { useServerChoices, type ServerChoices } from '@/components/subscriptions/server-choices'
import { SkipServerPrompt } from '@/components/subscriptions/skip-server-prompt'
import { useMoveBatch, type MoveBatch, type Outcome } from '@/components/subscriptions/use-move-batch'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { SubscriptionRow } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { formatNumber } from '@/lib/format'

interface MoveDialogProps {
  /** The services to move — one from its details window, or the list's selection; null when closed. */
  subscriptions: SubscriptionRow[] | null
  /** Where the panel reads the servers to move to. */
  servers: ServerChoices
  onClose: () => void
  /** One service moved: its fresh row. */
  onMoved: (subscription: SubscriptionRow) => void
}

/**
 * Move services to another server, one request each, so the admin watches every one of them go (or
 * fail, and why). A previous server that does not answer pauses the batch with a question: carry on
 * without deleting from it, or cancel. The dialog stays while the batch runs.
 */
export function MoveDialog({ subscriptions, servers, onClose, onMoved }: MoveDialogProps) {
  const batch = useMoveBatch(subscriptions, onMoved)

  return (
    <Modal open={subscriptions !== null} onClose={onClose} title="انتقال به سرور دیگر">
      {subscriptions && <MoveBody subscriptions={subscriptions} batch={batch} servers={servers} onClose={onClose} />}
    </Modal>
  )
}

function MoveBody({ subscriptions, batch, servers: readServers, onClose }: { subscriptions: SubscriptionRow[]; batch: MoveBatch; servers: ServerChoices; onClose: () => void }) {
  // The dialog stays while the batch runs.
  useHoldOpen(batch.running)
  const servers = useServerChoices(readServers)
  // A client left on the previous server is the owner's to remove: in an agent's shop, support's — the agent reaches no panel.
  const agentShop = !useMainShop()
  const { movable, queue, running, started, question } = batch
  // The way out takes the focus whenever the button pressed has gone: the start, once the batch runs; the prompt's, once
  // it is answered (while it asks, the prompt has it).
  const way = useRef<HTMLButtonElement>(null)
  const asking = question !== null
  useEffect(() => {
    if (running && !asking) way.current?.focus()
  }, [running, asking])
  const inactive = subscriptions.length - movable.length
  const already = movable.length - queue.length
  // A server every service is on already is no target.
  const options = (servers.data ?? []).filter((server) => !movable.every((s) => s.server.id === server.id))

  const summary = running
    ? `در حال انتقال… ${formatNumber(batch.moved + batch.failed)} از ${formatNumber(queue.length)}`
    : started
      ? [`${formatNumber(batch.moved)} منتقل شد`, batch.failed > 0 && `${formatNumber(batch.failed)} ناموفق`, batch.cancelled && 'انتقال لغو شد'].filter(Boolean).join(' · ')
      : ''

  return (
    <div className="grid gap-4">
      <div className="grid gap-1 text-body text-muted-foreground">
        <p>
          هر سرویس با زمان و حجم باقی‌مانده‌اش روی سرور مقصد ساخته و از سرور قبلی حذف می‌شود؛ لینکش عوض می‌شود و لینک جدید در ربات برای مشتری می‌رود. اگر سرور قبلی جواب ندهد، می‌پرسیم بدون حذف از آن
          ادامه دهیم یا انتقال لغو شود.
        </p>
        {inactive > 0 && <p>{formatNumber(inactive)} سرویس انتخاب‌شده فعال نیست و منتقل نمی‌شود.</p>}
        {already > 0 && <p>{formatNumber(already)} سرویس همین حالا روی این سرور است.</p>}
      </div>

      {servers.error && <ErrorState what="لیست سرورها" error={servers.error} onRetry={() => void servers.refetch()} retrying={servers.isFetching} />}

      <Field id="move_target" label="سرور مقصد">
        {(control) => (
          <Select value={batch.target} onValueChange={batch.setTarget} disabled={running || started || movable.length === 0}>
            <SelectTrigger {...control} data-autofocus className="w-full">
              <SelectValue placeholder={servers.isPending ? 'در حال بارگذاری…' : 'یک سرور انتخاب کنید'} />
            </SelectTrigger>
            <SelectContent>
              {options.map((server) => (
                // A server that cannot take a service now says why — its own words.
                <SelectItem
                  key={server.id}
                  value={String(server.id)}
                  disabled={server.unsellable_reason !== null}
                  hint={server.unsellable_reason ?? (server.active !== null ? `${formatNumber(server.active)} سرویس فعال` : undefined)}
                >
                  {server.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </Field>

      {queue.length > 0 && (
        <ul aria-label="سرویس‌ها" className="grid max-h-64 scrollbar-thin gap-px overflow-y-auto rounded-xl border border-border p-1.5 text-body">
          {queue.map((subscription) => (
            <OutcomeLine key={subscription.id} subscription={subscription} outcome={batch.outcomes[subscription.id]} />
          ))}
        </ul>
      )}

      {question && (
        <SkipServerPrompt
          reason={question.reason}
          question={
            question.others > 0
              ? `این سرویس و ${formatNumber(question.others)} سرویس دیگر همین سرور بدون حذف از «${question.subscription.server.name}» منتقل شوند؟ کلاینت‌های قبلی روی آن سرور می‌مانند و ${agentShop ? 'حذفشان از سرور با پشتیبانی است' : 'بعدا باید خودتان حذفشان کنید'}.`
              : `بدون حذف از «${question.subscription.server.name}» منتقل شود؟ کلاینت قبلی روی آن سرور می‌ماند و ${agentShop ? 'حذفش از سرور با پشتیبانی است' : 'بعدا باید خودتان حذفش کنید'}.`
          }
          skipLabel="انتقال بدون حذف از سرور قبلی"
          cancelLabel="لغو انتقال"
          onSkip={() => batch.reply(true)}
          onCancel={() => batch.reply(false)}
        />
      )}

      {/* Why is on the service's own line; here, what it meant for the rest. */}
      {batch.stopped && (
        <Callout tone="danger" icon={CircleSlash}>
          انتقال بعد از خطای <bdi dir="ltr">{batch.stopped.name}</bdi> متوقف شد: سرویس‌های بعدی هم به همین دلیل منتقل نمی‌شدند.
        </Callout>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
        <span role="status" className="text-footnote text-muted-foreground">
          {summary}
        </span>
        <div className="flex gap-2">
          {/* One way out the whole time — «انصراف», then «توقف بعد از این سرویس» while the batch runs, «بستن» once it is
              over —: the same button, so the focus it has stays with it as the batch moves on (one taking another's
              place would leave the focus on the dialog). Out of sight while the prompt asks, which has its own. */}
          <Button ref={way} variant="ghost" size="sm" hidden={running && asking} onClick={running ? batch.stopAfterThis : onClose}>
            {running ? 'توقف بعد از این سرویس' : started ? 'بستن' : 'انصراف'}
          </Button>
          {!started && (
            <Button size="sm" icon={ArrowLeftRight} disabled={batch.target === '' || queue.length === 0 || running} onClick={batch.start}>
              انتقال {formatNumber(queue.length)} سرویس
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}

/** One service of the batch: its name, where it is, and how its move went. */
function OutcomeLine({ subscription, outcome }: { subscription: SubscriptionRow; outcome: Outcome | undefined }) {
  const { icon, status } = describe(outcome, subscription.server.name)

  return (
    <li className="grid gap-0.5 rounded-md px-2 py-1.5">
      <span className="flex items-center gap-2">
        {icon}
        <bdi dir="ltr">{subscription.name}</bdi>
        <span className="text-footnote text-muted-foreground">{status}</span>
      </span>
      {outcome?.state === 'failed' && <span className="ps-6 text-footnote text-danger">{outcome.message}</span>}
    </li>
  )
}

/** A line's icon and words, `from` being the server the service was on. */
function describe(outcome: Outcome | undefined, from: string): { icon: ReactNode; status: string } {
  if (outcome === undefined) {
    return { icon: <Circle className="size-4 text-faint" aria-hidden />, status: `روی ${from}` }
  }
  switch (outcome.state) {
    case 'moving':
      return { icon: <LoaderCircle className="size-4 animate-spin text-muted-foreground" aria-hidden />, status: 'در حال انتقال…' }
    case 'asking':
      return { icon: <TriangleAlert className="size-4 text-warning" aria-hidden />, status: 'منتظر تصمیم شما' }
    case 'moved':
      return { icon: <Check className="size-4 text-success" aria-hidden />, status: outcome.left ? `منتقل شد به ${outcome.server} · کلاینت قبلی روی ${from} ماند` : `منتقل شد به ${outcome.server}` }
    case 'cancelled':
      return { icon: <CircleSlash className="size-4 text-muted-foreground" aria-hidden />, status: `لغو شد؛ روی ${from} ماند` }
    case 'failed':
      return { icon: <X className="size-4 text-danger" aria-hidden />, status: `روی ${from}` }
  }
}
