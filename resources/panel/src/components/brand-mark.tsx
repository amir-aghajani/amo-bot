import { cn } from '@/lib/utils'

/** The product mark: an ink square (inverted with the theme) carrying the shield glyph of the favicon. */
export function BrandMark({ className }: { className?: string }) {
  return (
    <span aria-hidden className={cn('flex shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground', className)}>
      <svg viewBox="0 0 64 64" className="size-[62%]" fill="none" stroke="currentColor" strokeWidth={6} strokeLinecap="round" strokeLinejoin="round">
        <path d="M32 10 L50 18 V32 C50 44 41 52 32 56 C23 52 14 44 14 32 V18 Z" />
        <path d="M24 33 L30 39 L41 26" />
      </svg>
    </span>
  )
}
