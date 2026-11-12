import { useCallback, useEffect, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Hammer, RefreshCcw, WifiOff } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { Choices } from '@/apps/app/parts/checkout/choice'
import { DiscountCodeField, useDiscountCheck, type CodeContext } from '@/apps/app/parts/checkout/discount-code'
import { OrderNotice, repriced, useOrdering } from '@/apps/app/parts/checkout/ordering'
import { PaymentMethods, usePaymentChoice } from '@/apps/app/parts/checkout/payment-methods'
import { discountLines, PriceSummary } from '@/apps/app/parts/checkout/price-summary'
import { useSettled } from '@/apps/app/parts/checkout/settled'
import { serviceTitle, useService } from '@/apps/app/parts/service'
import { leftoverWords, serviceId, windowWords } from '@/apps/app/screens/services/parts/service'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Chip, Field, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Fact, Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import { Price } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type {
  RenewalMethod,
  StoreCustomQuantity,
  StoreCustomServicesResponse,
  StorePlansResponse,
  StoreQuote,
  StoreRenewal,
  StoreRenewalPlan,
  StoreRenewalPlansResponse,
  StoreRenewalRequest,
  StoreRenewalResponse,
  StoreSubscription,
} from '@/lib/api-types'
import { formatBytes, formatDays, formatDeadline, formatDevices, formatDuration, formatGb, formatMoney, formatNumber, UNLIMITED, wholeNumberOf } from '@/lib/format'
import { RENEWAL_METHOD } from '@/lib/statuses'

/*
 * «تمدید سرویس», early too: the renewal's preview (`GET …/renewal`) — on the service's own plan, or for a service without
 * one on a plan the customer picks (`GET …/renewal-plans`, by category as the bot asks) or a package they build —, the
 * shop's renewal methods to pick from when it offers several (each previewed again: what the service is left with
 * differs), the price with a discount code, the way to pay, and the renewal sent with its Idempotency-Key and the price
 * shown (`POST …/renewal`) — then the payment screen. While the shop's renewal window is closed, its words say from when.
 */

/** What a service without a plan is renewed on: a plan of the list, or a package built. */
type Pick = { plan: number } | { gb: number; days: number } | null

/** What each renewal method does, under its name (RenewalMethod's words). */
const METHOD_WORDS: Record<RenewalMethod, string> = {
  extend: 'روزهای پلن به روزهای مانده و حجمش به حجم سرویس اضافه می‌شود.',
  reset_traffic: 'روزهای پلن به روزهای مانده اضافه می‌شود؛ حجم از نو شمرده می‌شود و حجم مانده از بین می‌رود.',
  reset_time: 'زمان از همین حالا از نو شروع می‌شود و روزهای مانده از بین می‌رود؛ حجم مانده به دوره جدید می‌رود.',
}

export default function RenewScreen() {
  const navigate = useNavigate()
  const id = serviceId(useParams().id)
  const query = useService(id)
  const service = query.data?.subscription
  const gone = Number.isNaN(id) || (query.error instanceof ApiError && query.error.status === 404)

  return (
    <Screen back title="تمدید سرویس" subtitle={service === undefined ? undefined : serviceTitle(service)} bottom="action">
      {gone ? (
        <Empty icon={WifiOff} title="این سرویس در حساب شما نیست" action={<Button onClick={() => navigate('/services', { replace: true })}>سرویس‌های من</Button>} />
      ) : service === undefined ? (
        query.isError ? (
          <ErrorView error={query.error} retry={() => void query.refetch()} />
        ) : (
          <LoadingBlocks count={4} />
        )
      ) : service.renewable || service.picks_plan ? (
        <Renewal service={service} />
      ) : (
        <Empty
          icon={RefreshCcw}
          title="این سرویس الان تمدید نمی‌شود"
          text="اگر فکر می‌کنید باید تمدید شود، با پشتیبانی در تماس باشید."
          action={<Button onClick={() => navigate(`/support/new?subscription=${service.id}`)}>پیام به پشتیبانی</Button>}
        />
      )}
    </Screen>
  )
}

/** The fields of `POST …/renewal` the screen shows a refusal under, beside their inputs. */
const SHOWN = ['method_id', 'discount_code'] as const

