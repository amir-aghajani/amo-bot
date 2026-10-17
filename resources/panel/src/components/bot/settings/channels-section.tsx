import { ChannelsCard } from '@/components/bot/channels-card'
import { SwitchCard } from '@/components/bot/settings/switch-card'
import { api } from '@/lib/api'
import type { BotSettingsData } from '@/lib/api-types'

/** The channel rule, above the list it applies (ChannelsCard) — the list's own changes apply at once. */
export function ChannelsSection({ settings }: { settings: BotSettingsData }) {
  return (
    <>
      <SwitchCard
        field="join_required"
        checked={settings.join_required}
        save={(values) => api.put('/bot/settings/channels', values)}
        title="عضویت اجباری"
        description="مشتری پیش از استفاده از ربات باید عضو کانال‌ها و گروه‌های لیست پایین باشد یا نه."
        label="عضویت اجباری در کانال"
        hint="مشتری باید عضو همه کانال‌های لیست پایین باشد؛ وگرنه به جای منو، لینک کانال‌ها و دکمه «عضو شدم» را می‌بیند. بعد از تایید شماره بررسی می‌شود و فقط برای مشتری‌ها؛ مدیرها معاف‌اند."
      />
      <ChannelsCard required={settings.join_required} />
    </>
  )
}
