import { useBotSettingsGroup, useHeldWhile } from '@/components/bot/settings/use-bot-settings-group'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotRemindersSettingsRequest, BotSettingsData } from '@/lib/api-types'

/** «یادآوری»: a service ending soon, its traffic running low — each sent once while it stays so, again after a renewal or more traffic. */
export function RemindersSection({ settings }: { settings: BotSettingsData }) {
  const saved = {
    expiry_reminder: settings.expiry_reminder,
    expiry_reminder_days: String(settings.expiry_reminder_days),
    traffic_reminder: settings.traffic_reminder,
    traffic_reminder_percent: String(settings.traffic_reminder_percent),
  } satisfies BotRemindersSettingsRequest
  const group = useBotSettingsGroup(saved, (values) => api.put('/bot/settings/reminders', values))
  const { values, set, error } = group
  // A reminder switched off keeps its number as it is kept, held; one changed stays open, as the save still carries it.
  const expiryHeld = useHeldWhile(!values.expiry_reminder, group, saved)
  const trafficHeld = useHeldWhile(!values.traffic_reminder, group, saved)

  return (
    <SectionCard
      form={group}
      title="یادآوری به مشتری"
      description="مشتری در تلگرام یا با ایمیل خبردار می‌شود که سرویسش رو به پایان است یا حجمش دارد تمام می‌شود؛ هر یادآوری یک بار، و بعد از تمدید یا افزایش حجم دوباره. اطلاعات سرویس‌ها هر ۱۵ دقیقه از پنل‌ها خوانده می‌شود."
    >
      <SwitchRow
        label="یادآوری نزدیک شدن تاریخ پایان"
        hint="سرویسی که تمدید خودکارش روشن است این یادآوری را نمی‌گیرد؛ تمدید خودکار خودش خبر می‌دهد."
        checked={values.expiry_reminder}
        onCheckedChange={(checked) => set('expiry_reminder', checked)}
        error={error('expiry_reminder')}
      />
      <Field id="reminder_days" label="چند روز پیش از پایان سرویس" error={error('expiry_reminder_days')}>
        <Input
          dir="ltr"
          inputMode="numeric"
          value={values.expiry_reminder_days}
          onChange={(e) => set('expiry_reminder_days', e.target.value)}
          disabled={expiryHeld('expiry_reminder_days')}
          className="sm:max-w-40"
        />
      </Field>
      <SwitchRow
        label="یادآوری تمام شدن حجم"
        hint="سرویس‌های با حجم نامحدود این یادآوری را نمی‌گیرند."
        checked={values.traffic_reminder}
        onCheckedChange={(checked) => set('traffic_reminder', checked)}
        error={error('traffic_reminder')}
      />
      <Field id="reminder_percent" label="بعد از مصرف چند درصد حجم" error={error('traffic_reminder_percent')}>
        <Input
          dir="ltr"
          inputMode="numeric"
          value={values.traffic_reminder_percent}
          onChange={(e) => set('traffic_reminder_percent', e.target.value)}
          disabled={trafficHeld('traffic_reminder_percent')}
          className="sm:max-w-40"
        />
      </Field>
    </SectionCard>
  )
}