function Renewal({ service }: { service: StoreSubscription }) {
  const id = service.id
  const [pick, setPick] = useState<Pick>(null)
  const [method, setMethod] = useState<RenewalMethod | null>(null)
  const ready = !service.picks_plan || pick !== null
  const picked = pick === null ? {} : 'plan' in pick ? { plan_id: pick.plan } : { gb: pick.gb, days: pick.days }

  const params = new URLSearchParams(Object.entries({ ...(method === null ? {} : { method }), ...picked }).map(([name, value]) => [name, String(value)]))
  const preview = useQuery({
    queryKey: [...keys.subscription(id), 'renewal', params.toString()],
    queryFn: () => store.get<StoreRenewalResponse>(`/subscriptions/${id}/renewal`, params),
    enabled: ready,
    placeholderData: keepPreviousData,
  })
  const renewal = ready ? preview.data?.renewal : undefined
  const settled = ready && preview.isSuccess && !preview.isPlaceholderData

  const check: CodeContext | null = renewal === undefined || !settled ? null : { for: 'renewal', subscription_id: id, method: renewal.method, ...picked }
  const code = useDiscountCheck(check)
  const amount = renewal === undefined ? undefined : (code.applied?.quote.amount ?? renewal.price)
  const payment = usePaymentChoice('renewal', amount)
  const ordering = useOrdering(
    (body: StoreRenewalRequest, key) => store.post(`/subscriptions/${id}/renewal`, body, { idempotencyKey: key }),
    (error) => {
      if (repriced(error)) {
        void preview.refetch()
        code.recheck()
      } else if (fieldProblem(error, 'method_id') !== undefined) {
        void payment.methods.refetch()
      } else {
        // Refused for the service or the plan (its window closed meanwhile, the plan gone): the preview says why.
        void preview.refetch()
      }
    },
  )
  const { changing, dismiss } = ordering
  // Stable: the package picker hands its pick over from an effect.
  const choosePlan = useCallback(
    (next: Pick) => {
      dismiss()
      setPick(next)
    },
    [dismiss],
  )
  const body: StoreRenewalRequest | null =
    renewal === undefined || amount === undefined || payment.method === null || !settled || preview.isFetching || code.busy || !payment.ready
      ? null
      : { method_id: payment.method.id, method: renewal.method, ...picked, ...(code.applied === null ? {} : { discount_code: code.applied.code }), expected_amount: amount }
  const opens = windowWords(service.renewal_window)
  // The preview's refusal is the shop's own word on why (its window among them): said once, in its words.
  const refused = preview.isError && preview.error instanceof ApiError && preview.error.status === 422

  return (
    <>
      {opens !== null && !refused && <Notice tone="warning">{opens}</Notice>}

      {service.picks_plan && <PlanPicker id={id} pick={pick} onPick={choosePlan} closed={opens !== null} />}

      {ready &&
        (preview.isError ? (
          refused ? (
            <Notice tone="warning">{problemOf(preview.error)}</Notice>
          ) : (
            <ErrorView error={preview.error} retry={() => void preview.refetch()} />
          )
        ) : renewal === undefined ? (
          <LoadingBlocks count={3} />
        ) : (
          <>
            {renewal.methods.length > 1 && (
              <Section title="روش تمدید">
                <Choices
                  look="rows"
                  label="روش تمدید"
                  value={method ?? renewal.method}
                  onChange={changing(setMethod)}
                  options={renewal.methods.map((choice) => ({ value: choice, title: RENEWAL_METHOD[choice], detail: METHOD_WORDS[choice] }))}
                />
              </Section>
            )}
            <Summary renewal={renewal} />
            <DiscountCodeField state={{ ...code, error: code.error ?? ordering.problem('discount_code'), apply: changing(code.apply), remove: changing(code.remove) }} />
            <PaymentMethods choice={{ ...payment, choose: changing(payment.choose) }} error={ordering.problem('method_id')} />
            <Bill renewal={renewal} quote={code.applied?.quote ?? null} />
            <OrderNotice ordering={ordering} fields={SHOWN} />
          </>
        ))}

      <MainAction
        text={amount === undefined ? null : `پرداخت ${formatMoney(amount)}`}
        enabled={body !== null && !ordering.held}
        busy={ordering.busy}
        shine
        onClick={() => body !== null && ordering.send(body)}
      />
    </>
  )
}

/** The renewal's bill: its price before its discounts, each discount (a code's once it holds), and the amount to pay. */
function Bill({ renewal, quote }: { renewal: StoreRenewal; quote: StoreQuote | null }) {
  const discounts = quote?.discounts ?? renewal.discounts
  const base = quote?.base ?? String(Number(renewal.price) + renewal.discounts.reduce((sum, line) => sum + Number(line.amount), 0))
  const total = quote?.amount ?? renewal.price

  return <PriceSummary lines={[{ label: 'قیمت تمدید', amount: base }, ...discountLines(discounts)]} total={total} before={base} />
}

