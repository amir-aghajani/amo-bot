import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { TicketPercent, X } from 'lucide-react'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { tap } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Field, TextInput } from '@/apps/app/ui/fields'
import { IconTile } from '@/apps/app/ui/visuals'
import type { StoreDiscountCheckRequest, StoreQuote } from '@/lib/api-types'
import { formatMoney } from '@/lib/format'

/*
 * «کد تخفیف» at a checkout — a plan's, a built service's, a renewal's, an extra's: a field the customer opens, types a
 * code into and applies. The code is checked as it is applied (`POST /discount-codes/check`) — never as it is typed:
 * each code that does not hold costs one of the customer's twenty tries an hour —, and again whenever what it is for
 * changes (another location, another amount may refuse it); the quote it makes is the price the order is sent with. The
 * field draws what a CodeState says, so a checkout that quotes codes its own way (the built service's quote takes the
 * code) gives it its own state.
 */

/** A code applied, and the quote it made: the price before, each discount, and what is left to pay. */
export interface AppliedCode {
  code: string
  quote: Pick<StoreQuote, 'base' | 'discounts' | 'amount'>
}

export interface CodeState {
  /** The code that holds now, and its quote; null for none (none applied, or the one applied was refused). */
  applied: AppliedCode | null
  /** Why the code applied does not hold, in the customer's words. */
  error?: string
  /** The code is being checked. */
  busy: boolean
  apply: (code: string) => void
  remove: () => void
}

/** What a code is checked for: `POST /discount-codes/check`'s request without the code. */
export type CodeContext = Omit<StoreDiscountCheckRequest, 'code'>

/**
 * A code checked for `context` (null while the checkout cannot say yet). `recheck()` asks again for a code that held —
 * after the order was refused for its price, say.
 */
export function useDiscountCheck(context: CodeContext | null): CodeState & { recheck: () => void } {
  const [code, setCode] = useState<string | null>(null)
  const check = useQuery({
    queryKey: ['discount-code', context, code],
    queryFn: async () => (await store.post('/discount-codes/check', { ...(context as CodeContext), code: code as string })).quote,
    enabled: code !== null && context !== null,
    // A refused code is never asked again by itself: each costs a try.
    staleTime: Infinity,
    refetchOnWindowFocus: false,
  })

  return {
    applied: code !== null && check.isSuccess ? { code, quote: check.data } : null,
    error: code !== null && check.isError ? (fieldProblem(check.error, 'code') ?? problemOf(check.error)) : undefined,
    busy: code !== null && check.isFetching,
    apply: (typed) => {
      if (typed === code) void check.refetch()
      else setCode(typed)
    },
    remove: () => setCode(null),
    recheck: () => {
      if (check.isSuccess) void check.refetch()
    },
  }
}

/** The code's own line of a quote: what it took off. */
function saving(applied: AppliedCode): string | null {
  return applied.quote.discounts.find((line) => line.source === 'code')?.amount ?? null
}

/**
 * The code's field: a line to open it, the field and its «اعمال», or the code applied with what it saves — the line and
 * the code applied drawn as a list's row is (a 32px icon square, the words after it), as the checkout's other rows are.
 */
export function DiscountCodeField({ state }: { state: CodeState }) {
  const [open, setOpen] = useState(false)
  const [typed, setTyped] = useState('')
  const { applied } = state

  if (applied !== null) {
    const saved = saving(applied)

    return (
      <div className="flex flex-col gap-2">
        <div className="card animate-rise flex min-h-14 items-center gap-3 px-4 py-3">
          <IconTile icon={TicketPercent} color="var(--app-success)" look="soft" />
          <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="text-body font-medium">
              کد <span className="ltr font-semibold">{applied.code}</span> اعمال شد
            </span>
            {saved !== null && <span className="text-footnote text-success">{formatMoney(saved)} تخفیف</span>}
          </span>
          <button
            type="button"
            onClick={() => {
              tap()
              // Dropped, the code leaves no trace: the line to open the field again.
              setTyped('')
              setOpen(false)
              state.remove()
            }}
            className="press tap-target text-hint relative grid size-8 shrink-0 place-items-center rounded-full bg-fill"
            aria-label="حذف کد تخفیف"
          >
            <X aria-hidden className="size-4" />
          </button>
        </div>
        {/* The order may still refuse a code that held a moment ago (its last use taken meanwhile). */}
        {state.error !== undefined && (
          <p className="px-4 text-caption text-danger" role="alert">
            {state.error}
          </p>
        )}
      </div>
    )
  }

  if (!open && state.error === undefined) {
    return (
      <button
        type="button"
        onClick={() => {
          tap()
          setOpen(true)
        }}
        className="card press text-accent-text flex min-h-12 items-center gap-3 px-4 text-start text-body font-medium"
      >
        <IconTile icon={TicketPercent} look="soft" />
        کد تخفیف دارید؟
      </button>
    )
  }

  const submit = () => {
    const code = typed.trim()
    if (code !== '') state.apply(code)
  }

  return (
    <Field label="کد تخفیف" error={state.error}>
      {(id) => (
        <form
          className="flex gap-2"
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          <TextInput
            id={id}
            ltr
            autoFocus
            invalid={state.error !== undefined}
            value={typed}
            onChange={(event) => setTyped(event.target.value)}
            placeholder="مثلا NOWRUZ"
            autoCapitalize="characters"
            autoComplete="off"
            autoCorrect="off"
            spellCheck={false}
            enterKeyHint="done"
          />
          <Button type="submit" variant="secondary" className="shrink-0" busy={state.busy} disabled={typed.trim() === ''}>
            اعمال
          </Button>
        </form>
      )}
    </Field>
  )
}
