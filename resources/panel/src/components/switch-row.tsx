import { useId, type ReactNode } from 'react'
import { Switch } from '@/components/switch'
import { cn } from '@/lib/utils'

interface SwitchRowProps {
  label: string
  /** What turning it on does, in one or two lines. */
  hint?: ReactNode
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  /** Validation message from the server; shown under the hint and marks the row invalid. */
  error?: string
  /** It cannot be switched now, for a reason said beside it: the switch is held — reachable, its words told, taking no press. */
  held?: boolean
  /** False for a switch that sits among a form's fields, where the box would be one border too many. */
  bordered?: boolean
  className?: string
}

/**
 * An on/off setting as one row: label and explanation on one side, the switch on the other — the whole row toggles. The
 * switch is told what its hint and its error say, as a field's control is (Field).
 */
export function SwitchRow({ label, hint, checked, onCheckedChange, error, held = false, bordered = true, className }: SwitchRowProps) {
  const id = useId()
  const described = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ') || undefined

  return (
    <label
      className={cn(
        'flex items-center justify-between gap-4',
        held ? 'cursor-not-allowed' : 'cursor-pointer',
        bordered && 'rounded-lg border px-3.5 py-3 transition-colors duration-150',
        bordered && !held && 'hover:bg-fill',
        bordered && (error ? 'border-danger-line' : 'border-border'),
        className,
      )}
    >
      <span className="grid gap-0.5">
        <span className="text-body font-medium">{label}</span>
        {hint && (
          <span id={`${id}-hint`} className="text-footnote text-muted-foreground">
            {hint}
          </span>
        )}
        {error && (
          <span id={`${id}-error`} className="text-footnote text-danger">
            {error}
          </span>
        )}
      </span>
      <Switch checked={checked} onCheckedChange={onCheckedChange} held={held} aria-label={label} aria-describedby={described} aria-invalid={error ? true : undefined} />
    </label>
  )
}
