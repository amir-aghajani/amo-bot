import { useState } from 'react'
import { queryOptions } from '@tanstack/react-query'
import { Flame, Plus } from 'lucide-react'
import { SaleForm } from '@/components/discounts/sale-form'
import { kindsWords, namedWords } from '@/components/discounts/targets'
import { windowWords } from '@/components/discounts/window'
import { EmptyState } from '@/components/empty-state'
import { ListView, PhoneFacts, RowTitleButton } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { EditorModal, useEditor } from '@/components/row-editor'
import { ActionsHead, EditMenu, RemoveConfirm } from '@/components/sortable-list'
import { StatusBadge } from '@/components/status-badge'
import { Switch } from '@/components/switch'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { DiscountSwitchRequest, SaleRow, SalesResponse } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { DISCOUNT_STATE } from '@/lib/statuses'
import { useRows } from '@/lib/use-rows'

const salesQuery = queryOptions({ queryKey: queryKeys.sales, queryFn: () => api.get<SalesResponse>('/sales') })

/** How sales stack, behind the header's ⓘ. */
const PROGRAM =
  'فروش ویژه درصدی از قیمت پلن‌هایی که انتخاب می‌کنید کم می‌کند، از زمان شروع تا زمان پایانش. اگر مشتری تخفیف اختصاصی یا تخفیف سطح وفاداری هم داشته باشد، فقط بزرگ‌ترین آن‌ها اعمال می‌شود و کد تخفیف روی مبلغ باقی‌مانده می‌آید. قیمت تخفیف‌خورده همه‌جا دیده می‌شود: دکمه پلن‌ها و جزئیات پلن در ربات، صفحه پرداخت و قیمت پلن در وب‌سایت.'

/** What a sale is for, in a short line: «خرید · ۲ پلن». */
function saleTargets(sale: SaleRow): string {
  return `${kindsWords(sale.order_types)} · ${sale.plans === null ? 'همه پلن‌ها' : namedWords('پلن', sale.plans)}`
}

/** «فروش ویژه» (both panels): the shop's timed percent-off sales, newest first, edited in a dialog. */
export function SalesPage() {
  const editor = useEditor<SaleRow>()
  const [removing, setRemoving] = useState<SaleRow | null>(null)
  const list = useRows({
    query: salesQuery,
    list: 'sales',
    remove: (sale) => api.delete(`/sales/${sale.id}`),
    patch: (sale, changes: DiscountSwitchRequest) => api.patch(`/sales/${sale.id}`, changes).then((answer) => answer.sale),
  })

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      ساخت فروش ویژه
    </Button>
  )

  return (
    <Page>
      <PageHeader title="فروش ویژه" description="تخفیف درصدی و زمان‌دار روی پلن‌ها، که خودکار روی قیمت می‌آید." info={PROGRAM} actions={add('default')} />

      <ListView
        list={list}
        noun="فروش‌های ویژه"
        empty={
          <EmptyState
            framed
            icon={Flame}
            title="هنوز فروش ویژه‌ای نساخته‌اید"
            description="برای مناسبت‌ها — نوروز، یلدا، جمعه سیاه — درصدی از قیمت پلن‌ها کم کنید، از یک روز و ساعت تا روز و ساعت دیگر."
            action={add('secondary')}
          />
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>نام</TableHead>
              <TableHead>تخفیف</TableHead>
              <TableHead className="hidden lg:table-cell">زمان</TableHead>
              <TableHead className="hidden md:table-cell">وضعیت</TableHead>
              <TableHead>فعال</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((sale) => (
              <TableRow key={sale.id}>
                <TableCell>
                  <RowTitleButton onClick={() => editor.edit(sale)}>{sale.name}</RowTitleButton>
                  <PhoneFacts>
                    <StatusBadge status={DISCOUNT_STATE[sale.state]} />
                  </PhoneFacts>
                </TableCell>
                <TableCell>
                  <div className="grid gap-0.5">
                    <span className="tabular">{formatNumber(sale.percent)}٪</span>
                    <span className="text-footnote text-muted-foreground">{saleTargets(sale)}</span>
                  </div>
                </TableCell>
                <TableCell className="hidden text-footnote text-muted-foreground lg:table-cell">{windowWords(sale.starts_at, sale.ends_at)}</TableCell>
                <TableCell className="hidden md:table-cell">
                  <StatusBadge status={DISCOUNT_STATE[sale.state]} />
                </TableCell>
                <TableCell>
                  <Switch checked={sale.enabled} onCheckedChange={(enabled) => list.patch.mutate({ row: sale, changes: { enabled } })} aria-label={`فعال بودن ${sale.name}`} />
                </TableCell>
                <TableCell>
                  <EditMenu label={sale.name} onEdit={() => editor.edit(sale)} onRemove={() => setRemoving(sale)} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <EditorModal editor={editor} size="md" title={(sale) => (sale ? `ویرایش «${sale.name}»` : 'ساخت فروش ویژه')}>
        {(sale) => (
          <SaleForm
            sale={sale}
            // Read the list again: it is newest first, so a new sale's place is the top.
            invalidates={[...list.invalidates, queryKeys.sales]}
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
        title="حذف فروش ویژه"
        description={(sale) => `«${sale.name}» حذف می‌شود.`}
        details={() => 'سفارش‌هایی که با این فروش ویژه ثبت شده‌اند تخفیفشان را همان‌طور نگه می‌دارند. برای کنار گذاشتن موقت، کافی است آن را غیرفعال کنید.'}
        removed="فروش ویژه حذف شد"
        onClose={() => setRemoving(null)}
      />
    </Page>
  )
}
