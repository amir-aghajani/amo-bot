import { useQuery } from '@tanstack/react-query'
import { BUY_TRAFFIC } from '@/apps/agent/buy-traffic'
import { accountQuery } from '@/apps/agent/queries'
import { renewWords, SellingNotice } from '@/components/agency/agency-standing'
import { useShellReads } from '@/components/shell/shell-context'
import { DashboardPage } from '@/pages/dashboard'

/** What the notice says of renewing while their account is still being read: true whether a request waits or not. */
const RENEW_UNREAD = 'برای تمدید، «حساب نمایندگی» را ببینید.'

/**
 * The agent's overview: the shop's dashboard, their bot's traffic shortage and terms of selling said to them — of
 * renewing, by their account as the shell's strip reads it (one read for both, never read again for this page while it
 * is kept): a request waiting, no button to send them to.
 */
export function AgentDashboardPage() {
  const read = useQuery({ ...accountQuery, enabled: useShellReads(), refetchOnMount: false })
  const account = read.data?.account
  const renew = account ? renewWords(account) : read.isError ? undefined : RENEW_UNREAD

  return <DashboardPage trafficHelp={BUY_TRAFFIC} standingNotice={(standing) => <SellingNotice standing={standing} renew={renew} />} />
}
