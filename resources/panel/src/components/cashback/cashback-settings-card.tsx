import { SwitchCard } from '@/components/bot/settings/switch-card'
import { api } from '@/lib/api'
import type { BotSettingsData } from '@/lib/api-types'

/** The `cashback` bot-settings group: whether delivered orders earn cashback at all. */
export function CashbackSettingsCard({ settings }: { settings: BotSettingsData }) {
  return (
    <SwitchCard
      field="cashback_enabled"
      checked={settings.cashback_enabled}
      save={(values) => api.put('/bot/settings/cashback', values)}
      title="کش‌بک"
      description="وقتی سفارشی تحویل شد، درصدی از مبلغی که مشتری پرداخت کرده به کیف پول او برمی‌گردد و در تلگرام یا با ایمیل خبردار می‌شود؛ با هر روش پرداختی، حتی کیف پول. اگر سفارش بعدا برگشت داده شود، کش‌بک آن از مبلغ برگشتی کم می‌شود."
      label="کش‌بک فعال باشد"
      hint="درصد هر سفارش بالاترین قانون فعالی است که با آن جور است، به اضافه کش‌بک سطح وفاداری مشتری؛ حداکثر 100 درصد. خاموش که باشد، هیچ سفارشی کش‌بک نمی‌گیرد، کش‌بک سطح‌ها هم نه."
    />
  )
}
