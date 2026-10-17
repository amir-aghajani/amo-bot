import { useState } from 'react'
import { queryOptions } from '@tanstack/react-query'
import { Crown, Plus } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { Dash, ListView, PhoneFacts, RowTitleButton } from '@/components/list-view'
import { TierForm } from '@/components/loyalty/tier-form'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { EditorModal, useEditor } from '@/components/row-editor'
import { ActionsHead, EditMenu, RemoveConfirm } from '@/components/sortable-list'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { LoyaltyTierRow, LoyaltyTiersResponse } from '@/lib/api-types'
import { formatMoney, formatNumber, formatPercent } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useRows } from '@/lib/use-rows'

const tiersQuery = queryOptions({ queryKey: queryKeys.loyaltyTiers, queryFn: () => api.get<LoyaltyTiersResponse>('/loyalty/tiers') })

/** A percent of a tier, «۵٪», or the lists' empty cell for none. */
const percent = (value: number) => (value > 0 ? formatPercent(value) : <Dash />)

/**
 * «سطوح وفاداری»: the tiers by what a customer has spent, lowest threshold first, each with its discount, its extra
 * cashback and how many customers are on it. A customer is on the highest tier reached; no tier means no program.
 */
export function LoyaltyPage() {
  const editor = useEditor<LoyaltyTierRow>()
  const [removing, setRemoving] = useState<LoyaltyTierRow | null>(null)
  // A threshold orders the list and moves customers between tiers, so every save reads the list (and customers) again.
  const list = useRows({
    query: tiersQuery,
    list: 'tiers',
    remove: (tier) => api.delete(`/loyalty/tiers/${tier.id}`),
    invalidates: [queryKeys.loyaltyTiers, queryKeys.everyUser],
  })
  const tiers = list.rows

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      افزودن سطح
    </Button>
  )

  return (
    <Page>
      <PageHeader
        title="سطوح وفاداری"
        count={list.isPending || list.error ? undefined : tiers.length}
        description="هر مشتری روی بالاترین سطحی است که جمع خریدهایش به حد آن رسیده؛ خرید، تمدید و حجم، زمان و کاربر اضافه حساب می‌شود، شارژ کیف پول و سفارش برگشت‌خورده نه. مشتری سطحش را در کیف پول ربات و در وب‌سایت می‌بیند و وقتی به سطح بالاتری برسد خبردار می‌شود."
        info="تخفیف سطح یکی از تخفیف‌های خودکار است: از میان فروش ویژه، تخفیف اختصاصی مشتری و تخفیف سطح، فقط بزرگ‌ترین روی قیمت اعمال می‌شود و کد تخفیف بعد از آن. کش‌بک سطح به درصد قانون‌های کش‌بک اضافه می‌شود، تا وقتی کش‌بک در صفحه «کش‌بک» روشن است."
        actions={add('default')}
      />

      <ListView
        list={list}
        noun="سطح‌ها"
        empty={
          <EmptyState
            framed
            icon={Crown}
            title="هنوز سطحی تعریف نشده است"
            description="بدون سطح، برنامه وفاداری خاموش است. مثلا برنزی از صفر، نقره‌ای از ۵۰۰ هزار و طلایی از ۲ میلیون تومان خرید؛ هر کدام با درصد تخفیف و کش‌بک خودش."
            action={add('secondary')}
          />
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>سطح</TableHead>
              <TableHead>حد خرید</TableHead>
              <TableHead className="hidden md:table-cell">تخفیف</TableHead>
              <TableHead className="hidden md:table-cell">کش‌بک اضافه</TableHead>
              <TableHead className="hidden md:table-cell">مشتری‌ها</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {tiers.map((tier) => (
              <TableRow key={tier.id}>
                <TableCell>
                  <RowTitleButton onClick={() => editor.edit(tier)}>{tier.name}</RowTitleButton>
                  <PhoneFacts>
                    <span>تخفیف {percent(tier.discount_percent)}</span>
                    <span>کش‌بک اضافه {percent(tier.cashback_percent)}</span>
                  </PhoneFacts>
                </TableCell>
                <TableCell className="tabular">{Number(tier.min_spent) > 0 ? `از ${formatMoney(tier.min_spent)}` : 'از ابتدا'}</TableCell>
                <TableCell className="hidden tabular md:table-cell">{percent(tier.discount_percent)}</TableCell>
                <TableCell className="hidden tabular md:table-cell">{percent(tier.cashback_percent)}</TableCell>
                <TableCell className="hidden tabular md:table-cell">{formatNumber(tier.customers)}</TableCell>
                <TableCell>
                  <EditMenu label={tier.name} onEdit={() => editor.edit(tier)} onRemove={() => setRemoving(tier)} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <EditorModal editor={editor} title={(tier) => (tier ? `ویرایش «${tier.name}»` : 'افزودن سطح')}>
        {(tier) => (
          <TierForm
            tier={tier}
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
        title="حذف سطح"
        description={(tier) => `«${tier.name}» حذف می‌شود.`}
        details={(tier) =>
          tier.customers === 0 ? 'هیچ مشتری‌ای روی این سطح نیست.' : `${formatNumber(tier.customers)} مشتری این سطح به سطح پایین‌تر می‌روند، یا بی‌سطح می‌مانند؛ خریدهایشان دست نمی‌خورد.`
        }
        removed="سطح حذف شد"
        onClose={() => setRemoving(null)}
      />
    </Page>
  )
}
