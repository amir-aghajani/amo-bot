import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, Tags, Users, Wallet } from 'lucide-react'
import { Balance } from '@/components/balance'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { Dash, ListView, PhoneFacts, RowMenu, SortableHead } from '@/components/list-view'
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
 * Every customer of the bot or the website, searchable and filterable by status, role and group. Each name opens the
 * customer's page; the row menu bans, gives the admin role, adjusts the wallet and sets the groups.
 */
export function UsersList() {
  const list = usePagedList({
    queryKey: queryKeys.users,
    read: (query) => api.get<UsersResponse>('/users', query),
    list: 'users',
    statuses: STATUSES,
    params: ['role', 'group'],
    ids: ['group'],
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
  const inGroup = list.params.group ?? ''
  const narrowed = list.filter !== '' || (list.params.role ?? '') !== '' || inGroup !== ''
  // A group deleted while the list was narrowed to it (in the groups' section, or since the address was kept) still
  // narrows it: its pill stays, under a name that says so, rather than reading «همه» — or going — over a narrowed list.
  const groupOptions = (groups.data?.groups ?? []).map((row) => ({ value: String(row.id), label: row.name }))
  if (inGroup !== '' && !groupOptions.some((option) => option.value === inGroup)) groupOptions.push({ value: inGroup, label: 'گروه حذف‌شده' })

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="نام، نام کاربری، ایمیل، موبایل یا شناسه" aria-label="جستجوی کاربر" />
        <FilterSelect label="وضعیت" value={list.filter} options={STATUSES.map((value) => ({ value, label: value === '' ? 'همه' : USER_STATUS[value].label }))} onChange={list.setFilter} />
        <FilterSelect
          label="نقش"
          value={list.params.role ?? ''}
          options={[{ value: '', label: 'همه' }, ...Object.entries(ROLES).map(([value, label]) => ({ value, label }))]}
          onChange={(value) => list.setParam('role', value)}
        />
        {groups.data && groupOptions.length > 0 && (
          <FilterSelect label="گروه" value={inGroup} options={[{ value: '', label: 'همه' }, ...groupOptions]} onChange={(value) => list.setParam('group', value)} />
        )}
      </div>

      <ListView
        list={list}
        noun="کاربران"
        unit="کاربر"
        skeletonRows={6}
        empty={
          <EmptyState
            framed
            icon={Users}
            title="هنوز کاربری ثبت نشده است"
            description={
              <>
                اولین کسی که به ربات <bdi dir="ltr">/start</bdi> بزند یا در وب‌سایت ثبت‌نام کند این‌جا ظاهر می‌شود.
              </>
            }
          />
        }
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
              {/* The customer's number — «#12» in the search box is them alone, as every list takes it. Only on the widest
                  screens: below them its room goes to the names. */}
              <TableHead className="hidden 2xl:table-cell">شماره</TableHead>
              {/* A phone held sideways has room for the Telegram id; a tablet, with the rail or the sidebar beside the
                  page, has less: it comes back with the widest columns. */}
              <TableHead className="hidden sm:table-cell md:hidden xl:table-cell">شناسه تلگرام</TableHead>
              <TableHead className="hidden xl:table-cell">موبایل</TableHead>
              <SortableHead list={list} by="balance">
                کیف پول
              </SortableHead>
              <SortableHead list={list} by="services" label="سرویس‌های فعال" className="hidden sm:table-cell">
                سرویس‌ها
              </SortableHead>
              <SortableHead list={list} by="orders" className="hidden xl:table-cell">
                سفارش‌ها
              </SortableHead>
              <TableHead className="hidden xl:table-cell">گروه‌ها</TableHead>
              <TableHead className="hidden md:table-cell">وضعیت</TableHead>
              <SortableHead list={list} by="last_seen" className="hidden xl:table-cell">
                آخرین بازدید
              </SortableHead>
              <SortableHead list={list} by="joined" className="hidden xl:table-cell">
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
                  <PhoneFacts className="ps-[2.625rem]">
                    <StatusBadge status={USER_STATUS[user.status]} />
                  </PhoneFacts>
                </TableCell>
                <TableCell className="hidden text-muted-foreground tabular 2xl:table-cell">
                  <span dir="ltr">#{user.id}</span>
                </TableCell>
                <TableCell className="hidden sm:table-cell md:hidden xl:table-cell">
                  {user.telegram_id === null ? (
                    <Dash />
                  ) : (
                    <bdi dir="ltr" className="text-footnote">
                      {user.telegram_id}
                    </bdi>
                  )}
                </TableCell>
                <TableCell className="hidden xl:table-cell">
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
                <TableCell className="hidden tabular xl:table-cell">{user.counts.orders === 0 ? <Dash /> : formatNumber(user.counts.orders)}</TableCell>
                <TableCell className="hidden xl:table-cell">
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
                <TableCell className="hidden md:table-cell">
                  <StatusBadge status={USER_STATUS[user.status]} />
                </TableCell>
                <TableCell className="hidden text-muted-foreground xl:table-cell">{user.last_seen_at ? timeAgo(user.last_seen_at) : <Dash />}</TableCell>
                <TableCell className="hidden text-muted-foreground xl:table-cell">{timeAgo(user.created_at)}</TableCell>
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
