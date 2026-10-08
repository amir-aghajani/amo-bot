import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Copy, Package, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/empty-state'
import { InfoBadge } from '@/components/info-tip'
import { Dash, ListView, RowTitleButton } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PlanForm } from '@/components/plans/plan-form'
import { EditorModal, useEditor } from '@/components/row-editor'
import { ActionsHead, EditMenu, OrderCell, OrderHead, RemoveConfirm } from '@/components/sortable-list'
import { Switch } from '@/components/switch'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DropdownMenuItem } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { ActivationRequest, PlanRow } from '@/lib/api-types'
import { formatDays, formatDevices, formatGb, formatMoney, formatNumber } from '@/lib/format'
import { plansQuery } from '@/lib/queries'
import { useRows } from '@/lib/use-rows'
import { cn } from '@/lib/utils'

/**
 * The catalogue: what customers can buy, in the order they see it — a plan the bot does not show flagged with why.
 * `addServers`: where servers are added (the owner's servers page), for a plan form in a shop without any; an agent's
 * panel adds none.
 */
export function PlansPage({ addServers }: { addServers?: string }) {
  const editor = useEditor<PlanRow>()
  const [removing, setRemoving] = useState<PlanRow | null>(null)
  const list = useRows({
    query: plansQuery,
    list: 'plans',
    reorder: (ids) => api.post('/plans/reorder', { ids }),
    remove: (plan) => api.delete(`/plans/${plan.id}`),
    patch: (plan, changes: ActivationRequest) => api.patch(`/plans/${plan.id}`, changes).then((answer) => answer.plan),
  })
  const plans = list.rows

  // The copy is made switched off, and opens in its form at once.
  const duplicate = useMutation({
    mutationFn: (plan: PlanRow) => api.post(`/plans/${plan.id}/duplicate`),
    onSuccess: ({ plan }) => {
      toast.success('کپی پلن ساخته شد (غیرفعال)')
      list.upsert(plan)
      editor.edit(plan)
    },
  })

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      افزودن پلن
    </Button>
  )

  return (
    <Page>
      {/* A count only of a list read: a failed read knows no number. */}
      <PageHeader
        title="پلن‌ها"
        count={list.isPending || list.error ? undefined : plans.length}
        description="بسته‌هایی که مشتری در ربات می‌خرد؛ ترتیب همین لیست، ترتیب نمایش است."
        actions={add('default')}
      />

      <ListView
        list={list}
        noun="پلن‌ها"
        empty={<EmptyState framed icon={Package} title="هنوز پلنی تعریف نشده است" description="اولین بسته را بسازید: حجم، مدت، تعداد دستگاه و قیمت." action={add('secondary')} />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <OrderHead />
              <TableHead>پلن</TableHead>
              <TableHead className="hidden xl:table-cell">دسته</TableHead>
              <TableHead className="hidden md:table-cell">حجم</TableHead>
              <TableHead className="hidden md:table-cell">مدت</TableHead>
              <TableHead className="hidden xl:table-cell">دستگاه</TableHead>
              <TableHead>قیمت</TableHead>
              <TableHead className="hidden lg:table-cell">سرورها</TableHead>
              <TableHead className="hidden lg:table-cell">اشتراک</TableHead>
              <TableHead>قابل خرید</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {plans.map((plan, index) => (
              <TableRow key={plan.id} className={cn(!plan.is_active && 'text-muted-foreground')}>
                <OrderCell list={list} index={index} label={plan.name} />
                <TableCell>
                  <div className="grid">
                    <RowTitleButton onClick={() => editor.edit(plan)}>{plan.name}</RowTitleButton>
                    {plan.description && <span className="line-clamp-1 max-w-64 text-footnote text-muted-foreground">{plan.description}</span>}
                    <span className="text-footnote text-muted-foreground md:hidden">
                      {formatGb(plan.traffic_gb)} · {formatDays(plan.duration_days)}
                    </span>
                    {/* Where their columns are not drawn, the category and what keeps the plan from a customer come here. */}
                    {plan.category && (
                      <span className="text-footnote text-muted-foreground xl:hidden">{plan.category.is_active ? plan.category.name : <InactiveCategory name={plan.category.name} />}</span>
                    )}
                    {plan.unsellable_reason && (
                      <span className="mt-1 flex">
                        <InfoBadge variant="warning" info={plan.unsellable_reason}>
                          در ربات دیده نمی‌شود
                        </InfoBadge>
                      </span>
                    )}
                    <Placement plan={plan} problems className="mt-1 lg:hidden" />
                  </div>
                </TableCell>
                <TableCell className="hidden xl:table-cell">
                  {plan.category ? plan.category.is_active ? <Badge variant="outline">{plan.category.name}</Badge> : <InactiveCategory name={plan.category.name} /> : <Dash />}
                </TableCell>
                <TableCell className="hidden tabular md:table-cell">{formatGb(plan.traffic_gb)}</TableCell>
                <TableCell className="hidden tabular md:table-cell">{formatDays(plan.duration_days)}</TableCell>
                <TableCell className="hidden tabular xl:table-cell">{formatDevices(plan.ip_limit)}</TableCell>
                <TableCell className="font-medium tabular">{formatMoney(plan.price)}</TableCell>
                <TableCell className="hidden lg:table-cell">
                  <Placement plan={plan} />
                </TableCell>
                <TableCell className="hidden tabular lg:table-cell">
                  <div className="grid">
                    <span className="text-foreground">{formatNumber(plan.counts.active_subscriptions)} فعال</span>
                    <span className="text-footnote text-muted-foreground">{formatNumber(plan.counts.sales)} فروش</span>
                  </div>
                </TableCell>
                <TableCell>
                  <Switch checked={plan.is_active} onCheckedChange={(is_active) => list.patch.mutate({ row: plan, changes: { is_active } })} aria-label={`قابل خرید بودن ${plan.name}`} />
                </TableCell>
                <TableCell>
                  <EditMenu label={plan.name} onEdit={() => editor.edit(plan)} onRemove={() => setRemoving(plan)}>
                    <DropdownMenuItem onSelect={() => duplicate.mutate(plan)}>
                      <Copy aria-hidden />
                      ساخت کپی
                    </DropdownMenuItem>
                  </EditMenu>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <EditorModal
        editor={editor}
        size="lg"
        title={(plan) => (plan ? `ویرایش «${plan.name}»` : 'افزودن پلن')}
        // A renewal is the plan as it is then: its price, term and traffic.
        description={(plan) =>
          plan ? 'تغییرات روی خریدهای بعدی و تمدیدها اثر می‌گذارد؛ اشتراک‌های فعلی تا تمدید بعدی با شرایط قبلی ادامه می‌دهند.' : 'بسته‌ای که مشتری می‌خرد: حجم، مدت، تعداد دستگاه و قیمت.'
        }
      >
        {(plan) => (
          <PlanForm
            plan={plan}
            addServers={addServers}
            onCancel={editor.close}
            onSaved={(saved) => {
              list.upsert(saved)
              editor.close()
            }}
          />
        )}
      </EditorModal>

      <RemoveConfirm
        row={removing}
        remove={list.remove}
        title="حذف پلن"
        description={(plan) => `«${plan.name}» حذف می‌شود. این کار برگشت‌پذیر نیست.`}
        details={() => 'هنوز سفارش یا اشتراکی با این پلن ثبت نشده است؛ سرورهایش فقط از پلن جدا می‌شوند.'}
        kept={inUse}
        removed="پلن حذف شد"
        onClose={() => setRemoving(null)}
      />
    </Page>
  )
}

/** Why a plan is not deleted — the server's rule: an order or a service points at it, and the history stays. Null when it may go. */
function inUse(plan: PlanRow): string | null {
  const { orders, subscriptions } = plan.counts
  if (orders === 0 && subscriptions === 0) return null

  const history = [orders > 0 && `${formatNumber(orders)} سفارش`, subscriptions > 0 && `${formatNumber(subscriptions)} اشتراک`].filter((part) => typeof part === 'string').join(' و ')
  return `با «${plan.name}» ${history} ثبت شده و برای حفظ تاریخچه حذف نمی‌شود؛ اگر نمی‌خواهید مشتری آن را بخرد، «قابل خرید» را خاموش کنید.`
}

/** A category the plan is in but the bot does not show: the plan is sold under «سایر پلن‌ها» instead. */
function InactiveCategory({ name }: { name: string }) {
  return (
    <InfoBadge variant="warning" info="این دسته غیرفعال است؛ پلن در ربات زیر «سایر پلن‌ها» فروخته می‌شود.">
      {name}
    </InfoBadge>
  )
}

/**
 * The servers a plan is sold on, each saying what of it is sold when pressed; amber when an entry cannot sell now — the
 * server's own reason behind it —, and the customer does not see the plan there. `problems`: only those, for a narrow
 * screen without the column (nothing when all is well). A plan on no server has a dash: its own flag says why.
 */
function Placement({ plan, problems = false, className }: { plan: PlanRow; problems?: boolean; className?: string }) {
  const entries = problems ? plan.servers.filter((entry) => entry.unsellable_reason !== null) : plan.servers

  if (entries.length === 0) return problems ? null : <Dash />

  return (
    <span className={cn('flex max-w-56 flex-wrap items-center gap-1', className)}>
      {entries.map((entry) =>
        entry.unsellable_reason ? (
          <InfoBadge key={entry.server.id} variant="warning" info={`${entry.unsellable_reason} تا درست نشود، مشتری این پلن را روی «${entry.server.name}» نمی‌بیند.`}>
            {entry.server.name}
          </InfoBadge>
        ) : (
          <InfoBadge
            key={entry.server.id}
            variant="outline"
            info={entry.all_inbounds ? 'کل سرور: هر اینباندی که روی این سرور قابل فروش باشد.' : `${formatNumber(entry.inbounds.length)} اینباند انتخاب‌شده از این سرور.`}
          >
            {entry.server.name}
          </InfoBadge>
        ),
      )}
    </span>
  )
}
