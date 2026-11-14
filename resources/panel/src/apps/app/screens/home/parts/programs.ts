import type { StoreAccountResponse, StoreResponse } from '@/lib/api-types'

/*
 * Which of the shop's parts the home and account screens lead to: the customer club while any of its programs runs,
 * and the reviews while the shop shows them in its app.
 */

/**
 * Whether the customers' perks reach the customer (`GET /me`'s `perks`): no sale, loyalty tier, code, cashback, wheel or
 * draw for one who pays agent prices while the shop withholds them. True until their account says otherwise.
 */
export function perksReach(account: StoreAccountResponse | undefined): boolean {
  return account?.perks !== false
}

/**
 * Whether «باشگاه مشتریان» has anything to show: invitations, or — while the perks reach the customer — loyalty tiers,
 * cashback, the wheel or the nightly draw.
 */
export function clubOpen(shop: StoreResponse, perks = true): boolean {
  const { programs } = shop.shop

  return (perks && (programs.loyalty || programs.cashback || programs.wheel || programs.lottery)) || shop.referral.enabled
}

/** Whether the shop shows its reviews in the app: `GET /`'s word on this door's reviews (`programs.reviews`). */
export function reviewsOpen(shop: StoreResponse): boolean {
  return shop.shop.programs.reviews
}
