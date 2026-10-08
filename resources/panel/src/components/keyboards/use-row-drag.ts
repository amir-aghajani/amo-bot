import { useState, type DragEvent } from 'react'
import type { Position } from '@/components/keyboards/use-keyboard-draft'
import { isRtl } from '@/lib/direction'

/**
 * Moving a keyboard's buttons by dragging (native drag and drop): where the dragged one came from, and where it would
 * land — the row under the pointer, before the first chip past it in reading order; the drop zone under the last row is
 * the row after it, a new one. The keyboard way is the button dialog's moves.
 */
export function useRowDrag(move: (from: Position, to: Position) => unknown) {
  const [dragging, setDragging] = useState<Position | null>(null)
  const [over, setOver] = useState<Position | null>(null)

  const end = () => {
    setDragging(null)
    setOver(null)
  }

  return {
    dragging,
    /** Where the dragged button would land in row `r`; null when not over it. */
    insertAt: (r: number) => (dragging && over?.row === r ? over.index : null),
    chip: (position: Position) => ({
      draggable: true,
      onDragStart: (event: DragEvent) => {
        event.dataTransfer.effectAllowed = 'move'
        event.dataTransfer.setData('text/plain', `${position.row}:${position.index}`)
        setDragging(position)
      },
      onDragEnd: end,
    }),
    row: (r: number) => ({
      onDragOver: (event: DragEvent<HTMLElement>) => {
        if (!dragging) return
        event.preventDefault()
        event.dataTransfer.dropEffect = 'move'
        // The index: how many chips sit before the pointer in reading order (to its right, on an RTL screen).
        const rtl = isRtl()
        const index = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('[data-chip]')).filter((chip) => {
          const rect = chip.getBoundingClientRect()
          const centre = rect.left + rect.width / 2
          return rtl ? centre > event.clientX : centre < event.clientX
        }).length
        if (over?.row !== r || over.index !== index) setOver({ row: r, index })
      },
      onDragLeave: () => {
        if (over?.row === r) setOver(null)
      },
      onDrop: (event: DragEvent<HTMLElement>) => {
        event.preventDefault()
        if (dragging && over?.row === r) move(dragging, over)
        end()
      },
    }),
  }
}
