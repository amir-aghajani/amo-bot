import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { api } from '@/lib/api'
import type { BotGiftCodesSettingsRequest, BotSettingsData } from '@/lib/api-types'

/** The `gift_codes` bot-settings group: whether customers may redeem gift codes. */
export function GiftCodesSettingsCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ gift_codes_enabled: settings.gift_codes_enabled } satisfies BotGiftCodesSettingsRequest, (values) => api.put('/bot/settings/gift_codes', values))
  const { values, set, error } = group

  return (
    <SectionCard form={group} title="کد هدیه در ربات و وب‌سایت" description="کدها را هر وقت بخواهید می‌سازید؛ مشتری‌ها فقط وقتی می‌توانند از آن‌ها استفاده کنند که این گزینه روشن باشد.">
      <SwitchRow
        label="استفاده از کد هدیه فعال باشد"
        hint="روشن که باشد، دکمه «کد هدیه» در منوی ربات می‌آید و مشتری با فرستادن کد، اعتبار یا سرویس رایگانش را می‌گیرد؛ وب‌سایت فروشگاه هم کد را می‌پذیرد. خاموش، دکمه از منو برداشته می‌شود و کدها سر جایشان می‌مانند."
        checked={values.gift_codes_enabled}
        onCheckedChange={(checked) => set('gift_codes_enabled', checked)}
        error={error('gift_codes_enabled')}
      />
    </SectionCard>
  )
}
