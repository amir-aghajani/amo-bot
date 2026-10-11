import { WithBotSettings } from '@/components/bot/settings/with-bot-settings'
import { DrawsList } from '@/components/lottery/draws-list'
import { LotterySettingsCard } from '@/components/lottery/lottery-settings-card'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'

/** «قرعه‌کشی»: the nightly draw among recent buyers — its rules (a bot-settings group) and the draws made, newest first. */
export function LotteryPage() {
  return (
    <Page width="default">
      <PageHeader
        title="قرعه‌کشی"
        description="هر شب یک قرعه‌کشی میان خریدارهای ۲۴ ساعت گذشته؛ برنده‌ها جایزه‌شان را همان لحظه می‌گیرند و نتیجه در گروه گزارش‌ها، زیر «خریدها»، هم می‌آید."
        info="هر روز فروشگاه فقط یک قرعه‌کشی دارد، حتی اگر ساعتش را عوض کنید. مدیرهای ربات و مشتری‌های مسدود هیچ‌وقت شرکت داده نمی‌شوند. برنده‌ای که جایزه به او نمی‌رسد (مثلا برای جایزه حجم، سرویس فعالی ندارد) کنار می‌رود و نفر بعدی قرعه برنده می‌شود."
      />
      <WithBotSettings what="تنظیمات قرعه‌کشی">{(settings) => <LotterySettingsCard settings={settings} />}</WithBotSettings>
      <DrawsList />
    </Page>
  )
}
