import { QrBackgroundCard } from '@/components/bot/qr-background-card'
import { SwitchCard } from '@/components/bot/settings/switch-card'
import { api } from '@/lib/api'
import type { BotSettingsResponse } from '@/lib/api-types'

/** Whether a delivered service comes with a QR code of its link, and the picture it is drawn on. */
export function QrSection({ data }: { data: BotSettingsResponse }) {
  return (
    <>
      <SwitchCard
        field="qr_enabled"
        checked={data.settings.qr_enabled}
        save={(values) => api.put('/bot/settings/qr', values)}
        title="کد QR"
        description="همراه لینک اتصال، یک تصویر کد QR هم برای مشتری فرستاده شود یا نه."
        label="ساخت کد QR"
        hint="روشن که باشد، سرویس تحویل‌شده، سرویس جابه‌جاشده، لینک تازه و «🔗 لینک اشتراک» با تصویر کد QR لینک اتصال روی پس‌زمینه پایین فرستاده می‌شوند و متن سرویس زیر تصویر می‌آید؛ مشتری با اسکن آن، سرویس را به برنامه اضافه می‌کند."
      />
      <QrBackgroundCard background={data.qr_background} />
    </>
  )
}
