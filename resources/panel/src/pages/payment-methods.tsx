import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { ErrorState } from '@/components/error-state'
import { Dash, ListView, RowTitleButton } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { AddMethodModal } from '@/components/payments/add-method-modal'
import { gatewayIcon, MethodNote } from '@/components/payments/gateways'
import { MethodForm } from '@/components/payments/gateways/method-form'
import { kindLabel } from '@/components/payments/kinds'
import { MethodSummary } from '@/components/payments/method-summary'
import { EditorModal, useEditor } from '@/components/row-editor'
import { ActionsHead, EditMenu, OrderCell, OrderHead, RemoveConfirm } from '@/components/sortable-list'
import { Switch } from '@/components/switch'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { PaymentMethodRow, PaymentMethodSwitchRequest } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { paymentDriversQuery, paymentMethodsQuery } from '@/lib/queries'
import { useRows } from '@/lib/use-rows'
import { cn } from '@/lib/utils'

/**
 * The ways customers can pay, in checkout order: the wallet the shop starts with plus whatever the
 * admin adds from the driver picker — one row per card, later one per online-gateway account.
 */
export function PaymentMethodsPage() {
  const [adding, setAdding] = useState(false)
  // A method is added through the driver picker; the editor only ever opens on one that exists.
  const editor = useEditor<PaymentMethodRow>()
  const [removing, setRemoving] = useState<PaymentMethodRow | null>(null)
  const list = useRows({
    query: paymentMethodsQuery,
    list: 'methods',
    reorder: (ids) => api.post('/payment-methods/reorder', { ids }),
    remove: (method) => api.delete(`/payment-methods/${method.id}`),
    patch: (method, changes: PaymentMethodSwitchRequest) => api.patch(`/payment-methods/${method.id}`, changes).then((answer) => answer.method),
  })
  const methods = list.rows
  const drivers = useQuery({ ...paymentDriversQuery, enabled: editor.open })

  return (
    <Page>
      <PageHeader
        title="روش‌های پرداخت"
        // Counted once the list was read — never a zero it does not know.
        count={list.isPending || list.error ? undefined : methods.length}
        description="راه‌هایی که مشتری با آن‌ها پرداخت می‌کند؛ هر کارت یک روش است و ترتیب همین لیست، ترتیب دکمه‌ها هنگام پرداخت."
        actions={
          <Button icon={Plus} onClick={() => setAdding(true)}>
            افزودن روش پرداخت
          </Button>
        }
      />

      {/* The wallet is always there, so the list is never empty. */}
      <ListView list={list} noun="روش‌های پرداخت" skeletonRows={2}>
        <Table>
          <TableHeader>
            <TableRow>
              <OrderHead />
              <TableHead>روش</TableHead>
              <TableHead className="hidden md:table-cell">جزئیات</TableHead>
              <TableHead className="hidden lg:table-cell">نوع</TableHead>
              <TableHead className="hidden lg:table-cell">پرداخت‌ها</TableHead>
              <TableHead>فعال</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {methods.map((method, index) => {
              const Icon = gatewayIcon(method.driver)
              // Two short lines rather than one long one, so the table never has to scroll sideways for a note. A method
              // whose driver is gone has nothing to pay with: no checkout offers it, switched on or not — said in its place.
              const summary =
                method.kind === null ? (
                  <span className="text-warning">به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.</span>
                ) : (
                  method.summary && (
                    <span className="grid gap-0.5">
                      <MethodSummary summary={method.summary} />
                      <MethodNote method={method} />
                    </span>
                  )
                )
              // A method of a driver the API describes has a form; the wallet has nothing to set, a driver gone nothing to read.
              const editable = !method.builtin && method.kind !== null

              return (
                <TableRow key={method.id} className={cn(!method.enabled && 'text-muted-foreground')}>
                  <OrderCell list={list} index={index} label={method.label} />
                  <TableCell>
                    <div className="flex items-center gap-3">
                      <span aria-hidden className={cn('flex size-8 shrink-0 items-center justify-center rounded-lg bg-fill-hover text-foreground', !method.enabled && 'text-faint')}>
                        {Icon ? <Icon className="size-4" /> : <span className="text-footnote font-semibold">{method.driver_label.slice(0, 2)}</span>}
                      </span>
                      <div className="grid">
                        {editable ? <RowTitleButton onClick={() => editor.edit(method)}>{method.label}</RowTitleButton> : <span className="font-medium text-foreground">{method.label}</span>}
                        <span className="text-footnote text-muted-foreground">{method.driver_label}</span>
                        {summary && <span className="text-footnote md:hidden">{summary}</span>}
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="hidden text-footnote md:table-cell">{summary ?? <Dash />}</TableCell>
                  <TableCell className="hidden lg:table-cell">
                    <Badge variant="outline">{kindLabel(method.kind)}</Badge>
                  </TableCell>
                  <TableCell className="hidden tabular lg:table-cell">{formatNumber(method.counts.payments)}</TableCell>
                  <TableCell>
                    <Switch checked={method.enabled} onCheckedChange={(enabled) => list.patch.mutate({ row: method, changes: { enabled } })} aria-label={`فعال بودن ${method.label}`} />
                  </TableCell>
                  <TableCell>
                    {/* The wallet is the shop's own: never edited, never deleted. A row whose driver is gone can still go. */}
                    {!method.builtin && <EditMenu label={method.label} onEdit={editable ? () => editor.edit(method) : undefined} onRemove={() => setRemoving(method)} />}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </ListView>

      <p className="text-footnote text-muted-foreground">کیف پول همیشه هست و فقط خاموش و روشن می‌شود؛ درگاه‌های آنلاین و پرداخت رمزارزی در نسخه‌های بعدی اضافه می‌شوند.</p>

      <AddMethodModal
        open={adding}
        onClose={() => setAdding(false)}
        onCreated={(method) => {
          list.upsert(method)
          setAdding(false)
        }}
      />

      <EditorModal
        editor={editor}
        size="md"
        title={(method) => (method ? `ویرایش «${method.label}»` : '')}
        // A payment reads its card through its method: an edit shows on the ones made before it too.
        description={() => 'تغییرات روی پرداخت‌های قبلی این روش هم دیده می‌شود. اگر کارت عوض شده، به‌جای ویرایش روش تازه‌ای بسازید و این یکی را خاموش کنید.'}
      >
        {(method) => {
          const driver = method && drivers.data?.find((d) => d.key === method.driver)
          if (!method) return null
          if (driver) {
            return (
              <MethodForm
                driver={driver}
                method={method}
                onSaved={(saved) => {
                  list.upsert(saved)
                  editor.close()
                }}
                onCancel={editor.close}
              />
            )
          }
          return drivers.error ? (
            <ErrorState what="درایورهای پرداخت" error={drivers.error} onRetry={() => void drivers.refetch()} retrying={drivers.isFetching} />
          ) : (
            <Skeleton className="h-64 w-full" />
          )
        }}
      </EditorModal>

      <RemoveConfirm
        row={removing}
        remove={list.remove}
        title="حذف روش پرداخت"
        description={(method) => `«${method.label}» حذف می‌شود و دیگر به مشتری نشان داده نمی‌شود.`}
        details={() => 'هنوز پرداختی با این روش ثبت نشده است. اگر فقط می‌خواهید موقتا کنار برود، به جای حذف خاموشش کنید.'}
        kept={(method) =>
          method.counts.payments > 0
            ? `با «${method.label}» ${formatNumber(method.counts.payments)} پرداخت ثبت شده و برای حفظ تاریخچه حذف نمی‌شود؛ اگر نمی‌خواهید مشتری آن را ببیند، خاموشش کنید.`
            : null
        }
        removed="روش پرداخت حذف شد"
        onClose={() => setRemoving(null)}
      />
    </Page>
  )
}
