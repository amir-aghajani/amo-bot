import { useState } from 'react'
import { Layers, Plus } from 'lucide-react'
import { LevelForm } from '@/components/agency/level-form'
import { agencyLevelsQuery } from '@/components/agency/queries'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleButton } from '@/components/list-view'
import { EditorModal, useEditor } from '@/components/row-editor'
import { SectionActions } from '@/components/sectioned-page'
import { ActionsHead, EditMenu, OrderCell, OrderHead, RemoveConfirm } from '@/components/sortable-list'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { AgencyLevelRow } from '@/lib/api-types'
import { formatMoney, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useRows } from '@/lib/use-rows'

/** The levels, in the order the bot lists them: a name and a price per GB; one agents are on is not deleted. */
export function LevelsList() {
  const editor = useEditor<AgencyLevelRow>()
  const [removing, setRemoving] = useState<AgencyLevelRow | null>(null)
  // The program's numbers count the levels, and the agents' rows name theirs with its price.
  const list = useRows({
    query: agencyLevelsQuery,
    list: 'levels',
    reorder: (ids) => api.post('/agency/levels/reorder', { ids }),
    remove: (level) => api.delete(`/agency/levels/${level.id}`),
    invalidates: [queryKeys.agency, queryKeys.agents],
  })

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      افزودن سطح
    </Button>
  )

  return (
    <>
      <SectionActions>{add('default')}</SectionActions>

      <ListView
        list={list}
        noun="سطح‌ها"
        empty={
          <EmptyState
            framed
            icon={Layers}
            title="هنوز سطحی ندارید"
            description="هر سطح یک نام و یک قیمت برای هر گیگابایت حجم است؛ مثلا برنزی ۴٬۰۰۰ و طلایی ۳٬۰۰۰ تومان. درخواست‌ها روی یکی از سطح‌ها تایید می‌شوند."
            action={add('secondary')}
          />
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <OrderHead />
              <TableHead>سطح</TableHead>
              <TableHead>هر گیگابایت</TableHead>
              <TableHead>نماینده‌ها</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((level, index) => (
              <TableRow key={level.id}>
                <OrderCell list={list} index={index} label={level.name} />
                <TableCell>
                  <RowTitleButton onClick={() => editor.edit(level)}>{level.name}</RowTitleButton>
                </TableCell>
                <TableCell className="tabular">{formatMoney(level.price_per_gb)}</TableCell>
                <TableCell className="tabular">{formatNumber(level.counts.agents)}</TableCell>
                <TableCell>
                  <EditMenu label={level.name} onEdit={() => editor.edit(level)} onRemove={() => setRemoving(level)} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <EditorModal editor={editor} title={(level) => (level ? `ویرایش «${level.name}»` : 'افزودن سطح')}>
        {(level) => (
          <LevelForm
            level={level}
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
        description={(level) => `«${level.name}» حذف می‌شود.`}
        details={() => 'هیچ نماینده‌ای روی این سطح نیست.'}
        kept={(level) =>
          level.counts.agents === 0
            ? null
            : level.counts.agents === 1
              ? `یک نماینده روی «${level.name}» است؛ اول او را به سطح دیگری ببرید، بعد سطح را حذف کنید.`
              : `${formatNumber(level.counts.agents)} نماینده روی «${level.name}» هستند؛ اول آن‌ها را به سطح دیگری ببرید، بعد سطح را حذف کنید.`
        }
        removed="سطح حذف شد"
        onClose={() => setRemoving(null)}
      />
    </>
  )
}
