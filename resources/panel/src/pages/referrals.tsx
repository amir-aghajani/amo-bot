import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Coins, Search, Settings2, ShieldCheck, UserPlus, Users } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { MenuButtonNotice } from '@/components/bot/menu-button-notice'
import { ConfirmModal } from '@/components/confirm-modal'
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
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { distinctUserLabel, UserIdentity, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type {
  InviteeSort,
  InviteesResponse,
  ReferralAlertReason,
  ReferralAlertRow,
  ReferralAlertsResponse,
  ReferralCommissionSort,
  ReferralCommissionsResponse,
  ReferralSummary,
  ReferralSummaryResponse,
  ReferrerSort,
  ReferrersResponse,
} from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
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
  alerts: {
    title: 'هشدارها',
    description:
      'معرف‌هایی که زیرمجموعه‌هایشان ساختگی به نظر می‌رسد، تازه‌ترین اول. تا هشدار رفع نشود هدیه شروع و جایزه دعوت به معرف داده نمی‌شود — و بعد از رفع هم آنچه نگه داشته شد داده نمی‌شود؛ پورسانت‌ها مثل قبل است.',
  },
}

/** Why an alert was raised, in the admins' words: how many and of what. */
const ALERT_REASON: Record<ReferralAlertReason, (count: number) => string> = {
  burst: (count) => `${formatNumber(count)} عضویت پشت سر هم با یک لینک`,
  idle: (count) => `${formatNumber(count)} زیرمجموعه بدون هیچ خرید`,
}

/** The program in a paragraph, behind the header's ⓘ on every section. */
const PROGRAM =
  'هر مشتری در ربات لینک دعوت خودش را دارد؛ کسی که اولین بار با آن لینک وارد ربات شود زیرمجموعه اوست و از پرداخت‌هایش پورسانتی به کیف پول معرف اضافه می‌شود؛ کسی که از قبل در ربات ثبت شده باشد، زیرمجموعه کسی نمی‌شود.'

/** What each list's headers sort it by — its own order leads. */
const REFERRER_SORTS: ReferrerSort[] = ['referrals', 'earned', 'last_referral']
const INVITEE_SORTS: InviteeSort[] = ['joined', 'earned']
const COMMISSION_SORTS: ReferralCommissionSort[] = ['created', 'commission']

/**
 * «زیرمجموعه‌گیری»: the program's numbers above four sections — referrers, invitees, commissions and fake-referral
 * alerts. The rules live in the bot settings.
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
            <StatCard label="جمع پورسانت‌ها" value={summary && formatAmount(summary.paid)} unit={MONEY_UNIT} hint={summary && commissionTerms(summary)} loading={read.isPending} />
          </StatGrid>
        </>
      )}
    >
      {{ referrers: <ReferrersList />, invitees: <InviteesList />, commissions: <CommissionsList />, alerts: <AlertsList /> }}
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
                  <div className="flex flex-wrap items-center gap-2">
                    <UserIdentity user={row.user} status={row.status} to={userPage(row.id)} />
                    {row.alerts > 0 && (
                      <TextLink to={searchLink('/referrals/alerts', row.id)} className="shrink-0">
                        <Badge variant="warning">هشدار</Badge>
                        <span className="sr-only">: {formatNumber(row.alerts)} هشدار بر این معرف؛ هدیه شروع و جایزه دعوت نگه داشته می‌شود</span>
                      </TextLink>
                    )}
                  </div>
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

/** Customers who came through a link, newest first, with their referrer and what they earned them; `?referrer=` narrows to one referrer. */
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

/** What a payment earns, in the stat's hint: the rate, of every payment or each one's first — and a renewal's own rate. */
function commissionTerms(summary: ReferralSummary): string {
  const terms = `${formatPercent(summary.rate)} از ${summary.first_only ? 'اولین پرداخت هر زیرمجموعه' : 'هر پرداخت'}`
  if (summary.first_only || summary.renewal_rate === summary.rate) return terms
  return `${terms}؛ ${summary.renewal_rate === 0 ? 'تمدید بدون پورسانت' : `تمدید ${formatPercent(summary.renewal_rate)}`}`
}

/**
 * Alerts on referrers whose referrals look fake, newest first. «رفع هشدار» (asked first) lets the referrer earn gifts again
 * from then on; what was held back stays held. A refusal shows in the dialog and the list is read again.
 */
function AlertsList() {
  const list = usePagedList({
    queryKey: queryKeys.referralAlerts,
    read: (query) => api.get<ReferralAlertsResponse>('/referrals/alerts', query),
    list: 'alerts',
    address: '/referrals/alerts',
  })
  const [clearing, setClearing] = useState<ReferralAlertRow | null>(null)

  const clear = useMutation({
    mutationFn: (alert: ReferralAlertRow) => api.post(`/referrals/alerts/${alert.id}/clear`),
    // The numbers above the lists and the referrers' marks.
    meta: { quiet: true, invalidates: [queryKeys.referrals, queryKeys.referrers] },
    onSuccess: (_, alert) => {
      list.remove(alert.id)
      setClearing(null)
      toast.success('هشدار رفع شد')
    },
    onError: () => list.refetch(),
  })

  return (
    <>
      <SearchBox value={list.typed} onChange={list.setTyped} placeholder="نام، نام کاربری یا شناسه معرف" aria-label="جستجوی هشدار" />
      <ListView
        list={list}
        noun="هشدارها"
        unit="هشدار"
        skeletonRows={4}
        empty={<EmptyState framed icon={ShieldCheck} title="هشداری نیست" description="معرفی که زیرمجموعه‌هایش ساختگی به نظر برسند، این‌جا و در گروه گزارش‌ها می‌آید." />}
        noMatch={<EmptyState framed icon={Search} title="هشداری پیدا نشد" description="با این جستجو چیزی نیست." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>معرف</TableHead>
              <TableHead>دلیل</TableHead>
              <TableHead className="hidden md:table-cell">زمان</TableHead>
              <TableHead>
                <span className="sr-only">عملیات</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((alert) => (
              <TableRow key={alert.id}>
                <TableCell>
                  <UserIdentity user={alert.referrer} status={alert.status} to={userPage(alert.referrer.id)} />
                </TableCell>
                <TableCell>{ALERT_REASON[alert.reason](alert.count)}</TableCell>
                <TableCell className="hidden text-muted-foreground md:table-cell">{timeAgo(alert.created_at)}</TableCell>
                <TableCell>
                  <Button size="sm" variant="secondary" onClick={() => setClearing(alert)} aria-label={`رفع هشدار ${distinctUserLabel(alert.referrer)}`}>
                    رفع هشدار
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <ConfirmModal
        open={clearing !== null}
        onClose={() => {
          setClearing(null)
          clear.reset()
        }}
        title="رفع هشدار"
        description={clearing ? distinctUserLabel(clearing.referrer) : undefined}
        confirmLabel="رفع هشدار"
        pending={clear.isPending}
        error={clear.error ? messageOf(clear.error) : null}
        onConfirm={() => clearing && clear.mutate(clearing)}
      >
        از این پس هدیه شروع و جایزه دعوت دوباره به این معرف داده می‌شود؛ آنچه تا حالا نگه داشته شد داده نمی‌شود. اگر هشدار دیگری هم روی او باشد، تا رفع آن هم چیزی داده نمی‌شود.
      </ConfirmModal>
    </>
  )
}
