import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotSettingsData, BotWheelSettingsRequest } from '@/lib/api-types'
import { formatDuration, formatMoney, wholeOf } from '@/lib/format'

/** The `wheel` bot-settings group: the switch, the wait between spins, buyers only, and a spin's cost. */
export function WheelSettingsCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(
    {
      wheel_enabled: settings.wheel_enabled,
      wheel_cooldown_hours: String(settings.wheel_cooldown_hours),
      wheel_buyers_only: settings.wheel_buyers_only,
      wheel_cost: String(settings.wheel_cost),
    } satisfies BotWheelSettingsRequest,
    (values) => api.put('/bot/settings/wheel', values),
  )
  const { values, set, error } = group
  const hours = wholeOf(values.wheel_cooldown_hours)
  const cost = wholeOf(values.wheel_cost)

  return (
    <SectionCard form={group} title="قوانین گردونه" description="مشتری هر چند ساعت یک بار گردونه را می‌چرخاند و یکی از جایزه‌ها را می‌برد؛ چرخشی که جایزه‌اش داده نشود حساب نمی‌شود.">
      <SwitchRow
        label="گردونه شانس فعال باشد"
        hint="روشن که باشد، دکمه «گردونه شانس» در منوی ربات می‌آید. خاموش، دکمه از منو برداشته می‌شود و جایزه‌ها و چرخش‌های قبلی سر جایشان می‌مانند."
        checked={values.wheel_enabled}
        onCheckedChange={(checked) => set('wheel_enabled', checked)}
        error={error('wheel_enabled')}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="wheel_cooldown"
          label="فاصله دو چرخش (ساعت)"
          error={error('wheel_cooldown_hours')}
          hint={hours !== null && Number(hours) > 0 ? `هر مشتری هر ${formatDuration(Number(hours))} یک بار.` : 'بین 1 تا 720 ساعت'}
        >
          <Input dir="ltr" inputMode="numeric" value={values.wheel_cooldown_hours} onChange={(e) => set('wheel_cooldown_hours', e.target.value)} />
        </Field>
        <Field
          id="wheel_cost"
          label="هزینه هر چرخش (تومان)"
          error={error('wheel_cost')}
          hint={cost !== null && Number(cost) > 0 ? `${formatMoney(cost)} از کیف پول مشتری کم می‌شود.` : 'صفر یعنی چرخش رایگان است.'}
        >
          <Input dir="ltr" inputMode="numeric" value={values.wheel_cost} onChange={(e) => set('wheel_cost', e.target.value)} />
        </Field>
      </div>
      <SwitchRow
        label="فقط مشتری‌هایی که خرید کرده‌اند"
        hint="روشن که باشد، فقط مشتری‌ای که دست‌کم یک سرویس خریده گردونه را می‌چرخاند؛ مدیرهای ربات همیشه می‌توانند."
        checked={values.wheel_buyers_only}
        onCheckedChange={(checked) => set('wheel_buyers_only', checked)}
        error={error('wheel_buyers_only')}
      />
    </SectionCard>
  )
}
