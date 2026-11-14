import { useMutation, useQuery, type QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { SwitchRow } from '@/components/switch-row'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { UserRow } from '@/lib/api-types'
import { botSettingsQuery } from '@/lib/queries'

interface PhoneCardProps {
  customer: UserRow
  /** What the change touches beside the customer's row, which `onSaved` puts in its place: the users table's copy. */
  invalidates: readonly QueryKey[]
  onSaved: (customer: UserRow) => void
}

/**
 * «شماره موبایل»: whether the customer verified their number, and whether they are excused from the shop's phone rule
 * — switched at once, held while it saves, whether the shop asks numbers now or not (the rule may be switched on later).
 * A bot admin is never asked anyway: their switch is held, saying so. Another country's number, while the bot takes
 * Iranian ones alone (its settings read only for such a number), is said to be asked again.
 */
export function PhoneCard({ customer, invalidates, onSaved }: PhoneCardProps) {
  const exempt = useMutation({
    mutationFn: (phoneExempt: boolean) => api.put(`/users/${customer.id}/phone-exempt`, { phone_exempt: phoneExempt }),
    meta: { invalidates },
    onSuccess: ({ user }) => {
      onSaved(user)
      const name = userLabel(user)
      toast.success(user.phone_exempt ? `${name} از ارسال شماره موبایل معاف شد` : `${name} دیگر از ارسال شماره موبایل معاف نیست`)
    },
  })
  const admin = customer.role === 'admin'
  const checked = exempt.isPending ? (exempt.variables ?? customer.phone_exempt) : customer.phone_exempt
  const outside = Boolean(customer.phone) && !customer.phone?.startsWith('+98')
  const settings = useQuery({ ...botSettingsQuery, enabled: outside }).data?.settings
  const askedAgain = outside && !admin && !checked && settings?.phone_required === true && settings.phone_iran_only

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>شماره موبایل</CardTitle>
          {/* The number itself is under the name, at the top of the page. */}
          <CardDescription>
            {askedAgain
              ? 'شماره‌اش ایرانی نیست و ربات دوباره شماره ایرانی از او می‌خواهد؛ اگر ندارد، او را از ارسال شماره موبایل معاف کنید.'
              : customer.phone
                ? 'شماره‌اش را تایید کرده است.'
                : 'هنوز شماره‌ای تایید نکرده است.'}
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <SwitchRow
          label="معاف از ارسال شماره موبایل"
          hint={admin ? 'مدیر ربات است و مدیرهای ربات هیچ‌وقت برای شماره موبایل نگه داشته نمی‌شوند.' : 'این کاربر هیچ‌وقت برای شماره موبایل نگه داشته نمی‌شود، حتی وقتی ربات شماره را لازم دارد.'}
          checked={checked}
          held={admin || exempt.isPending}
          onCheckedChange={(on) => exempt.mutate(on)}
        />
      </CardContent>
    </Card>
  )
}
