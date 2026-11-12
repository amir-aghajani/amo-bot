import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { LoaderCircle, MapPin, SlidersHorizontal } from 'lucide-react'
import { useNavigate } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { Choices } from '@/apps/app/parts/checkout/choice'
import { DiscountCodeField, type CodeState } from '@/apps/app/parts/checkout/discount-code'
import { OrderNotice, repriced, useOrdering } from '@/apps/app/parts/checkout/ordering'
import { PaymentMethods, usePaymentChoice } from '@/apps/app/parts/checkout/payment-methods'
import { discountLines, PriceSummary } from '@/apps/app/parts/checkout/price-summary'
import { useSettled } from '@/apps/app/parts/checkout/settled'
import { Quantity } from '@/apps/app/screens/buy/parts/quantity'
import { BY_PATTERN, nameReady, nameRequest, ServiceNameField, type ServiceName } from '@/apps/app/screens/buy/parts/service-name'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Row, Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import { Price } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { PriceTier, StoreCustomOrderRequest, StoreCustomQuoteResponse, StoreCustomServices, StoreCustomServicesResponse } from '@/lib/api-types'
import { formatDevices, formatGb, formatMoney, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * «سرویس دلخواه» (`/buy/custom`): a service of the traffic and days the customer chooses, on one of the builder's
 * servers, with its devices (`GET /custom-services`). Each quantity moves on a slider or by the shop's amounts; the
 * customer's own price — their discount, and a code once they apply one — is quoted as they settle
 * (`POST /custom-services/quote`), with the rate each quantity is priced at and the cheaper one ahead. Pay sends
 * `POST /custom-services/orders` at the quote shown, as a plan's checkout does.
 */

/** The fields of `POST /custom-services/orders` the screen shows a refusal under, beside their inputs. */
const SHOWN = ['server_id', 'name_mode', 'client_name', 'method_id', 'discount_code'] as const

/** How long a quantity rests before it is quoted. */
const SETTLE_MS = 350

export default function CustomScreen() {
  const navigate = useNavigate()
  const builder = useQuery({ queryKey: keys.customServices, queryFn: () => store.get<StoreCustomServicesResponse>('/custom-services') })

  if (builder.isPending) {
    return (
      <Screen back title="سرویس دلخواه">
        <LoadingBlocks count={4} />
      </Screen>
    )
  }
  if (builder.isError) {
    return (
      <Screen back title="سرویس دلخواه">
        <ErrorView error={builder.error} retry={() => void builder.refetch()} />
      </Screen>
    )
  }
  if (!builder.data.custom_services.offered) {
    return (
      <Screen back title="سرویس دلخواه">
        <Empty
          icon={SlidersHorizontal}
          title="ساخت سرویس دلخواه الان در دسترس نیست"
          text="از پلن‌های فروشگاه یکی را انتخاب کنید، یا کمی بعد دوباره سر بزنید."
          action={<Button onClick={() => navigate('/buy', { replace: true })}>دیدن پلن‌ها</Button>}
        />
      </Screen>
    )
  }

  return <Builder offer={builder.data.custom_services} />
}

/** What a quote is asked for. */
interface Wanted {
  gb: number
  days: number
  serverId: number | null
  code: string | null
}

/** A quote, and why the code sent with it did not hold (the price is then without it); the request it answers. */
interface Quoted {
  wanted: Wanted
  quote: StoreCustomQuoteResponse['quote']
  codeProblem: string | null
}

/** The rate a quantity is priced at (the last tier it reaches), and the next tier when it is cheaper. */
function ratesOf(tiers: readonly PriceTier[], value: number): { now: PriceTier | null; ahead: PriceTier | null } {
  const now = tiers.filter((tier) => tier.from <= value).at(-1) ?? null
  const ahead = tiers.find((tier) => tier.from > value) ?? null

  return { now, ahead: ahead !== null && now !== null && Number(ahead.price) < Number(now.price) ? ahead : null }
}

const formatDays = (days: number): string => `${formatNumber(days)} روز`

/** Whether two quotes are asked for the same. */
const same = (a: Wanted, b: Wanted): boolean => a.gb === b.gb && a.days === b.days && a.serverId === b.serverId && a.code === b.code

/** The code applied as the last quote took it — or why it did not —, while that quote was asked with it. */
function codeOf(answer: Quoted | undefined, code: string | null): Pick<CodeState, 'applied' | 'error'> {
  if (answer === undefined || code === null || answer.wanted.code !== code) return { applied: null }

  return answer.codeProblem === null ? { applied: { code, quote: answer.quote } } : { applied: null, error: answer.codeProblem }
}

function Builder({ offer }: { offer: StoreCustomServices }) {
  const { data: shop } = useShop()
  const selling = shop?.shop.taking_orders ?? true
  const [gb, setGb] = useState(() => offer.traffic.options[0] ?? offer.traffic.min)
  const [days, setDays] = useState(() => offer.days.options[0] ?? offer.days.min)
  const [serverId, setServerId] = useState<number | null>(offer.servers[0]?.id ?? null)
  const [name, setName] = useState<ServiceName>(BY_PATTERN)
  const [code, setCode] = useState<string | null>(null)

  const now = useMemo<Wanted>(() => ({ gb, days, serverId, code }), [gb, days, serverId, code])
  const wanted = useSettled(now, SETTLE_MS)
  const quoted = useQuery({
    queryKey: [...keys.customServices, 'quote', wanted],
    queryFn: async (): Promise<Quoted> => {
      const base = { gb: wanted.gb, days: wanted.days, ...(wanted.serverId === null ? {} : { server_id: wanted.serverId }) }
      try {
        const { quote } = await store.post('/custom-services/quote', { ...base, ...(wanted.code === null ? {} : { discount_code: wanted.code }) })
        return { wanted, quote, codeProblem: null }
      } catch (error) {
        // A code that does not hold — or one too many tried this hour — is the code's refusal: the price stands without it.
        const refused = fieldProblem(error, 'discount_code') ?? (error instanceof ApiError && error.status === 429 ? problemOf(error) : undefined)
        if (wanted.code === null || refused === undefined) throw error
        const { quote } = await store.post('/custom-services/quote', base)
        return { wanted, quote, codeProblem: refused }
      }
    },
    placeholderData: keepPreviousData,
    // Each code that does not hold costs a try: asked again only as the customer changes something.
    refetchOnWindowFocus: false,
    staleTime: 60_000,
  })
  const answer = quoted.data
  // The quote of what the screen shows now — not the last one's while the next is asked.
  const current = answer !== undefined && !quoted.isPlaceholderData && !quoted.isFetching && same(answer.wanted, now) ? answer : null
  const free = answer !== undefined && Number(answer.quote.amount) === 0
  // The ways to pay the last price quoted: read again as a new price comes, never emptied while a slider moves.
  const payment = usePaymentChoice('purchase', answer === undefined ? undefined : free ? null : answer.quote.amount)
  const method = free ? (payment.list[0] ?? null) : payment.method

  const ordering = useOrdering(
    (body: StoreCustomOrderRequest, key) => store.post('/custom-services/orders', body, { idempotencyKey: key }),
    (error) => {
      if (repriced(error)) void quoted.refetch()
      else if (fieldProblem(error, 'method_id') !== undefined) void payment.methods.refetch()
    },
  )
  const { changing } = ordering

  const quotedCode = codeOf(answer, code)
  const codeState: CodeState = {
    applied: quotedCode.applied,
    error: quotedCode.error ?? ordering.problem('discount_code'),
    // Checked while it waits for what it goes with to settle and while its quote is asked — not after a quote that failed.
    busy: code !== null && (quoted.isFetching || !same(wanted, now)),
    apply: changing((typed: string) => setCode(typed)),
    remove: changing(() => setCode(null)),
  }

  const server = offer.servers.find((candidate) => candidate.id === serverId) ?? null
  const traffic = ratesOf(offer.traffic.tiers, gb)
  const term = ratesOf(offer.days.tiers, days)
  const ready = selling && current !== null && method !== null && (offer.servers.length < 2 || server !== null) && nameReady(name) && (free || payment.ready) && !ordering.held

  const pay = () => {
    if (current === null || method === null) return
    ordering.send({
      gb: current.wanted.gb,
      days: current.wanted.days,
      ...(server === null ? {} : { server_id: server.id }),
      method_id: method.id,
      ...nameRequest(name),
      ...(codeState.applied === null ? {} : { discount_code: codeState.applied.code }),
      expected_amount: current.quote.amount,
    })
  }

  return (
    <Screen back title="سرویس دلخواه" subtitle="حجم و مدت را خودتان انتخاب کنید" bottom="action">
      <section className="card animate-rise flex flex-col items-center gap-1 p-5 text-center" aria-busy={current === null}>
        <span className="text-hint text-footnote">قیمت سرویس شما</span>
        {answer === undefined ? (
          <LoaderCircle aria-hidden className="text-hint my-2 size-6 animate-spin" />
        ) : (
          <Price amount={answer.quote.amount} before={answer.quote.base} className={cn('text-display transition-opacity', current === null && 'opacity-50')} />
        )}
        <span className="text-hint text-footnote">
          {formatGb(gb)} · {formatDays(days)} · {offer.devices > 0 ? formatDevices(offer.devices) : 'کاربر نامحدود'}
        </span>
      </section>

      {!selling && <Notice tone="warning">فعلا خرید ممکن نیست؛ کمی بعد دوباره سر بزنید.</Notice>}

      <Quantity
        label="حجم"
        value={gb}
        min={offer.traffic.min}
        max={offer.traffic.max}
        options={offer.traffic.options}
        format={formatGb}
        onChange={changing(setGb)}
        footer={
          traffic.now === null
            ? undefined
            : `هر گیگابایت ${formatMoney(traffic.now.price)}${traffic.ahead === null ? '' : ` · از ${formatGb(traffic.ahead.from)}، هر گیگابایت ${formatMoney(traffic.ahead.price)}`}`
        }
      />
      <Quantity
        label="مدت"
        value={days}
        min={offer.days.min}
        max={offer.days.max}
        options={offer.days.options}
        format={formatDays}
        onChange={changing(setDays)}
        footer={term.now === null ? undefined : `هر روز ${formatMoney(term.now.price)}${term.ahead === null ? '' : ` · از ${formatDays(term.ahead.from)}، هر روز ${formatMoney(term.ahead.price)}`}`}
      />

      {offer.servers.length > 1 ? (
        <Section title="لوکیشن" bare>
          <Choices
            label="لوکیشن"
            columns={2}
            value={serverId}
            onChange={changing(setServerId)}
            options={offer.servers.map((candidate) => ({ value: candidate.id, title: candidate.name, icon: MapPin }))}
          />
          {ordering.problem('server_id') !== undefined && (
            <p className="px-4 text-caption text-danger" role="alert">
              {ordering.problem('server_id')}
            </p>
          )}
        </Section>
      ) : (
        server !== null && (
          <Section>
            <Row icon={MapPin} title="لوکیشن" value={server.name} />
          </Section>
        )
      )}

      <ServiceNameField modes={shop?.shop.name_modes ?? []} value={name} onChange={changing(setName)} error={ordering.problem('client_name') ?? ordering.problem('name_mode')} />

      <DiscountCodeField state={codeState} />

      {!free && answer !== undefined && <PaymentMethods choice={{ ...payment, choose: changing(payment.choose) }} error={ordering.problem('method_id')} />}

      {answer !== undefined && (
        <PriceSummary
          lines={[
            { label: `حجم (${formatGb(answer.quote.gb)})`, amount: answer.quote.traffic_price },
            { label: `مدت (${formatDays(answer.quote.days)})`, amount: answer.quote.days_price },
            ...discountLines(answer.quote.discounts),
          ]}
          total={answer.quote.amount}
          before={answer.quote.base}
        />
      )}

      {quoted.isError && <ErrorView error={quoted.error} retry={() => void quoted.refetch()} />}

      <OrderNotice ordering={ordering} fields={SHOWN} />

      {/* The last price quoted, held back until the quote of what is shown comes. */}
      <MainAction text={answer === undefined ? 'در حال محاسبه قیمت' : free ? 'دریافت رایگان' : `پرداخت ${formatMoney(answer.quote.amount)}`} shine enabled={ready} busy={ordering.busy} onClick={pay} />
    </Screen>
  )
}
