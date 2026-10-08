import { createContext, useCallback, useContext, useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from 'react'
import { CircleAlert, Undo2, type LucideIcon } from 'lucide-react'
import { ErrorState } from '@/components/error-state'
import { useHoldOpen } from '@/components/modal'
import { Button } from '@/components/ui/button'
import { CardFooter } from '@/components/ui/card'
import { formatNumber } from '@/lib/format'
import { useConfirmDiscard, useUnsavedGuard } from '@/lib/use-unsaved-guard'

/**
 * A form-level failure (nothing to pin on one field), announced to assistive tech: ErrorState's line. With the `failure`
 * it came of, a wait the server asked for (a 429) counts down under it.
 */
export function FormError({ message, failure }: { message: string | null | undefined; failure?: unknown }) {
  if (!message) return null

  return <ErrorState variant="inline" error={failure ?? undefined} description={message} />
}

/**
 * A field its form marks invalid: a control (Field sets `aria-invalid` from the form's errors) — or a group of controls
 * that is one field of the form, its refusals said under it (`data-invalid`, focusable: a plan's servers).
 */
export const INVALID_FIELD = '[aria-invalid="true"], [data-invalid]'

/** A fold's mark — the rarely needed part of a form (Disclosure), hidden until opened —, which opens for a field it holds that is marked invalid. */
export const FOLD = 'data-fold'

/**
 * The folds that keep a field out of sight — closed Disclosures, which open for it —, none when it is in sight; null when
 * something else does: a section kept mounted but hidden, or inert — no place for the focus.
 */
function foldsOver(field: HTMLElement): HTMLElement[] | null {
  const folds: HTMLElement[] = []
  for (let node: HTMLElement | null = field; node !== null; node = node.parentElement) {
    if (node.hasAttribute('inert')) return null
    if (node.hasAttribute('hidden')) {
      if (!node.hasAttribute(FOLD)) return null
      folds.push(node)
    }
  }
  return folds
}

/** The focus on the field, once its folds have opened for it; what stops the wait. */
function focusOnceShown(field: HTMLElement, folds: HTMLElement[]): () => void {
  const shown = () => folds.every((fold) => !fold.hasAttribute('hidden'))
  if (shown()) {
    field.focus()
    return () => undefined
  }
  const observer = new MutationObserver(() => {
    if (!shown()) return
    observer.disconnect()
    field.focus()
  })
  for (const fold of folds) observer.observe(fold, { attributeFilter: ['hidden'] })
  return () => observer.disconnect()
}

/**
 * The fields of the footer's form that a refusal marked invalid — counted as the form shows them, so the footer's word
 * follows each fix and goes with the last —, and, as a submit ends with some (the server refused them by name, a 422),
 * the focus on the first one the admin can be taken to — on screen, or in a fold, which opens for it —, which brings it
 * into view however far above the footer it is. `anchor` goes on an element of the footer, inside the form.
 */
function useFieldsToFix(busy: boolean) {
  const [form, setForm] = useState<HTMLFormElement | null>(null)
  const anchor = useCallback((node: HTMLElement | null) => setForm(node?.closest('form') ?? null), [])

  const subscribe = useCallback(
    (changed: () => void) => {
      if (form === null) return () => undefined
      const observer = new MutationObserver(changed)
      observer.observe(form, { subtree: true, childList: true, attributeFilter: ['aria-invalid', 'data-invalid'] })
      return () => observer.disconnect()
    },
    [form],
  )
  const toFix = useSyncExternalStore(subscribe, () => form?.querySelectorAll(INVALID_FIELD).length ?? 0)

  const wasBusy = useRef(busy)
  useEffect(() => {
    const ended = wasBusy.current && !busy
    wasBusy.current = busy
    if (!ended || form === null) return
    for (const field of form.querySelectorAll<HTMLElement>(INVALID_FIELD)) {
      const folds = foldsOver(field)
      if (folds !== null) return focusOnceShown(field, folds)
    }
  }, [busy, form])

  return { anchor, toFix }
}

/** How many fields wait for a fix, said near the button that was refused. */
function FieldsToFix({ count }: { count: number }) {
  return (
    <span className="flex items-center gap-1.5 text-footnote text-danger">
      <CircleAlert className="size-3.5 shrink-0" aria-hidden />
      {count === 1 ? 'یک فیلد نیاز به اصلاح دارد' : `${formatNumber(count)} فیلد نیاز به اصلاح دارد`}
    </span>
  )
}

/** Whether the form inside makes something new: what a dialog opened on nothing says (`Creating`). */
const CreatingContext = createContext(false)

/**
 * What a dialog provides to the form it opens to make something new — EditorModal without a row, PickerModal's form, the
 * keyboard's button dialog —: the form has nothing saved to go back to, so its actions offer no revert, whatever it hands
 * them.
 */
export const Creating = CreatingContext.Provider

interface FormActionsProps {
  /** The form's failure (FormError), said above the row. */
  error?: string | null
  /** Close the dialog without saving; absent where the form is a card on a page (the server's connection). */
  onCancel?: () => void
  submitLabel: string
  /** True while the submit runs: the buttons lock and the submit shows a spinner. */
  busy?: boolean
  /** The submit cannot go now (nothing to submit yet, a sibling request running); cancel and revert stay free. */
  disabled?: boolean
  /** The submit button's icon when idle. */
  icon?: LucideIcon
  /** Something at the start of the row (a delete button, a test button). */
  start?: ReactNode
  /** The values say something else than where the form started (useForm's `dirty`): what would be lost — asked about first. */
  dirty?: boolean
  /**
   * Discard the unsaved changes in place (the form stays open) — handed by a form that edits what is saved (a row, a
   * text, a customer's groups), which then has nothing to do while nothing changed: its revert and its submit are held. A
   * form that makes something new hands none — or its dialog says it is new (`Creating`) —: there is nothing to go back to.
   */
  onRevert?: () => void
}

/**
 * The cancel/submit row that closes every form in a dialog — an edit's with a way to throw unsaved edits away, held as its
 * submit is while nothing changed —, and, while there are unsaved changes, the question before they are lost (closing
 * the tab, a link elsewhere, «انصراف», the dialog's ✕ or Escape). While the submit runs, the dialog waits for it; refused
 * field by field, the focus goes to the first field to fix and the row says how many are left.
 */
export function FormActions({ error, onCancel, submitLabel, busy = false, disabled = false, icon, start, dirty = false, onRevert }: FormActionsProps) {
  useUnsavedGuard(dirty && !busy)
  useHoldOpen(busy)
  const confirmDiscard = useConfirmDiscard()
  const { anchor, toFix } = useFieldsToFix(busy)
  // An edit of what is saved — never a new row's form, which has nothing to go back to.
  const revert = useContext(CreatingContext) ? undefined : onRevert

  return (
    <>
      <FormError message={error} />
      <div ref={anchor} className="flex flex-wrap items-center gap-2 pt-2">
        {start}
        {/* Wraps on a phone: revert, cancel and submit side by side are wider than a phone-wide dialog. */}
        <div className="ms-auto flex flex-wrap items-center justify-end gap-2">
          {toFix > 0 && <FieldsToFix count={toFix} />}
          {revert && <RevertButton onClick={revert} held={busy || !dirty} />}
          {onCancel && (
            <Button variant="secondary" onClick={() => confirmDiscard(onCancel)} disabled={busy}>
              انصراف
            </Button>
          )}
          {/* An edit with nothing changed has nothing to save: held, not disabled — just pressed, it keeps the focus. */}
          <Button type="submit" icon={icon} busy={busy} disabled={disabled} aria-disabled={revert !== undefined && !dirty}>
            {submitLabel}
          </Button>
        </div>
      </div>
    </>
  )
}

interface SaveFooterProps {
  saving: boolean
  dirty: boolean
  /** Discard the unsaved changes of this card. */
  onRevert: () => void
  /** Lock the save for another reason (a read-only file, a sibling request). */
  disabled?: boolean
  /** Save by hand instead of submitting the enclosing form (a card that is not a <form>). */
  onSave?: () => void
  /** Actions at the start of the footer (test buttons, a reset). */
  start?: ReactNode
}

/**
 * The foot of a card that edits in place: the unsaved hint, revert, and save — pinned under the card's content. While
 * something is unsaved, leaving asks first (the tab, a reload, a link to another page). Refused field by field, the
 * focus goes to the first field to fix and the foot says how many are left.
 */
export function SaveFooter({ saving, dirty, onRevert, disabled = false, onSave, start }: SaveFooterProps) {
  useUnsavedGuard(dirty && !saving)
  const { anchor, toFix } = useFieldsToFix(saving)

  return (
    <CardFooter ref={anchor}>
      {start}
      <div className="ms-auto flex flex-wrap items-center justify-end gap-2">
        {toFix > 0 ? <FieldsToFix count={toFix} /> : dirty && !saving && <span className="me-1 text-footnote text-faint">تغییرات ذخیره نشده</span>}
        <RevertButton onClick={onRevert} held={saving || !dirty} size="sm" />
        {/* Saved, it has nothing to save — and keeps the focus it was pressed with (held, not disabled). */}
        <Button type={onSave ? 'button' : 'submit'} size="sm" onClick={onSave} busy={saving} disabled={disabled} aria-disabled={!dirty}>
          ذخیره
        </Button>
      </div>
    </CardFooter>
  )
}

/**
 * "Throw my unsaved edits away" — the same control on every form, greyed out while there is nothing to revert, and held
 * rather than disabled: pressed, it keeps the focus.
 */
function RevertButton({ onClick, held = false, size = 'default' }: { onClick: () => void; held?: boolean; size?: 'default' | 'sm' }) {
  return (
    <Button variant="ghost" size={size} icon={Undo2} onClick={onClick} aria-disabled={held}>
      بازگردانی تغییرات
    </Button>
  )
}
