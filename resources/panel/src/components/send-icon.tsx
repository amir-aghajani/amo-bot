import { forwardRef } from 'react'
import { Send, type LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

/**
 * A send button's paper plane, flying toward the line's end: Lucide draws it flying right, so right to left it is
 * mirrored. A Lucide icon itself, for a button's `icon`.
 */
export const SendIcon: LucideIcon = forwardRef(function SendIcon({ className, ...props }, ref) {
  return <Send ref={ref} className={cn('rtl:-scale-x-100', className)} {...props} />
})
