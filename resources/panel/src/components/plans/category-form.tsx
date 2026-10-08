import type { QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { PlanCategoryRow } from '@/lib/api-types'
import { useForm } from '@/lib/use-form'

interface CategoryFormProps {
  category: PlanCategoryRow | null
  /** What else shows the categories — its list's (useRows' `invalidates`) —, read again once it is saved. */
  invalidates: readonly QueryKey[]
  onSaved: (category: PlanCategoryRow) => void
  onCancel: () => void
}

/** Create/edit form for one plan category; the caller puts it in a modal. */
export function CategoryForm({ category, invalidates, onSaved, onCancel }: CategoryFormProps) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm({ name: category?.name ?? '', is_active: category?.is_active ?? true })

  const save = handleSubmit(async () => {
    const data = await submit(() => (category ? api.put(`/plans/categories/${category.id}`, values) : api.post('/plans/categories', values)), { invalidates })
    if (data) {
      toast.success(category ? 'دسته ذخیره شد' : 'دسته اضافه شد')
      onSaved(data.category)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <Field id="category_name" label="نام دسته" error={error('name')} hint="همان‌طور که مشتری هنگام انتخاب دسته می‌بیند؛ مثلا «ماهانه» یا «اقتصادی».">
        <Input value={values.name} onChange={(e) => set('name', e.target.value)} placeholder="اقتصادی" autoFocus={!category} />
      </Field>

      <SwitchRow
        label="فعال"
        hint="دسته غیرفعال از لیست خرید کنار می‌رود؛ پلن‌هایش زیر «سایر پلن‌ها» می‌مانند."
        checked={values.is_active}
        onCheckedChange={(checked) => set('is_active', checked)}
        error={error('is_active')}
        bordered={false}
      />

      <FormActions error={formError} onCancel={onCancel} submitLabel={category ? 'ذخیره تغییرات' : 'افزودن دسته'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
