import { useState } from 'react'
import { keepPreviousData, useQuery, type UseQueryResult } from '@tanstack/react-query'
import { CreditCard, Globe, Plus, Wallet, type LucideIcon } from 'lucide-react'
import { Link } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { Choices } from '@/apps/app/parts/checkout/choice'
import { tap } from '@/apps/app/telegram/haptics'
import { Section } from '@/apps/app/ui/section'
import { ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { ACCENT_FILL } from '@/apps/app/ui/visuals'
import type { GatewayKind, OwnOrderType, StorePaymentMethod, StorePaymentMethodsResponse, StoreWalletResponse } from '@/lib/api-types'
import { amountDraft, formatBalance, formatMoney } from '@/lib/format'

/*
 * How the customer pays an order: the ways the shop takes for that kind of order at its price (`GET /payment-methods`,
 * in the bot's order), each with what it means — the wallet with its balance and whether it covers the price (what it
 * can spend, `GET /wallet`: an agent's credit in it; a way to top it up when it falls short), a card transfer and its
 * receipt, an online gateway's page. The first way that can pay is picked until the customer picks another. Every
 * ordering screen uses the same choice: `usePaymentChoice(kind, amount)` reads it, <PaymentMethods> draws it, and
 * `method` is what the order sends.
 */

/** The kinds of order a way to pay is asked for (`?for=`). */
type PaymentKind = OwnOrderType | 'wallet_topup'

interface PaymentChoice {
  /** The amount to pay, as `usePaymentChoice` was given it. */
  amount: string | null | undefined
  methods: UseQueryResult<StorePaymentMethodsResponse>
  /** The ways offered for this amount (the last answer's while the next is read). */
  list: StorePaymentMethod[]
  /** The customer's wallet, read while the wallet is among the ways; null until then. */
  wallet: StoreWalletResponse | null
  /** The way picked that can pay it — the customer's, else the first that can —; null while none can. */
  method: StorePaymentMethod | null
  /** The ways are read for this very amount (not the last one's while the next is read). */
  ready: boolean
  /** Whether a way can pay the amount: the wallet only while what it can spend covers it. */
  covers: (method: StorePaymentMethod) => boolean
  choose: (id: number) => void
}

/**
 * The ways to pay an order of `kind` at `amount` (after its discounts, Toman as the API writes it), and the customer's
 * pick. `null`: every way of the kind, whatever its bounds (a free order, an amount not chosen yet); `undefined`: the
 * order is not priced yet, and nothing is read.
 */
export function usePaymentChoice(kind: PaymentKind, amount: string | null | undefined): PaymentChoice {
  const asked = amount === null || amount === undefined ? null : amountDraft(amount)
  const methods = useQuery({
    queryKey: [...keys.paymentMethods, kind, asked],
    queryFn: () => store.get<StorePaymentMethodsResponse>('/payment-methods', new URLSearchParams(asked === null ? { for: kind } : { for: kind, amount: asked })),
    enabled: amount !== undefined,
    placeholderData: keepPreviousData,
  })
  const list = methods.data?.methods ?? []
  const wallet = useQuery({ queryKey: keys.wallet, queryFn: () => store.get<StoreWalletResponse>('/wallet'), enabled: list.some((method) => method.kind === 'instant') })
  const [chosen, setChosen] = useState<number | null>(null)

  const covers = (method: StorePaymentMethod) => method.kind !== 'instant' || asked === null || wallet.data === undefined || Number(wallet.data.spendable) >= Number(amount)
  const method = list.find((candidate) => candidate.id === chosen && covers(candidate)) ?? list.find(covers) ?? null

  return { amount, methods, list, wallet: wallet.data ?? null, method, ready: methods.isSuccess && !methods.isPlaceholderData, covers, choose: setChosen }
}

/** Each kind of way: its icon, its square's colour and what it means. */
const KINDS: Record<GatewayKind, { icon: LucideIcon; tint: string; text: string }> = {
  instant: { icon: Wallet, tint: 'var(--app-success)', text: 'پرداخت فوری از موجودی' },
  manual: { icon: CreditCard, tint: ACCENT_FILL, text: 'واریز کارت به کارت و ارسال رسید' },
  online: { icon: Globe, tint: '#8b5cf6', text: 'پرداخت در درگاه آنلاین' },
}

/** A way of no kind the app knows (its driver gone): a card's look. */
const UNKNOWN = KINDS.manual

interface PaymentMethodsProps {
  choice: PaymentChoice
  /** The order's refusal of the way picked (its `method_id`). */
  error?: string
}

/** The ways to pay, as a list to pick one of. */
export function PaymentMethods({ choice, error }: PaymentMethodsProps) {
  const { methods, list, wallet, method, amount } = choice

  if (methods.isPending) {
    return (
      <Section title="روش پرداخت">
        <div className="flex flex-col gap-3 p-4" role="status" aria-label="در حال بارگذاری">
          <Skeleton className="rounded-control h-10" />
          <Skeleton className="rounded-control h-10" />
        </div>
      </Section>
    )
  }
  if (methods.isError) {
    return <ErrorView error={methods.error} retry={() => void methods.refetch()} />
  }
  if (list.length === 0) {
    return <Notice tone="warning">برای این مبلغ روش پرداختی نیست؛ مبلغ دیگری انتخاب کنید یا با پشتیبانی تماس بگیرید.</Notice>
  }

  return (
    <Section title="روش پرداخت">
      <Choices
        look="rows"
        label="روش پرداخت"
        value={method?.id ?? null}
        onChange={choice.choose}
        options={list.map((option) => {
          const { icon, tint, text } = option.kind === null ? UNKNOWN : KINDS[option.kind]
          const short = !choice.covers(option) && wallet !== null && typeof amount === 'string' ? Number(amount) - Number(wallet.spendable) : null

          return {
            value: option.id,
            title: <span className="block truncate">{option.label}</span>,
            detail: option.kind === 'instant' && wallet !== null ? `موجودی: ${formatBalance(wallet.balance)}` : text,
            icon,
            tint,
            disabled: short !== null,
            below: short !== null && <Shortfall short={short} />,
          }
        })}
      />
      {error !== undefined && (
        <p className="px-4 pb-3 text-caption text-danger" role="alert">
          {error}
        </p>
      )}
    </Section>
  )
}

/** Under a wallet that does not cover the amount — from where its row's words start (60px) —: by how much, and the way to top it up. */
function Shortfall({ short }: { short: number }) {
  return (
    <div className="flex items-center justify-between gap-3 ps-15 pe-4 pb-3">
      <span className="text-footnote text-danger">موجودی کافی نیست؛ {formatMoney(short)} کم است.</span>
      <Link
        to="/wallet/top-up"
        onClick={() => tap()}
        className="press tap-target text-accent-text relative inline-flex h-8 shrink-0 items-center gap-1 rounded-full bg-[color-mix(in_srgb,var(--app-accent)_12%,transparent)] ps-2.5 pe-3 text-footnote leading-none font-semibold whitespace-nowrap"
      >
        <Plus aria-hidden className="size-4 shrink-0" />
        افزایش موجودی
      </Link>
    </div>
  )
}
