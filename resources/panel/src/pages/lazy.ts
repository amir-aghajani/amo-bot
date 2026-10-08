import { lazyPage } from '@/lib/lazy-page'

/* The shop's pages both panels mount, each its own chunk (lib/lazy-page); a panel adds its own pages beside them. */

export const BotSettingsPage = lazyPage(
  () => import('@/pages/bot-settings'),
  (module) => module.BotSettingsPage,
)
export const BotTextsPage = lazyPage(
  () => import('@/pages/bot-texts'),
  (module) => module.BotTextsPage,
)
export const CustomerPage = lazyPage(
  () => import('@/pages/customer'),
  (module) => module.CustomerPage,
)
export const DashboardPage = lazyPage(
  () => import('@/pages/dashboard'),
  (module) => module.DashboardPage,
)
export const KeyboardsPage = lazyPage(
  () => import('@/pages/keyboards'),
  (module) => module.KeyboardsPage,
)
export const OrdersPage = lazyPage(
  () => import('@/pages/orders'),
  (module) => module.OrdersPage,
)
export const PaymentMethodsPage = lazyPage(
  () => import('@/pages/payment-methods'),
  (module) => module.PaymentMethodsPage,
)
export const PaymentsPage = lazyPage(
  () => import('@/pages/payments'),
  (module) => module.PaymentsPage,
)
export const PlanCategoriesPage = lazyPage(
  () => import('@/pages/plan-categories'),
  (module) => module.PlanCategoriesPage,
)
export const PlansPage = lazyPage(
  () => import('@/pages/plans'),
  (module) => module.PlansPage,
)
export const ReferralsPage = lazyPage(
  () => import('@/pages/referrals'),
  (module) => module.ReferralsPage,
)
export const ReviewsPage = lazyPage(
  () => import('@/pages/reviews'),
  (module) => module.ReviewsPage,
)
export const SubscriptionsPage = lazyPage(
  () => import('@/pages/subscriptions'),
  (module) => module.SubscriptionsPage,
)
export const TicketPage = lazyPage(
  () => import('@/pages/ticket'),
  (module) => module.TicketPage,
)
export const TicketsPage = lazyPage(
  () => import('@/pages/tickets'),
  (module) => module.TicketsPage,
)
export const UsersPage = lazyPage(
  () => import('@/pages/users'),
  (module) => module.UsersPage,
)
export const WebsiteSettingsPage = lazyPage(
  () => import('@/pages/website-settings'),
  (module) => module.WebsiteSettingsPage,
)
