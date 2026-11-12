import { useEffect, useRef, useState, type ComponentType, type CSSProperties } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CalendarPlus, CircleSlash, FerrisWheel, Gauge, Gift, Package, PartyPopper, RotateCcw, Wallet } from 'lucide-react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { keys, queryClient } from '@/apps/app/lib/query'
import { useAccount } from '@/apps/app/lib/session'
import { problemOf, store } from '@/apps/app/lib/store'
import { glide, glideAt, RAMP, restingAngle, SETTLE, settleAt, sliceAt, SPEED, type Glide } from '@/apps/app/screens/club/parts/spin'
import { sliceTile, WheelFace } from '@/apps/app/screens/club/parts/wheel-face'
import { result, selection, tap } from '@/apps/app/telegram/haptics'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Section } from '@/apps/app/ui/section'
import { Sheet } from '@/apps/app/ui/sheet'
import { Empty, ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { Badge, Countdown, IconTile, useReached } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { StoreAccountResponse, StoreWheelResponse, StoreWheelSlice, StoreWheelSpinResponse, WheelPrizeKind } from '@/lib/api-types'
import { formatBalance, formatMoney, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * «گردونه شانس»: the wheel of the shop's prizes, tilted back a little for depth, what a spin costs and how often one may
 * be. «چرخاندن» spins it at once while the shop draws the slice (`POST /wheel/spin`), then glides it to rest on the slice
 * the shop drew — never one picked here —, the phone ticking as slices pass the pointer, and says what came of it: the
 * prize, «پوچ», or why it could not be given (the cost then back in the wallet). Before the next turn, a countdown.
 * With reduced motion, the wheel stops on its slice at once. What the spin changed (the next turn, the balance) is kept
 * the moment the shop answers, not once the wheel is still: left mid-spin, the wheel stops and the spin still counts.
 */

/** Each kind of prize's word and icon, for the list under the wheel. */
const KIND: Record<WheelPrizeKind, { word: string; icon: ComponentType<{ className?: string }> }> = {
  wallet: { word: 'شارژ کیف پول', icon: Wallet },
  traffic: { word: 'حجم', icon: Gauge },
  days: { word: 'زمان', icon: CalendarPlus },
  plan: { word: 'سرویس رایگان', icon: Package },
  nothing: { word: 'پوچ', icon: CircleSlash },
}

/** A prize's row: its hairline from where its words start, past its 32px tile (as ui/section's Row draws it). */
const ROW_INSET = { '--row-inset': '3.75rem' } as CSSProperties

/** At most one haptic tick in this many milliseconds, however fast the slices pass. */
const TICK_GAP = 70

const reducedMotion = (): boolean => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

/** What a spin's answer changes beyond the wheel: its next turn and slices, the balance, the services a prize went to. */
function keepSpin(answer: StoreWheelSpinResponse): void {
  const slice = answer.slices[answer.landed]
  queryClient.setQueryData<StoreWheelResponse>(keys.wheel, (old) => (old === undefined ? old : { ...old, slices: answer.slices, next_spin_at: answer.next_spin_at }))
  queryClient.setQueryData<StoreAccountResponse>(keys.me, (old) => (old === undefined ? old : { ...old, customer: { ...old.customer, balance: answer.balance } }))
  void queryClient.invalidateQueries({ queryKey: keys.wallet })
  if (slice !== undefined && slice.kind !== 'wallet' && slice.kind !== 'nothing') void queryClient.invalidateQueries({ queryKey: keys.subscriptions })
}

/** A burst of confetti from the top of the result, in the shop's colours — not with reduced motion. */
function Confetti() {
  const box = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const pieces = Array.from(box.current?.children ?? []) as HTMLElement[]
    const animations = pieces.map((piece, index) => {
      const angle = (index / pieces.length) * Math.PI * 2 + Math.random() * 0.4
      const distance = 90 + Math.random() * 90
      const x = Math.cos(angle) * distance
      const y = Math.sin(angle) * distance * 0.7 + 60
      return piece.animate?.(
        [
          { transform: 'translate(0, 0) rotate(0deg) scale(1)', opacity: 1 },
          { transform: `translate(${x}px, ${y}px) rotate(${Math.random() * 540 - 270}deg) scale(0.6)`, opacity: 0 },
        ],
        { duration: 1100 + Math.random() * 500, easing: 'cubic-bezier(0.15, 0.7, 0.3, 1)', fill: 'forwards' },
      )
    })
    return () =>
      animations.forEach((animation) => {
        // Cancelling rejects its `finished`; nobody waits on it.
        animation?.finished.catch(() => undefined)
        animation?.cancel()
      })
  }, [])

  return (
    <div ref={box} aria-hidden className="pointer-events-none absolute top-10 left-1/2 size-0">
      {Array.from({ length: 26 }, (_, index) => (
        <span
          key={index}
          className={cn('absolute block', index % 3 === 0 ? 'size-2 rounded-full' : 'h-1.5 w-2.5 rounded-[2px]')}
          style={{ background: ['var(--app-accent)', 'var(--app-success)', 'var(--app-warning)', 'color-mix(in srgb, var(--app-accent) 40%, white)'][index % 4] }}
        />
      ))}
    </div>
  )
}

