import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MapPin, PackageX } from 'lucide-react'
import { useLocation, useNavigate, useParams } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { fieldProblem, store } from '@/apps/app/lib/store'
import { Choices } from '@/apps/app/parts/checkout/choice'
import { DiscountCodeField, useDiscountCheck, type AppliedCode } from '@/apps/app/parts/checkout/discount-code'
import { OrderNotice, repriced, useOrdering } from '@/apps/app/parts/checkout/ordering'
import { PaymentMethods, usePaymentChoice } from '@/apps/app/parts/checkout/payment-methods'
import { discountLines, PriceSummary, type BillLine } from '@/apps/app/parts/checkout/price-summary'
import { ownPrice, PlanDiscount, PlanFacts, PlanHeading } from '@/apps/app/screens/buy/parts/plan-card'
import { BY_PATTERN, nameReady, nameRequest, ServiceNameField, type ServiceName } from '@/apps/app/screens/buy/parts/service-name'
import { idOf } from '@/apps/app/screens/support/parts/words'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Row, Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import { ApiError } from '@/lib/api'
import type { StoreOrderRequest, StorePlan, StorePlanResponse, StorePlansResponse } from '@/lib/api-types'
import { formatMoney } from '@/lib/format'

/*
 * A plan's checkout (`/buy/plans/:id`): the plan read again for this customer (`GET /plans/{id}` — the catalogue's card
 * stands in meanwhile), its note, the location to buy it on (the catalogue's, when it was filtered by one), the
 * service's name while the shop lets the buyer choose, a discount code, the way to pay, and the bill. Pay sends
 * `POST /orders` at the price shown (`expected_amount`) with the attempt's Idempotency-Key, then opens the payment; a
 * free plan — or a code that takes it all — is taken with one button, by the first way to pay.
 */

/** The fields of `POST /orders` the screen shows a refusal under, beside their inputs. */
const SHOWN = ['server_id', 'name_mode', 'client_name', 'method_id', 'discount_code'] as const

export default function PlanScreen() {
  // A plan's number as the address writes it: «0x1F» or «1e2» names no plan.
  const id = idOf(useParams().id) ?? 0
  const navigate = useNavigate()
  const known = id > 0
  const plan = useQuery({
    queryKey: [...keys.plans, id],
    queryFn: () => store.get<StorePlanResponse>(`/plans/${id}`),
    enabled: known,
    // The catalogue's card while the plan is read again: its prices and locations may have changed since.
    placeholderData: () => {
      const listed = queryClient
        .getQueryData<StorePlansResponse>(keys.plans)
        ?.groups.flatMap((group) => group.plans)
        .find((candidate) => candidate.id === id)
      return listed === undefined ? undefined : { plan: listed }
    },
  })

  if (!known || (plan.error instanceof ApiError && plan.error.status === 404)) {
    return (
      <Screen back>
        <Empty icon={PackageX} title="این پلن الان فروخته نمی‌شود" text="پلن دیگری از فهرست انتخاب کنید." action={<Button onClick={() => navigate('/buy', { replace: true })}>دیدن پلن‌ها</Button>} />
      </Screen>
    )
  }
  if (plan.data === undefined) {
    return (
      <Screen back title="تکمیل خرید">
        {plan.isError ? <ErrorView error={plan.error} retry={() => void plan.refetch()} /> : <LoadingBlocks count={4} />}
      </Screen>
    )
  }

  return <Checkout plan={plan.data.plan} fresh={!plan.isPlaceholderData && !plan.isFetching} reread={() => void plan.refetch()} />
}

interface CheckoutProps {
  plan: StorePlan
  /** The plan as the shop sells it now (not the catalogue's card, nor while it is read again). */
  fresh: boolean
  /** Read the plan again: its price, or its locations, changed. */
  reread: () => void
}

