import * as React from 'react'
import { CheckIcon, ChevronDownIcon, ChevronUpIcon } from 'lucide-react'
import { Select as SelectPrimitive } from 'radix-ui'
import { usePortalContainer } from '@/components/portal-container'
import { fieldClasses } from '@/components/ui/input'
import { cn } from '@/lib/utils'

function Select({ ...props }: React.ComponentProps<typeof SelectPrimitive.Root>) {
  return <SelectPrimitive.Root data-slot="select" {...props} />
}

function SelectGroup({ ...props }: React.ComponentProps<typeof SelectPrimitive.Group>) {
  return <SelectPrimitive.Group data-slot="select-group" {...props} />
}

function SelectValue({ ...props }: React.ComponentProps<typeof SelectPrimitive.Value>) {
  return <SelectPrimitive.Value data-slot="select-value" {...props} />
}

function SelectTrigger({
  className,
  size = 'default',
  children,
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Trigger> & {
  size?: 'sm' | 'default'
}) {
  return (
    <SelectPrimitive.Trigger
      data-slot="select-trigger"
      data-size={size}
      className={cn(
        fieldClasses,
        "flex w-fit items-center justify-between gap-2 px-3 whitespace-nowrap data-[placeholder]:text-faint data-[size=default]:h-8 data-[size=sm]:h-7 data-[size=sm]:rounded-md data-[size=sm]:px-2.5 data-[size=sm]:text-footnote *:data-[slot=select-value]:line-clamp-1 *:data-[slot=select-value]:flex *:data-[slot=select-value]:items-center *:data-[slot=select-value]:gap-2 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 [&_svg:not([class*='text-'])]:text-faint",
        className,
      )}
      {...props}
    >
      {children}
      <SelectPrimitive.Icon asChild>
        <ChevronDownIcon className="size-4 text-faint" />
      </SelectPrimitive.Icon>
    </SelectPrimitive.Trigger>
  )
}

// Lists open under the field like a menu (Radix's default lays them over it, aligned to the chosen item), from the
// field's start edge — and never wider than the room from there to the window's edge: a long option wraps instead.
function SelectContent({ className, children, position = 'popper', align = 'start', ...props }: React.ComponentProps<typeof SelectPrimitive.Content>) {
  const container = usePortalContainer()
  return (
    <SelectPrimitive.Portal container={container}>
      <SelectPrimitive.Content
        data-slot="select-content"
        className={cn(
          'relative z-50 max-h-(--radix-select-content-available-height) min-w-[8rem] origin-(--radix-select-content-transform-origin) overflow-x-hidden overflow-y-auto rounded-xl border border-border bg-popover text-popover-foreground shadow-popover data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-98 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-98',
          position === 'popper' && 'max-w-(--radix-select-content-available-width) data-[side=bottom]:translate-y-1 data-[side=top]:-translate-y-1',
          className,
        )}
        position={position}
        align={align}
        {...props}
      >
        <SelectScrollUpButton />
        <SelectPrimitive.Viewport className={cn('p-1', position === 'popper' && 'h-[var(--radix-select-trigger-height)] w-full min-w-[var(--radix-select-trigger-width)] scroll-my-1')}>
          {children}
        </SelectPrimitive.Viewport>
        <SelectScrollDownButton />
      </SelectPrimitive.Content>
    </SelectPrimitive.Portal>
  )
}

function SelectLabel({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.Label>) {
  return <SelectPrimitive.Label data-slot="select-label" className={cn('px-2 pt-2 pb-1 text-caption font-medium text-faint', className)} {...props} />
}

/**
 * An option: its words (what the field shows once picked) and, one of the panel's own, a `hint` under them in secondary
 * ink — what the option carries (why a server cannot take a service) — which wraps in the list and stays out of the field.
 */
function SelectItem({ className, children, hint, ...props }: React.ComponentProps<typeof SelectPrimitive.Item> & { hint?: React.ReactNode }) {
  return (
    <SelectPrimitive.Item
      data-slot="select-item"
      className={cn(
        "relative flex min-h-8 w-full cursor-default items-center gap-2 rounded-md py-1 ps-2 pe-8 text-body outline-hidden select-none focus:bg-fill-hover data-[disabled]:pointer-events-none data-[disabled]:opacity-45 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 [&_svg:not([class*='text-'])]:text-faint *:[span]:last:flex *:[span]:last:items-center *:[span]:last:gap-2",
        className,
      )}
      {...props}
    >
      <span data-slot="select-item-indicator" className="absolute end-2 flex size-4 items-center justify-center">
        <SelectPrimitive.ItemIndicator>
          <CheckIcon className="size-4 text-foreground" />
        </SelectPrimitive.ItemIndicator>
      </span>
      {hint === undefined ? (
        <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
      ) : (
        <div className="grid min-w-0 gap-0.5 py-0.5">
          <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
          <span className="text-footnote text-muted-foreground">{hint}</span>
        </div>
      )}
    </SelectPrimitive.Item>
  )
}

function SelectSeparator({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.Separator>) {
  return <SelectPrimitive.Separator data-slot="select-separator" className={cn('pointer-events-none -mx-1 my-1 h-px bg-border', className)} {...props} />
}

function SelectScrollUpButton({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.ScrollUpButton>) {
  return (
    <SelectPrimitive.ScrollUpButton data-slot="select-scroll-up-button" className={cn('flex cursor-default items-center justify-center py-1 text-faint', className)} {...props}>
      <ChevronUpIcon className="size-4" />
    </SelectPrimitive.ScrollUpButton>
  )
}

function SelectScrollDownButton({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.ScrollDownButton>) {
  return (
    <SelectPrimitive.ScrollDownButton data-slot="select-scroll-down-button" className={cn('flex cursor-default items-center justify-center py-1 text-faint', className)} {...props}>
      <ChevronDownIcon className="size-4" />
    </SelectPrimitive.ScrollDownButton>
  )
}

export { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue }
