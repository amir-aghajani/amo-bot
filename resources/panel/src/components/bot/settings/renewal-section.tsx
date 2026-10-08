import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotAutoRenewSettingsRequest, BotRenewalSettingsRequest, BotSettingsData } from '@/lib/api-types'

/** «تمدید سرویس»: the rule of every renewal, then «تمدید خودکار» — two groups, a card each. */
export function RenewalSection({ settings }: { settings: BotSettingsData }) {
  return (
    <>
      <RenewalCard settings={settings} />
      <AutoRenewCard settings={settings} />
    </>
  )
}

/** What a renewal does with the traffic left — the days left always carry. */
function RenewalCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ carry_traffic: settings.carry_traffic } satisfies BotRenewalSettingsRequest, (values) => api.put('/bot/settings/renewal', values))
  const { values, set, error } = group

  return (
    <SectionCard
      form={group}
      title="تمدید سرویس"
      description="قانون تمدید، چه مشتری خودش تمدید کند و چه تمدید خودکار: روزهای باقی‌مانده همیشه به دوره جدید اضافه می‌شود؛ درباره حجم باقی‌مانده این‌جا تصمیم بگیرید."
    >
      <SwitchRow
        label="حجم باقی‌مانده به دوره جدید منتقل شود"
        hint="روشن: حجم مصرف‌نشده دوره فعلی به حجم دوره جدید اضافه می‌شود. خاموش: حجم مصرف‌نشده تا پایان دوره فعلی قابل استفاده است و بعد از آن صفر می‌شود؛ دوره جدید با حجم پلن شروع می‌شود. تمدید زودتر از پایان دوره، چیزی از حجم فعلی مشتری کم نمی‌کند."
        checked={values.carry_traffic}
        onCheckedChange={(checked) => set('carry_traffic', checked)}
        error={error('carry_traffic')}
      />
    </SectionCard>
  )
}

/** «تمدید خودکار»: how many days before its deadline a service is renewed from the wallet, and how new services start. */
function AutoRenewCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ auto_renew_days: String(settings.auto_renew_days), auto_renew_default: settings.auto_renew_default } satisfies BotAutoRenewSettingsRequest, (values) =>
    api.put('/bot/settings/auto_renew', values),
  )
  const { values, set, error } = group

  return (
    <SectionCard
      form={group}
      title="تمدید خودکار"
      description="مشتری می‌تواند تمدید خودکار هر سرویسش را از صفحه همان سرویس در ربات روشن کند: چند روز پیش از پایان سرویس، قیمت پلن از کیف پولش کسر و سرویس تمدید می‌شود. اگر موجودی کافی نباشد، یک بار در ربات خبردار می‌شود."
    >
      <Field id="renewal_days" label="چند روز پیش از پایان سرویس" error={error('auto_renew_days')}>
        <Input dir="ltr" inputMode="numeric" value={values.auto_renew_days} onChange={(e) => set('auto_renew_days', e.target.value)} className="sm:max-w-40" />
      </Field>
      <SwitchRow
        label="برای سرویس‌های جدید روشن باشد"
        hint="روشن که باشد، هر سرویسی که از این به بعد خریده می‌شود با تمدید خودکار روشن شروع می‌شود و مشتری می‌تواند آن را خاموش کند. روی سرویس‌های فعلی اثری ندارد."
        checked={values.auto_renew_default}
        onCheckedChange={(checked) => set('auto_renew_default', checked)}
        error={error('auto_renew_default')}
      />
    </SectionCard>
  )
}
