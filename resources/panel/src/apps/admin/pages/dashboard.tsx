import { SystemCard } from '@/components/dashboard/system-card'
import { TextLink } from '@/components/text-link'
import { DashboardPage } from '@/pages/dashboard'

/** The owner's overview: the shop's dashboard with the system card beside it, and where to fix an agent's traffic shortage. */
export function OwnerDashboardPage() {
  return (
    <DashboardPage
      aside={<SystemCard />}
      trafficHelp={
        <>
          حجم ربات را از{' '}
          <TextLink inline to="/agents/list">
            نمایندگان
          </TextLink>{' '}
          تنظیم کنید.
        </>
      }
    />
  )
}
