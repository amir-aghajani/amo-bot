import * as React from 'react'
import { Popover as PopoverPrimitive } from 'radix-ui'
import { usePortalContainer } from '@/components/portal-container'
import { cn } from '@/lib/utils'

/*
 * A card that opens beside its trigger on a press — a tap, a click, Enter — and closes on Escape or a press outside, the
 * focus going into it and back to the trigger: drawn as the panel's other floating layers (the menus) — the popover
 * surface, 12px corners, a hairline, the soft shadow —, kept off the window's edges and never wider than the room beside
 * its trigger. One of the panel's own: it portals into the open dialog (PortalContainerContext), as the menus do —
 * re-apply that one-line `container` if the component is reinstalled with the shadcn CLI.
 */

function Popover({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Root>) {
  return <PopoverPrimitive.Root data-slot="popover" {...props} />
}

function PopoverTrigger({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Trigger>) {
  return <PopoverPrimitive.Trigger data-slot="popover-trigger" {...props} />
}

function PopoverContent({ className, align = 'center', sideOffset = 6, collisionPadding = 8, ...props }: React.ComponentProps<typeof PopoverPrimitive.Content>) {
  const container = usePortalContainer()
  return (
    <PopoverPrimitive.Portal container={container}>
      <PopoverPrimitive.Content
        data-slot="popover-content"
        align={align}
        sideOffset={sideOffset}
        collisionPadding={collisionPadding}
        className={cn(
          'z-50 w-72 max-w-(--radix-popover-content-available-width) origin-(--radix-popover-content-transform-origin) rounded-xl border border-border bg-popover p-3 text-body text-popover-foreground shadow-popover outline-hidden data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-98 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-98',
          className,
        )}
        {...props}
      />
    </PopoverPrimitive.Portal>
  )
}

export { Popover, PopoverTrigger, PopoverContent }
