import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { ErrorState } from '@/components/error-state'
import { Dash, ListView, RowTitleButton } from '@/components/list-view'
import { Page, useReadFailure } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { AddMethodModal } from '@/components/payments/add-method-modal'
import { gatewayIcon, hasNote, MethodNote } from '@/components/payments/gateways'
import { MethodForm } from '@/components/payments/gateways/method-form'
import { kindLabel } from '@/components/payments/kinds'
import { boundsLine, CardLines, MethodSummary } from '@/components/payments/method-summary'
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

/** «روش‌های پرداخت»: the ways to pay in checkout order — the built-in wallet plus the methods the admin adds. */
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
  const driversFailure = useReadFailure(drivers)

  return (
    <Page>
      <PageHeader
        title="روش‌های پرداخت"
        // Counted once the list was read — never a zero it does not know.
        count={list.isPending || list.error ? undefined : methods.length}
        description="راه‌هایی که مشتری با آن‌ها پرداخت می‌کند؛ هر روش برای مبلغ‌های میان کمترین و بیشترین مبلغش پیشنهاد می‌شود، یک روش کارت به کارت چند کارت دارد، و ترتیب همین لیست ترتیب دکمه‌ها هنگام پرداخت است."
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
              // Two short lines rather than one long one, so the table never scrolls sideways. A method whose driver is no
              // longer installed cannot take payments, switched on or not, and says so here.
              const bounds = boundsLine(method)
              const manual = method.driver === 'manual' && 'cards' in method ? method : null
              const summary =
                method.kind === null ? (
                  <span className="text-warning">به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.</span>
                ) : (
                  (method.summary || manual || bounds || hasNote(method)) && (
                    <span className="grid gap-0.5">
                      {method.summary && <MethodSummary summary={method.summary} />}
                      {manual && <CardLines cards={manual.cards} toSupport={manual.to_support} />}
                      <MethodNote method={method} />
                      {bounds && <span className="text-muted-foreground">{bounds}</span>}
                    </span>
                  )
                )
              // A method of a driver the API describes has a form — the wallet its label, bounds and switch —; a driver
              // gone nothing to read.
              const editable = method.kind !== null

              return (
                <TableRow key={method.id} className={cn(!method.enabled && 'text-muted-foreground')}>
                  <OrderCell list={list} index={index} label={method.label} />
                  {/* On a phone the details under the name wrap: on one line they would push the switch and the menu out of sight. */}
                  <TableCell className="max-md:whitespace-normal">
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
                    {/* The wallet is built in: never deleted — its name opens its form. A row whose driver is gone can still go. */}
                    {!method.builtin && <EditMenu label={method.label} onEdit={editable ? () => editor.edit(method) : undefined} onRemove={() => setRemoving(method)} />}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </ListView>

      <p className="text-footnote text-muted-foreground">کیف پول همیشه هست و حذف نمی‌شود؛ فقط نامش، کمترین و بیشترین مبلغش و روشن و خاموش بودنش عوض می‌شود.</p>

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
        // A payment keeps the card it was shown: a card paid to keeps its number, and is switched off rather than removed.
        description={(method) =>
          method?.driver === 'manual'
            ? 'هر پرداخت کارتی را که به آن نشان داده شده نگه می‌دارد. شماره کارتی که با آن پرداخت شده عوض نمی‌شود؛ اگر کارت عوض شده، کارت تازه‌ای اضافه و قبلی را خاموش کنید.'
            : 'کمترین و بیشترین مبلغ، مبلغ‌هایی است که این روش برای آن‌ها به مشتری پیشنهاد می‌شود.'
        }
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
          return driversFailure.error ? (
            <ErrorState what="درایورهای پرداخت" error={driversFailure.error} onRetry={() => void drivers.refetch()} retrying={driversFailure.retrying} />
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
