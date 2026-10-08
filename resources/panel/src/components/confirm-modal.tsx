import { useId, useState, type ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { FormError } from '@/components/form-footer'
import { Modal, useHoldOpen } from '@/components/modal'
import { NoteInput } from '@/components/note-input'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'

/** A decision's note — what the customer reads beside it (a rejection's reason, why an agency ended), or a ledger line. */
export interface NoteSpec {
  label: string
  placeholder: string
  /** What the server takes of it, when that is not a decision's note's: a refund's, which its wallet line carries (LEDGER_NOTE_MAX). */
  max?: number
}

interface ConfirmModalProps {
  open: boolean
  onClose: () => void
  title: string
  description?: ReactNode
  /** Extra explanation under the description (consequences, what stays). */
  children?: ReactNode
  confirmLabel?: string
  /** The way out's own words (the default: «انصراف»). */
  cancelLabel?: string
  /** Red button for things that delete or discard. */
  destructive?: boolean
  /** True while the request runs: buttons lock and the dialog cannot be dismissed. */
  pending?: boolean
  /** The decision takes a note; it may stay empty. */
  note?: NoteSpec
  /** The server refused the decision (a note too long, decided meanwhile): said above the buttons. */
  error?: string | null
  onConfirm: (note: string) => void
}

/** "Are you sure?" — cancel gets the initial focus so Enter never confirms by accident. */
export function ConfirmModal({ open, onClose, title, description, children, confirmLabel = 'تایید', cancelLabel, destructive = false, pending = false, note, error, onConfirm }: ConfirmModalProps) {
  const [text, setText] = useState('')
  // Every opening starts with an empty note (state adjusted during render, React's way to follow a prop).
  const [wasOpen, setWasOpen] = useState(open)
  if (wasOpen !== open) {
    setWasOpen(open)
    if (open) setText('')
  }

  return (
    <Modal open={open} onClose={onClose} size="sm" title={title} description={description}>
      <div className="grid gap-5">
        {children !== undefined && (typeof children === 'string' ? <p className="text-body text-muted-foreground">{children}</p> : children)}
        {note && <NoteField note={note} value={text} onChange={setText} />}
        <FormError message={error} />
        <ConfirmActions confirmLabel={confirmLabel} cancelLabel={cancelLabel} destructive={destructive} busy={pending} onCancel={onClose} onConfirm={() => onConfirm(text.trim())} />
      </div>
    </Modal>
  )
}

/** A decision's note field: held to what the server takes, with how much of it is used (NoteInput). */
export function NoteField({ note, value, onChange, autoFocus = false }: { note: NoteSpec; value: string; onChange: (value: string) => void; autoFocus?: boolean }) {
  const id = useId()

  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>{note.label}</Label>
      <NoteInput id={id} rows={2} max={note.max} value={value} onChange={(event) => onChange(event.target.value)} placeholder={note.placeholder} autoFocus={autoFocus} />
    </div>
  )
}

interface ConfirmActionsProps {
  confirmLabel: string
  cancelLabel?: string
  /** The confirm's icon (an operation's own). */
  icon?: LucideIcon
  destructive?: boolean
  busy?: boolean
  /** The buttons of an inline strip are a size down. */
  size?: 'sm' | 'default'
  /** Inline (a strip that takes the place of the button pressed), cancel takes the focus as it appears; in a dialog, the dialog gives it. */
  focusCancel?: boolean
  onCancel: () => void
  onConfirm: () => void
}

/**
 * The second look's row — cancel, then the confirm (red when it takes something away) — of every "are you sure" in the
 * panels. While the decision runs, the dialog it is in waits for it.
 */
export function ConfirmActions({ confirmLabel, cancelLabel = 'انصراف', icon, destructive = false, busy = false, size = 'default', focusCancel = false, onCancel, onConfirm }: ConfirmActionsProps) {
  useHoldOpen(busy)

  return (
    <div className="flex flex-wrap items-center justify-end gap-2">
      <Button variant="secondary" size={size} onClick={onCancel} disabled={busy} data-autofocus autoFocus={focusCancel}>
        {cancelLabel}
      </Button>
      <Button variant={destructive ? 'destructive' : 'default'} size={size} icon={icon} busy={busy} onClick={onConfirm}>
        {confirmLabel}
      </Button>
    </div>
  )
}
