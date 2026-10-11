import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { extraPricesAs, extraPricesDraft, extraPricesIn, extraPricesRead, extraPricesRequest, type ExtraPricesAs } from '@/components/extras/draft'
import { ExtraPricesFields } from '@/components/extras/extra-prices-fields'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { api } from '@/lib/api'
import type { BotExtrasSettingsRequest, BotSettingsData } from '@/lib/api-types'

/** The shop's price list's names in the bot settings: each field of a price list, `extra_` before it. */
const PREFIX = 'extra_'

/** The card's draft: the switch, and the shop's price list under the names the server keeps — and refuses — it by. */
type Values = { extras_enabled: boolean } & ExtraPricesAs<typeof PREFIX>

function draftOf(settings: BotSettingsData): Values {
  const prices = extraPricesDraft({
    traffic_price: settings.extra_traffic_price,
    traffic_options: settings.extra_traffic_options,
    traffic_min: settings.extra_traffic_min,
    traffic_max: settings.extra_traffic_max,
    time_price: settings.extra_time_price,
    time_options: settings.extra_time_options,
    time_min: settings.extra_time_min,
    time_max: settings.extra_time_max,
  })
  return { extras_enabled: settings.extras_enabled, ...extraPricesAs(PREFIX, prices) }
}

/** The group as its PUT sends it: the switch, and the price list as typed — the rows that say something. */
function requestOf(values: Values): BotExtrasSettingsRequest {
  const prices = extraPricesRequest(extraPricesIn(PREFIX, values))
  return {
    extras_enabled: values.extras_enabled,
    extra_traffic_price: prices.traffic_price,
    extra_traffic_options: prices.traffic_options,
    extra_traffic_min: prices.traffic_min,
    extra_traffic_max: prices.traffic_max,
    extra_time_price: prices.time_price,
    extra_time_options: prices.time_options,
    extra_time_min: prices.time_min,
    extra_time_max: prices.time_max,
  }
}

/**
 * «حجم و زمان اضافه»: whether customers may buy extra traffic and time for a service, and the shop's price list (a plan
 * may replace it with its own).
 */
export function ExtrasSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(draftOf(settings), (values) => api.put('/bot/settings/extras', requestOf(values)), {
    reads: (values) => ({ extras_enabled: values.extras_enabled, ...extraPricesRead(extraPricesIn(PREFIX, values)) }),
  })
  const { values, set, error } = group

  return (
    <SectionCard
      form={group}
      title="حجم و زمان اضافه"
      description="مشتری برای سرویسی که دارد، جدا از تمدید، حجم یا زمان بیشتر می‌خرد؛ هر کدام جدا، از صفحه همان سرویس در ربات و وب‌سایت. قیمت‌های این‌جا برای همه پلن‌هاست، مگر پلنی که قیمت‌های خودش را دارد (فرم پلن، بخش «حجم و زمان اضافه»)."
    >
      <SwitchRow
        label="فروش حجم و زمان اضافه"
        hint="خاموش که باشد، هیچ‌کدام فروخته نمی‌شود: نه با این قیمت‌ها و نه با قیمت‌های خود پلن‌ها."
        checked={values.extras_enabled}
        onCheckedChange={(checked) => set('extras_enabled', checked)}
        error={error('extras_enabled')}
      />
      <ExtraPricesFields form={group} prefix={PREFIX} />
    </SectionCard>
  )
}
