import { useQuery } from '@tanstack/react-query'
import type { RouteObject } from 'react-router'
import { BUY_TRAFFIC } from '@/apps/agent/buy-traffic'
import { AGENT_NAV } from '@/apps/agent/nav'
import { accountQuery } from '@/apps/agent/queries'
import { AppReady, Installation } from '@/components/app-status'
import { ErrorState } from '@/components/error-state'
import { NotFoundPage } from '@/components/not-found'
import { AppShell } from '@/components/shell/app-shell'
import type { PanelShell } from '@/components/shell/shell-context'
import { RequireAuth } from '@/lib/auth'
import { lazyPage } from '@/lib/lazy-page'
import {
  BotSettingsPage,
  BotTextsPage,
  CustomerPage,
  DashboardPage,
  KeyboardsPage,
  OrdersPage,
  PaymentMethodsPage,
  PaymentsPage,
  PlanCategoriesPage,
  PlansPage,
  ReferralsPage,
  ReviewsPage,
  SubscriptionsPage,
  TicketPage,
  TicketsPage,
  UsersPage,
  WebsiteSettingsPage,
} from '@/pages/lazy'

/*
 * An agent's panel (/agent/): their bot's shop — the shop's pages both panels have —, their account with the main bot
 * and the panel's look. They sign in only with the one-time link their account in the main bot gives them. Every page is
 * its own chunk.
 */

const AccountPage = lazyPage(
  () => import('@/apps/agent/pages/account'),
  (module) => module.AccountPage,
)
const BroadcastsPage = lazyPage(
  () => import('@/pages/broadcasts'),
  (module) => module.BroadcastsPage,
)
const LoginPage = lazyPage(
  () => import('@/apps/agent/pages/login'),
  (module) => module.LoginPage,
)
const SettingsPage = lazyPage(
  () => import('@/apps/agent/pages/settings'),
  (module) => module.SettingsPage,
)

const AGENT_SHELL: PanelShell = { nav: AGENT_NAV, role: 'نماینده' }

/**
 * An agent's routes, under the panels' root (root.tsx): before the shop is installed there is no agent, and nothing to
 * sign in to — every address says so.
 */
export const AGENT_ROUTES: RouteObject[] = [
  {
    element: <AppReady />,
    children: [
      {
        element: (
          <Installation
            done
            otherwise={<ErrorState variant="screen" kind="unavailable" title="فروشگاه هنوز راه‌اندازی نشده است" description="پنل نمایندگی بعد از نصب فروشگاه باز می‌شود؛ کمی بعد دوباره سر بزنید." />}
          />
        ),
        children: [
          { path: 'login', element: <LoginPage /> },
          {
            element: <RequireAuth />,
            children: [
              {
                element: <AppShell panel={AGENT_SHELL} />,
                children: [
                  { index: true, element: <DashboardPage trafficHelp={BUY_TRAFFIC} /> },
                  { path: 'tickets', element: <TicketsPage /> },
                  { path: 'tickets/:id', element: <TicketPage /> },
                  { path: 'reviews', element: <ReviewsPage /> },
                  { path: 'account', element: <AccountPage /> },
                  { path: 'plans', element: <PlansPage /> },
                  { path: 'categories', element: <PlanCategoriesPage /> },
                  { path: 'payment-methods', element: <PaymentMethodsPage /> },
                  { path: 'users', element: <UsersPage /> },
                  { path: 'users/groups', element: <UsersPage /> },
                  { path: 'users/:id', element: <AgentCustomerPage /> },
                  { path: 'orders', element: <OrdersPage /> },
                  { path: 'payments', element: <PaymentsPage /> },
                  { path: 'subscriptions', element: <AgentSubscriptionsPage /> },
                  { path: 'referrals/:section?', element: <ReferralsPage /> },
                  { path: 'keyboards', element: <KeyboardsPage /> },
                  { path: 'bot-texts', element: <BotTextsPage /> },
                  { path: 'broadcasts', element: <BroadcastsPage /> },
                  { path: 'bot-settings/:section?', element: <BotSettingsPage /> },
                  { path: 'website-settings/:section?', element: <WebsiteSettingsPage /> },
                  { path: 'settings/:section?', element: <SettingsPage /> },
                  { path: '*', element: <NotFoundPage /> },
                ],
              },
            ],
          },
        ],
      },
    ],
  },
]

/** The traffic the agent's bot has left: what extending a service takes its GB from. */
function useBotTraffic(): number | undefined {
  return useQuery({ ...accountQuery, select: (data) => data.account.bot.traffic_balance }).data
}

/** The shop's subscriptions screen, told the traffic the agent's bot has left. */
function AgentSubscriptionsPage() {
  return <SubscriptionsPage traffic={useBotTraffic()} />
}
// Loaded ahead as the page it draws is (lib/prefetch).
AgentSubscriptionsPage.preload = SubscriptionsPage.preload

/** A customer's page, told the traffic the agent's bot has left (their services are extended from it). */
function AgentCustomerPage() {
  return <CustomerPage traffic={useBotTraffic()} />
}
AgentCustomerPage.preload = CustomerPage.preload
