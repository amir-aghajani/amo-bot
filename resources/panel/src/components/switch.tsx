import { cn } from '@/lib/utils'

interface SwitchProps {
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  disabled?: boolean
  /** Held while a change runs (its own, or a sibling's in its list): it keeps the focus it has and takes no press. */
  held?: boolean
  /** Its name — a row's label (SwitchRow) or what a table's switch turns on ("قابل خرید بودن …"). */
  'aria-label': string
  /** The hint or the error under its row. */
  'aria-describedby'?: string
  'aria-invalid'?: boolean
}

/**
 * An on/off control (role="switch"), blue while on; the thumb moves along the inline axis so it reads correctly in RTL.
 * `held` is `aria-disabled`, not `disabled`, which would drop the keyboard on the document.
 */
export function Switch({ checked, onCheckedChange, disabled, held = false, ...rest }: SwitchProps) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      aria-disabled={held || undefined}
      onClick={() => {
        if (!held) onCheckedChange(!checked)
      }}
      className={cn(
        'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors duration-150 outline-none focus-visible:focus-ring disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50',
        checked ? 'bg-selected' : 'bg-border-strong',
      )}
      {...rest}
    >
      <span
        aria-hidden
        className={cn(
          'pointer-events-none block size-4 rounded-full bg-white shadow-[0_1px_2px_rgb(0_0_0/25%)] transition-transform duration-150 motion-reduce:transition-none',
          checked ? 'translate-x-[18px] rtl:-translate-x-[18px]' : 'translate-x-0.5 rtl:-translate-x-0.5',
        )}
      />
    </button>
  )
}
