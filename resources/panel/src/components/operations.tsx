import { useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react'
import { useMutation, type QueryKey } from '@tanstack/react-query'
import { Info, type LucideIcon } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ConfirmActions, NoteField, type NoteSpec } from '@/components/confirm-modal'
import { useSubjectGone } from '@/components/detail-modal'
import { FormError } from '@/components/form-footer'
import { Button } from '@/components/ui/button'
import { stateChanged } from '@/lib/api'
import { messageOf } from '@/lib/failure'
import { cn } from '@/lib/utils'

/*
 * A detail dialog's operations (an order's, a payment's, a service's): a button for each one the subject's state allows
 * — its row's `actions`, the server's rules —, a second look for one that is worded or takes something away (its
 * warning, the note the customer reads, confirm / back) or a form on the same strip for one that asks more than a
 * note, the refusal in the API's words, and a word when the subject moved on under the admin (the live updates brought
 * a state that no longer allows what was waiting) — one line, whichever said it first. The focus never drops to the
 * document: an operation running keeps it (held, not disabled), and once a run, a strip or an operation is over and the
 * control that had it is gone, it goes to the operation the admin chose — the first one the new state offers when it no
 * longer offers that — or to the dialog's word on what happened (a refusal because the subject moved on, a withdrawal).
 */

/** What one operation is called, what it does, and whether it asks first. */
export interface OperationSpec {
  label: string
  icon: LucideIcon
  tone: 'primary' | 'danger' | 'neutral'
  /** The toast, after the subject ("پرداخت #n …", "سرویس amir_2 …"). */
  done: string
  /** Shown before the operation runs, when it deserves a second look. */
  confirm?: string
  /** A note the operation takes (a reason the customer sees, a line for the ledger). */
  note?: NoteSpec
  /** It asks for more than a note: its strip holds the dialog's own form (OperationsSection's `form`), which runs it. */
  form?: true
}

/** One run: the note typed on the strip, and `force` — run it without what failed the last time (a panel that did not answer a delete). */
interface Run<A> {
  action: A
  note?: string
  force?: boolean
}

interface UseOperationsOptions<A extends string, R> {
  specs: Record<A, OperationSpec>
  /** What the subject's state allows now — its row's `actions`; nothing once the subject is gone (DetailModal's `gone`). */
  allowed: Record<A, boolean>
  perform: (run: Run<A>) => Promise<R>
  /**
   * What the subject's operations change beside the subject's own row (which `onDone` puts in its place) — the reads of
   * other screens, of whatever screen the dialog is on —, read again after each (their `meta.invalidates`).
   */
  invalidates: readonly QueryKey[]
  onDone: (result: R, run: Run<A>) => void
  /** The server refused because the subject moved on (a 422 on its state): the subject and the list behind it are read again (useOpenRow's `refresh`). */
  onStale: () => void
  /** A failure the dialog words its own way — true when it took it (a delete whose panel did not answer). */
  onFailure?: (error: unknown, run: Run<A>) => boolean
  /** An operation that is not run here (a move opens its own dialog) — true when it took it. */
  intercept?: (action: A) => boolean
  /** Something else holds the operations (the delete's question): the subject changing meanwhile withdraws nothing. */
  holding?: boolean
}

/**
 * Where the focus goes once something of the admin's own is over, should the control that had it be gone with it: back
 * to the operations (a run went through, a strip closed), or to the dialog's word on a refusal — `insist`ing, from
 * anywhere in the operations, when the refusal says the subject moved on (what the admin chose next may well be gone).
 */
interface FocusRequest {
  to: 'operations' | 'word'
  insist: boolean
  /** Each request once (OperationsSection keeps the last it took). */
  seq: number
}

/** A dialog's operations as its section draws them (OperationsSection). */
interface Operations<A extends string> {
  available: A[]
  /** The operation waiting on its strip for the second look. */
  pending: A | null
  /** The one that was waiting until the subject moved on and no longer allows it. */
  withdrawn: A | null
  note: string
  setNote: (note: string) => void
  /** The operation the admin chose last: the buttons come back with the focus on it, or on the first the state offers. */
  last: A | null
  /** The last run's refusal, in the API's words. */
  error: string | null
  /** The refusal says the subject moved on (a 422 on its state): the subject was read again, and the refusal is the dialog's one word on it. */
  movedOn: boolean
  /** Where the focus goes now, should the control that had it be gone. */
  focus: FocusRequest | null
  /** The operation in flight. */
  running: A | null
  /** Close the strip (back, or done with it while the dialog stays open). */
  close: () => void
  /** A press: what needs a word or a second look opens the strip first; the rest runs at once. */
  choose: (action: A) => void
  /** The strip's confirm. */
  confirm: () => void
  /** Run one its own way (`force`, after the dialog's own question). */
  run: (run: Run<A>) => void
}