/** What the renewal gives, and the service as it leaves it: its end, its traffic, and what of it goes with this period. */
function Summary({ renewal }: { renewal: StoreRenewal }) {
  const { plan, custom, after } = renewal
  const devices = plan?.devices ?? custom?.devices ?? 0
  const leftover = leftoverWords(after.traffic.remaining_bytes, after.next_period)

  return (
    <>
      <Section title="خلاصه تمدید">
        <Fact label="پلن">{plan?.name ?? 'سرویس دلخواه'}</Fact>
        <Fact label="مدت">{plan !== null ? formatDuration(plan.duration_hours) : formatDays(custom?.days ?? 0)}</Fact>
        <Fact label="حجم">{formatGb(plan?.traffic_gb ?? custom?.gb ?? 0)}</Fact>
        <Fact label="تعداد کاربر">{after.next_devices === null ? formatDevices(devices) : `${formatDevices(devices)} (از ${formatDeadline(after.next_devices.from)})`}</Fact>
      </Section>
      {plan?.checkout_note && <Notice>{plan.checkout_note}</Notice>}
      <Section title="بعد از تمدید" footer={leftover ?? undefined}>
        <Fact label="تاریخ اتمام">
          {after.term.duration_hours === 0
            ? UNLIMITED
            : after.term.awaits_first_use || after.term.expires_at === null
              ? `${formatDuration(after.term.duration_hours)} از اولین اتصال`
              : formatDeadline(after.term.expires_at)}
        </Fact>
        <Fact label="حجم باقی‌مانده">{after.traffic.limit_bytes === 0 ? UNLIMITED : formatBytes(after.traffic.remaining_bytes ?? 0)}</Fact>
      </Section>
    </>
  )
}

/** How long a typed GB or days rests before the package is previewed. */
const SETTLE_MS = 400

/** The plans' group key: a category's id, or none. */
const NONE = 'none'

/** The plan picker's row for a package built instead of a plan. */
const BUILD = 'build' as const

/**
 * For a service without a plan: the plans it may be renewed on, by category — the categories to choose from first when
 * there are several, as the bot asks —, and the package to build instead while the shop's builder is offered.
 */
function PlanPicker({ id, pick, onPick, closed }: { id: number; pick: Pick; onPick: (pick: Pick) => void; closed: boolean }) {
  const plans = useQuery({ queryKey: [...keys.subscription(id), 'renewal-plans'], queryFn: () => store.get<StoreRenewalPlansResponse>(`/subscriptions/${id}/renewal-plans`) })
  const [category, setCategory] = useState<string | null>(null)
  const [building, setBuilding] = useState(false)
  const groups = new Map<string, StoreRenewalPlan[]>()
  for (const plan of plans.data?.plans ?? []) {
    const key = plan.category_id === null ? NONE : String(plan.category_id)
    groups.set(key, [...(groups.get(key) ?? []), plan])
  }
  const several = groups.size > 1
  // The categories' names: the catalogue's (`GET /plans`), read only when there are several to tell apart.
  const catalog = useQuery({ queryKey: keys.plans, queryFn: () => store.get<StorePlansResponse>('/plans'), enabled: several })
  const names = new Map((catalog.data?.groups ?? []).flatMap((group) => (group.category === null ? [] : [[String(group.category.id), group.category.name] as const])))
  const shown = category !== null && groups.has(category) ? category : ([...groups.keys()][0] ?? null)

  if (plans.data === undefined) return plans.isError ? <ErrorView error={plans.error} retry={() => void plans.refetch()} /> : <LoadingBlocks count={2} />
  // None while the renewal window is closed: its words above say from when.
  if (groups.size === 0 && !plans.data.custom_services) return closed ? null : <Notice tone="warning">فعلا پلنی برای تمدید این سرویس نیست؛ با پشتیبانی تماس بگیرید.</Notice>

  return (
    <>
      {several && (
        <div className="-mx-4 -my-1 flex [scrollbar-width:none] gap-2 overflow-x-auto px-4 py-1 [&::-webkit-scrollbar]:hidden" role="group" aria-label="دسته پلن‌ها">
          {[...groups.keys()].map((key, index) => (
            <Chip
              key={key}
              active={!building && key === shown}
              onClick={() => {
                setCategory(key)
                setBuilding(false)
                if (pick !== null && !('plan' in pick && groups.get(key)?.some((plan) => plan.id === pick.plan))) onPick(null)
              }}
            >
              {key === NONE ? 'سایر پلن‌ها' : (names.get(key) ?? `دسته ${formatNumber(index + 1)}`)}
            </Chip>
          ))}
        </div>
      )}
      <Section title="پلن تمدید" footer="سرویس روی پلنی که انتخاب می‌کنید تمدید می‌شود و از این به بعد همان پلن را دارد.">
        <Choices
          look="rows"
          label="پلن تمدید"
          value={building ? BUILD : pick !== null && 'plan' in pick ? pick.plan : null}
          onChange={(value) => {
            setBuilding(value === BUILD)
            onPick(value === BUILD ? null : { plan: value })
          }}
          options={[
            ...(shown === null ? [] : (groups.get(shown) ?? [])).map((plan) => ({
              value: plan.id,
              title: plan.name,
              detail: [formatGb(plan.traffic_gb), formatDuration(plan.duration_hours), formatDevices(plan.devices)].join(' · '),
              end: <Price amount={plan.renewal_price} before={plan.price} />,
            })),
            ...(plans.data.custom_services
              ? [
                  {
                    value: BUILD,
                    title: (
                      <span className="inline-flex items-center gap-1.5">
                        <Hammer aria-hidden className="text-accent-text size-4 shrink-0" />
                        تمدید با سرویس دلخواه
                      </span>
                    ),
                    detail: 'حجم و مدت را خودتان انتخاب کنید',
                  },
                ]
              : []),
          ]}
        />
      </Section>
      {building && <PackagePicker onPick={onPick} />}
    </>
  )
}

