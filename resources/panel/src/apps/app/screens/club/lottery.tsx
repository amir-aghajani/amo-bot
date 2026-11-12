import type { CSSProperties } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Medal, ShoppingBag, Ticket, Trophy, UserRound } from 'lucide-react'
import { useNavigate } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { useAppActive } from '@/apps/app/telegram/active'
import { Button } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { ACCENT_FILL, Badge, Countdown, IconTile } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { StoreLotteryResponse } from '@/lib/api-types'
import { formatDate, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * «قرعه‌کشی شبانه»: the time left until tonight's draw, ticking; what each winner gets and how many win; the customer's
 * own chances in it so far and how one gets them; and the last draw's winners — first names, the rest hidden —, the
 * customer among them marked. A shop that runs no draw answers 404: said so.
 */

/** A winner's row: its hairline from where its words start, past its 32px circle (as ui/section's Row draws it). */
const ROW_INSET = { '--row-inset': '3.75rem' } as CSSProperties

/** The pills on the draw's card, 32px, in the card's ink over a wash of it; the prize's words may take a second line. */
const PILL_WASH = { background: 'color-mix(in srgb, var(--app-on-accent) 18%, transparent)' }

export default function LotteryScreen() {
  const navigate = useNavigate()
  const active = useAppActive()
  const lottery = useQuery({
    queryKey: keys.lottery,
    queryFn: () => store.get<StoreLotteryResponse>('/lottery'),
    // Past the hour the draw is made within minutes: ask again, while the app is in front, until the next one is named.
    refetchInterval: (query) => (active && query.state.data !== undefined && new Date(query.state.data.next_draw_at).getTime() <= Date.now() ? 60_000 : false),
  })

  if (lottery.isError) {
    return (
      <Screen back title="قرعه‌کشی شبانه">
        {lottery.error instanceof ApiError && lottery.error.status === 404 ? (
          <Empty icon={Ticket} title="قرعه‌کشی فعال نیست" text="این فروشگاه فعلا قرعه‌کشی شبانه ندارد." />
        ) : (
          <ErrorView error={lottery.error} retry={() => void lottery.refetch()} />
        )}
      </Screen>
    )
  }
  if (lottery.data === undefined) {
    return (
      <Screen back title="قرعه‌کشی شبانه">
        <LoadingBlocks count={3} />
      </Screen>
    )
  }

  const draw = lottery.data
  const winners = draw.last_draw?.winners ?? []

  return (
    <Screen back title="قرعه‌کشی شبانه">
      <section
        aria-label="قرعه‌کشی بعدی"
        className="text-on-accent rounded-card animate-rise relative isolate flex flex-col items-center overflow-hidden p-5 text-center"
        style={{ background: 'linear-gradient(160deg, color-mix(in srgb, var(--app-accent) 80%, white), var(--app-accent) 45%, color-mix(in srgb, var(--app-accent) 60%, black))' }}
      >
        <span aria-hidden className="absolute -top-16 left-1/2 -z-10 size-56 -translate-x-1/2 rounded-full bg-white/25 blur-3xl" />
        <Ticket aria-hidden className="absolute -end-6 -bottom-6 -z-10 size-32 -rotate-12 opacity-15" />
        <p className="text-footnote font-medium opacity-85">قرعه‌کشی بعدی</p>
        <p className="mt-1 text-display font-extrabold" aria-live="off">
          <Countdown to={draw.next_draw_at} done="در حال قرعه‌کشی…" />
        </p>
        <p className="text-caption opacity-85">{formatDate(draw.next_draw_at)}</p>
        <div className="mt-4 flex max-w-full flex-wrap justify-center gap-2">
          <span className="inline-flex min-h-8 max-w-full items-center rounded-2xl px-3 py-1.5 text-footnote font-semibold" style={PILL_WASH}>
            {draw.prize ?? 'جایزه به زودی اعلام می‌شود'}
          </span>
          <span className="inline-flex h-8 shrink-0 items-center rounded-full px-3 text-footnote leading-none font-semibold whitespace-nowrap" style={PILL_WASH}>
            {`${formatNumber(draw.winners)} برنده`}
          </span>
        </div>
      </section>

      <div className="card flex items-start gap-4 p-4">
        <span
          className={cn(
            'inline-flex size-14 shrink-0 items-center justify-center rounded-2xl text-title leading-none font-extrabold',
            draw.entries > 0 ? 'bg-accent text-on-accent' : 'text-hint bg-fill',
          )}
        >
          {formatNumber(draw.entries)}
        </span>
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <p className="text-body font-semibold">{draw.entries > 0 ? `${formatNumber(draw.entries)} شانس در قرعه‌کشی امشب` : 'هنوز شانسی در قرعه‌کشی امشب ندارید'}</p>
          <p className="text-hint text-footnote">
            {draw.per_buyer
              ? 'هر خریدار یک شانس دارد: با یک خرید تا پیش از قرعه‌کشی، شما هم شرکت داده می‌شوید.'
              : 'هر خرید یک شانس است: هر سفارش پرداخت‌شده (خرید، تمدید یا حجم، زمان و کاربر اضافه) یک شانس دیگر به شما می‌دهد.'}
          </p>
        </div>
      </div>
      {draw.entries === 0 && (
        <Button variant="secondary" block icon={<ShoppingBag />} onClick={() => navigate('/buy')}>
          خرید سرویس و گرفتن شانس
        </Button>
      )}

      {draw.last_draw !== null && (
        <Section title="برنده‌های قرعه‌کشی قبل" footer={formatDate(draw.last_draw.drawn_at)}>
          {winners.length === 0 ? (
            <p className="text-hint px-4 py-5 text-center text-footnote">در قرعه‌کشی قبل برنده‌ای نبود.</p>
          ) : (
            winners.map((winner, index) => {
              const icon = index === 0 ? Trophy : index < 3 ? Medal : UserRound

              return (
                <div key={index} className={cn('list-row flex min-h-12 items-center gap-3 px-4', winner.me && 'bg-[color-mix(in_srgb,var(--app-accent)_12%,transparent)]')} style={ROW_INSET}>
                  <IconTile icon={icon} color={winner.me ? ACCENT_FILL : 'var(--app-warning)'} look={winner.me ? 'solid' : 'soft'} round />
                  <span className={cn('min-w-0 flex-1 truncate text-body', winner.me && 'font-bold')}>{winner.name}</span>
                  {winner.me && <Badge tone="success">شما</Badge>}
                </div>
              )
            })
          )}
        </Section>
      )}
      {winners.some((winner) => winner.me) && <p className="text-center text-footnote">تبریک! شما از برنده‌های قرعه‌کشی قبل هستید.</p>}
    </Screen>
  )
}
