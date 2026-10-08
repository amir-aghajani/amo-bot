import { Users } from 'lucide-react'
import { Balance } from '@/components/balance'
import { EmptyState } from '@/components/empty-state'
import { Dash } from '@/components/list-view'
import { TextLink } from '@/components/text-link'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { UserIdentity, userPage } from '@/components/user-identity'
import type { RecentUser } from '@/lib/api-types'
import { timeAgo } from '@/lib/format'

/**
 * The customers who joined last — each name the way to their page —, as a section of the overview with a link to the
 * users; «—» when they could not be read (`users` undefined, no longer `loading`).
 */
export function RecentUsers({ users, loading }: { users: RecentUser[] | undefined; loading?: boolean }) {
  return (
    <section aria-labelledby="recent-users" className="grid gap-3">
      <div className="flex items-center justify-between gap-3 px-1">
        <h2 id="recent-users" className="text-heading font-semibold">
          مشتریان جدید
        </h2>
        <TextLink to="/users" inline className="text-footnote whitespace-nowrap">
          مشاهده همه
        </TextLink>
      </div>
      <Card className="p-2">
        {loading ? (
          <div className="grid gap-1.5 p-2">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-11 w-full" />
            ))}
          </div>
        ) : !users ? (
          <div className="grid place-items-center py-6">
            <Dash />
          </div>
        ) : users.length === 0 ? (
          <EmptyState compact icon={Users} title="هنوز کسی ثبت‌نام نکرده است" description="کاربرانی که به ربات /start بزنند این‌جا ظاهر می‌شوند." />
        ) : (
          <ul className="grid gap-px sm:grid-cols-2">
            {users.map((user) => (
              <li key={user.id} className="flex items-center gap-3 rounded-lg px-2.5 py-2 transition-colors hover:bg-fill">
                <UserIdentity user={user} avatar to={userPage(user.id)} className="flex-1" />
                <div className="text-end">
                  <Balance balance={user.balance} className="block text-body whitespace-nowrap" />
                  <div className="text-footnote whitespace-nowrap text-faint">{timeAgo(user.created_at)}</div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </section>
  )
}
