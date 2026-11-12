import { useCallback, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CircleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { AppearanceSection } from '@/components/mini-app/appearance-section'
import { ConnectionSection } from '@/components/mini-app/connection-section'
import { miniAppQuery, MiniAppSavedContext } from '@/components/mini-app/mini-app-query'
import { PreviewSection } from '@/components/mini-app/preview/preview-section'
import { useReadFailure } from '@/components/page'
import { SectionedPage } from '@/components/sectioned-page'
import { MINI_APP_SETTINGS_SECTIONS } from '@/components/shell/nav'
import { Skeleton } from '@/components/ui/skeleton'

/**
 * «مینی‌اپ تلگرام» (both panels): the shop's Telegram mini app, the customer side of the shop inside Telegram on the
 * Store API — its connection (the switch, its address, the bot's menu button), how it looks, and the app itself as a
 * customer sees it (its preview, which opens again as a card saves — counted here, not by the read, which a reconnect
 * reads again). One read feeds every card; each save answers the whole app, which replaces the read — with why Telegram
 * did not take the bot's menu button, said above the cards while it stands (the save itself stood).
 *
 * `appSettings`: where the panel sets the shop's address (the owner's), which must be https for Telegram; an agent has none.
 */
export function MiniAppSettingsPage({ appSettings }: { appSettings?: string }) {
  const read = useQuery(miniAppQuery)
  const { data, refetch } = read
  const failure = useReadFailure(read)
  const [saves, setSaves] = useState(0)
  const saved = useCallback(() => setSaves((count) => count + 1), [])

  return (
    <MiniAppSavedContext.Provider value={saved}>
      <SectionedPage
        sections={MINI_APP_SETTINGS_SECTIONS}
        width="narrow"
        header={() => ({ title: 'مینی‌اپ تلگرام', description: 'مشتری مینی‌اپ را از دکمه‌های ربات باز می‌کند و همان حسابی را می‌بیند که در ربات و وب‌سایت دارد.' })}
        above={() => (
          <>
            {failure.error && <ErrorState what="تنظیمات مینی‌اپ" error={failure.error} onRetry={() => void refetch()} retrying={failure.retrying} />}
            {!data && !failure.error && <Skeleton className="h-64 w-full rounded-xl" />}
            {data?.menu_button_error && (
              <Callout tone="warning" icon={CircleAlert} role="status">
                تنظیمات ذخیره شد، ولی {data.menu_button_error}
              </Callout>
            )}
          </>
        )}
      >
        {data
          ? {
              connection: <ConnectionSection miniApp={data.mini_app} appSettings={appSettings} />,
              appearance: <AppearanceSection miniApp={data.mini_app} />,
              preview: <PreviewSection miniApp={data.mini_app} saves={saves} />,
            }
          : EMPTY}
      </SectionedPage>
    </MiniAppSavedContext.Provider>
  )
}

const EMPTY = { connection: null, appearance: null, preview: null }