/** What came of a spin: the prize won, «پوچ», or why it could not be given. */
function SpinResult({ spin, onClose }: { spin: StoreWheelSpinResponse | null; onClose: () => void }) {
  const slice = spin === null ? undefined : spin.slices[spin.landed]
  const won = spin !== null && spin.given && slice?.kind !== 'nothing'
  const blank = slice?.kind === 'nothing'
  const Icon = won ? PartyPopper : blank ? CircleSlash : RotateCcw
  const tone = won ? 'var(--app-success)' : blank ? 'var(--app-hint)' : 'var(--app-warning)'

  return (
    <Sheet open={spin !== null} onClose={onClose}>
      {spin !== null && (
        <div className="relative flex flex-col items-center gap-2 pt-2 text-center">
          {won && !reducedMotion() && <Confetti />}
          <IconTile icon={Icon} size="2xl" color={tone} look="soft" round className="animate-rise mb-2" />
          <h2 className="text-title font-bold">{won ? 'تبریک! برنده شدید' : blank ? 'این بار پوچ بود' : 'جایزه داده نشد'}</h2>
          {slice !== undefined && !blank && <Badge tone={won ? 'success' : 'warning'}>{slice.label}</Badge>}
          <p className="max-w-sm text-body">{spin.words}</p>
          {spin.refunded && (
            <div className="w-full text-start">
              <Notice tone="success">هزینه چرخش به کیف پول شما برگشت.</Notice>
            </div>
          )}
          <p className="text-hint text-footnote">{`موجودی کیف پول: ${formatBalance(spin.balance)}`}</p>
          <Button block className="mt-4" onClick={onClose}>
            باشه
          </Button>
        </div>
      )}
    </Sheet>
  )
}

