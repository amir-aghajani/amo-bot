import { useState, type ChangeEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { keys } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { fieldProblem, store } from '@/apps/app/lib/store'
import { OrderNotice, useOrdering } from '@/apps/app/parts/checkout/ordering'
import { PaymentMethods, usePaymentChoice } from '@/apps/app/parts/checkout/payment-methods'
import { useSettled } from '@/apps/app/parts/checkout/settled'
import { selection } from '@/apps/app/telegram/haptics'
import { MainAction } from '@/apps/app/ui/button'
import { Field, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Section } from '@/apps/app/ui/section'
import { ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import type { StoreWalletResponse } from '@/lib/api-types'
import { formatAmount, formatBalance, formatMoney, MONEY_UNIT, tomanOf } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * «شارژ کیف پول»: an amount — one of the shop's buttons (its presets, then the amounts it draws at random), or one typed
 * as a person types it («۵۰ هزار», Persian digits, separators), checked here against the shop's bounds and read by the
 * shop itself — and a way to pay it among those that take that amount (parts/checkout, as every order's); then the
 * payment screen, the top-up sent with its attempt's key. The wallet never pays itself, so it is never offered. The shop
 * draws its random amounts afresh on every read (the wallet is read again as the app comes back into view): the one
 * picked keeps its button, highlighted, so what is sent is always what is shown.
 */

/** The fields of `POST /wallet/top-up` the screen shows a refusal under, beside their inputs. */
const SHOWN = ['amount', 'method_id'] as const

/** How long a typed amount rests before the ways to pay it are read. */
const SETTLE_MS = 450

export default function TopUpScreen() {
  const { data: shop } = useShop()
  const wallet = useQuery({ queryKey: keys.wallet, queryFn: () => store.get<StoreWalletResponse>('/wallet') })
  /** One of the shop's amounts, as the API wrote it — or null while the customer types their own. */
  const [picked, setPicked] = useState<string | null>(null)
  const [typed, setTyped] = useState('')

  const bounds = wallet.data?.top_up
  /** The shop's amount buttons: its presets, then the amounts it drew at random — and the one picked, drawn before. */
  const offered = bounds === undefined ? [] : [...bounds.presets, ...bounds.random]
  const amounts = picked !== null && !offered.includes(picked) ? [...offered, picked] : offered
  const text = typed.trim()
  const amount = picked !== null ? Number(picked) : text === '' ? null : tomanOf(text)
  const unread = picked === null && text !== '' && amount === null
  const outside = amount !== null && bounds !== undefined && (amount < Number(bounds.min) || amount > Number(bounds.max))
  /** The amount to top up by, once it reads and is within the bounds. */
  const usable = amount !== null && !outside ? amount : null
  const settled = useSettled(usable, picked !== null ? 0 : SETTLE_MS)

  const choice = usePaymentChoice('wallet_topup', settled === null ? null : String(settled))
  const chosen = choice.method?.id ?? null

  const ordering = useOrdering(
    (body: { amount: string; method_id: number }, key) => store.post('/wallet/top-up', body, { idempotencyKey: key }),
    (error) => {
      if (fieldProblem(error, 'method_id') !== undefined) void choice.methods.refetch()
    },
  )

  const amountProblem = unread
    ? 'مبلغ را به تومان و با عدد بنویسید؛ مثلا ۵۰۰۰۰.'
    : outside && bounds !== undefined
      ? `مبلغ شارژ باید بین ${formatMoney(bounds.min)} و ${formatMoney(bounds.max)} باشد.`
      : ordering.problem('amount')
  const closed = shop?.shop.taking_orders === false
  const current = usable !== null && settled === usable && choice.ready
  const send = picked ?? text

  const choose = ordering.changing((preset: string) => {
    setPicked(preset)
    setTyped('')
  })

  // The form stays while there is a wallet read: one read again that failed (as the app comes back) takes it, and what
  // was picked, no more away.
  if (wallet.isError && wallet.data === undefined) {
    return (
      <Screen back title="شارژ کیف پول">
        <ErrorView error={wallet.error} retry={() => void wallet.refetch()} />
      </Screen>
    )
  }

  return (
    <Screen back title="شارژ کیف پول" subtitle={wallet.data === undefined ? undefined : `موجودی فعلی: ${formatBalance(wallet.data.balance)}`} bottom="action">
      {bounds === undefined ? (
        <LoadingBlocks count={3} />
      ) : (
        <>
          {closed && <Notice tone="warning">فروشگاه فعلا سفارش نمی‌گیرد؛ کمی بعد دوباره سر بزنید.</Notice>}

          <Section title="مبلغ شارژ" bare>
            <div className={cn('grid gap-2', amounts.length === 4 || amounts.length <= 2 ? 'grid-cols-2' : 'grid-cols-3')} role="group" aria-label="مبلغ‌های آماده">
              {amounts.map((preset) => {
                const active = picked === preset

                return (
                  <button
                    key={preset}
                    type="button"
                    aria-pressed={active}
                    onClick={() => {
                      if (!active) selection()
                      choose(preset)
                    }}
                    className={cn(
                      'press rounded-control flex h-16 min-w-0 flex-col items-center justify-center px-2 transition-colors',
                      active ? 'bg-accent text-on-accent shadow-[0_8px_20px_-10px_var(--app-accent)]' : 'bg-section text-text shadow-card',
                    )}
                  >
                    <span className="text-heading font-bold">{formatAmount(preset)}</span>
                    <span className={cn('text-caption', active ? 'opacity-85' : 'text-hint')}>{MONEY_UNIT}</span>
                  </button>
                )
              })}
            </div>
          </Section>

          <Field
            label="یا مبلغ دلخواه"
            error={amountProblem}
            hint={picked === null && amount !== null ? `${formatMoney(amount)} شارژ می‌شود` : `از ${formatMoney(bounds.min)} تا ${formatMoney(bounds.max)}`}
          >
            {(id) => (
              <div className="relative">
                <TextInput
                  id={id}
                  inputMode="numeric"
                  autoComplete="off"
                  value={typed}
                  placeholder="مثلا ۷۵۰۰۰"
                  invalid={amountProblem !== undefined}
                  onChange={ordering.changing((event: ChangeEvent<HTMLInputElement>) => {
                    setTyped(event.target.value)
                    setPicked(null)
                  })}
                  className="pe-16"
                />
                <span className="text-hint pointer-events-none absolute inset-y-0 end-4 flex items-center text-footnote">{MONEY_UNIT}</span>
              </div>
            )}
          </Field>

          <PaymentMethods choice={{ ...choice, choose: ordering.changing(choice.choose) }} error={ordering.problem('method_id')} />
          {/* Under the ways to pay as a section's note sits under its card: 8px below it. */}
          {usable !== null && <p className="text-hint -mt-3 px-4 text-caption">مبلغ دقیق پرداخت در صفحه بعد نمایش داده می‌شود.</p>}

          <OrderNotice ordering={ordering} fields={SHOWN} />
        </>
      )}

      <MainAction
        text={usable === null ? 'پرداخت' : `پرداخت ${formatMoney(usable)}`}
        enabled={current && chosen !== null && !closed && !ordering.held}
        busy={ordering.busy}
        shine
        onClick={() => {
          if (chosen !== null && usable !== null) ordering.send({ amount: send, method_id: chosen })
        }}
      />
    </Screen>
  )
}
