import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { LoaderCircle, type LucideIcon } from 'lucide-react'
import { Slot } from 'radix-ui'
import { cn } from '@/lib/utils'

/*
 * The panel's buttons, drawn like the Console's: 32px tall, 8px corners, medium weight. `default` is the one inverted
 * primary per surface (black on light, near-white on dark); `secondary` is the everyday button (a hairline box on
 * light, a soft fill on dark); `ghost` sits inside toolbars and rows; `destructive` is the solid red of a deletion and
 * `danger` its quiet form (a red label that tints on hover — `danger-outline` with a red hairline, among other
 * operations' buttons); `link` is a button that reads as a link.
 * Three of the panel's own: `icon` (drawn before the label), `busy` (a request runs: the icon turns into a spinner and
 * the button waits) and a button held with `aria-disabled` — busy, or by its owner — that keeps the focus it has and
 * ignores presses, where `disabled` would drop the keyboard on the document (the submit just pressed, «صفحه آخر») —
 * re-apply them if the component is reinstalled with the shadcn CLI.
 */
const buttonVariants = cva(
  "relative inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg font-medium whitespace-nowrap select-none transition-[background-color,color,box-shadow,opacity] duration-150 outline-none focus-visible:focus-ring disabled:pointer-events-none disabled:opacity-45 aria-disabled:pointer-events-none aria-disabled:opacity-45 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground hover:bg-primary/85 active:bg-primary/80',
        secondary:
          'border border-border-strong bg-card text-foreground shadow-card hover:bg-fill active:bg-fill-hover dark:border-transparent dark:bg-fill-hover dark:hover:bg-fill-active dark:active:bg-fill-active',
        ghost: 'text-foreground hover:bg-fill-hover active:bg-fill-active',
        // An icon alone (IconButton): tertiary ink that darkens on a soft fill, and stays lit while its menu is open.
        quiet: 'text-faint hover:bg-fill-hover hover:text-foreground active:bg-fill-active aria-expanded:bg-fill-hover aria-expanded:text-foreground',
        destructive: 'bg-destructive text-white hover:bg-destructive/90 active:bg-destructive/85',
        danger: 'text-danger hover:bg-danger-soft active:bg-danger-soft',
        'danger-outline': 'border border-danger-line/70 text-danger hover:bg-danger-soft active:bg-danger-soft',
        link: 'rounded-sm text-link underline-offset-4 hover:underline',
      },
      size: {
        sm: "h-7 gap-1 rounded-md px-2.5 text-footnote [&_svg:not([class*='size-'])]:size-3.5",
        default: 'h-8 px-3 text-body',
        lg: 'h-9 px-4 text-body',
        icon: 'size-8',
        'icon-sm': 'size-7 rounded-md',
      },
    },
    // A link sits in its line: no height or padding of a button's.
    compoundVariants: [{ variant: 'link', className: 'h-auto px-0' }],
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  },
)

function Button({
  className,
  variant = 'default',
  size = 'default',
  asChild = false,
  type,
  icon: Icon,
  busy = false,
  disabled,
  onClick,
  'aria-disabled': ariaDisabled,
  children,
  ...props
}: React.ComponentProps<'button'> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
    /** Drawn before the label (not with `asChild`, whose one child is the whole button). */
    icon?: LucideIcon
    /** A request runs: the icon turns into a spinner and the button waits. */
    busy?: boolean
  }) {
  const Comp = asChild ? Slot.Root : 'button'
  // Held: it keeps the focus and swallows a press — a submit's included (Enter in a field clicks it).
  const held = busy || ariaDisabled === true || ariaDisabled === 'true'

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      // A plain button never submits by accident; a form's submit says so (`type="submit"`).
      type={asChild ? undefined : (type ?? 'button')}
      disabled={disabled}
      aria-disabled={held || undefined}
      aria-busy={busy || undefined}
      className={cn(buttonVariants({ variant, size, className }))}
      onClick={held ? (event: React.MouseEvent<HTMLButtonElement>) => event.preventDefault() : onClick}
      {...props}
    >
      {asChild ? (
        children
      ) : (
        <>
          {busy ? <LoaderCircle className="animate-spin" aria-hidden /> : Icon && <Icon aria-hidden />}
          {children}
        </>
      )}
    </Comp>
  )
}

export { Button }
