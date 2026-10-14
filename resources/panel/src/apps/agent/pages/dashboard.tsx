import { BUY_TRAFFIC } from '@/apps/agent/buy-traffic'
import { SellingNotice } from '@/components/agency/agency-standing'
import { DashboardPage } from '@/pages/dashboard'

/** The agent's overview: the shop's dashboard, their bot's traffic shortage and terms of selling said to them. */
export function AgentDashboardPage() {
  return <DashboardPage trafficHelp={BUY_TRAFFIC} standingNotice={(standing) => <SellingNotice standing={standing} />} />
}
