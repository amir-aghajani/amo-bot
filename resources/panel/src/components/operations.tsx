import { useId, useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react'
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
 * (its row's `actions`, the server's rules). An operation that is worded or takes something away first opens a strip
 * for a second look (its warning, the note the customer reads, back / confirm, and a second way through where the
 * subject offers one); one that needs more than a note shows the dialog's own form on the strip. A refusal shows the
 * API's message. When the live updates bring a state that no longer allows the operation waiting on the strip, it is
 * withdrawn with a word.
 *
 * The focus never falls to the document: an operation running keeps it (buttons are held, not disabled), and when a
 * run, a strip or an operation ends and takes the focused control with it, the focus goes to the chosen operation (or
 * the first one the new state offers), or to the dialog's word on what happened.
 */

/** What one operation is called, what it does, and whether it asks first. */
export interface OperationSpec {
  label: string
  icon: LucideIcon
  tone: 'primary' | 'danger' | 'neutral'
  /** The toast after the subject ("پرداخت #n …"). */
  done: string
  /** A warning shown before it runs: the operation deserves a second look. */
  confirm?: string
  /** A note it takes (a reason the customer sees, a ledger line). */
  note?: NoteSpec
  /** It needs more than a note: its strip holds the dialog's own form (OperationsSection's `form`), which runs it. */
  form?: true
}

/**
 * A second way through an operation's strip, under its buttons: run it with `force` — without what it would otherwise
 * touch (a claimed service's delete that leaves its client on the panel).
 */
export interface OperationAlternative {
  label: string
  /** What choosing it means, beside its button. */
  hint: ReactNode
}

/**
 * One run: the note typed on the strip, and `force` — run without what it would otherwise touch: what failed last time
 * (a panel that did not answer a delete), or with nothing failed, by the strip's alternative (OperationAlternative).
 */
interface Run<A> {
  action: A
  note?: string
  force?: boolean
}

interface UseOperationsOptions<A extends string, R> {
  specs: Record<A, OperationSpec>
  /** What the subject's state allows now (its row's `actions`). */
  allowed: Record<A, boolean>
  perform: (run: Run<A>) => Promise<R>
  /** The reads of other screens the operations change, refetched after each (`meta.invalidates`); `onDone` updates the row itself. */
  invalidates: readonly QueryKey[]
  onDone: (result: R, run: Run<A>) => void
  /** The server refused because the subject moved on (a 422 on `status`): refetch it and its list (useOpenRow's `refresh`). */
  onStale: () => void
  /** A failure the dialog words its own way; returns true when it took it (a delete whose panel did not answer). */
  onFailure?: (error: unknown, run: Run<A>) => boolean
  /** An operation run elsewhere (a move opens its own dialog); returns true when it took it. */
  intercept?: (action: A) => boolean
  /** Something else holds the operations (the delete's own question): a state change meanwhile withdraws nothing. */
  holding?: boolean
}

/**
 * Where the focus should go once something ends, if the focused control went with it: the operations, or the dialog's
 * word on a refusal. `insist` moves it from anywhere in the section, for a refusal saying the subject moved on (what the
 * admin pressed next may be gone).
 */
interface FocusRequest {
  to: 'operations' | 'word'
  insist: boolean
  /** Each request is handled once. */
  seq: number
}

/** A dialog's operations, as OperationsSection draws them. */
interface Operations<A extends string> {
  available: A[]
  /** The operation waiting on its strip for the second look. */
  pending: A | null
  /** The one that was waiting until the subject moved on and no longer allows it. */
  withdrawn: A | null
  note: string
  setNote: (note: string) => void
  /** The operation the admin chose last: the buttons come back with the focus on it, or on the first one offered. */
  last: A | null
  /** The last run's refusal, as the API's message. */
  error: string | null
  /** The refusal says the subject moved on: it was refetched, and the refusal is the dialog's only message about it. */
  movedOn: boolean
  /** Where the focus goes now, if the focused control is gone. */
  focus: FocusRequest | null
  /** The operation in flight. */
  running: A | null
  /** The run in flight is forced (`force`): a strip's alternative, not its confirm. */
  forced: boolean
  /** Close the strip (back, or done with it while the dialog stays open). */
  close: () => void
  /** A press: an operation that needs a word or a second look opens its strip; the rest run at once. */
  choose: (action: A) => void
  /** The strip's confirm. */
  confirm: () => void
  /** Run one directly: `force`, after the dialog's own question or by the strip's alternative. */
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

  /** A run; the last refusal goes with the new try. */
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
    forced: mutation.isPending && mutation.variables?.force === true,
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
  /** The subject as the withdrawal names it: «این سفارش». */
  subject: string
  /** The form on the strip of an operation with `form`: it runs the operation and closes the strip (`ops.close`). */
  form?: (action: A) => ReactNode
  /** A second way through an operation's strip, where the subject offers one (OperationAlternative); null for none. */
  alternative?: (action: A) => OperationAlternative | null
  /** Shown instead of the strip and the buttons while the dialog asks a question of its own (the delete's). */
  override?: ReactNode
}

/**
 * The dialog's operations under its facts: one line saying what became of an operation (a refusal because the
 * subject moved on, a withdrawal, or another refusal when no strip is open), then the buttons, the strip, or the
 * dialog's own question. Nothing for a subject that is gone (the dialog says so above its facts).
 */
export function OperationsSection<A extends string>({ ops, specs, subject, form, alternative, override }: OperationsSectionProps<A>) {
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
      {/* One element whatever it says, so the focus put on it stays as its content changes. */}
      {word !== null && (
        <div data-operations-word="" tabIndex={-1} className="rounded-lg outline-none focus-visible:focus-ring">
          {word}
        </div>
      )}
      {override ??
        (ops.pending !== null ? (
          <OperationStrip action={ops.pending} spec={specs[ops.pending]} ops={ops} error={onStrip && !ops.movedOn ? ops.error : null} alternative={alternative?.(ops.pending) ?? null}>
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
 * Move the focus once something ends (a run, a strip closed, a withdrawal) if the focused control went with it: to the
 * chosen operation (or the first one offered), or to the section's word after a refusal or a withdrawal. A refusal
 * saying the subject moved on takes the focus from anywhere in the section. A focus the admin put elsewhere stays.
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

/** A button per allowed operation. While one runs, all are held (aria-disabled: the pressed one keeps the focus) and it spins. */
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

interface OperationStripProps<A extends string> {
  action: A
  spec: OperationSpec
  ops: Operations<A>
  error: string | null
  alternative: OperationAlternative | null
  children?: ReactNode
}

/**
 * The second look before a worded or destructive operation: the warning, the note, the refusal of what was sent, back /
 * confirm, and under them the subject's alternative if it has one — or, for an operation with `form`, the dialog's form
 * (`children`), which has its own buttons.
 */
function OperationStrip<A extends string>({ action, spec, ops, error, alternative, children }: OperationStripProps<A>) {
  const Icon = spec.icon
  const running = ops.running !== null
  // The alternative's hint, read with its button.
  const hint = useId()

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
            busy={running && !ops.forced}
            waiting={running && ops.forced}
            focusCancel={!spec.note}
            onCancel={ops.close}
            onConfirm={ops.confirm}
          />
          {alternative && (
            <div className="grid gap-2 border-t border-border pt-3 sm:flex sm:items-center sm:justify-between">
              <p id={hint} className="text-footnote text-muted-foreground">
                {alternative.hint}
              </p>
              <Button
                size="sm"
                variant="secondary"
                // A long choice wraps on a phone rather than push the dialog wider than the screen.
                className="h-auto min-h-7 max-w-full shrink justify-self-end py-1 text-center whitespace-normal"
                busy={running && ops.forced}
                aria-disabled={running}
                aria-describedby={hint}
                onClick={() => ops.run({ action, note: spec.note ? ops.note : undefined, force: true })}
              >
                {alternative.label}
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  )
}
