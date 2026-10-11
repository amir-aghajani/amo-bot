import { useQuery } from '@tanstack/react-query'
import { MenuButtonNotice } from '@/components/bot/menu-button-notice'
import { WithBotSettings } from '@/components/bot/settings/with-bot-settings'
import { ProgramOff } from '@/components/program-off'
import { SectionedPage } from '@/components/sectioned-page'
import { WHEEL_SECTIONS } from '@/components/shell/nav'
import { PrizesList } from '@/components/wheel/prizes-list'
import { SpinsList } from '@/components/wheel/spins-list'
import { WheelSettingsCard } from '@/components/wheel/wheel-settings-card'
import { botSettingsQuery } from '@/lib/queries'

type Section = (typeof WHEEL_SECTIONS)[number]['value']

const HEADERS: Record<Section, { title: string; description: string }> = {
  prizes: {
    title: 'جایزه‌های گردونه',
    description: 'هر جایزه متنی که روی گردونه می‌آید، چیزی که می‌دهد و وزنی که شانسش را تعیین می‌کند؛ به همین ترتیب روی گردونه دیده می‌شوند.',
  },
  spins: {
    title: 'چرخش‌ها',
    description: 'هر بار که مشتری‌ای گردونه را چرخانده، تازه‌ترین اول: جایزه‌اش و این‌که داده شد یا نه.',
  },
  settings: {
    title: 'تنظیمات گردونه',
    description: 'روشن یا خاموش بودن گردونه، فاصله دو چرخش، هزینه هر چرخش و این‌که فقط خریدارها بچرخانند.',
  },
}

/** The wheel in a paragraph, behind the header's ⓘ on every section. */
const PROGRAM =
  'مشتری در ربات دکمه «گردونه شانس» را می‌زند و گردونه را می‌چرخاند؛ پیام چند بار جابه‌جا می‌شود و روی یکی از جایزه‌ها می‌ایستد. جایزه همان لحظه داده می‌شود: شارژ کیف پول، حجم یا زمان روی سرویس فعال او، یا یک سرویس رایگان. هر مشتری بعد از هر چرخش باید تا نوبت بعدی صبر کند؛ اگر جایزه‌ای همان لحظه داده نشود، هزینه چرخش برمی‌گردد و آن چرخش حساب نمی‌شود.'

/** «گردونه شانس» (both panels), in three sections: the wheel's slices and their chances, the spins made, and its rules. */
export function WheelPage() {
  const data = useQuery(botSettingsQuery).data?.settings

  return (
    <SectionedPage
      sections={WHEEL_SECTIONS}
      header={(current) => ({ ...HEADERS[current.value], info: PROGRAM })}
      above={(current) => (
        <>
          {data && !data.wheel_enabled && current.value !== 'settings' && (
            <ProgramOff to="/wheel/settings" action="روشن کردن در تنظیمات گردونه">
              گردونه شانس خاموش است: دکمه‌اش در منوی ربات نیست و کسی نمی‌تواند بچرخاند.
            </ProgramOff>
          )}
          {data?.wheel_enabled && <MenuButtonNotice action="wheel" label="گردونه شانس" screen="چرخاندن گردونه در ربات" />}
        </>
      )}
    >
      {{
        prizes: <PrizesList />,
        spins: <SpinsList />,
        settings: <WithBotSettings what="تنظیمات گردونه">{(settings) => <WheelSettingsCard settings={settings} />}</WithBotSettings>,
      }}
    </SectionedPage>
  )
}
