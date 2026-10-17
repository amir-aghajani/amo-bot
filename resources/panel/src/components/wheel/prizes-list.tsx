import { useState } from 'react'
import { queryOptions } from '@tanstack/react-query'
import { FerrisWheel, Plus } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { Dash, ListView, PhoneFacts, RowTitleButton } from '@/components/list-view'
import { EditorModal, useEditor } from '@/components/row-editor'
import { SectionActions } from '@/components/sectioned-page'
import { ActionsHead, EditMenu, OrderCell, OrderHead, RemoveConfirm } from '@/components/sortable-list'
import { Switch } from '@/components/switch'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PrizeForm } from '@/components/wheel/prize-form'
import { api } from '@/lib/api'
import type { WheelPrizeRow, WheelPrizesResponse, WheelPrizeSwitchRequest } from '@/lib/api-types'
import { formatGb, formatMoney, formatNumber, formatPercent } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { WHEEL_PRIZE_KIND } from '@/lib/statuses'
import { useRows } from '@/lib/use-rows'

const prizesQuery = queryOptions({ queryKey: queryKeys.wheelPrizes, queryFn: () => api.get<WheelPrizesResponse>('/wheel/prizes') })

/** What a slice gives, in a word under its label: «۱۰ گیگابایت حجم», «سرویس «یک‌ماهه»». */
function prizeWorth(prize: Pick<WheelPrizeRow, 'kind' | 'amount' | 'plan'>): string {
  const amount = Number(prize.amount ?? 0)
  switch (prize.kind) {
    case 'wallet':
      return `${formatMoney(amount)} شارژ کیف پول`
    case 'traffic':
      return `${formatGb(amount)} حجم`
    case 'days':
      return `${formatNumber(amount)} روز زمان`
    case 'plan':
      return prize.plan ? `سرویس «${prize.plan.name}»` : 'سرویس رایگان — پلنش حذف شده'
    case 'nothing':
      return WHEEL_PRIZE_KIND.nothing
  }
}

/** The wheel's slices in the admin's order (the order the wheel shows), each with its weight and resulting chance. */
export function PrizesList() {
  const editor = useEditor<WheelPrizeRow>()
  const [removing, setRemoving] = useState<WheelPrizeRow | null>(null)
  const list = useRows({
    query: prizesQuery,
    list: 'prizes',
    reorder: (ids) => api.post('/wheel/prizes/reorder', { ids }),
    remove: (prize) => api.delete(`/wheel/prizes/${prize.id}`),
    patch: (prize, changes: WheelPrizeSwitchRequest) => api.patch(`/wheel/prizes/${prize.id}`, changes).then((answer) => answer.prize),
  })
  const total = list.rows.reduce((sum, prize) => sum + (prize.enabled ? prize.weight : 0), 0)

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      افزودن جایزه
    </Button>
  )

  return (
    <>
      <SectionActions>{add('default')}</SectionActions>

      <ListView
        list={list}
        noun="جایزه‌ها"
        empty={
          <EmptyState
            framed
            icon={FerrisWheel}
            title="گردونه هنوز جایزه‌ای ندارد"
            description="جایزه‌ها را اضافه کنید — شارژ کیف پول، حجم، زمان، یک سرویس رایگان، یا «پوچ» — و به هر کدام وزنی بدهید؛ جایزه پروزن‌تر بیشتر می‌آید."
            action={add('secondary')}
          />
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <OrderHead />
              <TableHead>جایزه</TableHead>
              <TableHead className="hidden md:table-cell">وزن</TableHead>
              <TableHead>شانس</TableHead>
              <TableHead>فعال</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((prize, index) => (
              <TableRow key={prize.id}>
                <OrderCell list={list} index={index} label={prize.label} />
                <TableCell>
                  <div className="grid gap-0.5">
                    <RowTitleButton onClick={() => editor.edit(prize)}>{prize.label}</RowTitleButton>
                    <span className="text-footnote text-muted-foreground">{prizeWorth(prize)}</span>
                  </div>
                  <PhoneFacts>وزن {formatNumber(prize.weight)}</PhoneFacts>
                </TableCell>
                <TableCell className="hidden tabular md:table-cell">{formatNumber(prize.weight)}</TableCell>
                <TableCell className="tabular">{prize.enabled && total > 0 ? formatPercent((prize.weight / total) * 100) : <Dash />}</TableCell>
                <TableCell>
                  <Switch checked={prize.enabled} onCheckedChange={(enabled) => list.patch.mutate({ row: prize, changes: { enabled } })} aria-label={`فعال بودن ${prize.label}`} />
                </TableCell>
                <TableCell>
                  <EditMenu label={prize.label} onEdit={() => editor.edit(prize)} onRemove={() => setRemoving(prize)} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      {list.rows.length > 0 && (
        <p className="text-footnote text-muted-foreground">
          شانس هر جایزه سهم وزن آن از جمع وزن جایزه‌های فعال است. جایزه‌ای که همان لحظه به مشتری نمی‌رسد — حجم یا زمان برای کسی که سرویس فعالی ندارد، سرویسی که سروری تحویلش نمی‌دهد — برای او کنار
          گذاشته می‌شود و شانسش بین بقیه پخش می‌شود.
        </p>
      )}

      <EditorModal editor={editor} title={(prize) => (prize ? `ویرایش «${prize.label}»` : 'افزودن جایزه')}>
        {(prize) => (
          <PrizeForm
            prize={prize}
            invalidates={list.invalidates}
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
        title="حذف جایزه"
        description={(prize) => `«${prize.label}» از گردونه حذف می‌شود.`}
        details={() => 'چرخش‌هایی که روی این جایزه ایستاده‌اند همان‌طور در لیست چرخش‌ها می‌مانند. برای کنار گذاشتن موقت، کافی است آن را غیرفعال کنید.'}
        removed="جایزه حذف شد"
        onClose={() => setRemoving(null)}
      />
    </>
  )
}
