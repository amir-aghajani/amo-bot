import { useQuery } from '@tanstack/react-query'
import { PowerOff } from 'lucide-react'
import { ChannelsSection } from '@/components/bot/settings/channels-section'
import { ExpiredServicesSection } from '@/components/bot/settings/expired-services-section'
import { ExtrasSection } from '@/components/bot/settings/extras-section'
import { GeneralSection } from '@/components/bot/settings/general-section'
import { QrSection } from '@/components/bot/settings/qr-section'
import { ReferralSection } from '@/components/bot/settings/referral-section'
import { RemindersSection } from '@/components/bot/settings/reminders-section'
import { RenewalSection } from '@/components/bot/settings/renewal-section'
import { ReportsSection } from '@/components/bot/settings/reports-section'
import { WalletSection } from '@/components/bot/settings/wallet-section'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { SectionedPage } from '@/components/sectioned-page'
import { BOT_SETTINGS_SECTIONS } from '@/components/shell/nav'
import { Skeleton } from '@/components/ui/skeleton'
import type { BotSettingsResponse } from '@/lib/api-types'
import { botSettingsQuery } from '@/lib/queries'

/**
 * «تنظیمات ربات» (both panels): the shop's bot settings, a section per subject, each card saving its own group. The
 * running bot picks a change up within seconds; each bot (the shop on screen) has its own.
 */
export function BotSettingsPage() {
  const { data, error, refetch } = useQuery(botSettingsQuery)

  return (
    <SectionedPage
      sections={BOT_SETTINGS_SECTIONS}
      width="narrow"
      header={() => ({ title: 'تنظیمات ربات', description: 'رفتار ربات برای مشتری‌ها. تغییرات همان لحظه روی ربات اعمال می‌شود؛ نیازی به اجرای دوباره نیست.' })}
      above={() => (
        <>
          {error && <ErrorState what="تنظیمات" error={error} onRetry={() => void refetch()} />}
          {/* The one state that matters on every section: customers get nothing but the notice. */}
          {data && !data.settings.enabled && (
            <Callout tone="warning" icon={PowerOff}>
              <span className="font-medium">ربات خاموش است.</span> مشتری‌ها به جای منو فقط پیام «ربات فعلا غیرفعال است» می‌بینند. مدیرها همچنان می‌توانند از ربات استفاده کنند.
            </Callout>
          )}
          {!data && !error && <Skeleton className="h-64 w-full rounded-xl" />}
        </>
      )}
    >
      {data ? sectionsOf(data) : EMPTY}
    </SectionedPage>
  )
}

const EMPTY = { general: null, channels: null, wallet: null, renewal: null, extras: null, reminders: null, expired_services: null, referral: null, reports: null, qr: null }

function sectionsOf(data: BotSettingsResponse) {
  const { settings } = data
  return {
    general: <GeneralSection settings={settings} />,
    channels: <ChannelsSection settings={settings} />,
    wallet: <WalletSection settings={settings} />,
    renewal: <RenewalSection settings={settings} />,
    extras: <ExtrasSection settings={settings} />,
    reminders: <RemindersSection settings={settings} />,
    expired_services: <ExpiredServicesSection settings={settings} />,
    referral: <ReferralSection settings={settings} />,
    reports: <ReportsSection settings={settings} />,
    qr: <QrSection data={data} />,
  }
}
