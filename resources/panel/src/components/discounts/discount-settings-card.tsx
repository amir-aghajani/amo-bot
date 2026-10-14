import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotDiscountsSettingsRequest, BotSettingsData } from '@/lib/api-types'
import { formatMinutes, wholeOf } from '@/lib/format'
import { DISCOUNT_SETTINGS } from '@/lib/limits'

/**
 * The discount codes' rules — a group of the bot settings (`discounts`): how long an unpaid order made with a code holds
 * the code's use before it is dropped and the use given back («آزادسازی خودکار سهمیه»).
 */
export function DiscountSettingsCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ hold_minutes: String(settings.hold_minutes) } satisfies BotDiscountsSettingsRequest, (values) => api.put('/bot/settings/discounts', values))
  const { values, set, error } = group
  const minutes = wholeOf(values.hold_minutes)

  return (
    <SectionCard
      form={group}
      title="مهلت پرداخت با کد تخفیف"
      description="سفارشی که با کد تخفیف ثبت شده و پرداخت نشده، یکی از دفعات استفاده از کد را نگه می‌دارد؛ اگر تا این مهلت پرداخت نشود لغو می‌شود و سهم کد برای بقیه آزاد می‌شود."
    >
      <Field
        id="discounts_hold"
        label="مهلت (دقیقه)"
        error={error('hold_minutes')}
        hint={
          minutes === null
            ? `بین 0 تا ${DISCOUNT_SETTINGS.HOLD_MAX} دقیقه`
            : Number(minutes) === 0
              ? 'صفر یعنی سفارش با کد هم مثل هر سفارش پرداخت‌نشده دیگر، بعد از دو روز لغو می‌شود.'
              : `سفارش پرداخت‌نشده با کد، ${formatMinutes(Number(minutes))} بعد از آخرین تغییرش لغو می‌شود؛ سفارشی که رسیدش در دست بررسی است لغو نمی‌شود.`
        }
      >
        <Input dir="ltr" inputMode="numeric" value={values.hold_minutes} onChange={(e) => set('hold_minutes', e.target.value)} className="sm:max-w-40" />
      </Field>
    </SectionCard>
  )
}
