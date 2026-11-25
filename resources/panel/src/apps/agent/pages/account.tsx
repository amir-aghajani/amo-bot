import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bot, CalendarPlus, LogOut, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { BUY_TRAFFIC } from '@/apps/agent/buy-traffic'
import { accountQuery } from '@/apps/agent/queries'
import { renewWords, SellingNotice, StandingFacts, SUSPENDED_SALES } from '@/components/agency/agency-standing'
import { AgentBotName } from '@/components/agency/agent-bot-name'
import { TrafficLedger } from '@/components/agency/traffic-ledger'
import { TrafficShortageNotice } from '@/components/agency/traffic-shortage'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { Fact, FactList } from '@/components/fact-list'
import { Dash } from '@/components/list-view'
import { Page, useReadFailure } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { StatCard, StatGrid } from '@/components/stat-card'
import { StatusDot } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { AccountResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatAmount, formatBytes, formatDate, formatMoney, isDebt, MONEY_UNIT, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { AGENCY_REQUEST_STATUS } from '@/lib/statuses'

/**
 * «حساب نمایندگی», the agent's own page: their bot and its state, the traffic left with its ledger, their level, wallet
 * and credit, their terms of selling (an end, a monthly minimum, and why their bot sells nothing when it does not), and
 * signing out other browsers. Traffic is bought, and the bot's token handed over, in the main bot.
 */
export function AccountPage() {
  const read = useQuery(accountQuery)
  const { data, isPending, refetch } = read
  const failure = useReadFailure(read)

  const account = data?.account
  const bot = account?.bot
  // Below zero the wallet carries a debt (the credit in use, or support's debit by hand), worded as the bot words it.
  const debt = account !== undefined && isDebt(account.balance)

  return (
    <Page width="default">
      <PageHeader
        title="حساب نمایندگی"
        description="ربات شما با همین فروشگاه کار می‌کند: پلن‌ها، قیمت‌ها و مشتری‌هایش مال شماست و سرویس‌ها روی سرورهای فروشگاه ساخته می‌شوند. هر سرویسی که ربات می‌فروشد یا تمدید می‌کند، حجم پلنش را از حجم شما برمی‌دارد؛ حجمی هم که از صفحه اشتراک‌ها به سرویسی اضافه می‌کنید از همین حجم کم می‌شود."
      />

      {failure.error && <ErrorState what="حساب نمایندگی" error={failure.error} onRetry={() => void refetch()} retrying={failure.retrying} />}

      {bot?.problem && (
        <Callout tone="warning" icon={TriangleAlert}>
          {bot.problem}
        </Callout>
      )}
      {/* A bot connected once whose token is gone (no longer readable) has stopped — a warning: it needs its token again;
          one never connected is the next step, its first. */}
      {bot &&
        !bot.connected &&
        (bot.connected_at ? (
          <Callout tone="warning" icon={TriangleAlert}>
            ربات شما دیگر وصل نیست: در ربات اصلی از «نمایندگی» ← «ربات من» توکنش را دوباره بفرستید.
          </Callout>
        ) : (
          <Callout tone="info" icon={Bot}>
            ربات شما هنوز وصل نشده است: در ربات اصلی از «نمایندگی» ← «ربات من» توکنی را که <bdi dir="ltr">@BotFather</bdi> داده بفرستید.
          </Callout>
        ))}
      {data?.traffic_shortage && <TrafficShortageNotice shortage={data.traffic_shortage} help={BUY_TRAFFIC} />}
      <SellingNotice standing={account?.standing} renew={account && renewWords(account, true)} />

      <StatGrid label="حجم، سطح و کیف پول">
        {/* Where traffic is bought shows once: in the notice while the traffic sells nothing, else under the figure. */}
        <StatCard label="حجم باقی‌مانده" value={bot && formatBytes(bot.traffic_balance)} hint={data?.traffic_shortage ? undefined : BUY_TRAFFIC} loading={isPending} />
        <StatCard
          label="قیمت هر گیگابایت"
          value={account?.level ? formatAmount(account.level.price_per_gb) : undefined}
          unit={account?.level ? MONEY_UNIT : undefined}
          hint={account && (account.level ? `سطح «${account.level.name}»` : 'نمایندگی فعال نیست')}
          loading={isPending}
        />
        <StatCard
          label="کیف پول شما در فروشگاه"
          value={account && formatAmount(Math.abs(Number(account.balance)))}
          unit={debt ? `${MONEY_UNIT} بدهی` : MONEY_UNIT}
          hint={account && creditWords(account)}
          loading={isPending}
        />
      </StatGrid>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
        <div className="grid content-start gap-4">
          <Card>
            <CardHeader>
              <CardHeading>
                <CardTitle>ربات شما</CardTitle>
                <CardDescription>برای عوض کردن توکن، توکن تازه را از ربات اصلی بفرستید.</CardDescription>
              </CardHeading>
            </CardHeader>
            <CardContent>
              {isPending ? (
                <Skeleton className="h-24 w-full" />
              ) : !bot ? (
                <Dash />
              ) : (
                <FactList>
                  <Fact label="ربات">
                    <AgentBotName bot={bot} />
                  </Fact>
                  <Fact label="نام">{bot.title ?? <Dash />}</Fact>
                  {/* When the token was first handed over — whether the bot runs now is its status above. */}
                  <Fact label="اولین اتصال">{bot.connected_at ? formatDate(bot.connected_at) : <Dash />}</Fact>
                </FactList>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardHeading>
                <CardTitle>شرایط فروش</CardTitle>
                <CardDescription>
                  پایان نمایندگی و کف خرید حجم ماهانه را پشتیبانی فروشگاه اصلی تعیین می‌کند. بعد از پایان نمایندگی، یا ماهی که خرید حجمش به کف نرسد، در ربات شما {SUSPENDED_SALES} تازه انجام نمی‌شود؛
                  سرویس‌های فعلی مشتری‌ها کار می‌کنند.
                </CardDescription>
              </CardHeading>
            </CardHeader>
            <CardContent>
              {isPending ? (
                <Skeleton className="h-24 w-full" />
              ) : !account ? (
                <Dash />
              ) : (
                <FactList>
                  <StandingFacts standing={account.standing} renewal={<Renewal account={account} />} />
                </FactList>
              )}
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <CardHeading>
              <CardTitle>گردش حجم</CardTitle>
              <CardDescription>خرید حجم، فروش، تمدید و افزایش حجم سرویس‌ها، و بازگشت حجمی که به سرویس نرسید.</CardDescription>
            </CardHeading>
          </CardHeader>
          <CardContent>
            <TrafficLedger queryKey={queryKeys.accountTraffic} url="/account/traffic" />
          </CardContent>
        </Card>
      </div>

      <OtherBrowsers />
    </Page>
  )
}

type Account = AccountResponse['account']

/** An amount as it shows: whole Toman, half away from zero. */
function shown(amount: string): number {
  return Math.sign(Number(amount)) * Math.round(Math.abs(Number(amount)))
}

/**
 * The wallet's credit: how much, and — in debt — whether some is left to spend: in use, used up, or past it (support's
 * debit by hand), nothing to spend then.
 */
function creditWords(account: Account): string {
  if (Number(account.credit_limit) <= 0) return 'بدون اعتبار خرید'
  const credit = `اعتبار خرید: ${formatMoney(account.credit_limit)}`
  if (!isDebt(account.balance)) return credit
  // From the amounts as shown, so the words never contradict the figures beside them.
  const left = shown(account.credit_limit) + shown(account.balance)
  if (isDebt(left)) return `${credit} (تمام شده؛ بدهی از اعتبار گذشته است)`
  return `${credit} (${formatAmount(left) === formatAmount(0) ? 'تمام شده' : 'در حال استفاده'})`
}

/**
 * The agent's latest renewal request in a line: waiting since when, or what was decided — said of the decision, in the
 * past (the end it gave then, or support's reason), never as the end now: that is the line above it, which may have
 * moved since.
 */
function renewalWords(renewal: NonNullable<Account['renewal']>): string {
  if (renewal.status === 'pending') return `درخواست تمدید شما در انتظار بررسی است${renewal.created_at ? `؛ ${timeAgo(renewal.created_at)} فرستاده شد` : ''}.`
  const when = renewal.decided_at ? ` ${timeAgo(renewal.decided_at)}` : ''
  if (renewal.status === 'approved') {
    return renewal.extended_to === null ? `درخواست تمدید شما${when} تایید شد و نمایندگی بدون پایان شد.` : `درخواست تمدید شما${when} تایید شد و پایان نمایندگی ${formatDate(renewal.extended_to)} شد.`
  }
  return `درخواست تمدید شما${when} رد شد.${renewal.reason ? ` توضیح پشتیبانی: ${renewal.reason}` : ''}`
}

/**
 * Under the agency's end: the agent's latest renewal request, and «درخواست تمدید نمایندگی» while they may ask (their
 * agency has an end and nothing waits) — after a confirmation, as it notifies the main shop. The answer is the account
 * as it stands now. A refusal (one sent meanwhile from the main bot) is said in the dialog and the account read again.
 */
function Renewal({ account }: { account: Account }) {
  const queryClient = useQueryClient()
  const [asking, setAsking] = useState(false)
  const ask = useMutation({
    mutationFn: () => api.post('/account/renewal'),
    onSuccess: (data) => {
      queryClient.setQueryData(queryKeys.account, data)
      setAsking(false)
      toast.success('درخواست تمدید نمایندگی فرستاده شد')
    },
    onError: () => void queryClient.invalidateQueries({ queryKey: queryKeys.account }),
    meta: { quiet: true },
  })
  const { renewal } = account

  return (
    <>
      {renewal && (
        <p className="flex items-start gap-2 text-footnote text-muted-foreground">
          <StatusDot tone={AGENCY_REQUEST_STATUS[renewal.status].tone} className="mt-1.5" />
          <span>{renewalWords(renewal)}</span>
        </p>
      )}
      {account.actions.request_renewal && (
        <Button
          size="sm"
          variant="secondary"
          icon={CalendarPlus}
          onClick={() => {
            ask.reset()
            setAsking(true)
          }}
        >
          درخواست تمدید نمایندگی
        </Button>
      )}
      <ConfirmModal
        open={asking}
        onClose={() => setAsking(false)}
        title="درخواست تمدید نمایندگی"
        confirmLabel="فرستادن درخواست"
        pending={ask.isPending}
        error={ask.error ? messageOf(ask.error, 'status') : null}
        onConfirm={() => ask.mutate()}
      >
        درخواست شما برای پشتیبانی فروشگاه اصلی فرستاده می‌شود و پس از بررسی، نتیجه در ربات اصلی به شما اعلام می‌شود. تا آن موقع پایان نمایندگی شما تغییری نمی‌کند.
      </ConfirmModal>
    </>
  )
}

/** Signs every other browser out of the agent's panel (a lost phone, a link opened elsewhere), after a confirmation. */
function OtherBrowsers() {
  const [asking, setAsking] = useState(false)
  const end = useMutation({
    mutationFn: () => api.post('/auth/sessions/end'),
    onSuccess: () => {
      setAsking(false)
      toast.success('از همه مرورگرهای دیگر خارج شدید')
    },
    meta: { quiet: true },
  })

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>ورود به پنل</CardTitle>
          <CardDescription>هر لینک ورود، مرورگری را که در آن باز شود وارد پنل شما می‌کند. اگر پنل در دستگاهی باز مانده که دیگر دست شما نیست، از آن‌جا خارجش کنید.</CardDescription>
        </CardHeading>
        <CardAction>
          <Button
            variant="danger-outline"
            icon={LogOut}
            onClick={() => {
              end.reset()
              setAsking(true)
            }}
          >
            خروج از مرورگرهای دیگر
          </Button>
        </CardAction>
      </CardHeader>

      <ConfirmModal
        open={asking}
        onClose={() => setAsking(false)}
        title="خروج از مرورگرهای دیگر"
        confirmLabel="خروج از بقیه"
        destructive
        pending={end.isPending}
        error={end.error ? messageOf(end.error) : null}
        onConfirm={() => end.mutate()}
      >
        هر مرورگر و دستگاه دیگری که وارد پنل شما شده بیرون می‌رود و برای ورود دوباره لینک تازه‌ای از ربات لازم دارد؛ این مرورگر وارد می‌ماند.
      </ConfirmModal>
    </Card>
  )
}
