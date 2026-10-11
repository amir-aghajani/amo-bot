import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { api } from '@/lib/api'
import type { BotCashbackSettingsRequest, BotSettingsData } from '@/lib/api-types'

/** The `cashback` bot-settings group: whether delivered orders earn cashback at all. */
export function CashbackSettingsCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ cashback_enabled: settings.cashback_enabled } satisfies BotCashbackSettingsRequest, (values) => api.put('/bot/settings/cashback', values))
  const { values, set, error } = group

  return (
    <SectionCard
      form={group}
      title="کش‌بک"
      description="وقتی سفارشی تحویل شد، درصدی از مبلغی که مشتری پرداخت کرده به کیف پول او برمی‌گردد و در ربات خبردار می‌شود؛ با هر روش پرداختی، حتی کیف پول. اگر سفارش بعدا برگشت داده شود، کش‌بک آن از مبلغ برگشتی کم می‌شود."
    >
      <SwitchRow
        label="کش‌بک فعال باشد"
        hint="درصد هر سفارش بالاترین قانون فعالی است که با آن جور است، به اضافه کش‌بک سطح وفاداری مشتری؛ حداکثر ۱۰۰٪. خاموش که باشد، هیچ سفارشی کش‌بک نمی‌گیرد، کش‌بک سطح‌ها هم نه."
        checked={values.cashback_enabled}
        onCheckedChange={(checked) => set('cashback_enabled', checked)}
        error={error('cashback_enabled')}
      />
    </SectionCard>
  )
}
