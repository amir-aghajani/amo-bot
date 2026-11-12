import { useId, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { Copy } from 'lucide-react'
import { selection, tap } from '@/apps/app/telegram/haptics'
import { copyText } from '@/apps/app/ui/toaster'
import { cn } from '@/lib/utils'

/*
 * What a customer types and picks: a labelled field with its hint and its refusal under it, a text box, a box of a few
 * lines, segments to choose one of, chips, and a value with its copy button.
 */

interface FieldProps {
  label?: ReactNode
  hint?: ReactNode
  /** The API's refusal of it (its field's first message). */
  error?: string
  children: (id: string) => ReactNode
}

/** A field: its label, the control (given its id), and its hint or its refusal under it. */
export function Field({ label, hint, error, children }: FieldProps) {
  const id = useId()

  return (
    <div className="flex flex-col gap-2">
      {label !== undefined && (
        <label htmlFor={id} className="text-section-header px-4 text-footnote font-semibold">
          {label}
        </label>
      )}
      {children(id)}
      {error !== undefined ? (
        <p className="px-4 text-caption text-danger" role="alert">
          {error}
        </p>
      ) : (
        hint !== undefined && <p className="text-hint px-4 text-caption">{hint}</p>
      )}
    </div>
  )
}

const BOX = 'w-full rounded-control bg-section px-4 text-body text-text shadow-card outline-none transition-shadow placeholder:text-hint/70 focus:ring-2 focus:ring-accent'

/** A line to type; `ltr` for what reads left to right (a link, an email, a number). */
export function TextInput({ className, ltr = false, invalid = false, ...rest }: InputHTMLAttributes<HTMLInputElement> & { ltr?: boolean; invalid?: boolean }) {
  return <input className={cn(BOX, 'h-12', ltr && 'ltr text-left', invalid && 'ring-2 ring-danger', className)} aria-invalid={invalid || undefined} {...rest} />
}

/** A few lines to type. */
export function TextArea({ className, invalid = false, ...rest }: TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }) {
  return <textarea className={cn(BOX, 'min-h-28 resize-none py-3', invalid && 'ring-2 ring-danger', className)} aria-invalid={invalid || undefined} {...rest} />
}

interface SegmentedProps<T extends string> {
  value: T
  options: readonly { value: T; label: ReactNode }[]
  onChange: (value: T) => void
  className?: string
}

/**
 * One of a few, side by side (iOS's segmented control); each tapped over the control's whole height. A single choice:
 * a radio group to a screen reader, its choice checked.
 */
export function Segmented<T extends string>({ value, options, onChange, className }: SegmentedProps<T>) {
  return (
    <div className={cn('flex h-11 rounded-xl bg-fill p-1', className)} role="radiogroup">
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          role="radio"
          aria-checked={option.value === value}
          onClick={() => {
            if (option.value !== value) selection()
            onChange(option.value)
          }}
          className={cn(
            'tap-target relative h-9 flex-1 rounded-[9px] px-2 text-footnote leading-none font-semibold transition-[background-color,color,box-shadow]',
            option.value === value ? 'bg-section text-text shadow-[0_1px_3px_rgb(0_0_0/0.12)]' : 'text-hint',
          )}
        >
          {option.label}
        </button>
      ))}
    </div>
  )
}

/** A pill to pick among many (a category, an amount): 36px drawn, 44px to tap. */
export function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={() => {
        selection()
        onClick()
      }}
      className={cn(
        'press tap-target relative inline-flex h-9 shrink-0 items-center rounded-full px-4 text-footnote leading-none font-semibold whitespace-nowrap transition-colors',
        active ? 'bg-accent text-on-accent' : 'bg-section text-text shadow-card',
      )}
    >
      {children}
    </button>
  )
}

/** A value to copy (a link, a card number, an amount): shown as it reads, copied as given. */
export function CopyValue({ label, value, shown, done = 'کپی شد' }: { label?: ReactNode; value: string; shown?: ReactNode; done?: string }) {
  return (
    <button
      type="button"
      onClick={() => {
        tap()
        void copyText(value, done)
      }}
      className="press rounded-control flex min-h-14 w-full items-center gap-3 bg-fill px-4 py-2.5 text-start"
    >
      <span className="flex min-w-0 flex-1 flex-col gap-0.5">
        {label !== undefined && <span className="text-hint text-caption">{label}</span>}
        <span className="ltr truncate text-left text-body font-medium">{shown ?? value}</span>
      </span>
      <span className="text-accent-text grid size-9 shrink-0 place-items-center rounded-[10px] bg-[color-mix(in_srgb,var(--app-accent-text)_12%,transparent)]">
        <Copy className="size-4.5" />
      </span>
    </button>
  )
}
