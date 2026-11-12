import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { SearchX, WalletMinimal } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { rememberedCheckout } from '@/apps/app/lib/checkout'
import { keys, queryClient } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { OnlinePayment } from '@/apps/app/screens/pay/parts/gateway'
import { orderNumber, orderSubject } from '@/apps/app/screens/pay/parts/order-words'
import { Closed, Delivered, Failed, OrderFacts, Outcome, PayAgain, Underway } from '@/apps/app/screens/pay/parts/outcome'
import { CardTransfer } from '@/apps/app/screens/pay/parts/transfer'
import { idOf } from '@/apps/app/screens/support/parts/words'
import { useAppActive } from '@/apps/app/telegram/active'
import { result } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Empty, ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import { ApiError } from '@/lib/api'
import type { OrderStatus, StoreCheckout, StoreOrder, StoreOrderResponse } from '@/lib/api-types'

/*
 * An order's payment (`/pay/:orderId`), opened in place of the screen that ordered it. What the checkout answered
 * (lib/checkout keeps it for the order: the card to transfer to is answered there alone) and the order as it stands
 * (`GET /orders/{id}`, read again every few seconds while something is awaited and the app is in front) decide what it
 * shows: the service delivered, a delivery under way, a card to pay and the receipt to send, a gateway's page to pay on,
 * or why the order ended as it did. Back leads home — or to the services, or the wallet, it was about —, never into the
 * checkout.
 */

/** An order's states that wait for nothing more. */
const ENDED: readonly OrderStatus[] = ['fulfilled', 'failed', 'cancelled', 'refunded']

/** How often the order is read again: often while a gateway or a delivery is awaited, now and then while support has it (a receipt, a payment held), never once it ended. */
function watchEvery(order: StoreOrder | undefined): number | false {
  if (order === undefined || ENDED.includes(order.status)) return false
  if (order.status === 'paid' || order.status === 'processing') return 3_000
  const payment = order.payments[0]

  return payment?.method.kind === 'online' && payment.status === 'pending' && !payment.held ? 5_000 : 20_000
}

export default function PayScreen() {
  // An order's number as the address writes it: «0x1F» or «1e2» names no order.
  const orderId = idOf(useParams().orderId) ?? 0
  const navigate = useNavigate()
  const known = orderId > 0
  const [remembered] = useState(() => (known ? rememberedCheckout(orderId) : null))
  const active = useAppActive()
  const query = useQuery({
    queryKey: keys.order(orderId),
    queryFn: () => store.get<StoreOrderResponse>(`/orders/${orderId}`),
    enabled: known,
    // What the checkout answered stands until the order is read (at once: it may have moved on since).
    initialData: remembered === null ? undefined : { order: remembered.order },
    initialDataUpdatedAt: 0,
    refetchInterval: (current) => (active ? watchEvery(current.state.data?.order) : false),
  })
  const order = query.data?.order
  useEndNews(order?.status)

  if (!known || (query.error instanceof ApiError && query.error.status === 404)) {
    return (
      <Screen back="/">
        <Empty icon={SearchX} title="این سفارش پیدا نشد" action={<Button onClick={() => navigate('/', { replace: true })}>بازگشت به خانه</Button>} />
      </Screen>
    )
  }
  if (order === undefined) {
    return (
      <Screen back="/" title="پرداخت">
        {query.isError ? <ErrorView error={query.error} retry={() => void query.refetch()} /> : <LoadingBlocks count={3} />}
      </Screen>
    )
  }

  return (
    <Screen back={order.type === 'wallet_topup' ? '/wallet' : order.subscription !== null ? '/services' : '/'} title={`سفارش ${orderNumber(order.id)}`} subtitle={orderSubject(order)} bottom="action">
      <Payment order={order} remembered={remembered} />
    </Screen>
  )
}

/** As the order the screen watches ends, the phone says how, and what it changed is read again. */
function useEndNews(status: OrderStatus | undefined): void {
  const last = useRef(status)
  useEffect(() => {
    const before = last.current
    last.current = status
    if (before === undefined || status === undefined || before === status || !ENDED.includes(status)) return
    result(status === 'fulfilled' ? 'success' : 'warning')
    void queryClient.invalidateQueries({ queryKey: keys.subscriptions })
    void queryClient.invalidateQueries({ queryKey: keys.wallet })
    void queryClient.invalidateQueries({ queryKey: keys.me })
  }, [status])
}

/** The order's payment, as it stands. */
function Payment({ order, remembered }: { order: StoreOrder; remembered: StoreCheckout | null }) {
  switch (order.status) {
    case 'fulfilled':
      return <Delivered order={order} link={remembered?.subscription?.link ?? null} />
    case 'paid':
    case 'processing':
      return <Underway order={order} />
    case 'failed':
      return <Failed order={order} />
    case 'cancelled':
    case 'refunded':
      return <Closed order={order} />
    case 'pending':
      break
  }

  const payment = order.payments[0]
  if (payment?.method.kind === 'manual') {
    const transfer = remembered?.transfer
    return <CardTransfer order={order} payment={payment} transfer={transfer !== undefined && transfer !== null && transfer.payment_id === payment.id ? transfer : null} />
  }
  if (payment?.method.kind === 'online') return <OnlinePayment order={order} payment={payment} />

  // No payment yet, or the wallet's refused: the order is still to be paid.
  return (
    <>
      <Outcome tone="warning" icon={WalletMinimal} title="این سفارش هنوز پرداخت نشده است" />
      {payment !== undefined && payment.note !== null && <Notice tone="warning">{payment.note}</Notice>}
      <OrderFacts order={order} />
      <PayAgain order={order} />
    </>
  )
}