export function useOperations<A extends string, R>({ specs, allowed, perform, invalidates, onDone, onStale, onFailure, intercept, holding = false }: UseOperationsOptions<A, R>): Operations<A> {
  const [waiting, setWaiting] = useState<A | null>(null)
  const [note, setNote] = useState('')
  const [last, setLast] = useState<A | null>(null)
  const [refusal, setRefusal] = useState<{ message: string; movedOn: boolean } | null>(null)
  const [focus, setFocus] = useState<FocusRequest | null>(null)
  const moveFocus = (to: FocusRequest['to'], insist = false) => setFocus((current) => ({ to, insist, seq: (current?.seq ?? 0) + 1 }))

  const mutation = useMutation({
    mutationFn: perform,
    meta: { quiet: true, invalidates },
    onSuccess: (result, run) => {
      onDone(result, run)
      moveFocus('operations')
    },
    onError: (failure, run) => {
      if (onFailure?.(failure, run)) return
      const movedOn = stateChanged(failure)
      setRefusal({ message: messageOf(failure, 'note', 'status'), movedOn })
      moveFocus('word', movedOn)
      if (movedOn) onStale()
    },
  })

  /** A run: the last refusal goes with the new try. */
  const start = (run: Run<A>) => {
    setRefusal(null)
    mutation.mutate(run)
  }

  const gone = useSubjectGone()
  const available = gone ? [] : (Object.keys(specs) as A[]).filter((action) => allowed[action])
  // The admin's own operation in flight changes the state too, and is no withdrawal.
  const withdrawn = waiting !== null && !mutation.isPending && !holding && !available.includes(waiting) ? waiting : null
  const pending = withdrawn === null ? waiting : null

  return {
    available,
    pending,
    withdrawn,
    note,
    setNote,
    last,
    error: refusal?.message ?? null,
    movedOn: refusal?.movedOn ?? false,
    focus,
    running: mutation.isPending ? (mutation.variables?.action ?? null) : null,
    close: () => {
      setWaiting(null)
      setRefusal(null)
      moveFocus('operations')
    },
    choose: (action) => {
      setRefusal(null)
      setLast(action)
      if (intercept?.(action)) return
      if (specs[action].note || specs[action].confirm || specs[action].form) {
        setWaiting(action)
        setNote('')
      } else {
        start({ action })
      }
    },
    confirm: () => {
      if (pending !== null) start({ action: pending, note: specs[pending].note ? note : undefined })
    },
    run: (next) => {
      setLast(next.action)
      start(next)
    },
  }
}

interface OperationsSectionProps<A extends string> {
  ops: Operations<A>
  specs: Record<A, OperationSpec>
  /** The subject, as the withdrawal says it: «این سفارش». */
  subject: string
  /** The form on the strip of an operation that asks more than a note (its spec's `form`): it runs it, and closes the strip (`ops.close`). */
  form?: (action: A) => ReactNode
  /** In place of the strip and the buttons while the dialog asks something of its own (the delete's question). */
  override?: ReactNode
}

/**
 * The dialog's operations, under its facts: its word on what became of an operation — and under it the buttons, or the
 * strip waiting for its second look, or the dialog's own question. The word is one line: a refusal because the subject
 * moved on says what a withdrawal would; a refusal of what the strip sent (its note, a failure to send) is said on the
 * strip, by its buttons. None for a subject that is gone: the dialog says so above its facts.
 */
export function OperationsSection<A extends string>({ ops, specs, subject, form, override }: OperationsSectionProps<A>) {
  const gone = useSubjectGone()
  const root = useRef<HTMLElement>(null)
  useOperationsFocus(root, ops)

  const onStrip = !override && ops.pending !== null
  const word =
    ops.error !== null && ops.movedOn ? (
      <FormError message={ops.error} />
    ) : ops.withdrawn !== null ? (
      <Callout tone="info" icon={Info} role="status">
        {subject} همین الان تغییر کرد و «{specs[ops.withdrawn].label}» دیگر برایش ممکن نیست.
      </Callout>
    ) : ops.error !== null && !onStrip ? (
      <FormError message={ops.error} />
    ) : null
  if (gone || (word === null && !override && ops.pending === null && ops.available.length === 0)) return null

  return (
    <section ref={root} aria-label="عملیات" className="grid gap-3 border-t border-border pt-4">
      {/* One place, whatever it says: the focus put on it stays as what is under it changes. */}
      {word !== null && (
        <div data-operations-word="" tabIndex={-1} className="rounded-lg outline-none focus-visible:focus-ring">
          {word}
        </div>
      )}
      {override ??
        (ops.pending !== null ? (
          <OperationStrip spec={specs[ops.pending]} ops={ops} error={onStrip && !ops.movedOn ? ops.error : null}>
            {specs[ops.pending].form && form?.(ops.pending)}
          </OperationStrip>
        ) : (
          ops.available.length > 0 && <OperationButtons specs={specs} ops={ops} />
        ))}
    </section>
  )
}

