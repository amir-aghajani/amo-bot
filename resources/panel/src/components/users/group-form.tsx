import type { QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { CustomerGroupRow } from '@/lib/api-types'
import { useForm } from '@/lib/use-form'

interface GroupFormProps {
  group: CustomerGroupRow | null
  /** What else shows the groups — its list's (useRows' `invalidates`) —, read again once it is saved. */
  invalidates: readonly QueryKey[]
  onSaved: (group: CustomerGroupRow) => void
  onCancel: () => void
}

/** Create/rename form for one of the admin's groups of customers: just its name. */
export function GroupForm({ group, invalidates, onSaved, onCancel }: GroupFormProps) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm({ name: group?.name ?? '' })

  const save = handleSubmit(async () => {
    const data = await submit(() => (group ? api.put(`/customer-groups/${group.id}`, values) : api.post('/customer-groups', values)), { invalidates })
    if (data) {
      toast.success(group ? 'گروه ذخیره شد' : 'گروه اضافه شد')
      onSaved(data.group)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <Field id="group_name" label="نام گروه" error={error('name')} hint="فقط برای شما؛ مشتری آن را نمی‌بیند. پیام همگانی را می‌شود فقط برای یک گروه فرستاد.">
        <Input value={values.name} onChange={(e) => set('name', e.target.value)} placeholder="VIP" autoFocus={!group} />
      </Field>
      <FormActions error={formError} onCancel={onCancel} submitLabel={group ? 'ذخیره تغییرات' : 'افزودن گروه'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
