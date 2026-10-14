import type { ReactNode } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatNumber } from '@/lib/format'

interface SelectionBarProps {
  /** The list's selection (useSelection()): how many are ticked, and the way to untick them all. */
  selection: { size: number; clear: () => void }
  /** What a row is called: «رسید», «سرویس». */
  noun: string
  /** The bar's name for assistive tech: «رسیدهای انتخاب‌شده». */
  label: string
  /** What is done with the rows ticked: its buttons. */
  children: ReactNode
}

/**
 * A paged list's bar while some of its rows are ticked: how many, what is done with them, and unticking them all. Pinned
 * to the bottom of the screen while the list scrolls under it, so ticking a row never moves the rows.
 */
export function SelectionBar({ selection, noun, label, children }: SelectionBarProps) {
  if (selection.size === 0) return null

  return (
    <div
      role="toolbar"
      aria-label={label}
      className="sticky bottom-5 z-10 flex w-fit max-w-full flex-wrap items-center justify-center gap-2 self-center rounded-xl border border-border bg-popover py-1.5 ps-4 pe-1.5 shadow-popover"
    >
      <span className="text-body tabular">{`${formatNumber(selection.size)} ${noun} انتخاب شده`}</span>
      {children}
      <Button size="icon-sm" variant="ghost" icon={X} onClick={selection.clear} aria-label="لغو انتخاب" />
    </div>
  )
}
