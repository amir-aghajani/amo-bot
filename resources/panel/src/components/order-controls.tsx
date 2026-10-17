import { useLayoutEffect, useRef, useState } from 'react'
import { ArrowDown, ArrowUp } from 'lucide-react'
import { IconButton } from '@/components/icon-button'
import { formatNumber } from '@/lib/format'

/** An arrow: 24px, on a phone 32px — with the 12px between them there, 44px from one's middle to the other's. */
const ARROW = 'size-6 rounded-md max-md:size-8'

interface OrderControlsProps {
  /** What is being moved, for the accessible names ("انتقال X به بالا"). */
  label: string
  index: number
  count: number
  /** A move runs: the arrows hold — they keep the focus they have and take no press — until it is done. */
  held?: boolean
  onMove: (delta: -1 | 1) => void
}

/**
 * Up/down arrows for a row of an admin-ordered list, side by side, 24px each — on a phone 32px, 12px apart: each a
 * finger's 44px press (the button's own) that does not reach over the other. The focus goes
 * with the row: once a press has moved it, wherever the move put it in the page, the arrow pressed has the focus again —
 * the other one when the row reached that end —, so the keyboard can press on; and where it went is said to assistive
 * tech, politely («… به ردیف ۲ رفت»).
 */
export function OrderControls({ label, index, count, held = false, onMove }: OrderControlsProps) {
  const up = useRef<HTMLButtonElement>(null)
  const down = useRef<HTMLButtonElement>(null)
  // A press waiting for its row to move: where the row was, and which way it goes.
  const pressed = useRef<{ from: number; delta: -1 | 1 } | null>(null)
  const [said, setSaid] = useState('')
  const name = label || `ردیف ${formatNumber(index + 1)}`

  useLayoutEffect(() => {
    const press = pressed.current
    if (press === null || index === press.from) return
    pressed.current = null
    // Moved some other way (another row's move, the list read again): the focus stays where it is.
    if (index !== press.from + press.delta) return
    const onward = press.delta === -1 ? index > 0 : index < count - 1
    const arrow = press.delta === -1 ? (onward ? up : down) : onward ? down : up
    arrow.current?.focus()
    setSaid(`${label || 'این ردیف'} به ردیف ${formatNumber(index + 1)} رفت`)
  }, [index, count, label])

  const move = (delta: -1 | 1) => {
    pressed.current = { from: index, delta }
    // The line empties at the press, so a row back at a place it was said to reach (another row moved it meanwhile) is
    // said again: the same words left in place would be no change to announce.
    setSaid('')
    onMove(delta)
  }

  return (
    <span className="flex items-center gap-0.5 max-md:gap-3">
      <IconButton ref={up} aria-label={`انتقال ${name} به بالا`} className={ARROW} aria-disabled={held} disabled={index === 0} onClick={() => move(-1)}>
        <ArrowUp className="size-3.5" aria-hidden />
      </IconButton>
      <IconButton ref={down} aria-label={`انتقال ${name} به پایین`} className={ARROW} aria-disabled={held} disabled={index === count - 1} onClick={() => move(1)}>
        <ArrowDown className="size-3.5" aria-hidden />
      </IconButton>
      {/* Always there, empty until a move: a region that appears with its words is not reliably read. */}
      <span aria-live="polite" className="sr-only">
        {said}
      </span>
    </span>
  )
}