export default function WheelScreen() {
  const navigate = useNavigate()
  const { data: account } = useAccount()
  const wheel = useQuery({ queryKey: keys.wheel, queryFn: () => store.get<StoreWheelResponse>('/wheel') })
  /** The slices drawn: the wheel's, then the ones the spin was drawn among. */
  const [drawn, setDrawn] = useState<StoreWheelSlice[] | null>(null)
  const [landed, setLanded] = useState<number | null>(null)
  const [shown, setShown] = useState<StoreWheelSpinResponse | null>(null)
  const rotor = useRef<SVGGElement>(null)
  const pointer = useRef<HTMLDivElement>(null)
  const angle = useRef(0)
  const frame = useRef<number | null>(null)
  /** Stops the spin's motion where it is and lets its run end (as the screen goes). */
  const stop = useRef<(() => void) | null>(null)
  /** Whether the screen is still there to show what came of a spin. */
  const live = useRef(true)
  const tick = useRef({ slice: -1, at: 0 })

  const slices = drawn ?? wheel.data?.slices ?? []
  const nextSpin = wheel.data?.next_spin_at ?? null
  const ready = useReached(nextSpin)

  useEffect(() => {
    live.current = true
    return () => {
      live.current = false
      stop.current?.()
    }
  }, [])

  /** Turn the wheel to `rotation` (at the frame's time `now`); a slice passing the pointer ticks the phone and flicks the pointer. */
  const paint = (rotation: number, count: number, now = performance.now()) => {
    angle.current = rotation
    rotor.current?.setAttribute('transform', `rotate(${(rotation % 360).toFixed(2)})`)
    const slice = sliceAt(rotation, count)
    if (slice === tick.current.slice) return
    tick.current.slice = slice
    if (now - tick.current.at < TICK_GAP) return
    tick.current.at = now
    selection()
    pointer.current?.animate?.([{ transform: 'rotate(0deg)' }, { transform: 'rotate(-16deg)', offset: 0.3 }, { transform: 'rotate(0deg)' }], { duration: 170, easing: 'ease-out' })
  }

  /**
   * Spin until `landing` says where to rest — the slice's index among `count`, or null when the spin failed —, then
   * glide there (or slow to a stop). Resolves once the wheel is still.
   */
  const run = (landing: Promise<{ index: number; count: number } | null>, count: number) =>
    new Promise<void>((resolve) => {
      const end = () => {
        frame.current = null
        stop.current = null
        resolve()
      }
      stop.current = () => {
        if (frame.current !== null) cancelAnimationFrame(frame.current)
        end()
      }
      let target: { index: number; count: number } | null | undefined
      void landing.then((answer) => {
        target = answer
      })
      /** The first frame's time: every step counts from the frames' own clock. */
      let start: number | null = null
      let last = 0
      let speed = 0
      /** Once the shop answered: the way to rest, among how many slices, and when it began. */
      let rest: { path: Glide; count: number; start: number } | null = null

      const step = (now: number) => {
        if (start === null) {
          start = now
          last = now
        }
        if (rest === null) {
          speed = Math.min(SPEED, (SPEED * (now - start)) / (RAMP * 1000))
          paint(angle.current + (speed * (now - last)) / 1000, count, now)
          const answer = target
          if (answer !== undefined && now - start >= RAMP * 1000) {
            rest =
              answer === null
                ? { path: { from: angle.current, to: angle.current + speed * 0.4, overshoot: 0, duration: 1.2 }, count, start: now }
                : { path: glide(angle.current, speed, restingAngle(answer.index, answer.count, Math.random() * 0.6 - 0.3), answer.count), count: answer.count, start: now }
          }
        } else {
          const elapsed = (now - rest.start) / 1000
          if (elapsed < rest.path.duration) {
            paint(glideAt(rest.path, elapsed), rest.count, now)
          } else if (elapsed < rest.path.duration + SETTLE) {
            paint(settleAt(rest.path, elapsed - rest.path.duration), rest.count, now)
          } else {
            paint(rest.path.to, rest.count, now)
            end()
            return
          }
        }
        last = now
        frame.current = requestAnimationFrame(step)
      }
      frame.current = requestAnimationFrame(step)
    })

  const spin = useMutation({
    mutationFn: async () => {
      const request = store.post('/wheel/spin')
      const landing = request.then(
        (answer) => {
          keepSpin(answer)
          setDrawn(answer.slices)
          return { index: answer.landed, count: answer.slices.length }
        },
        () => null,
      )
      if (reducedMotion()) {
        const target = await landing
        if (target !== null) paint(restingAngle(target.index, target.count), target.count)
      } else {
        await run(landing, slices.length)
      }
      return request
    },
    meta: { quiet: true },
    onMutate: () => {
      tap('medium')
      setLanded(null)
    },
    onSuccess: (answer) => {
      // Left mid-spin: what it changed is kept already (keepSpin), and there is nothing left to show it on.
      if (!live.current) return
      setLanded(answer.landed)
      const slice = answer.slices[answer.landed]
      result(answer.given && slice?.kind !== 'nothing' ? 'success' : 'warning')
      setShown(answer)
    },
    onError: (error) => {
      result('error')
      toast.error(problemOf(error))
      void wheel.refetch()
    },
  })

  if (wheel.isError) {
    return (
      <Screen back title="گردونه شانس">
        {wheel.error instanceof ApiError && wheel.error.status === 422 ? (
          <Empty icon={FerrisWheel} title="گردونه شانس" text={problemOf(wheel.error)} />
        ) : (
          <ErrorView error={wheel.error} retry={() => void wheel.refetch()} />
        )}
      </Screen>
    )
  }

  const info = wheel.data
  const free = info === undefined || Number(info.cost) === 0
  const short = info !== undefined && !free && account !== undefined && Number(account.customer.balance) < Number(info.cost)

  return (
    <Screen back title="گردونه شانس" subtitle={info === undefined ? undefined : `هر ${formatNumber(info.cooldown_hours)} ساعت یک بار`} bottom="action">
      <div className="relative mx-auto w-full max-w-88 pt-3 pb-6" style={{ perspective: '1100px' }}>
        <div className="relative" style={{ transform: 'rotateX(14deg)', transformStyle: 'preserve-3d' }}>
          {info === undefined ? (
            <Skeleton className="aspect-square w-full rounded-full" />
          ) : slices.length === 0 ? (
            <Empty icon={FerrisWheel} title="گردونه فعلا جایزه‌ای ندارد" />
          ) : (
            <WheelFace slices={slices} rotor={rotor} spinning={spin.isPending} />
          )}
          {/* The pointer, over the top of the rim: its flap flicks as each slice passes. */}
          <div ref={pointer} aria-hidden className="absolute -top-1.5 left-1/2 z-10 -ml-3.5 w-7 origin-[50%_20%] drop-shadow-[0_3px_4px_rgb(0_0_0/0.3)]">
            <svg viewBox="0 0 36 46" className="block w-full">
              <path d="M18 44 L4 14 A15 15 0 1 1 32 14 Z" fill="#ffffff" />
              <circle cx="18" cy="15" r="6" style={{ fill: 'var(--app-accent)' }} />
            </svg>
          </div>
        </div>
        {/* Its shadow on the page. */}
        <div aria-hidden className="mx-auto -mt-2 h-5 w-3/4 rounded-full bg-black/25 blur-md" />
      </div>

      {info !== undefined && (
        <div className="flex justify-center">
          <span
            className="text-accent-text inline-flex h-8 items-center rounded-full px-4 text-footnote leading-none font-semibold whitespace-nowrap"
            style={{ background: 'color-mix(in srgb, var(--app-accent-text) 12%, transparent)' }}
          >
            {free ? 'چرخش رایگان' : `هزینه هر چرخش: ${formatMoney(info.cost)}`}
          </span>
        </div>
      )}

      {!ready && nextSpin !== null && !spin.isPending && (
        <div className="card flex items-center justify-between gap-3 p-4">
          <span className="text-hint text-footnote">نوبت بعدی شما</span>
          <span className="text-heading font-bold">
            <Countdown to={nextSpin} done="همین حالا" />
          </span>
        </div>
      )}

      {short && ready && (
        <Notice tone="warning">
          <div className="flex flex-col items-start gap-3">
            <p>موجودی کیف پول برای چرخش کافی نیست.</p>
            <Button size="sm" variant="secondary" onClick={() => navigate('/wallet/top-up')}>
              شارژ کیف پول
            </Button>
          </div>
        </Notice>
      )}

      {slices.length > 0 && (
        <Section title="جایزه‌های گردونه">
          {slices.map((slice, index) => {
            const { word, icon } = KIND[slice.kind]
            const here = index === landed

            return (
              <div key={index} aria-current={here || undefined} className={cn('list-row flex min-h-12 items-center gap-3 px-4', here && 'bg-fill')} style={ROW_INSET}>
                <IconTile icon={icon} {...sliceTile(index, slices.length)} />
                <span className="flex min-w-0 flex-1 flex-col gap-0.5 py-3">
                  <span className="truncate text-body">{slice.label}</span>
                  <span className="text-hint text-footnote">{word}</span>
                </span>
                {here && (
                  <Badge tone={slice.kind === 'nothing' ? 'hint' : 'success'}>
                    <Gift aria-hidden />
                    نتیجه چرخش
                  </Badge>
                )}
              </div>
            )
          })}
        </Section>
      )}

      <SpinResult spin={shown} onClose={() => setShown(null)} />

      {/* Put away while the result is shown: its sheet has its own button. Greyed out while the wallet cannot pay a spin. */}
      <MainAction
        text={shown !== null ? null : spin.isPending ? 'در حال چرخش…' : 'چرخاندن'}
        enabled={info !== undefined && slices.length > 0 && ready && !short}
        busy={spin.isPending}
        shine={ready && !short && !spin.isPending}
        onClick={() => spin.mutate()}
      />
    </Screen>
  )
}
