import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { goPay } from '@/apps/app/lib/checkout'
import { fieldProblem, problemOf } from '@/apps/app/lib/store'
import { refused, useAttempt } from '@/apps/app/parts/checkout/attempt'
import { result } from '@/apps/app/telegram/haptics'
import { Notice } from '@/apps/app/ui/states'
import { ApiError } from '@/lib/api'
import type { StoreCheckoutResponse } from '@/lib/api-types'

/*
 * Sending an ordering request — a purchase, a built service, a renewal, an extra, a top-up — as the Store API asks: with
 * its attempt's Idempotency-Key (parts/checkout/attempt), pressed again by the customer after no answer or a server
 * failure. A price the shop no longer asks (a 409 on `expected_amount`, naming the price now) is said in the shop's words
 * for the customer to confirm, and the screen reads it again; a 429 holds pay for its Retry-After. Every refusal the
 * screen shows by the field it is under, or in <OrderNotice>. The answer opens the payment (lib/checkout).
 */

/** The shop costs it otherwise now than the price shown (a 409 on `expected_amount`). */
export function repriced(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409 && error.field('expected_amount') !== undefined
}

interface Ordering<B> {
  /** Send the order, with its attempt's key. */
  send: (body: B) => void
  busy: boolean
  /** The shop asked to wait (a 429): pay is held until its Retry-After passed. */
  held: boolean
  /** The last refusal was the price: the customer confirms the new one. */
  repriced: boolean
  /** The last refusal under one of the request's fields. */
  problem: (field: string) => string | undefined
  /** The last refusal, in the customer's words, unless it is under one of `fields` (shown by their own inputs); null for none. */
  notice: (fields: readonly string[]) => string | null
  /** Forget the last refusal: the customer changed what they order, so it no longer says anything of it. The same function every time. */
  dismiss: () => void
  /** `change`, after forgetting the last refusal (a new function each time: not for an effect's dependencies). */
  changing: <A extends unknown[]>(change: (...args: A) => void) => (...args: A) => void
}

/**
 * The checkout's ordering request: `send(body)` posts it with `post(body, key)`. `onRefused` hears every refusal (a 4xx),
 * for the screen to read again what it was refused for (the price, the plan, the ways to pay).
 */
export function useOrdering<B>(post: (body: B, key: string) => Promise<StoreCheckoutResponse>, onRefused?: (error: unknown) => void): Ordering<B> {
  const navigate = useNavigate()
  const attempt = useAttempt()
  const [hold, setHold] = useState<{ seconds: number } | null>(null)

  useEffect(() => {
    if (hold === null) return
    const timer = setTimeout(() => setHold(null), hold.seconds * 1000)
    return () => clearTimeout(timer)
  }, [hold])

  const mutation = useMutation({
    mutationFn: (body: B) => post(body, attempt.key(body)),
    meta: { quiet: true },
    onSuccess: ({ checkout }, body) => {
      attempt.done(body)
      goPay(navigate, checkout)
    },
    onError: (error, body) => {
      result(repriced(error) ? 'warning' : 'error')
      attempt.failed(error, body)
      if (!refused(error)) return
      if (error instanceof ApiError && error.status === 429) setHold({ seconds: error.facts.retryAfter ?? 60 })
      onRefused?.(error)
    },
  })
  const { error, reset } = mutation

  return {
    send: mutation.mutate,
    busy: mutation.isPending,
    held: hold !== null,
    repriced: repriced(error),
    problem: (field) => fieldProblem(error, field),
    notice: (fields) => (error === null || fields.some((field) => fieldProblem(error, field) !== undefined) ? null : problemOf(error)),
    dismiss: reset,
    changing:
      (change) =>
      (...args) => {
        reset()
        change(...args)
      },
  }
}

/** The order's refusal no field shows: the price changed (a warning: confirm it again), or why it was not made. */
export function OrderNotice<B>({ ordering, fields }: { ordering: Ordering<B>; fields: readonly string[] }) {
  const text = ordering.notice(fields)
  if (text === null) return null

  return (
    <div role="alert" className="animate-rise">
      <Notice tone={ordering.repriced ? 'warning' : 'danger'}>{text}</Notice>
    </div>
  )
}
