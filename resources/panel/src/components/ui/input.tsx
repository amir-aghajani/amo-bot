import * as React from 'react'
import { cn } from '@/lib/utils'

/** The look every text field shares (inputs, textareas, select triggers, the search box). */
export const fieldClasses =
  'w-full min-w-0 rounded-lg border border-border-strong bg-card text-body text-foreground transition-[border-color,box-shadow,background-color] duration-150 outline-none placeholder:text-faint hover:border-border-stronger focus-visible:focus-field disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger-line aria-invalid:focus-visible:shadow-[0_0_0_3px_color-mix(in_srgb,var(--danger-line)_35%,transparent)] dark:border-border dark:bg-fill dark:hover:border-border-strong'

function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        fieldClasses,
        'h-8 px-3 py-1 file:inline-flex file:h-6 file:border-0 file:bg-transparent file:text-footnote file:font-medium file:text-foreground selection:bg-info-soft',
        className,
      )}
      {...props}
    />
  )
}

export { Input }
