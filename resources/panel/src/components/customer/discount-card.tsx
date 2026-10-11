import { useState } from 'react'
import type { QueryKey } from '@tanstack/react-query'
import { Percent } from 'lucide-react'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { UserRow } from '@/lib/api-types'
import { formatNumber, wholeOf } from '@/lib/format'
import { useForm } from '@/lib/use-form'

interface DiscountCardProps {
  customer: UserRow
  /** What the save changes beside the customer's row, which `onSaved` puts in its place: the users table's copy. */
  invalidates: readonly QueryKey[]
  onSaved: (customer: UserRow) => void
}

/**
 * «تخفیف اختصاصی»: the customer's personal percent off, set or removed in a dialog. It is one of the automatic discounts
 * (with sales and the loyalty tier's), of which only the largest applies.
 */
export function DiscountCard({ customer, invalidates, onSaved }: DiscountCardProps) {
  const [editing, setEditing] = useState(false)
  const percent = customer.discount_percent

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>تخفیف اختصاصی</CardTitle>
        </CardHeading>
        <CardAction>
          <Button variant="secondary" size="sm" icon={Percent} onClick={() => setEditing(true)}>
            {percent > 0 ? 'تغییر' : 'تعیین'}
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent>
        <p className="text-body text-muted-foreground">
          {percent > 0 ? (
            <>
              <span className="font-medium text-foreground tabular">{formatNumber(percent)}٪</span> روی هر خرید، تمدید و حجم و زمان اضافه — اگر فروش ویژه یا سطح وفاداری‌اش بیشتر باشد، همان بیشتر.
            </>
          ) : (
            'ندارد.'
          )}
        </p>
      </CardContent>

      <Modal open={editing} onClose={() => setEditing(false)} size="sm" title={`تخفیف اختصاصی ${userLabel(customer)}`}>
        {editing && (
          <DiscountForm
            customer={customer}
            invalidates={invalidates}
            onCancel={() => setEditing(false)}
            onSaved={(saved) => {
              onSaved(saved)
              setEditing(false)
            }}
          />
        )}
      </Modal>
    </Card>
  )
}

function DiscountForm({ customer, invalidates, onCancel, onSaved }: DiscountCardProps & { onCancel: () => void }) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm({ percent: String(customer.discount_percent) })
  const percent = wholeOf(values.percent)

  const save = handleSubmit(async () => {
    const result = await submit(() => api.put(`/users/${customer.id}/discount`, values), { invalidates })
    if (result) {
      toast.success(result.user.discount_percent > 0 ? 'تخفیف اختصاصی ذخیره شد' : 'تخفیف اختصاصی برداشته شد')
      onSaved(result.user)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-4">
      <Field
        id="customer_discount"
        label="درصد تخفیف"
        error={error('percent')}
        hint={percent !== null && Number(percent) > 0 ? `${formatNumber(Number(percent))}٪ از هر خریدش کم می‌شود، تا وقتی برش دارید.` : '0 یعنی بدون تخفیف اختصاصی؛ بین 0 تا 100.'}
      >
        <Input dir="ltr" inputMode="numeric" autoComplete="off" value={values.percent} onChange={(e) => set('percent', e.target.value)} className="sm:max-w-28" autoFocus />
      </Field>
      <FormActions error={formError} onCancel={onCancel} submitLabel="ذخیره" busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
