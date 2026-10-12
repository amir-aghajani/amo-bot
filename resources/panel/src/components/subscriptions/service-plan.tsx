import { Badge } from '@/components/ui/badge'
import type { SubscriptionRow } from '@/lib/api-types'

/** The line under a service's name in a list: its plan, the «تست» mark of a free test account, or that its plan is gone. */
export function ServicePlan({ subscription }: { subscription: Pick<SubscriptionRow, 'is_test' | 'plan'> }) {
  if (subscription.is_test) {
    return (
      <Badge variant="info" title="اکانت تست رایگان">
        تست
      </Badge>
    )
  }

  return <span className="text-footnote text-muted-foreground">{subscription.plan?.name ?? 'پلن حذف شده'}</span>
}
