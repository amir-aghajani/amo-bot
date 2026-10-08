import { cloneElement, type ReactElement, type ReactNode } from 'react'
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
 * Label + control + hint/error, wired with ids: the control is told its id, whether it is invalid
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
