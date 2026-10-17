import { createContext, useCallback, useContext, useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from 'react'
import { CircleAlert, RefreshCw, Undo2, type LucideIcon } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { useHoldOpen } from '@/components/modal'
import { Button } from '@/components/ui/button'
import { CardFooter } from '@/components/ui/card'
import { formatNumber } from '@/lib/format'
import { useConfirmDiscard, useUnsavedGuard } from '@/lib/use-unsaved-guard'
import { cn } from '@/lib/utils'

/*
 * The two footers every edit surface ends with: FormActions (a form in a dialog: revert, cancel, submit) and SaveFooter
 * (a card edited in place: revert, save). Both guard unsaved changes (lib/use-unsaved-guard) and, when a submit comes
 * back refused field by field, focus the first invalid field and say how many are left.
 */

/** A form-level failure (nothing to pin on one field), as ErrorState's inline line; with its `failure`, a 429's wait counts down. */
export function FormError({ message, failure }: { message: string | null | undefined; failure?: unknown }) {
  if (!message) return null

  return <ErrorState variant="inline" error={failure ?? undefined} description={message} />
}

/**
 * A card edited in place whose server copy changed under its unsaved draft (useForm's `elsewhere`: another tab saved
 * it, a live refresh): the draft stays, as typed — saved, it takes the new copy's place —, and its button shows the new
 * copy instead, putting the draft aside (`onTake`, the form's revert).
 */
export function ChangedElsewhere({ shown, onTake }: { shown: boolean; onTake: () => void }) {
  if (!shown) return null

  return (
    <Callout tone="info" icon={RefreshCw} role="status">
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <span className="min-w-0">این بخش جای دیگری هم ذخیره شد. تغییرهای شما سر جایش مانده و با ذخیره جای آن را می‌گیرد؛ یا نسخه تازه را ببینید و تغییرها را کنار بگذارید.</span>
        <Button variant="secondary" size="sm" onClick={onTake}>
          دیدن نسخه تازه
        </Button>
      </div>
    </Callout>
  )
}

/**
 * A field the form marks invalid: a control (Field sets `aria-invalid`), or a focusable group of controls that is one
 * field (`data-invalid`: a plan's servers).
 */
export const INVALID_FIELD = '[aria-invalid="true"], [data-invalid]'

/** Marks a Disclosure's folded part, which opens when a field inside is marked invalid. */
export const FOLD = 'data-fold'

/**
 * The closed folds that hide a field (empty when it is in sight), or null when something else hides it — a hidden
 * section, or an inert one — so the focus cannot go there.
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

/** Focus the field once its folds have opened; returns what stops the wait. */
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
 * The footer's form's invalid fields: how many are marked now (so the count follows each fix), and — when a submit ends
 * with some — the focus on the first one that can take it, opening its folds first; focusing scrolls it into view.
 * `anchor` goes on an element of the footer, inside the form.
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

/** How many fields wait for a fix, shown beside the buttons. */
function FieldsToFix({ count }: { count: number }) {
  return (
    <span className="flex items-center gap-1.5 text-footnote text-danger">
      <CircleAlert className="size-3.5 shrink-0" aria-hidden />
      {count === 1 ? 'یک فیلد نیاز به اصلاح دارد' : `${formatNumber(count)} فیلد نیاز به اصلاح دارد`}
    </span>
  )
}

/**
 * A row that sticks to the foot of a phone's sheet (.app-modal below `md`) over what scrolls under it, edge to edge: a
 * form's buttons, a review's operations. A sticky box stays clear of its scroller's padding (the dialog body's 20px
 * `pb-5`): pulled down into it, the row sits on the sheet's very edge, nothing scrolling by beneath it.
 */
export const SHEET_FOOT =
  'max-md:in-[.app-modal]:sticky max-md:in-[.app-modal]:-bottom-5 max-md:in-[.app-modal]:z-10 max-md:in-[.app-modal]:-mx-5 max-md:in-[.app-modal]:border-t max-md:in-[.app-modal]:border-border max-md:in-[.app-modal]:bg-popover max-md:in-[.app-modal]:px-5 max-md:in-[.app-modal]:py-3'

const CreatingContext = createContext(false)

/**
 * Provided by a dialog whose form makes something new (EditorModal without a row, PickerModal's form): there is
 * nothing saved to go back to, so FormActions offers no revert whatever the form passes.
 */
export const Creating = CreatingContext.Provider

interface FormActionsProps {
  /** The form's failure (FormError), shown above the row. */
  error?: string | null
  /** Close the dialog without saving; absent for a form that is a card on a page. */
  onCancel?: () => void
  submitLabel: string
  /** The submit runs: the buttons lock and the submit spins. */
  busy?: boolean
  /** Nothing to submit yet (or a sibling request running); cancel and revert stay free. */
  disabled?: boolean
  /** The submit cannot go yet, for a reason the form says beside it: held (aria-disabled), so a press keeps the focus. */
  held?: boolean
  /** The submit's icon when idle. */
  icon?: LucideIcon
  /** Something at the start of the row (a delete button, a test button). */
  start?: ReactNode
  /** The values differ from where the form started (useForm's `dirty`): leaving asks first. */
  dirty?: boolean
  /**
   * Discard the unsaved changes in place. Passed by a form that edits something saved — its revert and submit are then
   * held while nothing changed. A form that makes something new passes none (or is inside `Creating`).
   */
  onRevert?: () => void
}

/**
 * The cancel/submit row of every form in a dialog. While the values are dirty, leaving (the tab, a link, «انصراف», the
 * dialog's ✕) asks first; while the submit runs, the dialog waits for it. On a phone its buttons are a finger's 44px,
 * and in a dialog's sheet the row sticks to the sheet's foot, so the submit of a long form stays in reach.
 */
export function FormActions({ error, onCancel, submitLabel, busy = false, disabled = false, held = false, icon, start, dirty = false, onRevert }: FormActionsProps) {
  useUnsavedGuard(dirty && !busy)
  useHoldOpen(busy)
  const confirmDiscard = useConfirmDiscard()
  const { anchor, toFix } = useFieldsToFix(busy)
  const revert = useContext(CreatingContext) ? undefined : onRevert

  return (
    <>
      <FormError message={error} />
      <div ref={anchor} className={cn('flex flex-wrap items-center gap-2 pt-2', SHEET_FOOT)}>
        {start}
        {/* Wraps on a phone: revert, cancel and submit side by side are wider than a phone-wide dialog. */}
        <div className="ms-auto flex flex-wrap items-center justify-end gap-2">
          {toFix > 0 && <FieldsToFix count={toFix} />}
          {revert && <RevertButton onClick={revert} held={busy || !dirty} />}
          {onCancel && (
            <Button variant="secondary" onClick={() => confirmDiscard(onCancel)} disabled={busy} className="max-md:h-11">
              انصراف
            </Button>
          )}
          {/* An edit with nothing changed has nothing to save: held rather than disabled, so a press keeps the focus. */}
          <Button type="submit" icon={icon} busy={busy} disabled={disabled} aria-disabled={held || (revert !== undefined && !dirty)} className="max-md:h-11">
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
  /** Discard this card's unsaved changes. */
  onRevert: () => void
  /** Lock the save for another reason (config.php cannot be written). */
  disabled?: boolean
  /** Save by hand, for a card that is not a <form>. */
  onSave?: () => void
  /** Actions at the start of the footer (a test button). */
  start?: ReactNode
}

/** The foot of a card that edits in place: the unsaved hint, revert and save. While dirty, leaving asks first. */
export function SaveFooter({ saving, dirty, onRevert, disabled = false, onSave, start }: SaveFooterProps) {
  useUnsavedGuard(dirty && !saving)
  const { anchor, toFix } = useFieldsToFix(saving)

  return (
    <CardFooter ref={anchor}>
      {start}
      <div className="ms-auto flex flex-wrap items-center justify-end gap-2">
        {toFix > 0 ? <FieldsToFix count={toFix} /> : dirty && !saving && <span className="me-1 text-footnote text-faint">تغییرات ذخیره نشده</span>}
        <RevertButton onClick={onRevert} held={saving || !dirty} size="sm" />
        {/* Nothing to save: held rather than disabled, so a press keeps the focus. */}
        <Button type={onSave ? 'button' : 'submit'} size="sm" onClick={onSave} busy={saving} disabled={disabled} aria-disabled={!dirty} className="max-md:h-11">
          ذخیره
        </Button>
      </div>
    </CardFooter>
  )
}

/** «بازگردانی تغییرات»: held (not disabled) while there is nothing to revert, so a press keeps the focus. */
function RevertButton({ onClick, held = false, size = 'default' }: { onClick: () => void; held?: boolean; size?: 'default' | 'sm' }) {
  return (
    <Button variant="ghost" size={size} icon={Undo2} onClick={onClick} aria-disabled={held} className="max-md:h-11">
      بازگردانی تغییرات
    </Button>
  )
}
