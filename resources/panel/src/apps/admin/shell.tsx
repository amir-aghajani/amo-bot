import { useQuery } from '@tanstack/react-query'
import { ADMIN_NAV } from '@/apps/admin/nav'
import { ShopSwitcher } from '@/apps/admin/shop-switcher'
import { agencySummaryQuery } from '@/components/agency/queries'
import type { PanelShell } from '@/components/shell/shell-context'
import { CountBadge } from '@/components/status-badge'

/** The agency requests nobody has decided, beside «درخواست‌ها» — read with the agents page's own query, on that column only. */
function PendingRequests({ area, section }: { area: string; section: string }) {
  const shown = area === 'agents' && section === 'requests'
  const { data } = useQuery({ ...agencySummaryQuery, enabled: shown })
  const pending = shown ? (data?.summary.pending ?? 0) : 0

  return pending > 0 && <CountBadge count={pending} tone="warning" label="در انتظار" />
}

/** The owner's panel in the shared shell: their navigation, the shop picker under the brand, the agency's pending requests beside their section. */
export const ADMIN_SHELL: PanelShell = {
  nav: ADMIN_NAV,
  role: 'مالک فروشگاه',
  picker: ShopSwitcher,
  sectionBadge: PendingRequests,
}
