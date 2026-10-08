import { useQuery, type QueryKey } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { toast } from 'sonner'
import { ErrorState } from '@/components/error-state'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { TextLink } from '@/components/text-link'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { UserRow } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { customerGroupsQuery } from '@/lib/queries'
import { asSet, useForm } from '@/lib/use-form'

interface GroupsModalProps {
  /** The customer whose groups are set; null = closed. */
  user: UserRow | null
  /**
   * What the save changes beside the customer's row, which `onSaved` puts in its place: the groups' counts, and the
   * screen's other copy of the customer (the users table, under their page).
   */
  invalidates: readonly QueryKey[]
  onClose: () => void
  onSaved: (user: UserRow) => void
}

/**
 * The admin's groups a customer is in: every group ticked or not, saved as a whole. With no group yet it says so, and
 * where one is made — nothing to choose, nothing to save.
 */
export function GroupsModal({ user, invalidates, onClose, onSaved }: GroupsModalProps) {
  return (
    <Modal open={user !== null} onClose={onClose} size="sm" title={user ? `گروه‌های ${userLabel(user)}` : 'گروه‌ها'}>
      {user && <GroupsForm key={user.id} user={user} invalidates={invalidates} onCancel={onClose} onSaved={onSaved} />}
    </Modal>
  )
}

function GroupsForm({ user, invalidates, onCancel, onSaved }: { user: UserRow; invalidates: readonly QueryKey[]; onCancel: () => void; onSaved: (user: UserRow) => void }) {
  const groups = useQuery(customerGroupsQuery)
  // The customer's groups are a set: ticked off and on again, a group is no change, wherever it came back in the list.
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm({ group_ids: user.groups.map((group) => group.id) }, { reads: ({ group_ids }) => asSet(group_ids) })

  const toggle = (id: number, on: boolean) => set('group_ids', on ? [...values.group_ids, id] : values.group_ids.filter((groupId) => groupId !== id))

  const save = handleSubmit(async () => {
    const result = await submit(() => api.put(`/users/${user.id}/groups`, values), { invalidates })
    if (result) {
      toast.success('گروه‌های مشتری ذخیره شد')
      onSaved(result.user)
    }
  })

  const { data } = groups
  // Nothing to choose — no group yet, or none read —: nothing to save.
  const choosable = data !== undefined && data.groups.length > 0

  return (
    <form onSubmit={save} noValidate className="grid gap-4">
      {groups.error ? (
        <ErrorState what="گروه‌ها" error={groups.error} onRetry={() => void groups.refetch()} retrying={groups.isFetching} />
      ) : !data ? (
        <Skeleton className="h-24 w-full" />
      ) : data.groups.length === 0 ? (
        <p className="text-body text-muted-foreground">
          هنوز گروهی نساخته‌اید؛ اول در بخش{' '}
          <TextLink to="/users/groups" inline>
            «گروه‌ها»
          </TextLink>{' '}
          یک گروه بسازید، بعد مشتری را در آن بگذارید.
        </p>
      ) : (
        <ul className="-mx-2 grid gap-px" aria-label="گروه‌ها">
          {data.groups.map((group) => (
            <li key={group.id}>
              <label className="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 transition-colors hover:bg-fill">
                <Checkbox checked={values.group_ids.includes(group.id)} onCheckedChange={(checked) => toggle(group.id, checked === true)} aria-label={group.name} />
                <span className="flex-1">{group.name}</span>
                <span className="text-footnote text-faint tabular">{formatNumber(group.counts.users)} نفر</span>
              </label>
            </li>
          ))}
        </ul>
      )}
      {/* An unknown group (deleted meanwhile) is refused on the list as a whole. */}
      <FormActions error={error('group_ids') ?? formError} onCancel={onCancel} submitLabel="ذخیره گروه‌ها" busy={busy} disabled={!choosable} icon={Check} dirty={dirty} onRevert={revert} />
    </form>
  )
}
