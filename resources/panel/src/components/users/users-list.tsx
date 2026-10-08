import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, Tags, Users, Wallet } from 'lucide-react'
import { Balance } from '@/components/balance'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { Dash, ListView, RowMenu, SortableHead } from '@/components/list-view'
import { SearchBox } from '@/components/search-box'
import { useSectionShown } from '@/components/sectioned-page'
import { StatusBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { distinctUserLabel, TelegramChatLink, UserIdentity, userPage } from '@/components/user-identity'
import { AccountChangeConfirm, AccountMenuItems, AdminMark, useAccountChange } from '@/components/users/account-actions'
import { GroupsModal } from '@/components/users/groups-modal'
import { WalletModal } from '@/components/users/wallet-modal'
import { api } from '@/lib/api'
import type { UserRole, UserRow, UserSort, UsersResponse, UserStatus } from '@/lib/api-types'
import { formatNumber, timeAgo } from '@/lib/format'
import { customerGroupsQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { USER_STATUS } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'
import { cn } from '@/lib/utils'

const STATUSES: (UserStatus | '')[] = ['', 'active', 'banned']

/** The role pill's words: the bot's admins — on the shop's website too, while it lets them in —, or everyone else. */
const ROLES: Record<UserRole, string> = { admin: 'مدیران ربات', customer: 'مشتری‌ها' }

/** What the headers sort the customers by — the list's own order, the newest first, leads. */
const SORTS: UserSort[] = ['joined', 'last_seen', 'balance', 'orders', 'services']

/**
 * Everyone who ever talked to the bot or signed up on the website — searchable, paged, filterable by status, by role (the
 * bot's admins: the website's settings link here) and by one of the admin's groups — each name the way to the customer's
 * page; with ban/unban, the bot's admin role, the wallet and the groups each is in.
 */
export function UsersList() {
  const list = usePagedList({
    queryKey: queryKeys.users,
    read: (query) => api.get<UsersResponse>('/users', query),
    list: 'users',
    statuses: STATUSES,
    params: ['role', 'group'],
    choices: { role: Object.keys(ROLES) },
    sorts: SORTS,
    address: '/users',
  })
  const groups = useQuery(customerGroupsQuery)
  const account = useAccountChange(list.replace)
  const [wallet, setWallet] = useState<UserRow | null>(null)
  const [grouping, setGrouping] = useState<UserRow | null>(null)
  // A row's dialog is the list's: the list's section going out of sight — a link inside one to «گروه‌ها» — closes it
  // (state adjusted during render), rather than leaving it open over the other section.
  const shown = useSectionShown()
  const [wasShown, setWasShown] = useState(shown)
  if (wasShown !== shown) {
    setWasShown(shown)
    if (!shown) {
      setWallet(null)
      setGrouping(null)
    }
  }
  // What found nothing, to say what to change: the search, the filters, or both.
  const searched = list.typed.trim() !== ''
  const narrowed = list.filter !== '' || (list.params.role ?? '') !== '' || (list.params.group ?? '') !== ''

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="نام، نام کاربری، ایمیل، موبایل، شناسه تلگرام یا شماره" aria-label="جستجوی کاربر" />
        <FilterSelect label="وضعیت" value={list.filter} options={STATUSES.map((value) => ({ value, label: value === '' ? 'همه' : USER_STATUS[value].label }))} onChange={list.setFilter} />
        <FilterSelect
          label="نقش"
          value={list.params.role ?? ''}
          options={[{ value: '', label: 'همه' }, ...Object.entries(ROLES).map(([value, label]) => ({ value, label }))]}
          onChange={(value) => list.setParam('role', value)}
        />
        {groups.data && groups.data.groups.length > 0 && (
          <FilterSelect
            label="گروه"
            value={list.params.group ?? ''}
            options={[{ value: '', label: 'همه' }, ...groups.data.groups.map((group) => ({ value: String(group.id), label: group.name }))]}
            onChange={(value) => list.setParam('group', value)}
          />
        )}
      </div>

      <ListView
        list={list}
        noun="کاربران"
        unit="کاربر"
        skeletonRows={6}
        empty={<EmptyState framed icon={Users} title="هنوز کاربری ثبت نشده است" description="اولین کسی که به ربات /start بزند یا در وب‌سایت ثبت‌نام کند این‌جا ظاهر می‌شود." />}
        noMatch={
          <EmptyState
            framed
            icon={Search}
            title="کاربری پیدا نشد"
            description={searched ? `با این جستجو${narrowed ? ' و فیلتر' : ''} کسی نیست. عبارت را کوتاه‌تر کنید${narrowed ? ' یا فیلترها را بردارید' : ''}.` : 'با این فیلتر کسی نیست.'}
          />
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>کاربر</TableHead>
              {/* The customer's number — «#12» in the search box is them alone, as every list takes it. */}
              <TableHead className="hidden xl:table-cell">شماره</TableHead>
              <TableHead className="hidden sm:table-cell">شناسه تلگرام</TableHead>
              <TableHead className="hidden md:table-cell">موبایل</TableHead>
              <SortableHead list={list} by="balance">
                کیف پول
              </SortableHead>
              <SortableHead list={list} by="services" label="سرویس‌های فعال" className="hidden sm:table-cell">
                سرویس‌ها
              </SortableHead>
              <SortableHead list={list} by="orders" className="hidden lg:table-cell">
                سفارش‌ها
              </SortableHead>
              <TableHead className="hidden lg:table-cell">گروه‌ها</TableHead>
              <TableHead>وضعیت</TableHead>
              <SortableHead list={list} by="last_seen" className="hidden xl:table-cell">
                آخرین بازدید
              </SortableHead>
              <SortableHead list={list} by="joined" className="hidden md:table-cell">
                عضویت
              </SortableHead>
              <TableHead className="w-10">
                <span className="sr-only">عملیات</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((user) => (
              <TableRow key={user.id} className={cn(user.status === 'banned' && 'text-muted-foreground')}>
                <TableCell>
                  <UserIdentity user={user} avatar badge={user.role === 'admin' && <AdminMark />} to={userPage(user.id)} />
                </TableCell>
                <TableCell className="hidden text-muted-foreground tabular xl:table-cell">
                  <span dir="ltr">#{user.id}</span>
                </TableCell>
                <TableCell className="hidden sm:table-cell">
                  {user.telegram_id === null ? (
                    <Dash />
                  ) : (
                    <bdi dir="ltr" className="text-footnote">
                      {user.telegram_id}
                    </bdi>
                  )}
                </TableCell>
                <TableCell className="hidden md:table-cell">
                  {user.phone ? (
                    <bdi dir="ltr" className="text-footnote">
                      {user.phone}
                    </bdi>
                  ) : (
                    <Dash />
                  )}
                </TableCell>
                <TableCell>
                  <Balance balance={user.balance} />
                </TableCell>
                <TableCell className="hidden tabular sm:table-cell">
                  {user.counts.subscriptions === 0 ? (
                    <Dash />
                  ) : (
                    <>
                      {formatNumber(user.counts.active_subscriptions)} فعال <span className="text-footnote text-faint">از {formatNumber(user.counts.subscriptions)}</span>
                    </>
                  )}
                </TableCell>
                <TableCell className="hidden tabular lg:table-cell">{user.counts.orders === 0 ? <Dash /> : formatNumber(user.counts.orders)}</TableCell>
                <TableCell className="hidden lg:table-cell">
                  {user.groups.length === 0 ? (
                    <Dash />
                  ) : (
                    <span className="flex max-w-52 flex-wrap gap-1">
                      {user.groups.map((group) => (
                        <Badge key={group.id} variant="outline" className="font-normal">
                          {group.name}
                        </Badge>
                      ))}
                    </span>
                  )}
                </TableCell>
                <TableCell>
                  <StatusBadge status={USER_STATUS[user.status]} />
                </TableCell>
                <TableCell className="hidden text-muted-foreground xl:table-cell">{user.last_seen_at ? timeAgo(user.last_seen_at) : <Dash />}</TableCell>
                <TableCell className="hidden text-muted-foreground md:table-cell">{timeAgo(user.created_at)}</TableCell>
                <TableCell>
                  <RowMenu label={distinctUserLabel(user)}>
                    {user.telegram_id !== null && (
                      <DropdownMenuItem asChild>
                        <TelegramChatLink user={user} />
                      </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => setWallet(user)}>
                      <Wallet aria-hidden />
                      کیف پول
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setGrouping(user)}>
                      <Tags aria-hidden />
                      گروه‌ها
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <AccountMenuItems user={user} account={account} />
                  </RowMenu>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <WalletModal user={wallet} onClose={() => setWallet(null)} onChanged={list.replace} />
      <GroupsModal
        user={grouping}
        // The groups' counts move.
        invalidates={[queryKeys.customerGroups]}
        onClose={() => setGrouping(null)}
        onSaved={(user) => {
          list.replace(user)
          setGrouping(null)
        }}
      />

      <AccountChangeConfirm account={account} />
    </>
  )
}
