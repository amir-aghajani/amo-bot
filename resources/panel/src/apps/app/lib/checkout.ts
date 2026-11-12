import type { NavigateFunction } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import type { StoreCheckout } from '@/lib/api-types'

/*
 * What a checkout answered, handed from the screen that ordered (a plan, a built service, a renewal, extras, a top-up)
 * to the payment screen (`/pay/:orderId`). The answer holds what is shown once — the card to transfer to, the gateway's
 * page —, so it is kept for the order in this tab's storage: going back to the payment, or a reload, shows it again. A
 * checkout made again with its Idempotency-Key answers the same card and page.
 */

const PREFIX = 'amobot-app:checkout:'

/** Keep the checkout for its order. */
export function rememberCheckout(checkout: StoreCheckout): void {
  try {
    sessionStorage.setItem(PREFIX + checkout.order.id, JSON.stringify(checkout))
  } catch {
    // Storage refused: the payment screen reads the order alone.
  }
}

/** The checkout kept for an order; null when none is (another tab, or the storage refused). */
export function rememberedCheckout(orderId: number): StoreCheckout | null {
  try {
    const kept = sessionStorage.getItem(PREFIX + orderId)
    return kept === null ? null : (JSON.parse(kept) as StoreCheckout)
  } catch {
    return null
  }
}

/**
 * After an ordering request: keep its checkout, refresh what it changes (the wallet, the orders, the services), and open
 * its payment screen in place of the ordering screen.
 */
export function goPay(navigate: NavigateFunction, checkout: StoreCheckout): void {
  rememberCheckout(checkout)
  void queryClient.invalidateQueries({ queryKey: keys.me })
  void queryClient.invalidateQueries({ queryKey: keys.wallet })
  void queryClient.invalidateQueries({ queryKey: keys.orders })
  void queryClient.invalidateQueries({ queryKey: keys.subscriptions })
  navigate(`/pay/${checkout.order.id}`, { replace: true })
}
