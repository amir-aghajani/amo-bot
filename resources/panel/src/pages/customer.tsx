import { useQuery, useQueryClient } from '@tanstack/react-query'
import { EllipsisVertical } from 'lucide-react'
import { useParams } from 'react-router'
import { BackLink } from '@/components/back-link'
import { OrdersCard } from '@/components/customer/orders-card'
import { PaymentsCard } from '@/components/customer/payments-card'
import { AgencyCard, GroupsCard, ReferralCard, WalletCard } from '@/components/customer/profile-cards'
import { ServicesCard } from '@/components/customer/services-card'
import { TicketsCard } from '@/components/customer/tickets-card'
import { WebsiteAccountCard } from '@/components/customer/website-account-card'
import { ErrorState } from '@/components/error-state'
import { NotFoundPage } from '@/components/not-found'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { StatusBadge } from '@/components/status-badge'
import { shopServerChoices, type ServerChoices } from '@/components/subscriptions/server-choices'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import { TelegramChatLink, userLabel } from '@/components/user-identity'
import { AccountChangeConfirm, AccountMenuItems, AdminMark, useAccountChange } from '@/components/users/account-actions'
import type { AgentRow, UserDetailResponse, UserRow } from '@/lib/api-types'
import { useTitleSubject } from '@/lib/document-title'
import { formatDate, timeAgo } from '@/lib/format'
import { customerQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { USER_STATUS } from '@/lib/statuses'

export interface CustomerPageProps {
  /** Where the panel reads the servers a service moves to — the shop's, unless it reads more (the owner's). */
  servers?: ServerChoices
  /** A server's own page, in a panel that has one (the owner's): a service's server links there. */
  serverPage?: (id: number) => string
  /** The traffic the shop's bot may still sell, where the panel reads it (an agent's own): what extending a service takes its GB from. */
  traffic?: number
  /** Where an agent is managed, in a panel that has the place (the owner's agents list): the agency card links there. */
  agentPage?: (agent: AgentRow) => string
}

/**
 * One customer of the shop (`/users/:id`, both panels), as support answers them: who they are — the three identifiers
 * apart —, their account (ban, the bot's admin role), their latest services, payments and orders (each opening its own
 * screen's dialog, and the way to all of them on that screen, narrowed to the customer) and support tickets (each leading
 * to its own page, and the way to all of them on the tickets screen), their wallet, what the referral
 * program knows of them, their groups, their account on the shop's website (its ways in, two-factor sign-in, the devices
 * signed in, the accounts merged into it), and an agent's agency. Another shop's customer is not found. Another
 * customer's page starts afresh: nothing of one — a draft, an open dialog — is left on another.
 */
export function CustomerPage(props: CustomerPageProps) {
  const id = Number(useParams().id)
  if (!Number.isSafeInteger(id) || id <= 0) return <NotFoundPage />

  return <CustomerView key={id} id={id} {...props} />
}

function CustomerView({ id, servers = shopServerChoices, serverPage, traffic, agentPage }: CustomerPageProps & { id: number }) {
  const queryClient = useQueryClient()
  const detail = useQuery(customerQuery(id))
  useTitleSubject(detail.data && userLabel(detail.data.user))

  /** The customer's row as an operation handed it back: on their page at once. */
  const changed = (row: UserRow) => queryClient.setQueryData<UserDetailResponse>(queryKeys.user(id), (current) => current && { ...current, user: row })
  // What changes the customer here shows on the users table too: it is read again. (A service's, an order's or a
  // payment's operation reads this page again itself — its dialog names the customer's page among what it changes.)
  const elsewhere = [queryKeys.users]
  const account = useAccountChange(changed, elsewhere)

  if (detail.isPending) {
    return (
      <Page width="default">
        <div className="grid gap-3" aria-busy="true" aria-label="در حال بارگذاری مشتری">
          <BackLink to="/users">کاربران</BackLink>
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-4 w-80 max-w-full" />
        </div>
        <div className="grid gap-4 xl:grid-cols-3">
          <Skeleton className="h-72 w-full rounded-xl xl:col-span-2" />
          <Skeleton className="h-72 w-full rounded-xl" />
        </div>
      </Page>
    )
  }

  const failure = detail.error && <ErrorState what="مشتری" error={detail.error} onRetry={() => void detail.refetch()} retrying={detail.isFetching} />

  if (!detail.data) {
    return (
      <Page width="default">
        <div className="grid gap-3">
          <BackLink to="/users">کاربران</BackLink>
          <PageHeader title="مشتری" />
        </div>
        {failure}
      </Page>
    )
  }

  const { user, referral, agency, account: website } = detail.data

  return (
    <Page width="default">
      <div className="grid gap-3">
        <BackLink to="/users">کاربران</BackLink>
        <PageHeader
          title={
            // The spaces keep the name and its marks words apart for assistive tech, not one.
            <span className="flex flex-wrap items-center gap-2">
              {user.name ? <bdi>{user.name}</bdi> : <span className="text-faint">بدون نام</span>} <StatusBadge status={USER_STATUS[user.status]} /> {user.role === 'admin' && <AdminMark />}{' '}
              {agency && <Badge variant="outline">نماینده</Badge>}
            </span>
          }
          description={<Identity user={user} />}
          actions={
            <>
              <Button asChild variant="secondary">
                <TelegramChatLink user={user} />
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="secondary" size="icon" icon={EllipsisVertical} aria-label="حساب مشتری" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <AccountMenuItems user={user} account={account} />
                </DropdownMenuContent>
              </DropdownMenu>
            </>
          }
        />
      </div>

      {/* A later read that failed leaves the page as it was, under the failure. */}
      {failure}

      <div className="grid gap-4 xl:grid-cols-3">
        <div className="grid min-w-0 content-start gap-4 xl:col-span-2">
          <ServicesCard customer={user} servers={servers} serverPage={serverPage} traffic={traffic} />
          <PaymentsCard customer={user} />
          <OrdersCard customer={user} />
          <TicketsCard customer={user} />
        </div>

        <div className="grid min-w-0 content-start gap-4">
          <WalletCard customer={user} credit={agency?.credit_limit ?? null} invalidates={elsewhere} onChanged={changed} />
          <ReferralCard customer={user} referral={referral} />
          {/* The groups' counts move too. */}
          <GroupsCard customer={user} invalidates={[...elsewhere, queryKeys.customerGroups]} onSaved={changed} />
          <WebsiteAccountCard customer={user} account={website} />
          {agency && <AgencyCard agent={agency} agentPage={agentPage} />}
        </div>
      </div>

      <AccountChangeConfirm account={account} />
    </Page>
  )
}

/**
 * Under the name: the customer's number in the shop («#12» in the users list's search is them alone), the handle and
 * the Telegram id — never blended with it; none for a customer who signed up on the website —, their email, the
 * verified phone, when they came and were last seen.
 */
function Identity({ user }: { user: UserRow }) {
  return (
    <>
      <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
        <span>
          مشتری{' '}
          <bdi dir="ltr" className="text-foreground">
            #{user.id}
          </bdi>
        </span>
        {user.telegram_id === null ? (
          <span>بدون تلگرام</span>
        ) : (
          <>
            {user.username ? (
              <bdi dir="ltr" className="text-foreground">
                @{user.username}
              </bdi>
            ) : (
              <span>بدون نام کاربری</span>
            )}
            <span>
              شناسه تلگرام{' '}
              <bdi dir="ltr" className="text-foreground">
                {user.telegram_id}
              </bdi>
            </span>
          </>
        )}
        {user.email && (
          <span>
            ایمیل{' '}
            <bdi dir="ltr" className="text-foreground">
              {user.email}
            </bdi>
          </span>
        )}
        {user.phone && (
          <span>
            موبایل{' '}
            <bdi dir="ltr" className="text-foreground">
              {user.phone}
            </bdi>
          </span>
        )}
      </span>
      <span className="mt-1 block text-footnote">
        عضویت {formatDate(user.created_at, { dateStyle: 'medium' })} · آخرین بازدید {user.last_seen_at ? timeAgo(user.last_seen_at) : 'هنوز نه'}
      </span>
    </>
  )
}
