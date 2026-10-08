import { useState } from 'react'
import { Plus, Tags } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleButton } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { CategoryForm } from '@/components/plans/category-form'
import { EditorModal, useEditor } from '@/components/row-editor'
import { ActionsHead, EditMenu, OrderCell, OrderHead, RemoveConfirm } from '@/components/sortable-list'
import { Switch } from '@/components/switch'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { ActivationRequest, PlanCategoryRow } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { planCategoriesQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { useRows } from '@/lib/use-rows'
import { cn } from '@/lib/utils'

/** The groups plans are filed under; the customer picks one first as soon as one is active (the bot's rule, PlanCategoryService::isGrouped). */
export function PlanCategoriesPage() {
  const editor = useEditor<PlanCategoryRow>()
  const [removing, setRemoving] = useState<PlanCategoryRow | null>(null)
  // The plans list and the plan form name the categories too.
  const list = useRows({
    query: planCategoriesQuery,
    list: 'categories',
    reorder: (ids) => api.post('/plans/categories/reorder', { ids }),
    remove: (category) => api.delete(`/plans/categories/${category.id}`),
    patch: (category, changes: ActivationRequest) => api.patch(`/plans/categories/${category.id}`, changes).then((answer) => answer.category),
    invalidates: [queryKeys.plans, queryKeys.planOptions],
  })
  const categories = list.rows

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      افزودن دسته
    </Button>
  )

  return (
    <Page>
      <PageHeader
        title="دسته‌بندی‌ها"
        // Counted once the list was read — never a zero it does not know.
        count={list.isPending || list.error ? undefined : categories.length}
        description="گروه‌هایی که پلن‌ها زیرشان می‌روند؛ تا وقتی دسته فعالی هست، مشتری در ربات اول دسته را انتخاب می‌کند و پلن‌های بی‌دسته زیر «سایر پلن‌ها» می‌آیند. هر پلن دسته‌اش را در فرم خودش می‌گیرد."
        actions={add('default')}
      />

      <ListView
        list={list}
        noun="دسته‌ها"
        empty={
          <EmptyState
            framed
            icon={Tags}
            title="هنوز دسته‌ای تعریف نشده است"
            description="بدون دسته، مشتری مستقیم لیست پلن‌ها را می‌بیند. دسته‌ها وقتی به کار می‌آیند که پلن‌ها زیاد شوند: ماهانه، سه‌ماهه، اقتصادی…"
            action={add('secondary')}
          />
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <OrderHead />
              <TableHead>دسته</TableHead>
              <TableHead className="hidden md:table-cell">پلن‌ها</TableHead>
              <TableHead>فعال</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {categories.map((category, index) => (
              <TableRow key={category.id} className={cn(!category.is_active && 'text-muted-foreground')}>
                <OrderCell list={list} index={index} label={category.name} />
                <TableCell>
                  <div className="grid">
                    <RowTitleButton onClick={() => editor.edit(category)}>{category.name}</RowTitleButton>
                    <span className="text-footnote text-muted-foreground md:hidden">{formatNumber(category.counts.plans)} پلن</span>
                  </div>
                </TableCell>
                <TableCell className="hidden tabular md:table-cell">{formatNumber(category.counts.plans)} پلن</TableCell>
                <TableCell>
                  <Switch checked={category.is_active} onCheckedChange={(is_active) => list.patch.mutate({ row: category, changes: { is_active } })} aria-label={`فعال بودن ${category.name}`} />
                </TableCell>
                <TableCell>
                  <EditMenu label={category.name} onEdit={() => editor.edit(category)} onRemove={() => setRemoving(category)} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <EditorModal editor={editor} title={(category) => (category ? `ویرایش «${category.name}»` : 'افزودن دسته')}>
        {(category) => (
          <CategoryForm
            category={category}
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
        title="حذف دسته"
        description={(category) => `«${category.name}» حذف می‌شود.`}
        details={(category) =>
          category.counts.plans === 0
            ? 'این دسته پلنی ندارد.'
            : category.counts.plans === 1
              ? 'پلن این دسته حذف نمی‌شود؛ بدون دسته می‌ماند و زیر «سایر پلن‌ها» فروخته می‌شود.'
              : `${formatNumber(category.counts.plans)} پلن این دسته حذف نمی‌شوند؛ بدون دسته می‌مانند و زیر «سایر پلن‌ها» فروخته می‌شوند.`
        }
        removed="دسته حذف شد"
        onClose={() => setRemoving(null)}
      />
    </Page>
  )
}
