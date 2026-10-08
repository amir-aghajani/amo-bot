import { useQuery } from '@tanstack/react-query'
import { Coins, Search, Settings2, UserPlus, Users } from 'lucide-react'
import { Link } from 'react-router'
import { MenuButtonNotice } from '@/components/bot/menu-button-notice'
import { CustomerFilter, customerList } from '@/components/customer-filter'
import { EmptyState } from '@/components/empty-state'
import { ErrorState } from '@/components/error-state'
import { Dash, ListView, SortableHead } from '@/components/list-view'
import { ProgramOff } from '@/components/program-off'
import { SearchBox } from '@/components/search-box'
import { SectionedPage } from '@/components/sectioned-page'
import { REFERRAL_SECTIONS } from '@/components/shell/nav'
import { StatCard, StatGrid } from '@/components/stat-card'
import { searchLink, TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { InviteeSort, InviteesResponse, ReferralCommissionSort, ReferralCommissionsResponse, ReferralSummaryResponse, ReferrerSort, ReferrersResponse } from '@/lib/api-types'
import { formatAmount, formatMoney, formatNumber, formatPercent, MONEY_UNIT, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { usePagedList } from '@/lib/use-paged-list'

type Section = (typeof REFERRAL_SECTIONS)[number]['value']

const HEADERS: Record<Section, { title: string; description: string }> = {
  referrers: {
    title: 'معرف‌ها',
    description: 'مشتری‌هایی که لینک دعوتشان کسی را به ربات آورده، بیشترین زیرمجموعه اول؛ با پورسانتی که تا حالا گرفته‌اند.',
  },
  invitees: {
    title: 'زیرمجموعه‌ها',
    description: 'مشتری‌هایی که با لینک دعوت آمده‌اند، تازه‌ترین اول: چه کسی آن‌ها را آورده و پرداخت‌هایشان چقدر پورسانت داشته است.',
  },
  commissions: {
    title: 'پورسانت‌ها',
    description: 'پورسانت‌هایی که بابت پرداخت زیرمجموعه‌ها به کیف پول معرف‌ها اضافه شده، تازه‌ترین اول.',
  },
}

/** The program in a paragraph, behind the header's ⓘ on every section. */
const PROGRAM =
  'هر مشتری در ربات لینک دعوت خودش را دارد؛ کسی که اولین بار با آن لینک وارد ربات شود زیرمجموعه اوست و از پرداخت‌هایش پورسانتی به کیف پول معرف اضافه می‌شود؛ کسی که از قبل در ربات ثبت شده باشد، زیرمجموعه کسی نمی‌شود.'

/** What each list's headers sort it by — its own order leads. */
const REFERRER_SORTS: ReferrerSort[] = ['referrals', 'earned', 'last_referral']
const INVITEE_SORTS: InviteeSort[] = ['joined', 'earned']
const COMMISSION_SORTS: ReferralCommissionSort[] = ['created', 'commission']

/**
 * The referral program: its numbers over three sections, listed in the sidebar — the customers whose links brought
 * someone, the customers who came through a link (who brought whom), and the commissions paid out. The rules are a
 * section of the bot settings. The sections stay mounted when another is shown, so each keeps its search and page.
 * Numbers that could not be read say so, and are «—».
 */
export function ReferralsPage() {
  const read = useQuery({ queryKey: queryKeys.referrals, queryFn: () => api.get<ReferralSummaryResponse>('/referrals') })
  const summary = read.data?.summary

  return (
    <SectionedPage
      sections={REFERRAL_SECTIONS}
      header={(current) => ({
        ...HEADERS[current.value],
        info: PROGRAM,
        actions: (
          <Button variant="secondary" asChild>
            <Link to="/bot-settings/referral">
              <Settings2 aria-hidden />
              قوانین پورسانت
            </Link>
          </Button>
        ),
      })}
      above={() => (
        <>
          {summary && !summary.enabled && (
            <ProgramOff to="/bot-settings/referral" action="روشن کردن در تنظیمات ربات">
              زیرمجموعه‌گیری خاموش است: لینک‌های دعوت کسی را زیرمجموعه نمی‌کنند و پرداختی پورسانت ندارد. زیرمجموعه‌ها و پورسانت‌های قبلی سر جایشان می‌مانند.
            </ProgramOff>
          )}
          {summary?.enabled && <MenuButtonNotice action="affiliates" label="زیرمجموعه‌گیری" screen="صفحه لینک دعوتشان" />}
          {read.error && <ErrorState what="آمار زیرمجموعه‌گیری" error={read.error} onRetry={() => void read.refetch()} retrying={read.isFetching} />}

          <StatGrid label="آمار زیرمجموعه‌گیری">
            <StatCard label="معرف‌ها" value={summary && formatNumber(summary.referrers)} hint="مشتری‌هایی که لینکشان کسی را آورده" loading={read.isPending} />
            <StatCard label="زیرمجموعه‌ها" value={summary && formatNumber(summary.referred)} hint="مشتری‌هایی که با لینک دعوت آمده‌اند" loading={read.isPending} />
            <StatCard label="پورسانت‌های پرداخت‌شده" value={summary && formatNumber(summary.commissions)} hint="هر کدام بابت یک پرداخت" loading={read.isPending} />
            <StatCard
              label="جمع پورسانت‌ها"
              value={summary && formatAmount(summary.paid)}
              unit={MONEY_UNIT}
              hint={summary && `${formatPercent(summary.rate)} از ${summary.first_only ? 'اولین پرداخت هر زیرمجموعه' : 'هر پرداخت'}`}
              loading={read.isPending}
            />
          </StatGrid>
        </>
      )}
    >
      {{ referrers: <ReferrersList />, invitees: <InviteesList />, commissions: <CommissionsList /> }}
    </SectionedPage>
  )
}

/** Customers whose link brought someone, most referrals first; the count opens the list of the ones they brought. */
function ReferrersList() {
  const list = usePagedList({
    queryKey: queryKeys.referrers,
    read: (query) => api.get<ReferrersResponse>('/referrals/referrers', query),
    list: 'referrers',
    sorts: REFERRER_SORTS,
    address: '/referrals',
  })

  return (
    <>
      <SearchBox value={list.typed} onChange={list.setTyped} placeholder="نام، نام کاربری یا شناسه تلگرام معرف" aria-label="جستجوی معرف" />
      <ListView
        list={list}
        noun="معرف‌ها"
        unit="معرف"
        skeletonRows={5}
        empty={<EmptyState framed icon={Users} title="هنوز لینکی کسی را نیاورده است" description="اولین مشتری‌ای که با لینک دعوت یک نفر وارد ربات شود، معرفش این‌جا می‌آید." />}
        noMatch={<EmptyState framed icon={Search} title="معرفی پیدا نشد" description="با این جستجو کسی نیست." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>معرف</TableHead>
              <SortableHead list={list} by="referrals">
                زیرمجموعه‌ها
              </SortableHead>
              <SortableHead list={list} by="earned">
                پورسانت دریافتی
              </SortableHead>
              <SortableHead list={list} by="last_referral" className="hidden md:table-cell">
                آخرین زیرمجموعه
              </SortableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((row) => (
              <TableRow key={row.id}>
                <TableCell>
                  <UserIdentity user={row.user} status={row.status} to={userPage(row.id)} />
                </TableCell>
                <TableCell>
                  {/* The invitees' list, narrowed to this referrer: the column says what the count is, the link's name what it opens. */}
                  <TextLink to={customerList('/referrals/invitees', row.id, 'referrer')} className="font-medium tabular">
                    {formatNumber(row.referrals)} نفر<span className="sr-only">، زیرمجموعه‌های این معرف</span>
                  </TextLink>
                </TableCell>
                <TableCell className="tabular">{Number(row.earned) > 0 ? formatMoney(row.earned) : <Dash />}</TableCell>
                <TableCell className="hidden text-muted-foreground md:table-cell">{row.last_referral_at ? timeAgo(row.last_referral_at) : <Dash />}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>
    </>
  )
}

/**
 * Customers who came through a link, newest first: who brought them and what their payments earned that one — or the
 * ones one referrer's link brought (`referrer`: the referrers list's count, a customer's page).
 */
function InviteesList() {
  const list = usePagedList({
    queryKey: queryKeys.invitees,
    read: (query) => api.get<InviteesResponse>('/referrals/invitees', query),
    list: 'invitees',
    params: ['referrer'],
    sorts: INVITEE_SORTS,
    address: '/referrals/invitees',
  })

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="زیرمجموعه یا معرف: نام، نام کاربری یا شناسه" aria-label="جستجوی زیرمجموعه" />
        <CustomerFilter label="معرف" value={list.params.referrer ?? ''} onClear={() => list.setParam('referrer', '')} />
      </div>
      <ListView
        list={list}
        noun="زیرمجموعه‌ها"
        unit="زیرمجموعه"
        skeletonRows={5}
        empty={<EmptyState framed icon={UserPlus} title="هنوز کسی با لینک دعوت نیامده است" description="کسی که اولین بار با لینک دعوت یک نفر وارد ربات شود، این‌جا می‌آید." />}
        noMatch={<EmptyState framed icon={Search} title="زیرمجموعه‌ای پیدا نشد" description="با این جستجو یا فیلتر کسی نیست." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>زیرمجموعه</TableHead>
              <TableHead>معرف</TableHead>
              <SortableHead list={list} by="earned">
                پورسانت برای معرف
              </SortableHead>
              <SortableHead list={list} by="joined" className="hidden md:table-cell">
                عضویت
              </SortableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((row) => (
              <TableRow key={row.id}>
                <TableCell>
                  <UserIdentity user={row.user} status={row.status} to={userPage(row.id)} />
                </TableCell>
                <TableCell>{row.referrer ? <UserIdentity user={row.referrer} to={userPage(row.referrer.id)} /> : <Dash />}</TableCell>
                <TableCell className="tabular">{Number(row.earned) > 0 ? formatMoney(row.earned) : <Dash />}</TableCell>
                <TableCell className="hidden text-muted-foreground md:table-cell">{timeAgo(row.joined_at)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>
    </>
  )
}

/** The commissions paid out, newest first: who earned what from whose payment (a link to it on the payments screen). */
function CommissionsList() {
  const list = usePagedList({
    queryKey: queryKeys.referralCommissions,
    read: (query) => api.get<ReferralCommissionsResponse>('/referrals/commissions', query),
    list: 'commissions',
    sorts: COMMISSION_SORTS,
    address: '/referrals/commissions',
  })

  return (
    <>
      <SearchBox value={list.typed} onChange={list.setTyped} placeholder="شماره پرداخت، معرف یا زیرمجموعه" aria-label="جستجوی پورسانت" />
      <ListView
        list={list}
        noun="پورسانت‌ها"
        unit="پورسانت"
        skeletonRows={5}
        empty={<EmptyState framed icon={Coins} title="هنوز پورسانتی پرداخت نشده است" description="اولین پرداخت موفق یک زیرمجموعه، پورسانت معرفش را این‌جا می‌آورد." />}
        noMatch={<EmptyState framed icon={Search} title="پورسانتی پیدا نشد" description="با این جستجو چیزی نیست." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <SortableHead list={list} by="commission">
                پورسانت
              </SortableHead>
              <TableHead>معرف</TableHead>
              <TableHead>زیرمجموعه</TableHead>
              <TableHead className="hidden lg:table-cell">پرداخت</TableHead>
              <SortableHead list={list} by="created" className="hidden md:table-cell">
                زمان
              </SortableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((row) => (
              <TableRow key={row.id}>
                <TableCell>
                  <div className="grid">
                    <span className="font-medium whitespace-nowrap tabular">{formatMoney(row.commission)}</span>
                    <span className="text-footnote text-muted-foreground">{formatPercent(row.rate)}</span>
                  </div>
                </TableCell>
                <TableCell>
                  <UserIdentity user={row.referrer} to={userPage(row.referrer.id)} />
                </TableCell>
                <TableCell>
                  <UserIdentity user={row.customer} to={userPage(row.customer.id)} />
                </TableCell>
                <TableCell className="hidden lg:table-cell">
                  <div className="grid">
                    <TextLink to={searchLink('/payments', row.payment.id)} className="w-fit font-medium">
                      <span dir="ltr">#{row.payment.id}</span>
                    </TextLink>
                    <span className="text-footnote whitespace-nowrap text-muted-foreground tabular">{formatMoney(row.payment.amount)}</span>
                  </div>
                </TableCell>
                <TableCell className="hidden text-muted-foreground md:table-cell">{timeAgo(row.created_at)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>
    </>
  )
}
