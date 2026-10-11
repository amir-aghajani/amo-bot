import type { QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { CustomerGroupRequest, CustomerGroupRow } from '@/lib/api-types'
import { useForm } from '@/lib/use-form'

interface GroupFormProps {
  group: CustomerGroupRow | null
  /** Other reads to refresh once it is saved (useRows' `invalidates`). */
  invalidates: readonly QueryKey[]
  onSaved: (group: CustomerGroupRow) => void
  onCancel: () => void
}

/** What a customer of a «همکار» group pays, in the words the group's form and its list's badge say it in. */
export const AGENT_PRICING_HINT = 'مشتری‌های این گروه مثل نماینده‌ها قیمت نماینده پلن‌ها را می‌پردازند.'

/** Create/edit form for a customer group: its name, and whether its members pay agent prices («همکار»). */
export function GroupForm({ group, invalidates, onSaved, onCancel }: GroupFormProps) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm<Required<CustomerGroupRequest>>({
    name: group?.name ?? '',
    agent_pricing: group?.agent_pricing ?? false,
  })

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
      <SwitchRow label="همکار" hint={AGENT_PRICING_HINT} checked={values.agent_pricing} onCheckedChange={(checked) => set('agent_pricing', checked)} error={error('agent_pricing')} bordered={false} />
      <FormActions error={formError} onCancel={onCancel} submitLabel={group ? 'ذخیره تغییرات' : 'افزودن گروه'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
