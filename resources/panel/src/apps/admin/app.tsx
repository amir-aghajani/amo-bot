import { Navigate, type RouteObject } from 'react-router'
import { ADMIN_SHELL } from '@/apps/admin/shell'
import { AppReady, Installation } from '@/components/app-status'
import { NotFoundPage } from '@/components/not-found'
import { AppShell } from '@/components/shell/app-shell'
import type { ServerChoices } from '@/components/subscriptions/server-choices'
import { api } from '@/lib/api'
import type { AgentRow, ServersResponse } from '@/lib/api-types'
import { RequireAuth } from '@/lib/auth'
import { lazyPage } from '@/lib/lazy-page'
import {
  BotSettingsPage,
  BotTextsPage,
  CustomerPage,
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
 * The owner's panel (/admin/): the shop's pages both panels have, and the shop's own — its servers, the agents, the
 * mass gift, the system card on the dashboard, the panel's settings and, before the shop is installed, the installer.
 * Every page is its own chunk.
 */

const AgentsPage = lazyPage(
  () => import('@/apps/admin/pages/agents'),
  (module) => module.AgentsPage,
)
const BroadcastsPage = lazyPage(
  () => import('@/apps/admin/pages/broadcasts'),
  (module) => module.OwnerBroadcastsPage,
)
const DashboardPage = lazyPage(
  () => import('@/apps/admin/pages/dashboard'),
  (module) => module.OwnerDashboardPage,
)
const InstallPage = lazyPage(
  () => import('@/apps/admin/pages/install'),
  (module) => module.InstallPage,
)
const LoginPage = lazyPage(
  () => import('@/apps/admin/pages/login'),
  (module) => module.LoginPage,
)
const RecoverPage = lazyPage(
  () => import('@/apps/admin/pages/recover'),
  (module) => module.RecoverPage,
)
const ServerDetailPage = lazyPage(
  () => import('@/apps/admin/pages/server-detail'),
  (module) => module.ServerDetailPage,
)
const ServersPage = lazyPage(
  () => import('@/apps/admin/pages/servers'),
  (module) => module.ServersPage,
)
const SettingsPage = lazyPage(
  () => import('@/apps/admin/pages/settings'),
  (module) => module.SettingsPage,
)

/** The owner reads the servers themselves for the subscriptions screen: with each one's running services, every shop's. */
const ownerServers: ServerChoices = () =>
  api
    .get<ServersResponse>('/servers')
    .then((data) => data.servers.map((server) => ({ id: server.id, name: server.name, unsellable_reason: server.unsellable_reason, active: server.counts.active_subscriptions })))

const serverPage = (id: number) => `/servers/${id}`

/** An agent on the agents list, found by their Telegram id (else their email): where their level, credit, traffic and agency are managed. */
const agentPage = (agent: AgentRow) => `/agents/list?search=${encodeURIComponent(agent.user.telegram_id ?? agent.user.email ?? '')}`

/**
 * The owner's routes, under the panels' root (root.tsx). Before the shop is installed there is nothing but the installer
 * — every other address goes to it —; once it is, the installer's address goes to the sign-in, and everything else is
 * the panel's, behind the sign-in — but the sign-in itself and its recovery (a lost login set again with a key off the
 * host's files).
 */
export const ADMIN_ROUTES: RouteObject[] = [
  {
    element: <AppReady />,
    children: [
      {
        element: <Installation done={false} otherwise={<Navigate to="/login" replace />} />,
        children: [{ path: 'install', element: <InstallPage /> }],
      },
      {
        element: <Installation done otherwise={<Navigate to="/install" replace />} />,
        children: [
          { path: 'login', element: <LoginPage /> },
          { path: 'recover', element: <RecoverPage /> },
          {
            element: <RequireAuth />,
            children: [
              {
                element: <AppShell panel={ADMIN_SHELL} />,
                children: [
                  { index: true, element: <DashboardPage /> },
                  { path: 'tickets', element: <TicketsPage /> },
                  { path: 'tickets/:id', element: <TicketPage /> },
                  { path: 'reviews', element: <ReviewsPage /> },
                  { path: 'servers', element: <ServersPage /> },
                  { path: 'servers/:id', element: <ServerDetailPage /> },
                  { path: 'plans', element: <PlansPage addServers="/servers" /> },
                  { path: 'categories', element: <PlanCategoriesPage /> },
                  { path: 'payment-methods', element: <PaymentMethodsPage /> },
                  { path: 'users', element: <UsersPage /> },
                  { path: 'users/groups', element: <UsersPage /> },
                  { path: 'users/:id', element: <CustomerPage servers={ownerServers} serverPage={serverPage} agentPage={agentPage} /> },
                  { path: 'orders', element: <OrdersPage /> },
                  { path: 'payments', element: <PaymentsPage /> },
                  { path: 'subscriptions', element: <SubscriptionsPage servers={ownerServers} serverPage={serverPage} /> },
                  { path: 'referrals/:section?', element: <ReferralsPage /> },
                  { path: 'agents/:section?', element: <AgentsPage /> },
                  { path: 'keyboards', element: <KeyboardsPage /> },
                  { path: 'bot-texts', element: <BotTextsPage /> },
                  { path: 'broadcasts/:section?', element: <BroadcastsPage /> },
                  { path: 'bot-settings/:section?', element: <BotSettingsPage /> },
                  { path: 'website-settings/:section?', element: <WebsiteSettingsPage mailSettings="/settings/mail" /> },
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
