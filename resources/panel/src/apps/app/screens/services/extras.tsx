import { useState, type ChangeEvent, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BatteryCharging, PenLine, WifiOff } from 'lucide-react'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { DiscountCodeField, useDiscountCheck, type CodeContext } from '@/apps/app/parts/checkout/discount-code'
import { OrderNotice, repriced, useOrdering } from '@/apps/app/parts/checkout/ordering'
import { PaymentMethods, usePaymentChoice } from '@/apps/app/parts/checkout/payment-methods'
import { discountLines, PriceSummary } from '@/apps/app/parts/checkout/price-summary'
import { useSettled } from '@/apps/app/parts/checkout/settled'
import { serviceTitle, useService } from '@/apps/app/parts/service'
import { serviceId } from '@/apps/app/screens/services/parts/service'
import { selection } from '@/apps/app/telegram/haptics'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Field, Segmented, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import { Badge, Price } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { DiscountLine, StoreCheckoutResponse, StoreExtraOffer, StoreExtrasResponse, StoreSubscription } from '@/lib/api-types'
import { formatDays, formatDevices, formatGb, formatMoney, formatNumber, wholeNumberOf } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * «حجم، زمان و کاربر اضافه» for a service, as the bot's «🔋 حجم اضافه», «⏳ زمان اضافه» and «👥 کاربر اضافه» sell them:
 * the kinds it can take now (`GET …/extras`), each one's amounts as cards with their prices — the customer's after their
 * automatic discount, the shop's struck through beside it — and any amount typed while the shop sells by the unit; a
 * discount code, the way to pay, and the order (`POST …/extra-traffic|extra-time|extra-devices`) with its
 * Idempotency-Key and the price shown, then the payment screen.
 */

type Kind = 'traffic' | 'time' | 'devices'

interface KindWords {
  /** Its segment. */
  tab: string
  /** Its amounts in words: «۱۰ گیگابایت», «۷ روز», «۲ کاربر». */
  words: (count: number) => string
  /** One unit, for the price of one: «هر گیگابایت». */
  each: string
  /** The field typed into, and its refusal's words. */
  label: string
  order: 'extra_traffic' | 'extra_time' | 'extra_devices'
  field: 'gb' | 'days' | 'devices'
}

const KINDS: Record<Kind, KindWords> = {
  traffic: { tab: 'حجم', words: formatGb, each: 'هر گیگابایت', label: 'حجم (گیگابایت)', order: 'extra_traffic', field: 'gb' },
  time: { tab: 'زمان', words: formatDays, each: 'هر روز', label: 'مدت (روز)', order: 'extra_time', field: 'days' },
  devices: { tab: 'کاربر', words: formatDevices, each: 'هر کاربر', label: 'تعداد کاربر', order: 'extra_devices', field: 'devices' },
}

const ORDER: Kind[] = ['traffic', 'time', 'devices']

/** The fields of an extra's order the screen shows a refusal under, beside their inputs. */
const SHOWN = ['method_id', 'discount_code'] as const

/** How long a typed amount rests before it is priced. */
const SETTLE_MS = 400

/** An extra's order: its kind, its amount, and the rest of its body. */
interface ExtraRequest {
  kind: Kind
  quantity: number
  rest: { method_id: number; discount_code?: string; expected_amount?: string }
}

/** The amount picked: one of the offer's, or one typed. */
type Amount = { option: number } | { typed: true } | null

export default function ExtrasScreen() {
  const navigate = useNavigate()
  const id = serviceId(useParams().id)
  const service = useService(id)
  const offer = useQuery({
    queryKey: [...keys.subscription(id), 'extras'],
    queryFn: () => store.get<StoreExtrasResponse>(`/subscriptions/${id}/extras`),
    enabled: !Number.isNaN(id),
  })
  const subscription = service.data?.subscription
  const gone = Number.isNaN(id) || [service.error, offer.error].some((error) => error instanceof ApiError && error.status === 404)
  const failed = service.isError ? service.error : offer.isError ? offer.error : null

  return (
    <Screen back title="خرید اضافه" subtitle={subscription === undefined ? undefined : serviceTitle(subscription)} bottom="action">
      {gone ? (
        <Empty icon={WifiOff} title="این سرویس در حساب شما نیست" action={<Button onClick={() => navigate('/services', { replace: true })}>سرویس‌های من</Button>} />
      ) : subscription !== undefined && offer.data !== undefined ? (
        // Shown while there is something to show: a read again that failed takes no checkout (nor its attempt) away. An
        // offer not read again holds pay until it is.
        <>
          {offer.isError && (
            <div role="alert">
              <Notice tone="danger">
                {`${problemOf(offer.error)} `}
                <button type="button" className="text-accent-text font-semibold" onClick={() => void offer.refetch()}>
                  تلاش دوباره
                </button>
              </Notice>
            </div>
          )}
          <Extras service={subscription} offer={offer.data.extras} fresh={!offer.isFetching && !offer.isError} refresh={() => void offer.refetch()} />
        </>
      ) : failed !== null ? (
        <ErrorView
          error={failed}
          retry={() => {
            void service.refetch()
            void offer.refetch()
          }}
        />
      ) : (
        <LoadingBlocks count={4} />
      )}
    </Screen>
  )
}