function Checkout({ plan, fresh, reread }: CheckoutProps) {
  const { state } = useLocation()
  const { data: shop } = useShop()
  const selling = shop?.shop.taking_orders ?? true
  const [serverId, setServerId] = useState<number | null>((state as { serverId?: number } | null)?.serverId ?? null)
  const [name, setName] = useState<ServiceName>(BY_PATTERN)

  const server = plan.locations.find((location) => location.id === serverId) ?? plan.locations[0] ?? null
  const code = useDiscountCheck(server === null ? null : { for: 'purchase', plan_id: plan.id, server_id: server.id })
  const amount = code.applied?.quote.amount ?? plan.offer_price
  const free = Number(amount) === 0
  // A free order names the first way to pay, whatever its bounds: the shop settles it with nothing taken.
  const payment = usePaymentChoice('purchase', free ? null : amount)
  const method = free ? (payment.list[0] ?? null) : payment.method

  const ordering = useOrdering(
    (body: StoreOrderRequest, key) => store.post('/orders', body, { idempotencyKey: key }),
    (error) => {
      if (repriced(error)) {
        reread()
        code.recheck()
      } else if (fieldProblem(error, 'plan_id') !== undefined || fieldProblem(error, 'server_id') !== undefined) {
        reread()
      } else if (fieldProblem(error, 'method_id') !== undefined) {
        void payment.methods.refetch()
      }
    },
  )
  const { changing } = ordering

  const ready = selling && fresh && server !== null && method !== null && nameReady(name) && !code.busy && payment.ready && !ordering.held

  const pay = () => {
    if (server === null || method === null) return
    ordering.send({
      plan_id: plan.id,
      server_id: server.id,
      method_id: method.id,
      ...nameRequest(name),
      ...(code.applied === null ? {} : { discount_code: code.applied.code }),
      expected_amount: amount,
    })
  }

  return (
    <Screen back title="تکمیل خرید" bottom="action">
      <section className="card animate-rise flex flex-col gap-4 p-4">
        <PlanHeading plan={plan} as="h2" full />
        <div className="flex flex-col gap-2">
          <PlanFacts plan={plan} />
          {plan.discount !== null && <PlanDiscount discount={plan.discount} />}
        </div>
      </section>

      {plan.checkout_note !== null && (
        <Notice>
          <p className="whitespace-pre-line">{plan.checkout_note}</p>
        </Notice>
      )}

      {!selling && <Notice tone="warning">فعلا خرید ممکن نیست؛ کمی بعد دوباره سر بزنید.</Notice>}

      {plan.locations.length > 1 ? (
        <Section title="لوکیشن" bare>
          <Choices
            label="لوکیشن"
            columns={2}
            value={server?.id ?? null}
            onChange={changing(setServerId)}
            options={plan.locations.map((location) => ({ value: location.id, title: location.name, icon: MapPin }))}
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

      <DiscountCodeField state={{ ...code, error: code.error ?? ordering.problem('discount_code'), apply: changing(code.apply), remove: changing(code.remove) }} />

      {!free && <PaymentMethods choice={{ ...payment, choose: changing(payment.choose) }} error={ordering.problem('method_id')} />}

      <PriceSummary lines={bill(plan, code.applied?.quote ?? null)} total={amount} before={plan.price} />

      <OrderNotice ordering={ordering} fields={SHOWN} />

      <MainAction text={free ? 'دریافت رایگان' : `پرداخت ${formatMoney(amount)}`} shine enabled={ready} busy={ordering.busy} onClick={pay} />
    </Screen>
  )
}

/** The plan's bill: its price (struck through under the customer's own lower one), each discount, before the amount to pay. */
function bill(plan: StorePlan, quote: AppliedCode['quote'] | null): BillLine[] {
  const own = ownPrice(plan)
  const lines: BillLine[] = [{ label: 'قیمت پلن', amount: plan.price, kind: own === null ? undefined : 'struck' }]
  if (own !== null) lines.push({ label: own.label, amount: own.amount })
  if (quote !== null) return [...lines, ...discountLines(quote.discounts)]

  const base = Number(own?.amount ?? plan.price)
  const off = base - Number(plan.offer_price)
  if (plan.discount !== null && off > 0) lines.push({ label: plan.discount.label, amount: String(off), kind: 'off' })

  return lines
}
