import { useQuery } from '@tanstack/react-query'
import { agencyLevelsQuery } from '@/components/agency/queries'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { MoneyField } from '@/components/money-field'
import { useReadFailure } from '@/components/page'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { AgencyTermsRequest } from '@/lib/api-types'
import { decimalInput, formatPricePerGb } from '@/lib/format'
import { asAmount } from '@/lib/use-form'

/**
 * What an agent is given — their level, and the credit their wallet may go below zero by —, as the form holds it: each
 * as typed. Their terms of selling are SellingTermsFields'.
 */
export type AgencyTerms = Record<'level_id' | 'credit_limit', string>

/** The two as the API takes them. */
export const termsBody = (values: AgencyTerms): Pick<AgencyTermsRequest, 'level_id' | 'credit_limit'> => ({ level_id: values.level_id, credit_limit: decimalInput(values.credit_limit) })

/** The two as the server reads them, for a form's `reads`: the credit as whole Toman («۵۰۰٬۰۰۰» is 500000). */
export const termsRead = (values: AgencyTerms) => ({ level_id: values.level_id.trim(), credit_limit: asAmount(values.credit_limit) })

interface AgencyTermsFieldsProps {
  values: AgencyTerms
  set: <K extends keyof AgencyTerms>(key: K, value: AgencyTerms[K]) => void
  error: (field: string) => string | undefined
}

/** The level an agent is on and their credit — approving a request, and changing an agent; levels that could not be read say so. */
export function AgencyTermsFields({ values, set, error }: AgencyTermsFieldsProps) {
  const levels = useQuery(agencyLevelsQuery)
  const { data } = levels
  const failure = useReadFailure(levels)

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {failure.error && !data && <ErrorState what="لیست سطح‌ها" error={failure.error} onRetry={() => void levels.refetch()} retrying={failure.retrying} className="sm:col-span-2" />}
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
      <MoneyField
        id="agency_credit"
        label="اعتبار (تومان)"
        placeholder="0"
        value={values.credit_limit}
        onChange={(credit) => set('credit_limit', credit)}
        error={error('credit_limit')}
        says={(money) => `= ${money}؛ موجودی کیف پول تا این اندازه منفی می‌شود.`}
        hint="بدون اعتبار: فقط با موجودی کیف پول."
      />
    </div>
  )
}
