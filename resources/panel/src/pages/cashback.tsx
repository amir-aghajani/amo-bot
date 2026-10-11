import { useState } from 'react'
import { queryOptions } from '@tanstack/react-query'
import { HandCoins, Plus } from 'lucide-react'
import { WithBotSettings } from '@/components/bot/settings/with-bot-settings'
import { CashbackSettingsCard } from '@/components/cashback/cashback-settings-card'
import { RuleForm } from '@/components/cashback/rule-form'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleButton } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { EditorModal, useEditor } from '@/components/row-editor'
import { ActionsHead, EditMenu, RemoveConfirm } from '@/components/sortable-list'
import { Switch } from '@/components/switch'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { CashbackRuleRow, CashbackRulesResponse, CashbackRuleSwitchRequest } from '@/lib/api-types'
import { formatPercent } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { ORDER_TYPE } from '@/lib/statuses'
import { useRows } from '@/lib/use-rows'
import { cn } from '@/lib/utils'

const rulesQuery = queryOptions({ queryKey: queryKeys.cashbackRules, queryFn: () => api.get<CashbackRulesResponse>('/cashback/rules') })

/** A rule's name in a sentence: its percent and its kinds, «۵٪ خرید و تمدید». */
const ruleName = (rule: CashbackRuleRow) => `${formatPercent(rule.percent)} ${rule.order_types.map((type) => ORDER_TYPE[type]).join(' و ')}`

/**
 * «کش‌بک»: the program's switch (a bot-settings group) and its rules — a percent of what a delivered order paid, for some
 * kinds of order, optionally one customer group and one way to pay. The highest matching rule counts, plus the tier's.
 */
export function CashbackPage() {
  const editor = useEditor<CashbackRuleRow>()
  const [removing, setRemoving] = useState<CashbackRuleRow | null>(null)
  const list = useRows({
    query: rulesQuery,
    list: 'rules',
    remove: (rule) => api.delete(`/cashback/rules/${rule.id}`),
    patch: (rule, changes: CashbackRuleSwitchRequest) => api.patch(`/cashback/rules/${rule.id}`, changes).then((answer) => answer.rule),
  })
  const rules = list.rows

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      افزودن قانون
    </Button>
  )

  return (
    <Page width="default">
      <PageHeader
        title="کش‌بک"
        description="درصدی از مبلغ هر سفارش تحویل‌شده که به کیف پول مشتری برمی‌گردد — برای نوع سفارش، گروه مشتری و روش پرداختی که قانون می‌گوید."
        info="از میان قانون‌های فعالی که با یک سفارش جور هستند بالاترین درصد حساب می‌شود و درصد کش‌بک سطح وفاداری مشتری به آن اضافه می‌شود. هر سفارش یک بار کش‌بک می‌گیرد؛ سفارش رایگان هیچ. اگر سفارش برگشت داده شود، کش‌بکش از مبلغ برگشتی کم می‌شود."
        actions={add('default')}
      />

      <WithBotSettings what="تنظیمات کش‌بک">{(settings) => <CashbackSettingsCard settings={settings} />}</WithBotSettings>

      <ListView
        list={list}
        noun="قانون‌های کش‌بک"
        empty={
          <EmptyState
            framed
            icon={HandCoins}
            title="هنوز قانونی ندارید"
            description="مثلا ۵٪ برای همه خریدها و تمدیدها، یا ۱۰٪ برای گروه VIP که با کارت به کارت پرداخت می‌کند. بدون قانون، فقط کش‌بک سطح‌های وفاداری داده می‌شود."
            action={add('secondary')}
          />
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>درصد</TableHead>
              <TableHead>نوع سفارش</TableHead>
              <TableHead className="hidden md:table-cell">گروه مشتری</TableHead>
              <TableHead className="hidden md:table-cell">روش پرداخت</TableHead>
              <TableHead>فعال</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {rules.map((rule) => (
              <TableRow key={rule.id} className={cn(!rule.enabled && 'text-muted-foreground')}>
                <TableCell>
                  <RowTitleButton onClick={() => editor.edit(rule)}>{formatPercent(rule.percent)}</RowTitleButton>
                </TableCell>
                <TableCell>{rule.order_types.map((type) => ORDER_TYPE[type]).join('، ')}</TableCell>
                <TableCell className="hidden md:table-cell">{rule.customer_group?.name ?? 'همه مشتری‌ها'}</TableCell>
                <TableCell className="hidden md:table-cell">{rule.payment_method?.label ?? 'همه روش‌ها'}</TableCell>
                <TableCell>
                  <Switch checked={rule.enabled} onCheckedChange={(enabled) => list.patch.mutate({ row: rule, changes: { enabled } })} aria-label={`فعال بودن کش‌بک ${ruleName(rule)}`} />
                </TableCell>
                <TableCell>
                  <EditMenu label={ruleName(rule)} onEdit={() => editor.edit(rule)} onRemove={() => setRemoving(rule)} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <EditorModal editor={editor} title={(rule) => (rule ? `ویرایش کش‌بک ${ruleName(rule)}` : 'افزودن قانون کش‌بک')}>
        {(rule) => (
          <RuleForm
            rule={rule}
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
        title="حذف قانون کش‌بک"
        description={(rule) => `کش‌بک ${ruleName(rule)} حذف می‌شود.`}
        details={() => 'کش‌بکی که سفارش‌ها تا امروز با این قانون گرفته‌اند سر جایش می‌ماند.'}
        removed="قانون کش‌بک حذف شد"
        onClose={() => setRemoving(null)}
      />
    </Page>
  )
}
