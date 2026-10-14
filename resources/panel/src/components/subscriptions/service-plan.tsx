import { Badge } from '@/components/ui/badge'
import type { SubscriptionRow } from '@/lib/api-types'

/** What a service added by its link is called, as its mark's tooltip and the kind filter say it. */
export const CLAIMED = 'افزوده‌شده با لینک'

/** A service added by its link and not renewed yet: no plan — the customer picks one when renewing it (BotText::NoPlanName). */
export const PLAN_AT_RENEWAL = 'بدون پلن'

/** A service its customer built («سرویس دلخواه»): no plan, and never added by its link (BotText::CustomPlanName). */
export const BUILT = 'سرویس دلخواه'

/**
 * The line under a service's name in a list: its plan, the «تست» mark of a free test account, or — without a plan — what
 * the bot's service card says in its place (BUILT, PLAN_AT_RENEWAL). A service the customer added by its link is marked
 * «با لینک».
 */
export function ServicePlan({ subscription }: { subscription: Pick<SubscriptionRow, 'is_test' | 'plan' | 'claimed_at'> }) {
  if (subscription.is_test) {
    return (
      <Badge variant="info" title="اکانت تست رایگان">
        تست
      </Badge>
    )
  }

  const plan = <span className="text-footnote text-muted-foreground">{subscription.plan?.name ?? (subscription.claimed_at === null ? BUILT : PLAN_AT_RENEWAL)}</span>
  if (subscription.claimed_at === null) return plan

  return (
    <span className="flex flex-wrap items-center gap-1.5">
      <Badge variant="info" title={CLAIMED}>
        با لینک
      </Badge>
      {plan}
    </span>
  )
}
