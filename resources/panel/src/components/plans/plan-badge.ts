import type { PlanBadge } from '@/lib/api-types'

/** The labels a plan's card may wear in the mini app and on the website, in the words the card shows (PlanBadge::label()). */
export const PLAN_BADGES: Record<PlanBadge, string> = {
  bestseller: 'پرفروش‌ترین',
  recommended: 'پیشنهاد ما',
  vip: 'VIP',
}

/** A badge picked in the form, as the plan keeps it; '' — none — for anything else. */
export function badgeOf(value: string): PlanBadge | '' {
  return Object.keys(PLAN_BADGES).find((badge): badge is PlanBadge => badge === value) ?? ''
}
