import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Copy, Plus, Search, TicketPercent } from 'lucide-react'
import { CodeForm } from '@/components/discounts/code-form'
import { kindsWords, namedWords } from '@/components/discounts/targets'
import { windowWords } from '@/components/discounts/window'
import { EmptyState } from '@/components/empty-state'
import { ListView, PhoneFacts, RowTitleButton } from '@/components/list-view'
import { EditorModal, useEditor } from '@/components/row-editor'
import { SearchBox } from '@/components/search-box'
import { SectionActions } from '@/components/sectioned-page'
import { ActionsHead, EditMenu, RemoveConfirm } from '@/components/sortable-list'
import { StatusBadge } from '@/components/status-badge'
import { Switch } from '@/components/switch'
import { Button } from '@/components/ui/button'
import { DropdownMenuItem } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { DiscountCodeRow, DiscountCodesResponse } from '@/lib/api-types'
import { copyText } from '@/lib/clipboard'
import { formatMoney, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { DISCOUNT_STATE } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'

/** What a code takes off, in a few words: «۲۰٪ تا ۵۰٬۰۰۰ تومان», «۱۵٬۰۰۰ تومان», «رایگان». */
function codeWorth(code: Pick<DiscountCodeRow, 'type' | 'value' | 'max_discount'>): string {
  switch (code.type) {
    case 'percent':
      return `${formatNumber(Number(code.value ?? 0))}٪${code.max_discount ? ` تا ${formatMoney(code.max_discount)}` : ''}`
    case 'fixed':
      return formatMoney(code.value ?? 0)
    case 'free':
      return 'رایگان'
  }
}

/** What a code holds for, in a short line: «خرید، تمدید · ۲ پلن · ۱ سرور · خرید اول · دست‌کم ۱۰۰٬۰۰۰ تومان». */
function codeTargets(code: Pick<DiscountCodeRow, 'order_types' | 'plans' | 'servers' | 'first_purchase_only' | 'min_amount'>): string {
  const parts = [kindsWords(code.order_types)]
  if (code.plans !== null) parts.push(namedWords('پلن', code.plans))
  if (code.servers !== null) parts.push(namedWords('سرور', code.servers))
  if (code.first_purchase_only) parts.push('خرید اول')
  if (code.min_amount !== null) parts.push(`دست‌کم ${formatMoney(code.min_amount)}`)
  return parts.join(' · ')
}

/** How often a code was used: «۳», or «۳ از ۱۰» against its cap. */
function uses(code: Pick<DiscountCodeRow, 'uses' | 'max_uses'>): string {
  return code.max_uses === null ? formatNumber(code.uses) : `${formatNumber(code.uses)} از ${formatNumber(code.max_uses)}`
}

/**
 * Why a code cannot be deleted, as the row says (`deletable`, the server's own rule): its orders keep it for their
 * history — those that used it, or, when none counts, those refunded since. Null for a code that may go.
 */
function keptFor(code: Pick<DiscountCodeRow, 'deletable' | 'uses'>): string | null {
  if (code.deletable) return null
  return code.uses > 0
    ? `این کد ${formatNumber(code.uses)} بار در سفارش‌ها استفاده شده و برای سابقه می‌ماند؛ به جای حذف، آن را غیرفعال کنید.`
    : 'این کد فقط در سفارش‌هایی آمده که بازپرداخت شده‌اند؛ استفاده‌ای از آن حساب نمی‌شود، ولی برای سابقه آن سفارش‌ها می‌ماند. به جای حذف، آن را غیرفعال کنید.'
}

/**
 * The discount codes, newest first, searched by code or note, edited in a dialog. A code an order names is kept for the
 * orders' history: it can only be switched off, not deleted — and its delete says so before anything is asked.
 */
export function CodesList() {
  const editor = useEditor<DiscountCodeRow>()
  const [removing, setRemoving] = useState<DiscountCodeRow | null>(null)
  const list = usePagedList({
    queryKey: queryKeys.discountCodes,
    read: (query) => api.get<DiscountCodesResponse>('/discount-codes', query),
    list: 'codes',
    address: '/discounts',
  })
  const toggle = useMutation({
    mutationFn: ({ code, enabled }: { code: DiscountCodeRow; enabled: boolean }) => api.patch(`/discount-codes/${code.id}`, { enabled }),
    onSuccess: ({ code }) => list.replace(code),
  })
  // The dialog that asked says why it was refused.
  const remove = useMutation({
    mutationFn: (code: DiscountCodeRow) => api.delete(`/discount-codes/${code.id}`),
    meta: { quiet: true },
    onSuccess: (_, code) => list.remove(code.id),
  })

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      ساخت کد تخفیف
    </Button>
  )

  return (
    <>
      <SectionActions>{add('default')}</SectionActions>

      <SearchBox value={list.typed} onChange={list.setTyped} placeholder="کد یا یادداشت آن" aria-label="جستجوی کد تخفیف" />

      <ListView
        list={list}
        noun="کدهای تخفیف"
        unit="کد"
        empty={
          <EmptyState
            framed
            icon={TicketPercent}
            title="هنوز کد تخفیفی نساخته‌اید"
            description="کدی بسازید — درصدی، مبلغ ثابت یا رایگان — و آن را به مشتری‌ها بدهید؛ در ربات و وب‌سایت، هنگام پرداخت وارد می‌شود."
            action={add('secondary')}
          />
        }
        noMatch={<EmptyState framed icon={Search} title="کدی پیدا نشد" description="با این جستجو کدی نیست." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>کد</TableHead>
              <TableHead>تخفیف</TableHead>
              <TableHead className="hidden lg:table-cell">زمان</TableHead>
              <TableHead className="hidden md:table-cell">استفاده</TableHead>
              <TableHead className="hidden md:table-cell">وضعیت</TableHead>
              <TableHead>فعال</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((code) => (
              <TableRow key={code.id}>
                <TableCell>
                  <div className="grid justify-items-start gap-0.5">
                    <RowTitleButton onClick={() => editor.edit(code)}>
                      <bdi dir="ltr" className="font-medium tracking-wide">
                        {code.code}
                      </bdi>
                    </RowTitleButton>
                    {code.note && <span className="text-footnote text-muted-foreground">{code.note}</span>}
                  </div>
                  <PhoneFacts>
                    <StatusBadge status={DISCOUNT_STATE[code.state]} />
                    <span className="tabular">{uses(code)} استفاده</span>
                  </PhoneFacts>
                </TableCell>
                <TableCell>
                  <div className="grid gap-0.5">
                    <span>{codeWorth(code)}</span>
                    <span className="max-w-72 text-footnote whitespace-normal text-muted-foreground">{codeTargets(code)}</span>
                  </div>
                </TableCell>
                <TableCell className="hidden text-footnote text-muted-foreground lg:table-cell">{windowWords(code.starts_at, code.ends_at)}</TableCell>
                <TableCell className="hidden tabular md:table-cell">{uses(code)}</TableCell>
                <TableCell className="hidden md:table-cell">
                  <StatusBadge status={DISCOUNT_STATE[code.state]} />
                </TableCell>
                <TableCell>
                  <Switch checked={code.enabled} held={toggle.isPending} onCheckedChange={(enabled) => toggle.mutate({ code, enabled })} aria-label={`فعال بودن ${code.code}`} />
                </TableCell>
                <TableCell>
                  <EditMenu label={`کد ${code.code}`} onEdit={() => editor.edit(code)} onRemove={() => setRemoving(code)}>
                    <DropdownMenuItem onSelect={() => void copyText(code.code, 'کد کپی شد')}>
                      <Copy aria-hidden />
                      کپی کد
                    </DropdownMenuItem>
                  </EditMenu>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <EditorModal editor={editor} size="md" title={(code) => (code ? `ویرایش کد ${code.code}` : 'ساخت کد تخفیف')}>
        {(code) => <CodeForm code={code} invalidates={[queryKeys.discountCodes]} onCancel={editor.close} onSaved={editor.close} />}
      </EditorModal>

      <RemoveConfirm
        row={removing}
        remove={remove}
        title="حذف کد تخفیف"
        description={(code) => `کد ${code.code} حذف می‌شود و دیگر پذیرفته نمی‌شود.`}
        details={() => 'برای کنار گذاشتن موقت، کافی است آن را غیرفعال کنید.'}
        kept={keptFor}
        removed="کد تخفیف حذف شد"
        onClose={() => setRemoving(null)}
      />
    </>
  )
}
