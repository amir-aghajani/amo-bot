import { ChannelsCard } from '@/components/bot/channels-card'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { api } from '@/lib/api'
import type { BotChannelsSettingsRequest, BotSettingsData } from '@/lib/api-types'

/** The channel rule, above the list it applies (ChannelsCard) — the list's own changes apply at once. */
export function ChannelsSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ join_required: settings.join_required } satisfies BotChannelsSettingsRequest, (values) => api.put('/bot/settings/channels', values))
  const { values, set, error } = group

  return (
    <>
      <SectionCard form={group} title="عضویت اجباری" description="مشتری پیش از استفاده از ربات باید عضو کانال‌ها و گروه‌های لیست پایین باشد یا نه.">
        <SwitchRow
          label="عضویت اجباری در کانال"
          hint="مشتری باید عضو همه کانال‌های لیست پایین باشد؛ وگرنه به جای منو، لینک کانال‌ها و دکمه «عضو شدم» را می‌بیند. بعد از تایید شماره بررسی می‌شود و فقط برای مشتری‌ها؛ مدیرها معاف‌اند."
          checked={values.join_required}
          onCheckedChange={(checked) => set('join_required', checked)}
          error={error('join_required')}
        />
      </SectionCard>
      <ChannelsCard required={settings.join_required} />
    </>
  )
}
