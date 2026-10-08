import { useState } from 'react'
import { Plus, Tags } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleButton } from '@/components/list-view'
import { EditorModal, useEditor } from '@/components/row-editor'
import { SectionActions } from '@/components/sectioned-page'
import { ActionsHead, EditMenu, OrderCell, OrderHead, RemoveConfirm } from '@/components/sortable-list'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { GroupForm } from '@/components/users/group-form'
import { api } from '@/lib/api'
import type { CustomerGroupRow } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { customerGroupsQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { useRows } from '@/lib/use-rows'

/** The admin's groups of customers, in their order: a name each, with how many are in it; deleting one empties nobody. */
export function GroupsList() {
  const editor = useEditor<CustomerGroupRow>()
  const [removing, setRemoving] = useState<CustomerGroupRow | null>(null)
  // The customers' rows name their groups, in this list's order: a group renamed, moved or gone shows there too.
  const list = useRows({
    query: customerGroupsQuery,
    list: 'groups',
    reorder: (ids) => api.post('/customer-groups/reorder', { ids }),
    remove: (group) => api.delete(`/customer-groups/${group.id}`),
    invalidates: [queryKeys.users],
  })

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={editor.create}>
      افزودن گروه
    </Button>
  )

  return (
    <>
      <SectionActions>{add('default')}</SectionActions>

      <ListView
        list={list}
        noun="گروه‌ها"
        empty={
          <EmptyState
            framed
            icon={Tags}
            title="هنوز گروهی ندارید"
            description="گروه‌ها مشتری‌ها را برای شما دسته می‌کنند — VIP، همکاران، یک کمپین — و پیام همگانی را می‌شود فقط برای یک گروه فرستاد. مشتری‌ها را از منوی هر ردیف در لیست «کاربران» به گروه اضافه کنید."
            action={add('secondary')}
          />
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <OrderHead />
              <TableHead>گروه</TableHead>
              <TableHead>مشتری‌ها</TableHead>
              <ActionsHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((group, index) => (
              <TableRow key={group.id}>
                <OrderCell list={list} index={index} label={group.name} />
                <TableCell>
                  <RowTitleButton onClick={() => editor.edit(group)}>{group.name}</RowTitleButton>
                </TableCell>
                <TableCell className="tabular">{formatNumber(group.counts.users)}</TableCell>
                <TableCell>
                  <EditMenu label={group.name} onEdit={() => editor.edit(group)} editLabel="تغییر نام" onRemove={() => setRemoving(group)} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <EditorModal editor={editor} title={(group) => (group ? `تغییر نام «${group.name}»` : 'افزودن گروه')}>
        {(group) => (
          <GroupForm
            group={group}
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
        title="حذف گروه"
        description={(group) => `«${group.name}» حذف می‌شود.`}
        details={(group) =>
          group.counts.users === 0
            ? 'هیچ مشتری‌ای در این گروه نیست.'
            : group.counts.users === 1
              ? 'یک مشتری در این گروه است؛ فقط از گروه بیرون می‌آید و حسابش دست نمی‌خورد.'
              : `${formatNumber(group.counts.users)} مشتری در این گروه هستند؛ فقط از گروه بیرون می‌آیند و حسابشان دست نمی‌خورد.`
        }
        removed="گروه حذف شد"
        onClose={() => setRemoving(null)}
      />
    </>
  )
}
