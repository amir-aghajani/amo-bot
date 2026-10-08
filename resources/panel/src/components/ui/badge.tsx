import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { Slot } from 'radix-ui'
import { cn } from '@/lib/utils'

/*
 * A small label (the Console's chips: «New», «Beta», «Not enabled», a count beside a title): 20px tall, 5px corners,
 * a soft tint with a status family's text colour (the tones of lib/statuses) — or a neutral fill, or a hairline.
 * Status words go through StatusBadge, counts through CountBadge.
 */
const badgeVariants = cva(
  'inline-flex h-5 w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-[5px] px-1.5 text-caption font-medium whitespace-nowrap [&>svg]:pointer-events-none [&>svg]:size-3',
  {
    variants: {
      variant: {
        neutral: 'bg-fill text-muted-foreground',
        info: 'bg-info-soft text-info',
        success: 'bg-success-soft text-success',
        warning: 'bg-warning-soft text-warning',
        danger: 'bg-danger-soft text-danger',
        outline: 'border border-border text-muted-foreground',
      },
    },
    defaultVariants: {
      variant: 'neutral',
    },
  },
)

function Badge({ className, variant = 'neutral', asChild = false, ...props }: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot.Root : 'span'

  return <Comp data-slot="badge" data-variant={variant} className={cn(badgeVariants({ variant }), className)} {...props} />
}

export { Badge }
