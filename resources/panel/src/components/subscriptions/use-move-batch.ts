import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, ApiError, stateChanged } from '@/lib/api'
import type { SubscriptionRow } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'
import { useUnloadGuard } from '@/lib/use-unsaved-guard'

/** How one service of the batch stands. */
export type Outcome = { state: 'moving' } | { state: 'asking' } | { state: 'moved'; server: string; left: boolean } | { state: 'failed'; message: string } | { state: 'cancelled' }

/** The batch waits on the admin: a previous server failed. */
export interface Question {
  subscription: SubscriptionRow
  reason: string
  /** Services later in the batch on the same previous server — the answer covers them too. */
  others: number
}

/**
 * How one request went: moved, or the step that failed and why — `leave` being the server's refusal to leave the client
 * on the previous one (an agent's shop may, only while that panel is out of reach).
 */
type Result = { step: 'moved' } | { step: 'previous' | 'target' | 'leave' | 'service'; message: string }

/**
 * What a batch moves beyond the services it moved — read again once it is over: the list behind it (a client found gone
 * on its panel on the way is marked deleted), the servers' counts and room, the dashboard's.
 */
const BATCH_CHANGES = [queryKeys.subscriptions, queryKeys.serverChoices, queryKeys.dashboards]

/**
 * A batch of services moved to another server, one request each, in order, so the admin watches every one of them go
 * (or fail, and why) — the one source of what the move dialog shows. A previous server that does not answer pauses the
 * batch on a question (`question`, answered with `reply`): carry on without deleting from it — the rest of the batch on
 * that server goes the same way — or cancel there. A target that refuses one stops the batch there (`stopped`, that
 * service — its outcome says why): the next would fail too; so does a refusal to carry on without the previous server.
 * Every opening (a new `subscriptions`) starts afresh. The page going away (the browser's back) stops the batch after
 * the service under way — a question it waited on is answered «لغو» —, and closing the tab meanwhile asks first. The
 * batch is one mutation: once it is over, what it moved beyond its services is read again (BATCH_CHANGES).
 */
export function useMoveBatch(subscriptions: SubscriptionRow[] | null, onMoved: (subscription: SubscriptionRow) => void) {
  const [opened, setOpened] = useState(subscriptions)
  const [target, setTarget] = useState('')
  const [outcomes, setOutcomes] = useState<Record<number, Outcome>>({})
  const [stopped, setStopped] = useState<SubscriptionRow | null>(null)
  const [question, setQuestion] = useState<Question | null>(null)
  // Read by the running loop between two services: a stop asked meanwhile, the admin's answer to the question.
  const stop = useRef(false)
  const answer = useRef<((skip: boolean) => void) | null>(null)
  useEffect(
    () => () => {
      stop.current = true
      answer.current?.(false)
      answer.current = null
    },
    [],
  )

  // A new opening (state adjusted during render, React's way to follow a prop).
  if (opened !== subscriptions) {
    setOpened(subscriptions)
    setTarget('')
    setOutcomes({})
    setStopped(null)
    setQuestion(null)
  }

  // Only an active service has time and traffic left to carry over, and one already on the target has nowhere to go:
  // the rest of a selection stays put.
  const movable = (subscriptions ?? []).filter((s) => s.actions.move)
  const queue = movable.filter((s) => String(s.server.id) !== target)

  const record = (id: number, outcome: Outcome) => setOutcomes((current) => ({ ...current, [id]: outcome }))

  /** Pause the batch until the admin answers: true = carry on without the previous server. */
  const ask = (next: Question) =>
    new Promise<boolean>((resolve) => {
      answer.current = resolve
      setQuestion(next)
    })

  /** One request. A previous server that failed is not recorded yet: the admin is asked first. */
  const moveOne = async (subscription: SubscriptionRow, leavePrevious: boolean): Promise<Result> => {
    record(subscription.id, { state: 'moving' })
    try {
      const { subscription: fresh } = await api.post(`/subscriptions/${subscription.id}/move`, { server_id: Number(target), leave_previous: leavePrevious })
      record(subscription.id, { state: 'moved', server: fresh.server.name, left: leavePrevious })
      onMoved(fresh)
      return { step: 'moved' }
    } catch (error) {
      const field = (name: string) => (error instanceof ApiError ? error.field(name) : undefined)
      const previous = field('previous')
      if (previous !== undefined && !leavePrevious) return { step: 'previous', message: previous }
      // The target refusing it (or not answering) refuses the next one too, and a refusal to leave the client on the
      // previous server refuses the rest of that server's; anything else is this service's own.
      const refused = field('server_id') ?? field('target')
      const message = refused ?? previous ?? messageOf(error, 'status')
      record(subscription.id, { state: 'failed', message })
      return { step: refused !== undefined ? 'target' : leavePrevious && stateChanged(error) ? 'leave' : 'service', message }
    }
  }

  // The batch, start to end — done, stopped or cancelled there. Every service's failure is its outcome's: the batch
  // itself fails at nothing.
  const batch = useMutation({
    mutationFn: async () => {
      stop.current = false
      setStopped(null)
      // Previous servers the admin chose to leave out: the rest of the batch on them goes without asking again.
      const leftOut = new Set<number>()
      for (const [index, subscription] of queue.entries()) {
        if (stop.current) break
        const from = subscription.server.id
        let result = await moveOne(subscription, leftOut.has(from))
        if (result.step === 'previous') {
          record(subscription.id, { state: 'asking' })
          const others = queue.slice(index + 1).filter((s) => s.server.id === from).length
          if (!(await ask({ subscription, reason: result.message, others }))) {
            record(subscription.id, { state: 'cancelled' })
            break
          }
          leftOut.add(from)
          result = await moveOne(subscription, true)
        }
        if (result.step === 'target' || result.step === 'leave') {
          setStopped(subscription)
          break
        }
      }
    },
    meta: { invalidates: BATCH_CHANGES },
  })
  const running = batch.isPending
  useUnloadGuard(running)

  const tally = (state: Outcome['state']) => queue.filter((s) => outcomes[s.id]?.state === state).length

  return {
    target,
    setTarget,
    /** The active services of the selection, and those of them not on the target already. */
    movable,
    queue,
    outcomes,
    running,
    started: Object.keys(outcomes).length > 0,
    moved: tally('moved'),
    failed: tally('failed'),
    cancelled: tally('cancelled') > 0,
    /** The service whose refusal stopped the batch (the target's, or the previous server's to be left out): its outcome says why. */
    stopped,
    question,
    start: () => batch.mutate(),
    /** Stop after the service under way. */
    stopAfterThis: () => {
      stop.current = true
    },
    /** The answer to the question: true = carry on without the previous server. */
    reply: (skip: boolean) => {
      setQuestion(null)
      answer.current?.(skip)
      answer.current = null
    },
  }
}

export type MoveBatch = ReturnType<typeof useMoveBatch>
