import type { StoreResponse } from '@/lib/api-types'

/*
 * Which of the shop's parts the home and account screens lead to: the customer club while any of its programs runs,
 * and the reviews while the shop shows them in its app.
 */

/** Whether «باشگاه مشتریان» has anything to show: loyalty tiers, cashback, the wheel, the nightly draw or invitations. */
export function clubOpen(shop: StoreResponse): boolean {
  const { programs } = shop.shop

  return programs.loyalty || programs.cashback || programs.wheel || programs.lottery || shop.referral.enabled
}

/** Whether the shop shows its reviews in the app: `GET /`'s word on this door's reviews (`programs.reviews`). */
export function reviewsOpen(shop: StoreResponse): boolean {
  return shop.shop.programs.reviews
}