interface ExtrasProps {
  service: StoreSubscription
  offer: StoreExtrasResponse['extras']
  /** The offer as the shop sells it now (not while it is read again). */
  fresh: boolean
  /** Read the offer again: its prices changed. */
  refresh: () => void
}

function Extras({ service, offer, fresh, refresh }: ExtrasProps) {
  const kinds = ORDER.filter((kind) => offer[kind] !== null)
  const [asked] = useSearchParams()
  const [chosen, setChosen] = useState<Kind | null>(() => kinds.find((kind) => kind === asked.get('kind')) ?? null)
  const kind = chosen !== null && kinds.includes(chosen) ? chosen : (kinds[0] ?? null)
  const [amount, setAmount] = useState<Amount>(null)
  const [typed, setTyped] = useState('')
  const settledTyped = useSettled(typed, SETTLE_MS)

  const sale = kind === null ? null : offer[kind]
  const words = kind === null ? null : KINDS[kind]
  const price = sale === null || amount === null ? null : priceOf(sale, amount, settledTyped)
  const check: CodeContext | null = words === null || price === null ? null : { for: words.order, subscription_id: service.id, ...quantityOf(words.field, price.quantity) }
  const code = useDiscountCheck(check)
  const quote = code.applied?.quote ?? null
  const total = price === null ? undefined : (quote?.amount ?? price.amount)
  const payment = usePaymentChoice(words?.order ?? 'extra_traffic', total)
  const ordering = useOrdering(
    (request: ExtraRequest, key) => send(service.id, request, key),
    (error) => {
      if (repriced(error)) {
        refresh()
        code.recheck()
      } else if (fieldProblem(error, 'method_id') !== undefined) {
        void payment.methods.refetch()
      }
    },
  )
  const { changing } = ordering
  const request: ExtraRequest | null =
    kind === null || price === null || total === undefined || payment.method === null || !payment.ready || !fresh || code.busy || settledTyped !== typed
      ? null
      : {
          kind,
          quantity: price.quantity,
          rest: {
            method_id: payment.method.id,
            ...(code.applied === null ? {} : { discount_code: code.applied.code }),
            // A typed count of devices is charged prorated as a whole, which only the shop works out: no amount is held to.
            ...(price.exact || quote !== null ? { expected_amount: total } : {}),
          },
        }

  if (kind === null || sale === null || words === null) {
    return <Empty icon={BatteryCharging} title="الان چیزی برای این سرویس فروخته نمی‌شود" text="حجم، زمان یا کاربر اضافه برای این سرویس در دسترس نیست." />
  }
  const pick = changing((next: Amount) => {
    setAmount(next)
    if (next !== null && 'option' in next) setTyped('')
  })
  const count = wholeNumberOf(typed)
  const wrongTyped = sale.custom !== null && typed.trim() !== '' && (count === null || count < sale.custom.min || count > sale.custom.max)

  return (
    <>
      {kinds.length > 1 && (
        <Segmented
          value={kind}
          options={kinds.map((value) => ({ value, label: KINDS[value].tab }))}
          onChange={changing((value: Kind) => {
            setChosen(value)
            setAmount(null)
            setTyped('')
          })}
        />
      )}

      {(sale.discount !== null || sale.offer_price !== null) && (
        <div className="flex flex-wrap items-center gap-2 px-4">
          {sale.discount !== null && <Badge tone="success">{sale.discount.label}</Badge>}
          {sale.offer_price !== null && (
            <span className="text-hint text-footnote">
              {words.each}: {formatMoney(sale.offer_price)}
            </span>
          )}
        </div>
      )}
      {kind === 'devices' && sale.days !== null && <Notice>{`قیمت‌ها برای ${formatNumber(sale.days)} روز مانده از دوره فعلی است؛ کاربرهای اضافه تا پایان همین دوره می‌مانند.`}</Notice>}

      <Section title="مقدار" bare>
        <div className="grid grid-cols-2 gap-3" role="radiogroup" aria-label="مقدار">
          {sale.options.map((option) => (
            <AmountTile key={option.quantity} picked={amount !== null && 'option' in amount && amount.option === option.quantity} onPick={() => pick({ option: option.quantity })}>
              <span className="text-heading font-bold">{words.words(option.quantity)}</span>
              <Price amount={option.offer_price} before={option.price} className="text-footnote" />
            </AmountTile>
          ))}
          {sale.custom !== null && (
            <AmountTile picked={amount !== null && 'typed' in amount} onPick={() => pick({ typed: true })}>
              <span className="inline-flex items-center gap-1.5 text-heading font-bold">
                <PenLine aria-hidden className="text-accent-text size-4 shrink-0" />
                مقدار دلخواه
              </span>
              <span className="text-hint text-footnote">{`${formatNumber(sale.custom.min)} تا ${formatNumber(sale.custom.max)}`}</span>
            </AmountTile>
          )}
        </div>
      </Section>

      {amount !== null && 'typed' in amount && sale.custom !== null && (
        <Field
          label={words.label}
          hint={price === null ? `عددی از ${formatNumber(sale.custom.min)} تا ${formatNumber(sale.custom.max)}` : undefined}
          error={wrongTyped ? `عددی از ${formatNumber(sale.custom.min)} تا ${formatNumber(sale.custom.max)} بنویسید.` : undefined}
        >
          {(fieldId) => (
            <TextInput id={fieldId} ltr inputMode="numeric" autoFocus value={typed} invalid={wrongTyped} onChange={changing((event: ChangeEvent<HTMLInputElement>) => setTyped(event.target.value))} />
          )}
        </Field>
      )}

      {price !== null && total !== undefined && (
        <>
          <DiscountCodeField state={{ ...code, error: code.error ?? ordering.problem('discount_code'), apply: changing(code.apply), remove: changing(code.remove) }} />
          <PaymentMethods choice={{ ...payment, choose: changing(payment.choose) }} error={ordering.problem('method_id')} />
          <PriceSummary
            lines={[{ label: `${words.tab} اضافه (${words.words(price.quantity)})`, amount: quote?.base ?? price.before }, ...discountLines(quote?.discounts ?? price.discounts)]}
            total={total}
            before={quote?.base ?? price.before}
          />
          {!price.exact && quote === null && <p className="text-hint -mt-2 px-4 text-caption">مبلغ دقیق تعداد دلخواه را فروشگاه حساب می‌کند و ممکن است یکی دو تومان کمتر باشد.</p>}
          <OrderNotice ordering={ordering} fields={SHOWN} />
        </>
      )}

      <MainAction
        text={total === undefined ? null : `پرداخت ${formatMoney(total)}`}
        enabled={request !== null && !ordering.held}
        busy={ordering.busy}
        shine
        onClick={() => request !== null && ordering.send(request)}
      />
    </>
  )
}

