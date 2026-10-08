import { QrBackgroundCard } from '@/components/bot/qr-background-card'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { api } from '@/lib/api'
import type { BotQrSettingsRequest, BotSettingsResponse } from '@/lib/api-types'

/** Whether a delivered service comes with a QR code of its link, and the picture it is drawn on. */
export function QrSection({ data }: { data: BotSettingsResponse }) {
  const group = useBotSettingsGroup({ qr_enabled: data.settings.qr_enabled } satisfies BotQrSettingsRequest, (values) => api.put('/bot/settings/qr', values))
  const { values, set, error } = group

  return (
    <>
      <SectionCard form={group} title="کد QR" description="همراه لینک اتصال، یک تصویر کد QR هم برای مشتری فرستاده شود یا نه.">
        <SwitchRow
          label="ساخت کد QR"
          hint="روشن که باشد، بعد از هر خرید، کد QR لینک اتصال روی تصویر پس‌زمینه پایین ساخته و کنار پیام سرویس فرستاده می‌شود؛ مشتری با اسکن آن، سرویس را به برنامه اضافه می‌کند."
          checked={values.qr_enabled}
          onCheckedChange={(checked) => set('qr_enabled', checked)}
          error={error('qr_enabled')}
        />
      </SectionCard>
      <QrBackgroundCard background={data.qr_background} />
    </>
  )
}
