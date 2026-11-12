import { useQuery } from '@tanstack/react-query'
import { keys } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { store } from '@/apps/app/lib/store'
import { Banners } from '@/apps/app/screens/home/parts/banners'
import { Greeting } from '@/apps/app/screens/home/parts/greeting'
import { ServiceCarousel } from '@/apps/app/screens/home/parts/service-carousel'
import { Shortcuts } from '@/apps/app/screens/home/parts/shortcuts'
import { WalletCard } from '@/apps/app/screens/home/parts/wallet-card'
import { useAppActive } from '@/apps/app/telegram/active'
import { Screen } from '@/apps/app/ui/screen'
import { ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import type { StoreAccountResponse } from '@/lib/api-types'

/*
 * «خانه», the app's first tab: a hello under the shop's logo with the bell beside it, the customer's running services,
 * their wallet, the shop's banners and the shortcuts to every place of the app. Each part reads on its own and holds its
 * place with a skeleton meanwhile, so a slow read never keeps the rest from showing.
 */

/** How often the bell's count is read again while the home screen is open and the app in front. */
const BELL_MS = 60_000

export default function HomeScreen() {
  const shop = useShop()
  const active = useAppActive()
  // GET /me, read again every minute for the bell while the app is in front (and when it comes back into view).
  const account = useQuery({ queryKey: keys.me, queryFn: () => store.get<StoreAccountResponse>('/me'), refetchInterval: active ? BELL_MS : false })

  if (shop.isError) {
    return (
      <Screen bottom="tabs">
        <ErrorView error={shop.error} retry={() => void shop.refetch()} />
      </Screen>
    )
  }
  if (shop.data === undefined) {
    return (
      <Screen bottom="tabs">
        <LoadingBlocks count={4} />
      </Screen>
    )
  }

  const customer = account.data?.customer

  return (
    <Screen bottom="tabs">
      <Greeting title={shop.data.app?.title ?? shop.data.shop.name} logo={shop.data.app?.logo_url ?? null} firstName={customer?.first_name ?? null} unread={account.data?.unread_notifications ?? 0} />
      {!shop.data.shop.taking_orders && <Notice tone="warning">فروشگاه فعلا سفارش تازه نمی‌گیرد؛ سرویس‌ها و کیف پول شما مثل همیشه در دسترس است.</Notice>}
      <ServiceCarousel />
      <WalletCard balance={customer?.balance} />
      <Banners shop={shop.data} />
      <Shortcuts shop={shop.data} />
    </Screen>
  )
}
