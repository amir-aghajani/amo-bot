import { useState } from 'react'
import { ArrowLeftRight, Copy, Gift, Power, PowerOff, RefreshCw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { DetailFooter, DetailModal, useSubjectGone } from '@/components/detail-modal'
import { Fact, FactList } from '@/components/fact-list'
import { OperationsSection, useOperations, type OperationSpec } from '@/components/operations'
import { StatusBadge } from '@/components/status-badge'
import { ExtendForm } from '@/components/subscriptions/extend-form'
import { ServiceExpiry, UsageMeter } from '@/components/subscriptions/service-usage'
import { SkipServerPrompt } from '@/components/subscriptions/skip-server-prompt'
import { TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { CustomerFacts } from '@/components/user-identity'
import { api, ApiError } from '@/lib/api'
import type { SubscriptionRow } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { copyText } from '@/lib/clipboard'
import { idLabel } from '@/lib/direction'
import { formatBytes, formatDate, formatDays, formatDevices, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { SUBSCRIPTION_STATUS } from '@/lib/statuses'
import { cn } from '@/lib/utils'

type Action = keyof SubscriptionRow['actions']

/** What each operation is called, what it does, and whether it asks first. */
const OPERATIONS: Record<Action, OperationSpec> = {
  sync: { label: 'به‌روزرسانی از پنل', icon: RefreshCw, tone: 'neutral', done: 'از پنل به‌روز شد' },
  // Its strip is a form (ExtendForm): the days, the traffic, the note, whether the customer hears it.
  extend: { label: 'افزایش زمان و حجم', icon: Gift, tone: 'neutral', done: 'اضافه شد', form: true },
  enable: { label: 'فعال کردن دوباره', icon: Power, tone: 'primary', done: 'دوباره فعال شد' },
  disable: {
    label: 'غیرفعال کردن',
    icon: PowerOff,
    tone: 'danger',
    done: 'غیرفعال شد',
    confirm: 'کلاینت روی پنل خاموش می‌شود و تا فعال شدن دوباره وصل نمی‌شود. مشتری در ربات خبردار می‌شود.',
    note: { label: 'توضیح برای مشتری', placeholder: 'اختیاری؛ مثلا: استفاده خارج از قوانین' },
  },
  // Not run from here: it opens the move dialog, which picks the server and shows how the move went.
  move: { label: 'انتقال به سرور دیگر', icon: ArrowLeftRight, tone: 'neutral', done: 'منتقل شد' },
  delete: {
    label: 'حذف سرویس',
    icon: Trash2,
    tone: 'danger',
    done: 'حذف شد',
    confirm: 'کلاینت از پنل و سرویس از فروشگاه کامل حذف می‌شود و لینکش دیگر کار نمی‌کند؛ این کار برگشت ندارد. اگر سرویس فعال باشد، مشتری در ربات خبردار می‌شود.',
    note: { label: 'توضیح برای مشتری', placeholder: 'اختیاری؛ فقط برای سرویسی که هنوز فعال است فرستاده می‌شود' },
  },
}

interface SubscriptionModalProps {
  subscription: SubscriptionRow | null
  /** The subscription was deleted meanwhile, elsewhere: nothing more can be done to it. */
  gone?: boolean
  onClose: () => void
  /** The subscription came back in a new state: into its list (what else it moved is read again — the operations' `invalidates`). */
  onChanged: (subscription: SubscriptionRow) => void
  /** The subscription was deleted: it is gone for good — out of its list. */
  onDeleted: (subscription: SubscriptionRow) => void
  /** The server says the row is out of date (someone got there first): reload it and the list. */
  onStale: () => void
  /** «انتقال به سرور دیگر»: hand the service to the move dialog. */
  onMove: (subscription: SubscriptionRow) => void
  /** A server's own page, in a panel that has one (the owner's): the service's server links there. */
  serverPage?: (id: number) => string
  /** The traffic the shop's bot may still sell, where the panel reads it (an agent's own): what extending a service takes its GB from. */
  traffic?: number
}

/** A service as the admin manages it: its link, the facts, and every operation its state allows. */
export function SubscriptionModal({ subscription, gone, onClose, onChanged, onDeleted, onStale, onMove, serverPage, traffic }: SubscriptionModalProps) {
  return (
    <DetailModal subject={subscription} gone={gone} title={(subject) => <bdi dir="ltr">{subject.name}</bdi>} description={(subject) => `اشتراک ${idLabel(subject.id)}`} onClose={onClose}>
      {(subject) => (
        <SubscriptionBody subscription={subject} onClose={onClose} onChanged={onChanged} onDeleted={onDeleted} onStale={onStale} onMove={onMove} serverPage={serverPage} traffic={traffic} />
      )}
    </DetailModal>
  )
}

interface SubscriptionBodyProps {
  subscription: SubscriptionRow
  onClose: () => void
  onChanged: (subscription: SubscriptionRow) => void
  onDeleted: (subscription: SubscriptionRow) => void
  onStale: () => void
  onMove: (subscription: SubscriptionRow) => void
  serverPage?: (id: number) => string
  traffic?: number
}

/**
 * What a service's operation changes beside the service: its servers' counts and room (the subscriptions screen's server
 * pills, the move's targets), the dashboard's counts, and the customer's page — their counts.
 */
const CHANGES = [queryKeys.serverChoices, queryKeys.dashboards, queryKeys.everyUser]

/** An extension, besides: in an agent's shop its GB come out of the bot's traffic, which the agent's panel shows (the account). */
const EXTENDED = [...CHANGES, queryKeys.account]

function SubscriptionBody({ subscription, onClose, onChanged, onDeleted, onStale, onMove, serverPage, traffic }: SubscriptionBodyProps) {
  const gone = useSubjectGone()
  // A client left on its panel is the owner's to remove: in an agent's shop, support's — the agent reaches no panel.
  const agentShop = !useMainShop()
  // A delete the panel did not take: what it said, while the admin decides whether to delete without it.
  const [panelDown, setPanelDown] = useState<string | null>(null)

  const ops = useOperations({
    specs: OPERATIONS,
    allowed: subscription.actions,
    // The fresh row — or null after a delete, which leaves no row behind (204); `force` deletes without the panel.
    perform: async ({ action, note, force }) =>
      action === 'delete'
        ? api.post(`/subscriptions/${subscription.id}/delete`, { note, leave_panel: force }).then(() => null)
        : (await api.post(`/subscriptions/${subscription.id}/${action}`, note === undefined ? undefined : { note })).subscription,
    invalidates: CHANGES,
    onDone: (fresh, { action, force }) => {
      setPanelDown(null)
      ops.close()
      if (fresh === null) {
        onDeleted(subscription)
        if (force) toast.warning(`سرویس ${subscription.name} حذف شد؛ کلاینتش روی سرور «${subscription.server.name}» ماند و ${agentShop ? 'حذفش از سرور با پشتیبانی است' : 'باید خودتان حذفش کنید'}`)
        else toast.success(`سرویس ${subscription.name} ${OPERATIONS.delete.done}`)
        onClose()
        return
      }
      onChanged(fresh)
      if (action === 'sync' && fresh.status === 'deleted') toast.warning(`کلاینت ${fresh.name} روی پنل پیدا نشد؛ اشتراک حذف‌شده علامت خورد`)
      else toast.success(`سرویس ${fresh.name} ${OPERATIONS[action].done}`)
    },
    onStale,
    // A delete whose panel did not answer turns into the question whether to delete without it; deleting without it
    // refused (an agent's shop while that panel answers) or failed goes back to the delete's strip, which says why.
    onFailure: (error, { action, force }) => {
      if (action !== 'delete') return false
      if (force) {
        setPanelDown(null)
        return false
      }
      const panel = error instanceof ApiError ? error.field('panel') : undefined
      if (panel === undefined) return false
      setPanelDown(panel)
      return true
    },
    // A move picks its server in its own dialog.
    intercept: (action) => {
      if (action !== 'move') return false
      onMove(subscription)
      return true
    },
    // The question waits on the admin however the row changes meanwhile.
    holding: panelDown !== null,
  })

  return (
    <div className="grid gap-5">
      <LinkBox link={subscription.link} dead={gone || subscription.status === 'deleted'} />
      <Facts subscription={subscription} serverPage={serverPage} />
      <OperationsSection
        ops={ops}
        specs={OPERATIONS}
        subject="این سرویس"
        form={() => (
          <ExtendForm
            subscription={subscription}
            traffic={traffic}
            invalidates={EXTENDED}
            onCancel={ops.close}
            onExtended={(fresh, gift) => {
              ops.close()
              onChanged(fresh)
              toast.success(`${gift} به سرویس ${fresh.name} ${OPERATIONS.extend.done}`)
            }}
            onStale={onStale}
          />
        )}
        override={
          panelDown && (
            <SkipServerPrompt
              reason={panelDown}
              question={`سرویس بدون حذف از سرور، فقط در فروشگاه حذف شود؟ کلاینتش روی «${subscription.server.name}» می‌ماند و اگر سرور دوباره در دسترس باشد، تا ${agentShop ? 'پشتیبانی حذفش نکند' : 'خودتان حذفش نکنید'} کار می‌کند.`}
              skipLabel="حذف فقط از فروشگاه"
              cancelLabel="لغو حذف"
              destructive
              busy={ops.running !== null}
              onSkip={() => ops.run({ action: 'delete', note: ops.note, force: true })}
              onCancel={() => {
                setPanelDown(null)
                ops.close()
              }}
            />
          )
        }
      />
      <DetailFooter user={subscription.user} busy={ops.running !== null} onClose={onClose} />
    </div>
  )
}

/**
 * The link the customer got — a click selects it, the button copies it. A link that no longer works (its client gone
 * from the panel, or the service from the shop) is kept for what it was — a customer may paste it to support —, said
 * dead, with nothing to copy.
 */
function LinkBox({ link, dead }: { link: string; dead: boolean }) {
  return (
    <div className="grid gap-1.5">
      <span className="text-footnote text-muted-foreground">لینک اشتراک</span>
      <div className="flex items-center gap-2 rounded-lg border border-border bg-fill p-2 ps-3">
        <span dir="ltr" className={cn('min-w-0 flex-1 text-footnote break-all select-all', dead && 'text-muted-foreground')}>
          {link}
        </span>
        {!dead && (
          <Button size="sm" variant="secondary" icon={Copy} onClick={() => void copyText(link, 'لینک اشتراک کپی شد')}>
            کپی
          </Button>
        )}
      </div>
      {dead && <span className="text-footnote text-muted-foreground">این لینک دیگر کار نمی‌کند.</span>}
    </div>
  )
}

/** Everything known about the service, as of the panel's last answer. */
function Facts({ subscription, serverPage }: { subscription: SubscriptionRow; serverPage?: (id: number) => string }) {
  return (
    <FactList>
      <Fact label="وضعیت">
        <StatusBadge status={SUBSCRIPTION_STATUS[subscription.status]} />
        {subscription.status === 'disabled' && subscription.disabled_at && <span className="ms-2 text-footnote text-muted-foreground">از {formatDate(subscription.disabled_at)}</span>}
      </Fact>
      <CustomerFacts user={subscription.user} />
      <Fact label="پلن">{subscription.plan?.name ?? <span className="text-muted-foreground">پلن حذف شده</span>}</Fact>
      <Fact label="سرور">{serverPage ? <TextLink to={serverPage(subscription.server.id)}>{subscription.server.name}</TextLink> : subscription.server.name}</Fact>
      <Fact label="مصرف">
        <UsageMeter traffic={subscription.traffic} className="max-w-64" />
      </Fact>
      <Fact label="پایان">
        <ServiceExpiry subscription={subscription} />
      </Fact>
      <Fact label="مدت">{formatDays(subscription.duration_days)}</Fact>
      {subscription.duration_days > 0 && <Fact label="تمدید خودکار">{subscription.auto_renew ? 'روشن' : <span className="text-muted-foreground">خاموش</span>}</Fact>}
      {subscription.next_period && (
        <Fact label="دوره بعدی">
          از {formatDate(subscription.next_period.starts_at, { dateStyle: 'medium' })}، حداکثر {formatBytes(subscription.next_period.traffic)}
          <span className="block text-footnote text-muted-foreground">تمدید شده؛ حجم مصرف‌نشده دوره فعلی تا آن روز قابل استفاده است.</span>
        </Fact>
      )}
      <Fact label="دستگاه هم‌زمان">{formatDevices(subscription.ip_limit)}</Fact>
      {subscription.starts_at && (
        <Fact label="اولین اتصال">
          <span className="text-muted-foreground">{formatDate(subscription.starts_at)}</span>
        </Fact>
      )}
      <Fact label="خرید">
        <span className="text-muted-foreground">{formatDate(subscription.created_at)}</span>
      </Fact>
      <Fact label="آخرین پرسش از پنل">
        <span className="text-muted-foreground">{subscription.last_synced_at ? timeAgo(subscription.last_synced_at) : 'هنوز نه'}</span>
      </Fact>
    </FactList>
  )
}
