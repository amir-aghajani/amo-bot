import { SystemCard } from '@/components/dashboard/system-card'
import { TextLink } from '@/components/text-link'
import { DashboardPage } from '@/pages/dashboard'

/**
 * The owner's overview: the shop's, with the machinery behind it (the bot, the scheduler) beside its queues — and, an
 * agent's shop open whose traffic sells nothing, where its traffic is set right.
 */
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