/** The section's word, where the focus goes to read it. */
const WORD = '[data-operations-word]'

/**
 * The focus, once something under the admin's hands is over — a run, a strip closed, an operation withdrawn — and the
 * control it was on gone with it (never the document): to the operation the admin chose, or the first one the state now
 * offers; to the section's word after a refusal (from anywhere in the section, when it says the subject moved on) or a
 * withdrawal. A focus the admin put elsewhere stays there.
 */
function useOperationsFocus<A extends string>(root: RefObject<HTMLElement | null>, ops: Operations<A>) {
  const handled = useRef(0)
  const withdrawn = useRef<A | null>(null)

  useLayoutEffect(() => {
    const request = ops.focus !== null && ops.focus.seq !== handled.current ? ops.focus : null
    if (ops.focus !== null) handled.current = ops.focus.seq
    const withdrawal = ops.withdrawn !== null && withdrawn.current === null
    withdrawn.current = ops.withdrawn
    const section = root.current
    if (section === null || (request === null && !withdrawal)) return

    const active = document.activeElement
    const lost = !(active instanceof HTMLElement) || active === document.body || !active.isConnected
    const word = section.querySelector<HTMLElement>(WORD)
    const chosen = ops.last !== null && ops.available.includes(ops.last) ? ops.last : ops.available[0]
    const operation = chosen === undefined ? null : section.querySelector<HTMLElement>(`[data-operation="${chosen}"]`)

    if (request?.insist && word !== null && (lost || section.contains(active))) word.focus()
    else if (lost) (request?.to === 'operations' ? (operation ?? word) : (word ?? operation))?.focus()
  })
}

/**
 * One button per operation the subject's state allows — while one runs, every one held (aria-disabled, not disabled:
 * the one pressed keeps the focus, a press does nothing) and the running one turning.
 */
function OperationButtons<A extends string>({ specs, ops }: { specs: Record<A, OperationSpec>; ops: Operations<A> }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      {ops.available.map((action) => {
        const spec = specs[action]
        return (
          <Button
            key={action}
            data-operation={action}
            size="sm"
            variant={spec.tone === 'primary' ? 'default' : spec.tone === 'danger' ? 'danger-outline' : 'secondary'}
            icon={spec.icon}
            busy={ops.running === action}
            aria-disabled={ops.running !== null}
            onClick={() => ops.choose(action)}
          >
            {spec.label}
          </Button>
        )
      })}
    </div>
  )
}

/**
 * The second look before a worded or destructive operation: the warning, the note, the refusal of what it sent, back /
 * confirm — or, for one that asks more than a note, the dialog's form (`children`), which has its own.
 */
function OperationStrip<A extends string>({ spec, ops, error, children }: { spec: OperationSpec; ops: Operations<A>; error: string | null; children?: ReactNode }) {
  const Icon = spec.icon

  return (
    <div className={cn('grid gap-3 rounded-xl border p-4', spec.tone === 'danger' ? 'border-danger-line/70 bg-danger-soft/35' : 'border-border bg-fill')}>
      <div className={cn('flex items-center gap-2 text-body font-medium', spec.tone === 'danger' && 'text-danger')}>
        <Icon className="size-4" aria-hidden />
        {spec.label}
      </div>
      {spec.confirm && <p className="text-body text-muted-foreground">{spec.confirm}</p>}
      {children ?? (
        <>
          {spec.note && <NoteField note={spec.note} value={ops.note} onChange={ops.setNote} autoFocus />}
          <FormError message={error} />
          <ConfirmActions
            size="sm"
            cancelLabel="برگشت"
            confirmLabel={spec.label}
            icon={spec.icon}
            destructive={spec.tone === 'danger'}
            busy={ops.running !== null}
            focusCancel={!spec.note}
            onCancel={ops.close}
            onConfirm={ops.confirm}
          />
        </>
      )}
    </div>
  )
}
