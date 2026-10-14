import { useQuery } from '@tanstack/react-query'
import { Info } from 'lucide-react'
import { agencySettingsQuery } from '@/components/agency/queries'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { MoneyField } from '@/components/money-field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { AgencySettings, AgencySettingsRequest, AgencySettingsResponse } from '@/lib/api-types'
import { amountDraft, decimalInput, wholeNumberOf, wholesOf } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { asSet, trimmed } from '@/lib/use-form'
import { useSettingsGroup } from '@/lib/use-settings-group'

/** The program's rules, as read; the form keeps its draft while another section is shown. */
export function AgencySettingsCard() {
  const read = useQuery(agencySettingsQuery)

  if (read.error) return <ErrorState what="تنظیمات نمایندگی" error={read.error} onRetry={() => void read.refetch()} retrying={read.isFetching} />

  return read.data ? <SettingsForm settings={read.data.settings} /> : <Skeleton className="h-64 w-full rounded-xl" />
}

/** The rules as the form edits them: amounts as typed, the presets as one line. */
function draft(settings: AgencySettings) {
  return {
    enabled: settings.enabled,
    default_credit: amountDraft(settings.default_credit),
    traffic_presets: settings.traffic_presets.join(', '),
    traffic_min: String(settings.traffic_min),
    term_days: String(settings.term_days),
    monthly_minimum: amountDraft(settings.monthly_minimum),
    warn_days: String(settings.warn_days),
  } satisfies AgencySettingsRequest
}

/**
 * Whether the program takes requests, what an approval gives — the starting credit, the agency's length, the monthly
 * minimum —, the traffic agents buy (the amounts offered and the least), and how many days ahead an agent is warned
 * before their selling stops.
 */
function SettingsForm({ settings }: { settings: AgencySettings }) {
  const group = useSettingsGroup({
    // The amounts in the API's form (a decimal point stays one); the server reads the preset list as typed (Persian
    // digits, any separator).
    save: (values) =>
      api.put('/agency/settings', {
        ...values,
        default_credit: decimalInput(values.default_credit),
        traffic_min: decimalInput(values.traffic_min),
        term_days: decimalInput(values.term_days),
        monthly_minimum: decimalInput(values.monthly_minimum),
        warn_days: decimalInput(values.warn_days),
      }),
    queryKey: queryKeys.agencySettings,
    apply: (_current: AgencySettingsResponse | undefined, result) => result,
    initial: draft(settings),
    saved: 'تنظیمات نمایندگی ذخیره شد',
    // The amounts offered as the server keeps them, each once in its own order: the same list typed otherwise is no change.
    reads: (values) => trimmed({ ...values, traffic_presets: asSet(wholesOf(values.traffic_presets) ?? [values.traffic_presets.trim()]) }),
  })
  const { values, set, error } = group

  return (
    <SectionCard
      form={group}
      title="برنامه نمایندگی"
      description="مشتری از صفحه «نمایندگی» ربات درخواست می‌دهد و پشتیبانی آن را با انتخاب یک سطح تایید یا رد می‌کند. نماینده ربات فروش خودش را دارد و حجمی را که رباتش می‌فروشد با قیمت هر گیگابایت سطحش از ربات اصلی می‌خرد."
    >
      <SwitchRow
        label="نمایندگی فعال باشد"
        hint="خاموش که باشد، درخواست تازه‌ای ثبت نمی‌شود و دکمه «نمایندگی» برای مشتری‌ها از منوی ربات برداشته می‌شود. نماینده‌ها همچنان حسابشان را می‌بینند و رباتشان کار می‌کند."
        checked={values.enabled}
        onCheckedChange={(checked) => set('enabled', checked)}
        error={error('enabled')}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <MoneyField
          id="agency_default_credit"
          label="اعتبار اولیه نماینده (تومان)"
          placeholder="0"
          value={values.default_credit}
          onChange={(credit) => set('default_credit', credit)}
          error={error('default_credit')}
          says={(money) => `= ${money}؛ موجودی نماینده تا این اندازه می‌تواند منفی شود.`}
          hint="بدون اعتبار: نماینده فقط با موجودی کیف پولش می‌خرد."
        />
        <Field id="agency_traffic_min" label="کمترین خرید حجم (گیگابایت)" error={error('traffic_min')} hint="کمترین حجمی که نماینده می‌تواند بنویسد.">
          <Input dir="ltr" inputMode="numeric" value={values.traffic_min} onChange={(e) => set('traffic_min', e.target.value)} className="sm:max-w-40" />
        </Field>
      </div>
      <Field
        id="agency_traffic_presets"
        label="حجم‌های پیشنهادی (گیگابایت)"
        error={error('traffic_presets')}
        hint="دکمه‌هایی که نماینده در «خرید حجم» می‌بیند، با ویرگول جدا؛ قیمت هر کدام از سطح خود نماینده حساب می‌شود."
      >
        <Input dir="ltr" autoComplete="off" placeholder="50, 100, 200, 500" value={values.traffic_presets} onChange={(e) => set('traffic_presets', e.target.value)} />
      </Field>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field
          id="agency_term_days"
          label="مدت نمایندگی (روز)"
          error={error('term_days')}
          hint={wholeNumberOf(values.term_days) ? 'از روز تایید؛ بعد از آن فروش ربات نماینده متوقف می‌شود تا تمدیدش کنید.' : '0: بدون پایان.'}
        >
          <Input dir="ltr" inputMode="numeric" value={values.term_days} onChange={(e) => set('term_days', e.target.value)} />
        </Field>
        <MoneyField
          id="agency_monthly_minimum"
          label="کف خرید ماهانه (تومان)"
          value={values.monthly_minimum}
          onChange={(minimum) => set('monthly_minimum', minimum)}
          error={error('monthly_minimum')}
          placeholder="0"
          hint="0: بدون کف خرید."
          says={(money) => `= ${money} خرید حجم در هر ماه شمسی.`}
        />
        <Field id="agency_warn_days" label="هشدار پیش از توقف (روز)" error={error('warn_days')} hint="چند روز پیش از پایان نمایندگی، یا پایان ماهی که به کف نرسیده، به نماینده خبر داده شود.">
          <Input dir="ltr" inputMode="numeric" value={values.warn_days} onChange={(e) => set('warn_days', e.target.value)} />
        </Field>
      </div>
      <Callout tone="info" icon={Info}>
        اعتبار اولیه، مدت نمایندگی و کف خرید ماهانه برای تاییدی است که از دکمه‌های گروه گزارش‌ها انجام می‌شود؛ در پنل، هنگام تایید هر درخواست می‌شود مقدار دیگری داد، و بعدا از «سطح، اعتبار و شرایط
        فروش» هر نماینده.
      </Callout>
    </SectionCard>
  )
}
