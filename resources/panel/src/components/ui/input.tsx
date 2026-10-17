import * as React from 'react'
import { cn } from '@/lib/utils'

/**
 * The look every text field shares (inputs, textareas, select triggers, the search box). Its hairline is a theme's own
 * colour (`--field-line`), never a `dark:` border, which would win over the focus and a refusal. 16px on a phone, where
 * a smaller field's focus zooms the page (iOS) — and a one-line field (an input, a select) a finger's 44px tall there. A read-only one (`readOnly`: a reply held while it sends, codes shown to
 * copy) is flat — the page's fill, its words a step quieter, no hover —, so it never reads as a field to type in, while
 * it stays as legible, selectable and focusable as it is; a disabled one fades.
 */
export const fieldClasses =
  'w-full min-w-0 rounded-lg border border-field-line bg-card text-body text-foreground transition-[border-color,box-shadow,background-color] duration-150 outline-none placeholder:text-faint hover:border-field-line-hover focus-visible:focus-field disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger-line aria-invalid:focus-visible:shadow-[0_0_0_3px_color-mix(in_srgb,var(--danger-line)_35%,transparent)] max-md:text-base dark:bg-fill [&[readonly]]:cursor-default [&[readonly]]:border-border [&[readonly]]:bg-fill [&[readonly]]:text-muted-foreground [&[readonly]]:hover:border-border dark:[&[readonly]]:bg-transparent'

function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        fieldClasses,
        'h-8 px-3 py-1 max-md:h-11 file:inline-flex file:h-6 file:border-0 file:bg-transparent file:text-footnote file:font-medium file:text-foreground selection:bg-info-soft',
        className,
      )}
      {...props}
    />
  )
}

export { Input }
