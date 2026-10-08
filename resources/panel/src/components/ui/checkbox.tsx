import * as React from 'react'
import { CheckIcon, MinusIcon } from 'lucide-react'
import { Checkbox as CheckboxPrimitive } from 'radix-ui'
import { cn } from '@/lib/utils'

function Checkbox({ className, ...props }: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        'peer group size-4 shrink-0 rounded-[4px] border border-border-stronger bg-card transition-[background-color,border-color,box-shadow] duration-150 outline-none focus-visible:focus-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger-line data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary data-[state=indeterminate]:text-primary-foreground dark:data-[state=unchecked]:bg-transparent',
        className,
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator data-slot="checkbox-indicator" className="grid place-content-center text-current transition-none">
        {/* "Some of them": a select-all over a partial selection shows a dash, not a tick. */}
        <CheckIcon className="size-3 group-data-[state=indeterminate]:hidden" strokeWidth={3} />
        <MinusIcon className="hidden size-3 group-data-[state=indeterminate]:block" strokeWidth={3} />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  )
}

export { Checkbox }