/** A package to renew with: its GB and its days, each a button of the shop's or a number typed within the builder's bounds. */
function PackagePicker({ onPick }: { onPick: (pick: Pick) => void }) {
  const builder = useQuery({ queryKey: keys.customServices, queryFn: () => store.get<StoreCustomServicesResponse>('/custom-services') })
  const [gb, setGb] = useState('')
  const [days, setDays] = useState('')
  const typed = useSettled(`${gb}|${days}`, SETTLE_MS)
  const offer = builder.data?.custom_services

  useEffect(() => {
    if (offer === undefined) return
    const [typedGb = '', typedDays = ''] = typed.split('|')
    const chosenGb = within(typedGb, offer.traffic)
    const chosenDays = within(typedDays, offer.days)
    onPick(chosenGb === null || chosenDays === null ? null : { gb: chosenGb, days: chosenDays })
  }, [typed, offer, onPick])

  if (builder.data === undefined) return builder.isError ? <ErrorView error={builder.error} retry={() => void builder.refetch()} /> : <LoadingBlocks count={1} />
  if (!builder.data.custom_services.offered) return <Notice tone="warning">ساخت سرویس دلخواه الان در دسترس نیست.</Notice>

  return (
    <Section title="سرویس دلخواه">
      <div className="flex flex-col gap-4 p-4">
        <Quantity
          label="حجم (گیگابایت)"
          value={gb}
          onChange={setGb}
          quantity={builder.data.custom_services.traffic}
          words={(count) => `${formatNumber(count)} گیگ`}
          refusal={(min, max) => `حجم را عددی بین ${formatNumber(min)} تا ${formatNumber(max)} گیگابایت بنویسید.`}
        />
        <Quantity
          label="مدت (روز)"
          value={days}
          onChange={setDays}
          quantity={builder.data.custom_services.days}
          words={(count) => `${formatNumber(count)} روز`}
          refusal={(min, max) => `مدت را عددی بین ${formatNumber(min)} تا ${formatNumber(max)} روز بنویسید.`}
        />
      </div>
    </Section>
  )
}

/** A typed whole number within the quantity's bounds; null otherwise. */
function within(typed: string, quantity: StoreCustomQuantity): number | null {
  const count = wholeNumberOf(typed)

  return count !== null && count >= quantity.min && count <= quantity.max ? count : null
}

interface QuantityProps {
  label: string
  value: string
  onChange: (value: string) => void
  quantity: StoreCustomQuantity
  words: (count: number) => string
  refusal: (min: number, max: number) => string
}

function Quantity({ label, value, onChange, quantity, words, refusal }: QuantityProps) {
  const wrong = value.trim() !== '' && within(value, quantity) === null

  return (
    <Field label={label} hint={`از ${formatNumber(quantity.min)} تا ${formatNumber(quantity.max)}`} error={wrong ? refusal(quantity.min, quantity.max) : undefined}>
      {(fieldId) => (
        <div className="flex flex-col gap-2">
          {quantity.options.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {quantity.options.map((option) => (
                <Chip key={option} active={wholeNumberOf(value) === option} onClick={() => onChange(String(option))}>
                  {words(option)}
                </Chip>
              ))}
            </div>
          )}
          <TextInput
            id={fieldId}
            ltr
            inputMode="numeric"
            className="bg-fill"
            value={value}
            invalid={wrong}
            placeholder={`${formatNumber(quantity.min)}–${formatNumber(quantity.max)}`}
            onChange={(event) => onChange(event.target.value)}
          />
        </div>
      )}
    </Field>
  )
}
