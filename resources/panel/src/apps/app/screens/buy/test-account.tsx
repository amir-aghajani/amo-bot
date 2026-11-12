import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CircleCheckBig, FlaskConical, Hourglass, MapPin, ServerOff } from 'lucide-react'
import { useNavigate } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { Choices } from '@/apps/app/parts/checkout/choice'
import { result } from '@/apps/app/telegram/haptics'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Fact, Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import { IconTile } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { StoreSubscription, StoreTestAccountResponse } from '@/lib/api-types'
import { formatBytes, formatDeadline, formatDuration, MEGABYTE } from '@/lib/format'

/*
 * «اکانت تست رایگان» (`/test`): the servers giving a free test now, each with its traffic and hours (counted from the
 * first connection, when they are) — or, when the customer can take none, why, as the bot says it (`GET /test-account`;
 * a 404 while the shop gives none). Taking one (`POST /test-account`) makes the service at once: the screen shows it
 * and opens it.
 */

type Offer = StoreTestAccountResponse['offers'][number]

const trafficOf = (megabytes: number): string => (megabytes > 0 ? formatBytes(megabytes * MEGABYTE) : 'حجم نامحدود')
const hoursOf = (hours: number): string => (hours > 0 ? formatDuration(hours) : 'زمان نامحدود')

/** An offer's line: its traffic and its hours, and from when they count. */
const offerLine = (offer: Offer): string => `${trafficOf(offer.traffic_mb)} · ${hoursOf(offer.duration_hours)}${offer.on_hold ? ' (از اولین اتصال)' : ''}`

export default function TestAccountScreen() {
  const navigate = useNavigate()
  const offers = useQuery({ queryKey: keys.testAccount, queryFn: () => store.get<StoreTestAccountResponse>('/test-account') })
  const [picked, setPicked] = useState<number | null>(null)
  const take = useMutation({
    mutationFn: (serverId: number) => store.post('/test-account', { server_id: serverId }),
    meta: { quiet: true },
    onSuccess: () => {
      result('success')
      void queryClient.invalidateQueries({ queryKey: keys.subscriptions })
      // What the customer may take changed: read again when they come back, not under the service just made.
      void queryClient.invalidateQueries({ queryKey: keys.testAccount, refetchType: 'none' })
    },
    onError: (error) => {
      result('error')
      // The quota refused, or that server gives none now: the offers say what is left.
      if (fieldProblem(error, 'status') !== undefined || fieldProblem(error, 'server_id') !== undefined) void offers.refetch()
    },
  })

  if (take.data !== undefined) return <Taken subscription={take.data.subscription} />

  const closed = offers.error instanceof ApiError && offers.error.status === 404
  const data = offers.data
  const offer = data?.offers.find((candidate) => candidate.server.id === picked) ?? data?.offers[0] ?? null
  const open = data !== undefined && data.refusal === null && offer !== null

  return (
    <Screen back title="اکانت تست رایگان" bottom={open ? 'action' : 'none'}>
      {closed ? (
        <Empty icon={FlaskConical} title="اکانت تست در این فروشگاه فعال نیست" action={<Button onClick={() => navigate('/buy', { replace: true })}>دیدن پلن‌ها</Button>} />
      ) : offers.isPending ? (
        <LoadingBlocks count={3} />
      ) : offers.isError ? (
        <ErrorView error={offers.error} retry={() => void offers.refetch()} />
      ) : data !== undefined && data.refusal !== null ? (
        <Empty icon={Hourglass} title="الان اکانت تست نمی‌گیرید" text={data.refusal} action={<Button onClick={() => navigate('/buy', { replace: true })}>دیدن پلن‌ها</Button>} />
      ) : !open ? (
        <Empty icon={ServerOff} title="فعلا سروری اکانت تست نمی‌دهد" text="کمی بعد دوباره سر بزنید." />
      ) : (
        <>
          <section className="animate-rise flex flex-col items-center gap-3 px-6 pt-2 text-center">
            <IconTile icon={FlaskConical} size="2xl" color="var(--app-success)" look="soft" round />
            <p className="text-hint max-w-xs text-footnote">پیش از خرید، سرویس را رایگان امتحان کنید. سروری که می‌خواهید را انتخاب کنید.</p>
          </section>
          <Section title="سرور" bare>
            <Choices
              label="سرور"
              value={offer.server.id}
              onChange={(serverId) => {
                take.reset()
                setPicked(serverId)
              }}
              options={data.offers.map((candidate) => ({ value: candidate.server.id, title: candidate.server.name, detail: offerLine(candidate), icon: MapPin }))}
            />
          </Section>
          {take.isError && (
            <div role="alert" className="animate-rise">
              <Notice tone="danger">{problemOf(take.error)}</Notice>
            </div>
          )}
          <MainAction text="دریافت اکانت تست" enabled={!take.isPending} busy={take.isPending} onClick={() => take.mutate(offer.server.id)} />
        </>
      )}
    </Screen>
  )
}

/** The test account just made: what it is, and the way to it. */
function Taken({ subscription }: { subscription: StoreSubscription }) {
  const navigate = useNavigate()
  const { traffic, term } = subscription

  return (
    <Screen back="/" bottom="action">
      <section className="animate-rise flex flex-col items-center gap-2 px-6 pt-6 text-center">
        <IconTile icon={CircleCheckBig} size="2xl" color="var(--app-success)" look="soft" round className="mb-2" />
        <h2 className="text-title font-extrabold">اکانت تست شما آماده است</h2>
        <p className="text-hint max-w-xs text-body">در صفحه سرویس، لینک اتصال را بردارید و در برنامه خود وارد کنید.</p>
      </section>
      <Section>
        <Fact label="نام سرویس">
          <span className="ltr">{subscription.name}</span>
        </Fact>
        <Fact label="سرور">{subscription.server.name}</Fact>
        <Fact label="حجم">{traffic.limit_bytes > 0 ? formatBytes(traffic.limit_bytes) : 'نامحدود'}</Fact>
        <Fact label="مدت">
          {term.duration_hours > 0 ? formatDuration(term.duration_hours) : 'نامحدود'}
          {term.awaits_first_use && <span className="text-hint text-footnote"> (از اولین اتصال)</span>}
        </Fact>
        {term.expires_at !== null && <Fact label="پایان">{formatDeadline(term.expires_at)}</Fact>}
      </Section>
      <MainAction text="مشاهده سرویس" onClick={() => navigate(`/services/${subscription.id}`, { replace: true })} />
    </Screen>
  )
}
