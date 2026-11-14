import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { api } from '@/lib/api'
import type { BotAgentPerksSettingsRequest, BotSettingsData } from '@/lib/api-types'
import { appConfig, MAIN_SHOP } from '@/lib/config'

/**
 * The `agent_perks` bot-settings group: whether whoever pays agent prices — an agent, or a customer in a «همکار» group —
 * goes without the customers' perks, whatever they buy: the agent price where a plan has one, the regular price
 * elsewhere. An agent's shop has no agents, nor the custom builder: its card speaks of the «همکار» groups alone.
 */
export function AgentPerksCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ agents_without_perks: settings.agents_without_perks } satisfies BotAgentPerksSettingsRequest, (values) => api.put('/bot/settings/agent_perks', values))
  const { values, set, error } = group
  const main = appConfig.shop === MAIN_SHOP
  const who = main ? 'نماینده‌ها و کاربرهای گروه‌های «همکار»' : 'کاربرهای گروه‌های «همکار»'
  const regular = main ? 'پلن بدون قیمت نماینده، حجم، زمان و کاربر اضافه، سرویس دلخواه' : 'پلن بدون قیمت نماینده، حجم، زمان و کاربر اضافه'

  return (
    <SectionCard form={group} title="همکارها" description={`${who} قیمت نماینده پلن‌ها را می‌پردازند؛ این‌جا تعیین می‌کنید که تخفیف‌ها و جایزه‌های مشتری‌ها هم به آن‌ها برسد یا نه.`}>
      <SwitchRow
        label="همکارها فقط با قیمت همکاری بخرند"
        hint={`روشن که باشد، فروش ویژه، سطح وفاداری، کد تخفیف، قیمت خرید اول، کش‌بک، گردونه شانس و قرعه‌کشی شبانه به ${who} نمی‌رسد، هر چه بخرند؛ جایی که قیمت نماینده‌ای نیست (${regular}) قیمت معمولی را می‌پردازند. تخفیف اختصاصی‌ای که خودتان به یک نفر داده‌اید می‌ماند.`}
        checked={values.agents_without_perks}
        onCheckedChange={(checked) => set('agents_without_perks', checked)}
        error={error('agents_without_perks')}
      />
    </SectionCard>
  )
}
