import { useEffect, useId, useRef, type KeyboardEvent } from 'react'
import { useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { Check, Inbox, Paperclip, RefreshCw, TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { FormActions } from '@/components/form-footer'
import { IconButton } from '@/components/icon-button'
import { TextLink } from '@/components/text-link'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { api, ApiError, isTransportError, stateChanged } from '@/lib/api'
import type { PaymentRikhtamAttachResponse, PaymentRikhtamResponse, PaymentRow, RikhtamDeposit } from '@/lib/api-types'
import { idLabel, isRtl } from '@/lib/direction'
import { formatDate, formatMoney, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useForm } from '@/lib/use-form'
import { cn } from '@/lib/utils'

/** Where the owner pastes Rikhtam's key again: «اتصال ریختم» on the payment methods page. */
const SETTINGS = '/payment-methods'

/** No answer came (the connection, the panel's wait): Rikhtam may have taken it all the same — the payment, read again, says. */
const NO_ANSWER = 'جوابی نرسید و معلوم نیست واریز پیوست شد یا نه؛ وضعیت پرداخت دوباره خوانده شد. اگر هنوز تایید نشده است، دوباره امتحان کنید.'

interface RikhtamAttachProps {
  payment: PaymentRow
  /** What attaching changes beside the payment — orders, services, the customer's wallet —, read again once it went through. */
  invalidates: readonly QueryKey[]
  onCancel: () => void
  /** Rikhtam answered: the payment as it is now (approved, unless the deposit did not cover its order), Rikhtam's words, and its fee when it named one. */
  onAttached: (answer: PaymentRikhtamAttachResponse) => void
  /** The payment may have moved on (refused on its state, or no answer to say whether it was attached): it and the list behind it are read again. */
  onStale: () => void
}

/**
 * «پیوست واریز ریختم» for one open card payment: the deposits Rikhtam holds that matched no payment — a customer who
 * rounded the sum —, read from Rikhtam as the strip opens (`GET …/rikhtam`, the closest amounts first, the exact one
 * marked), one picked and what attaching it does said before it is sent (`POST …/rikhtam`): Rikhtam confirms it on the
 * shop's address, which approves the payment when it covers its order, and charges its usual fee. Rikhtam's refusal is
 * said above the buttons, its list read again; with no word on what came of it (Rikhtam or the connection down), the
 * payment is read again, its state the answer.
 */
export function RikhtamAttach({ payment, invalidates, onCancel, onAttached, onStale }: RikhtamAttachProps) {
  const queryClient = useQueryClient()
  const read = useQuery({
    queryKey: queryKeys.paymentRikhtam(payment.id),
    queryFn: () =>
      api.get<PaymentRikhtamResponse>(`/payments/${payment.id}/rikhtam`).catch((failed: unknown) => {
        // Refused as an approval is — the payment moved on meanwhile, or Rikhtam refused the key —: the payment read
        // again (a change withdraws the strip), the refusal shown meanwhile.
        if (stateChanged(failed)) onStale()
        throw failed
      }),
    // Rikhtam's list moves as deposits come and are attached: asked afresh each time the strip opens.
    staleTime: 0,
    gcTime: 0,
  })
  const { values, set, error, formError, failure, busy, submit, handleSubmit } = useForm({ uid: '' })
  const deposits = read.data?.connected ? read.data.deposits : []
  // Only one that covers the order's own price pays it: one short of it is shown, never picked.
  const picked = deposits.find((deposit) => deposit.uid === values.uid && deposit.covers) ?? null

  // The strip took the place of the button pressed: the focus comes to it (then to the pick, once Rikhtam answered).
  const form = useRef<HTMLFormElement>(null)
  useEffect(() => {
    if (!form.current?.contains(document.activeElement)) form.current?.focus()
  }, [])

  const attach = handleSubmit(async () => {
    if (picked === null) return
    const answer = await submit(
      async () => {
        try {
          return await api.post(`/payments/${payment.id}/rikhtam`, { uid: picked.uid })
        } catch (failed) {
          // Not a refusal: whether it was attached is the payment's state now (a 503 says so) — it, and what attaching
          // changes, read again.
          if (!(failed instanceof ApiError && failed.status === 422)) {
            onStale()
            for (const queryKey of invalidates) void queryClient.invalidateQueries({ queryKey })
          }
          throw failed
        }
      },
      {
        onInvalid: (errors) => {
          if (errors.status) onStale()
          // Rikhtam refused this deposit (taken meanwhile, say): its list as it stands now.
          else void read.refetch()
        },
        invalidates,
      },
    )
    if (answer) onAttached(answer)
  })

  return (
    <form ref={form} onSubmit={attach} noValidate tabIndex={-1} aria-label="پیوست واریز ریختم" className="grid gap-4 outline-none">
      {read.isPending ? (
        <div className="grid gap-2" aria-busy="true" aria-label="در حال خواندن واریزها از ریختم">
          <Skeleton className="h-14 w-full rounded-lg" />
          <Skeleton className="h-14 w-full rounded-lg" />
        </div>
      ) : read.isError ? (
        <ErrorState what="واریزهای ریختم" error={read.error} onRetry={() => void read.refetch()} retrying={read.isFetching} />
      ) : !read.data.connected ? (
        <Callout tone="warning" icon={TriangleAlert}>
          ریختم دیگر به فروشگاه وصل نیست؛ کلید امنیتی درگاه را در{' '}
          <TextLink to={SETTINGS} inline>
            روش‌های پرداخت
          </TextLink>
          ، بخش «اتصال ریختم»، دوباره بگذارید.
        </Callout>
      ) : deposits.length === 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-3">
          <p className="flex min-w-0 items-start gap-2 text-footnote text-muted-foreground">
            <Inbox className="mt-0.5 size-4 shrink-0" aria-hidden />
            ریختم واریزی ندارد که به پرداختی وصل نشده باشد. اگر مشتری همین الان واریز کرده، کمی بعد دوباره ببینید.
          </p>
          <IconButton aria-label="تازه‌سازی" title="تازه‌سازی" icon={RefreshCw} busy={read.isFetching} onClick={() => void read.refetch()} />
        </div>
      ) : (
        <DepositPicker deposits={deposits} amount={payment.amount} value={values.uid} onChange={(uid) => set('uid', uid)} refreshing={read.isFetching} onRefresh={() => void read.refetch()} />
      )}

      {picked !== null && (
        <p role="status" className="text-footnote text-muted-foreground">
          واریز {formatMoney(picked.amount)}
          {picked.account !== null && (
            <>
              {' '}
              از حساب <Account digits={picked.account} />
            </>
          )}{' '}
          به پرداخت {idLabel(payment.id)} وصل می‌شود و پرداخت تایید می‌شود؛ ریختم کارمزد معمولش را برای این واریز می‌گیرد.
        </p>
      )}

      <FormActions error={isTransportError(failure) ? NO_ANSWER : (formError ?? error('uid'))} onCancel={onCancel} submitLabel="پیوست و تایید" busy={busy} held={picked === null} icon={Paperclip} />
    </form>
  )
}

/** An account by its last four digits, as Rikhtam names it: «····1234», left to right. */
function Account({ digits }: { digits: string }) {
  return (
    <bdi dir="ltr" className="tabular">
      ····{digits}
    </bdi>
  )
}

/** How far a deposit is from the payment's sum: «همین مبلغ», or how much less or more — or that it is short of the order. */
function Distance({ deposit, amount }: { deposit: RikhtamDeposit; amount: string }) {
  if (!deposit.covers) return <Badge variant="warning">کمتر از مبلغ سفارش</Badge>
  const difference = Number(deposit.amount) - Number(amount)
  if (difference === 0) return <Badge variant="success">همین مبلغ</Badge>

  return (
    <span className="text-footnote text-muted-foreground">
      {formatMoney(Math.abs(difference))} {difference < 0 ? 'کمتر' : 'بیشتر'}
    </span>
  )
}

interface DepositPickerProps {
  deposits: RikhtamDeposit[]
  /** The payment's sum, each deposit's distance measured from it. */
  amount: string
  value: string
  onChange: (uid: string) => void
  refreshing: boolean
  onRefresh: () => void
}

/**
 * The deposits as one choice (a radio group: arrows move the pick, Tab leaves), each with its amount and its distance
 * from the payment's sum, when it came and the account it came to — and a way to ask Rikhtam again. One short of the
 * order's own price stays in its place, said so and held (`aria-disabled`): the arrows pass it by.
 */
function DepositPicker({ deposits, amount, value, onChange, refreshing, onRefresh }: DepositPickerProps) {
  const label = useId()
  const radios = useRef(new Map<string, HTMLButtonElement>())
  const pickable = deposits.filter((deposit) => deposit.covers)
  // Nothing picked yet: the first (the closest) that pays takes the Tab stop — or the first, to be read, when none does.
  const current = pickable.some((deposit) => deposit.uid === value) ? value : (pickable[0]?.uid ?? deposits[0]?.uid ?? '')

  // Drawn once Rikhtam answered: a focus waiting on the strip (or lost) moves to the pick; one put elsewhere stays.
  const group = useRef<HTMLDivElement>(null)
  const first = useRef(current)
  useEffect(() => {
    const active = document.activeElement
    const waiting = !(active instanceof HTMLElement) || active === document.body || (group.current !== null && active !== group.current && active.contains(group.current))
    if (waiting) radios.current.get(first.current)?.focus()
  }, [])

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const [forward, backward] = isRtl() ? ['ArrowLeft', 'ArrowRight'] : ['ArrowRight', 'ArrowLeft']
    const step = event.key === 'ArrowDown' || event.key === forward ? 1 : event.key === 'ArrowUp' || event.key === backward ? -1 : 0
    if (step === 0) return
    event.preventDefault()
    const index = pickable.findIndex((deposit) => deposit.uid === current)
    const next = pickable[(index + step + pickable.length) % pickable.length]
    if (!next) return
    onChange(next.uid)
    radios.current.get(next.uid)?.focus()
  }

  return (
    <div className="grid gap-2">
      <div className="flex items-center justify-between gap-3">
        <div className="grid gap-0.5">
          <span id={label} className="text-body font-medium">
            واریزهای ریختم
          </span>
          <span className="text-footnote text-muted-foreground">واریزهایی که به پرداختی وصل نشده‌اند؛ نزدیک‌ترین مبلغ اول.</span>
        </div>
        <IconButton aria-label="تازه‌سازی" title="تازه‌سازی" icon={RefreshCw} busy={refreshing} onClick={onRefresh} />
      </div>
      {/* Padded out of its own margin: a focused deposit's ring is not cut by the scrolling edge. */}
      <div ref={group} role="radiogroup" aria-labelledby={label} onKeyDown={onKeyDown} className="-m-1 grid max-h-72 scrollbar-thin gap-2 overflow-y-auto p-1">
        {deposits.map((deposit) => {
          const selected = deposit.covers && deposit.uid === value
          return (
            <button
              key={deposit.uid}
              ref={(element) => {
                if (element) radios.current.set(deposit.uid, element)
                else radios.current.delete(deposit.uid)
              }}
              type="button"
              role="radio"
              aria-checked={selected}
              aria-disabled={!deposit.covers || undefined}
              tabIndex={deposit.uid === current ? 0 : -1}
              onClick={() => {
                if (deposit.covers) onChange(deposit.uid)
              }}
              className={cn(
                'flex items-center gap-3 rounded-lg border px-3 py-2.5 text-start transition-colors duration-150 outline-none focus-visible:focus-ring',
                selected ? 'border-selected bg-info-soft/40' : deposit.covers ? 'border-border bg-card hover:bg-fill' : 'cursor-not-allowed border-border bg-card text-muted-foreground',
              )}
            >
              <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span className="font-medium tabular">{formatMoney(deposit.amount)}</span>
                  <Distance deposit={deposit} amount={amount} />
                </span>
                <span className="text-footnote text-muted-foreground">
                  {deposit.received_at === null ? 'زمانش معلوم نیست' : `${formatDate(deposit.received_at)} (${timeAgo(deposit.received_at)})`}
                  {deposit.account !== null && (
                    <>
                      {' · '}
                      <Account digits={deposit.account} />
                    </>
                  )}
                </span>
              </span>
              {selected && <Check className="size-4 shrink-0 text-selected" aria-hidden />}
            </button>
          )
        })}
      </div>
      {pickable.length === 0 && <p className="text-footnote text-muted-foreground">هیچ‌کدام از این واریزها به اندازه مبلغ سفارش نیست و این پرداخت با آن‌ها تایید نمی‌شود.</p>}
    </div>
  )
}
