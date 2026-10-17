import { windowEnd, windowRequest, type WindowEnd } from '@/components/discounts/window'
import { WindowField } from '@/components/discounts/window-field'
import { MoneyField } from '@/components/money-field'
import type { AgencyTermsRequest } from '@/lib/api-types'
import { amountDraft, decimalInput } from '@/lib/format'
import { asAmount } from '@/lib/use-form'

/**
 * An agent's terms of selling as a form holds them: when their agency ends (a Jalali day and an hour on the shop's clock;
 * a blank day for never) and the Toman of traffic they buy each Jalali month at least (0 for none, as in the program's own
 * field). Past either, their shop sells nothing new — their bot, panel and services run on.
 */
export interface SellingTermsDraft {
  expires: WindowEnd
  monthly_minimum: string
}

/**
 * The terms an agent has (or are given by default) as the form starts from them. Without an end, a day typed ends at its
 * last hour (23), as a discount's or a sale's end does — not at its first.
 */
export function sellingTermsDraft(expiresAt: string | null, minimum: string | null): SellingTermsDraft {
  return { expires: windowEnd(expiresAt, '23'), monthly_minimum: amountDraft(minimum ?? '0') }
}

/** The two as the API takes them: a blank end is never, a 0 minimum none. */
export const sellingTermsBody = (values: SellingTermsDraft): Pick<AgencyTermsRequest, 'expires_at' | 'monthly_minimum'> => ({
  expires_at: windowRequest(values.expires),
  monthly_minimum: decimalInput(values.monthly_minimum),
})

/** The two as the server reads them, for a form's `reads`: the end as the day and hour sent, the minimum as whole Toman. */
export const sellingTermsRead = (values: SellingTermsDraft) => ({ expires_at: windowRequest(values.expires), monthly_minimum: asAmount(values.monthly_minimum) })

/**
 * sellingTermsBody() of those changed from `kept` (what the form started from) — one left out is kept. The field keeps an
 * end to the hour: sent back as shown, an end set to the minute would move.
 */
export function sellingTermsChanges(values: SellingTermsDraft, kept: SellingTermsDraft): Pick<AgencyTermsRequest, 'expires_at' | 'monthly_minimum'> {
  const [body, before] = [sellingTermsBody(values), sellingTermsBody(kept)]
  return {
    ...(body.expires_at !== before.expires_at ? { expires_at: body.expires_at } : {}),
    ...(body.monthly_minimum !== before.monthly_minimum ? { monthly_minimum: body.monthly_minimum } : {}),
  }
}

interface SellingTermsFieldsProps {
  values: SellingTermsDraft
  onExpires: (expires: WindowEnd) => void
  onMinimum: (minimum: string) => void
  error: (field: string) => string | undefined
}

/** The end and the monthly minimum of an agent — approving a request, and changing an agent. */
export function SellingTermsFields({ values, onExpires, onMinimum, error }: SellingTermsFieldsProps) {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <WindowField id="agency_expires" label="پایان نمایندگی" value={values.expires} onChange={onExpires} error={error('expires_at')} open="بدون پایان" />
      <MoneyField
        id="agency_monthly_minimum"
        label="کف خرید ماهانه (تومان)"
        value={values.monthly_minimum}
        onChange={onMinimum}
        error={error('monthly_minimum')}
        placeholder="0"
        hint="0: بدون کف خرید."
        says={(money) => `= ${money} خرید حجم در هر ماه شمسی؛ کمتر از آن، فروش ربات از ماه بعد متوقف می‌شود.`}
      />
    </div>
  )
}
