import { cloneElement, useId, type ReactElement, type ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

/** What the field's control receives so the label and the hint/error line point at it. */
interface FieldControlProps {
  id: string
  'aria-invalid': boolean
  'aria-describedby': string | undefined
}

interface FieldProps {
  id: string
  label: ReactNode
  /** Helper line under the control. */
  hint?: ReactNode
  /** Validation message; also marks the field invalid for assistive tech. */
  error?: string
  optional?: boolean
  className?: string
  /**
   * The control: one element (an Input, a Textarea), which gets the wiring props merged in — or a
   * render function for a control that sits inside a wrapper (a select's trigger, an input beside a
   * button), which is handed the props to put on the right element.
   */
  children: ReactElement<Partial<FieldControlProps>> | ((control: FieldControlProps) => ReactNode)
}

/** aria-describedby value matching what <Field> renders under the control. */
function describedBy(id: string, hint?: unknown, error?: string): string | undefined {
  if (error) return `${id}-error`
  if (hint) return `${id}-hint`
  return undefined
}

/**
 * Label + control + hint/error, wired with ids: the control gets its id, whether it is invalid
 * and which line describes it, so callers only name the field once.
 */
export function Field({ id, label, hint, error, optional, className, children }: FieldProps) {
  const control: FieldControlProps = { id, 'aria-invalid': !!error, 'aria-describedby': describedBy(id, hint, error) }

  return (
    // content-start: in a multi-column form the cells stretch to the tallest one; without it grid would
    // spread that extra height over the rows and push this field's control below its neighbours'.
    <div className={cn('grid min-w-0 content-start gap-1.5', className)}>
      <Label htmlFor={id} className="flex items-baseline gap-1.5">
        {label}
        {optional && <span className="text-caption font-normal text-faint">(اختیاری)</span>}
      </Label>
      {typeof children === 'function' ? children(control) : cloneElement(children, control)}
      {error ? (
        <p id={`${id}-error`} className="text-footnote text-danger">
          {error}
        </p>
      ) : hint ? (
        <p id={`${id}-hint`} className="text-footnote text-muted-foreground">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

interface FieldGroupProps {
  label: ReactNode
  optional?: boolean
  /** A line under the label about the controls (read with the group, before them). */
  description?: ReactNode
  /** The line under the controls while nothing is refused. */
  hint?: ReactNode
  /** The refusal — or several, one a line — that mark the whole group invalid. */
  error?: string | readonly string[]
  className?: string
  children: ReactNode
}

/**
 * Several controls that are one field of a form — tick boxes, a row of choices, a list of rows — labelled as one. While
 * refused the group is marked invalid and focusable, so a refused submit moves the focus to it as to any single field
 * (FormActions looks for `[data-invalid]`).
 */
export function FieldGroup({ label, optional, description, hint, error, className, children }: FieldGroupProps) {
  const id = useId()
  const errors = error === undefined ? [] : typeof error === 'string' ? [error] : error
  const invalid = errors.length > 0
  const described = [description ? `${id}-about` : null, invalid || hint ? `${id}-line` : null].filter(Boolean).join(' ')

  return (
    <div
      role="group"
      aria-labelledby={`${id}-label`}
      aria-describedby={described || undefined}
      data-invalid={invalid ? '' : undefined}
      tabIndex={invalid ? -1 : undefined}
      className={cn('grid min-w-0 content-start gap-1.5 rounded-md outline-none focus-visible:focus-ring', className)}
    >
      <div className="grid gap-0.5">
        <span id={`${id}-label`} className="flex items-baseline gap-1.5 text-body font-medium">
          {label}
          {optional && <span className="text-caption font-normal text-faint">(اختیاری)</span>}
        </span>
        {description && (
          <p id={`${id}-about`} className="text-footnote leading-relaxed text-muted-foreground">
            {description}
          </p>
        )}
      </div>
      {children}
      {invalid ? (
        <div id={`${id}-line`} className="grid gap-0.5 text-footnote whitespace-pre-line text-danger">
          {errors.map((message) => (
            <p key={message}>{message}</p>
          ))}
        </div>
      ) : (
        hint && (
          <p id={`${id}-line`} className="text-footnote leading-relaxed text-muted-foreground">
            {hint}
          </p>
        )
      )}
    </div>
  )
}

interface FormPartProps {
  icon: LucideIcon
  title: string
  /** A line under the title about what the part sets. */
  about: ReactNode
  children: ReactNode
}

/** A titled, framed part of a long form or settings card (the referral program's parts, one extra's prices). */
export function FormPart({ icon: Icon, title, about, children }: FormPartProps) {
  const id = useId()

  return (
    <div role="group" aria-labelledby={`${id}-title`} className="grid content-start gap-4 rounded-xl border border-border p-4">
      <div className="grid gap-1">
        <h3 id={`${id}-title`} className="flex items-center gap-2 text-body font-medium">
          <Icon className="size-4 text-muted-foreground" aria-hidden />
          {title}
        </h3>
        <p className="text-footnote leading-relaxed text-muted-foreground">{about}</p>
      </div>
      {children}
    </div>
  )
}
