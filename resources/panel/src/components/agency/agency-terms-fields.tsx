import { useQuery } from '@tanstack/react-query'
import { agencyLevelsQuery } from '@/components/agency/queries'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { AgencyTermsRequest } from '@/lib/api-types'
import { decimalInput, decimalOf, formatMoney, formatPricePerGb } from '@/lib/format'

/** What an agent is given — their level, and the credit their wallet may go below zero by —, as the form holds it: each as typed. */
export type AgencyTerms = Record<keyof AgencyTermsRequest, string>

/** The two as the API takes them. */
export const termsBody = (values: AgencyTerms): AgencyTermsRequest => ({ level_id: values.level_id, credit_limit: decimalInput(values.credit_limit) })

interface AgencyTermsFieldsProps {
  values: AgencyTerms
  set: <K extends keyof AgencyTerms>(key: K, value: AgencyTerms[K]) => void
  error: (field: string) => string | undefined
}

/** The level an agent is on and their credit — approving a request, and changing an agent; levels that could not be read say so. */
export function AgencyTermsFields({ values, set, error }: AgencyTermsFieldsProps) {
  const levels = useQuery(agencyLevelsQuery)
  const { data } = levels
  const credit = decimalOf(values.credit_limit)

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {levels.error && !data && <ErrorState what="لیست سطح‌ها" error={levels.error} onRetry={() => void levels.refetch()} retrying={levels.isFetching} className="sm:col-span-2" />}
      <Field
        id="agency_level"
        label="سطح"
        error={error('level_id')}
        hint={data && data.levels.length === 0 ? 'هنوز سطحی ندارید؛ از بخش «سطح‌ها» بسازید.' : 'قیمتی که نماینده هر گیگابایت حجم رباتش را با آن می‌خرد.'}
      >
        {(control) => (
          <Select value={values.level_id} onValueChange={(value) => set('level_id', value)}>
            <SelectTrigger {...control} className="w-full">
              <SelectValue placeholder="انتخاب سطح" />
            </SelectTrigger>
            <SelectContent>
              {data?.levels.map((level) => (
                <SelectItem key={level.id} value={String(level.id)}>
                  {level.name} · {formatPricePerGb(level.price_per_gb)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </Field>
      <Field
        id="agency_credit"
        label="اعتبار (تومان)"
        error={error('credit_limit')}
        hint={credit !== null && Number(credit) > 0 ? `= ${formatMoney(credit)}؛ موجودی کیف پول تا این اندازه منفی می‌شود.` : 'بدون اعتبار: فقط با موجودی کیف پول.'}
      >
        <Input dir="ltr" inputMode="decimal" autoComplete="off" placeholder="0" value={values.credit_limit} onChange={(e) => set('credit_limit', e.target.value)} />
      </Field>
    </div>
  )
}
