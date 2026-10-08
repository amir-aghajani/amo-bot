import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Bot, LogOut, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { BUY_TRAFFIC } from '@/apps/agent/buy-traffic'
import { accountQuery } from '@/apps/agent/queries'
import { AgentBotName } from '@/components/agency/agent-bot-name'
import { TrafficLedger } from '@/components/agency/traffic-ledger'
import { TrafficShortageNotice } from '@/components/agency/traffic-shortage'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { Fact, FactList } from '@/components/fact-list'
import { Dash } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { StatCard, StatGrid } from '@/components/stat-card'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import { messageOf } from '@/lib/failure'
import { formatAmount, formatBytes, formatDate, formatMoney, MONEY_UNIT } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/**
 * «حساب نمایندگی», the agent's own page: their bot (who it is, whether it runs, what keeps it from running), the traffic
 * it may still sell and every line of it, their level and price per GB, their wallet and credit with the shop, and the
 * way to sign every other browser out of their panel. Traffic is bought, and the bot's token handed over, from the main
 * bot. An account that could not be read says so, its figures «—».
 */
export function AccountPage() {
  const { data, error, isPending, refetch } = useQuery(accountQuery)

  const account = data?.account
  const bot = account?.bot
  // Below zero the wallet carries a debt (the credit in use), worded as the bot words it.
  const debt = account !== undefined && Number(account.balance) < 0

  return (
    <Page width="default">
      <PageHeader
        title="حساب نمایندگی"
        description="ربات شما با همین فروشگاه کار می‌کند: پلن‌ها، قیمت‌ها و مشتری‌هایش مال شماست و سرویس‌ها روی سرورهای فروشگاه ساخته می‌شوند. هر سرویسی که ربات می‌فروشد یا تمدید می‌کند، حجم پلنش را از حجم شما برمی‌دارد؛ حجمی هم که از صفحه اشتراک‌ها به سرویسی اضافه می‌کنید از همین حجم کم می‌شود."
      />

      {error && <ErrorState what="حساب نمایندگی" error={error} onRetry={() => void refetch()} />}

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
            ربات شما هنوز وصل نشده است: در ربات اصلی از «نمایندگی» ← «ربات من» توکنی را که @BotFather داده بفرستید.
          </Callout>
        ))}
      {data?.traffic_shortage && <TrafficShortageNotice shortage={data.traffic_shortage} help={BUY_TRAFFIC} />}

      <StatGrid label="حجم، سطح و کیف پول">
        {/* Where traffic is bought is said once: by the notice while the traffic sells nothing, else under the figure. */}
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
          hint={account && (Number(account.credit_limit) > 0 ? `اعتبار خرید: ${formatMoney(account.credit_limit)}${debt ? ' (در حال استفاده)' : ''}` : 'بدون اعتبار خرید')}
          loading={isPending}
        />
      </StatGrid>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
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

/**
 * Every other browser signed in to the agent's panel — with a link opened on another device, a phone lost — signed out
 * at once, after a second look; this one stays.
 */
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