/** An amount and what it costs: before the automatic discount, the discount as a line, and the price to pay. */
interface Priced {
  quantity: number
  before: string
  amount: string
  discounts: DiscountLine[]
  /** Whether `amount` is the shop's own figure (an option's, or a typed amount of traffic or time). */
  exact: boolean
}

/**
 * The price of the amount picked — an option's as the shop gave it; a typed one's as the shop works it out (the unit
 * price times it, less the discount's percent of that rounded down) —; null while the typed amount is none of the offer.
 */
function priceOf(sale: StoreExtraOffer, amount: NonNullable<Amount>, typed: string): Priced | null {
  const line = (before: number, after: number): DiscountLine[] =>
    sale.discount === null || before <= after ? [] : [{ source: sale.discount.source, label: sale.discount.label, amount: String(before - after), percent: sale.discount.percent }]
  if ('option' in amount) {
    const option = sale.options.find((candidate) => candidate.quantity === amount.option)
    if (option === undefined) return null
    return { quantity: option.quantity, before: option.price, amount: option.offer_price, discounts: line(Number(option.price), Number(option.offer_price)), exact: true }
  }
  const count = wholeNumberOf(typed)
  if (sale.custom === null || sale.price === null || count === null || count < sale.custom.min || count > sale.custom.max) return null
  const before = Number(sale.price) * count
  const after = before - Math.floor((before * (sale.discount?.percent ?? 0)) / 100)

  return { quantity: count, before: String(before), amount: String(after), discounts: line(before, after), exact: sale.days === null }
}

/** An amount under its kind's own field: `gb`, `days` or `devices`. */
function quantityOf(field: KindWords['field'], quantity: number): { gb: number } | { days: number } | { devices: number } {
  return field === 'gb' ? { gb: quantity } : field === 'days' ? { days: quantity } : { devices: quantity }
}

/** The order of an extra, at its own address and in its own field. */
function send(id: number, request: ExtraRequest, key: string): Promise<StoreCheckoutResponse> {
  const { kind, quantity, rest } = request
  if (kind === 'traffic') return store.post(`/subscriptions/${id}/extra-traffic`, { gb: quantity, ...rest }, { idempotencyKey: key })
  if (kind === 'time') return store.post(`/subscriptions/${id}/extra-time`, { days: quantity, ...rest }, { idempotencyKey: key })

  return store.post(`/subscriptions/${id}/extra-devices`, { devices: quantity, ...rest }, { idempotencyKey: key })
}

function AmountTile({ picked, onPick, children }: { picked: boolean; onPick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={picked}
      onClick={() => {
        if (!picked) selection()
        onPick()
      }}
      className={cn(
        'card press flex min-h-20 flex-col items-start justify-center gap-1 p-4 text-start transition-shadow',
        // Picked, the card's lift and the accent's ring are drawn as one shadow (as parts/checkout/choice draws a card).
        picked && 'inset-ring-accent-text shadow-card inset-ring-2',
      )}
    >
      {children}
    </button>
  )
}
