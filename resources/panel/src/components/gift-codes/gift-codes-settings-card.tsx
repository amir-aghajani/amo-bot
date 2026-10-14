import { SwitchCard } from '@/components/bot/settings/switch-card'
import { api } from '@/lib/api'
import type { BotSettingsData } from '@/lib/api-types'

/** The `gift_codes` bot-settings group: whether customers may redeem gift codes. */
export function GiftCodesSettingsCard({ settings }: { settings: BotSettingsData }) {
  return (
    <SwitchCard
      field="gift_codes_enabled"
      checked={settings.gift_codes_enabled}
      save={(values) => api.put('/bot/settings/gift_codes', values)}
      title="کد هدیه در ربات و وب‌سایت"
      description="کدها را هر وقت بخواهید می‌سازید؛ مشتری‌ها فقط وقتی می‌توانند از آن‌ها استفاده کنند که این گزینه روشن باشد."
      label="استفاده از کد هدیه فعال باشد"
      hint="روشن که باشد، دکمه «کد هدیه» در منوی ربات می‌آید و مشتری با فرستادن کد، اعتبار یا سرویس رایگانش را می‌گیرد؛ وب‌سایت فروشگاه هم کد را می‌پذیرد. خاموش، دکمه از منو برداشته می‌شود و کدها سر جایشان می‌مانند."
    />
  )
}
