import { OwnerSellingNotice } from '@/components/agency/agency-standing'
import { SystemCard } from '@/components/dashboard/system-card'
import { TextLink } from '@/components/text-link'
import { DashboardPage } from '@/pages/dashboard'

/** The agents list, where an agent's traffic and terms of selling are set. */
const AGENTS = (
  <TextLink inline to="/agents/list">
    نمایندگان
  </TextLink>
)

/**
 * The owner's overview: the shop's dashboard with the system card beside it — and, in an agent's shop, the agent's
 * traffic shortage and terms of selling said of the agent, with where to see to them.
 */
export function OwnerDashboardPage() {
  return (
    <DashboardPage
      aside={<SystemCard />}
      trafficHelp={<>حجم ربات را از {AGENTS} تنظیم کنید.</>}
      standingNotice={(standing) => <OwnerSellingNotice standing={standing} help={<>از {AGENTS} به آن رسیدگی کنید.</>} />}
    />
  )
}
