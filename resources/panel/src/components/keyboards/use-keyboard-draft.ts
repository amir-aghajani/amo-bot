import { toast } from 'sonner'
import { api } from '@/lib/api'
import type { KeyboardButtonSpec, KeyboardLayoutData, KeyboardsResponse, KeyboardType } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { trimmed, useForm } from '@/lib/use-form'

/** A button's place: its row, and its index in the row's reading order. */
export interface Position {
  row: number
  index: number
}

/**
 * A row of the draft: its buttons, and who it is — a row moved is the same row in its new place (its element, its
 * focus), whatever it holds. Ids are the draft's own: they never reach the server.
 */
export interface DraftRow {
  id: number
  buttons: KeyboardButtonSpec[]
}

interface Values {
  type: KeyboardType
  rows: DraftRow[]
}

/** The server's copy as a draft: its rows numbered in order. */
const draftOf = (keyboard: KeyboardLayoutData): Values => ({ type: keyboard.type, rows: keyboard.rows.map((buttons, id) => ({ id, buttons })) })

/** The draft as its save sends it: the type and the rows' buttons — a row's id is the draft's own, so a row put back is no change. */
const layoutOf = ({ type, rows }: Values) => ({ type, rows: rows.map((row) => row.buttons) })

/**
 * One keyboard's draft: its type and rows of buttons, the edits a row and a button take — each within the API's limits
 * (`KeyboardsResponse.limits`) —, and its save. The draft follows the server's copy (a save, a reset, another admin's
 * save). The server checks the rest (an action twice, a label too long or with a premium emoji's tag in it) and its
 * refusal lands under the row it is about (`rows.2.1`). A button moved one row past the last (`to.row` the number of
 * rows) makes a new row for it.
 */
export function useKeyboardDraft(keyboard: KeyboardLayoutData, limits: KeyboardsResponse['limits'], onSaved: (keyboard: KeyboardLayoutData) => void) {
  const form = useForm<Values>(draftOf(keyboard), { follow: true, reads: (values) => trimmed(layoutOf(values)) })
  const { rows } = form.values

  // Row errors are keyed by position, so any change of the rows makes all of them stale.
  const change = (next: DraftRow[]) => {
    form.set('rows', next)
    form.setErrors({})
  }

  /** A row the draft makes: an id no row of it has. */
  const newRow = (buttons: KeyboardButtonSpec[] = []): DraftRow => ({ id: Math.max(-1, ...rows.map((row) => row.id)) + 1, buttons })

  /** The row `r` with its buttons changed. */
  const editRow = (r: number, edit: (buttons: KeyboardButtonSpec[]) => KeyboardButtonSpec[]) => rows.map((row, i) => (i === r ? { ...row, buttons: edit(row.buttons) } : row))

  const tooMany = (row: number) => {
    if ((rows[row]?.buttons.length ?? 0) < limits.per_row) return false
    toast.error(`هر ردیف حداکثر ${formatNumber(limits.per_row)} دکمه می‌گیرد.`)
    return true
  }

  const noRoom = () => {
    if (rows.length < limits.rows) return false
    toast.error(`کیبورد حداکثر ${formatNumber(limits.rows)} ردیف می‌گیرد.`)
    return true
  }

  return {
    form,
    /** The rows as the keyboard has them: buttons alone, row by row. */
    buttons: rows.map((row) => row.buttons),
    addRow: () => {
      if (!noRoom()) change([...rows, newRow()])
    },
    removeRow: (r: number) => change(rows.filter((_, i) => i !== r)),
    moveRow: (r: number, delta: -1 | 1) => {
      const target = r + delta
      const [moving, other] = [rows[r], rows[target]]
      if (moving === undefined || other === undefined) return
      change(rows.map((row, i) => (i === r ? other : i === target ? moving : row)))
    },
    setButton: (at: Position, button: KeyboardButtonSpec) => change(editRow(at.row, (buttons) => buttons.map((item, j) => (j === at.index ? button : item)))),
    addButton: (r: number, button: KeyboardButtonSpec) => {
      if (!tooMany(r)) change(editRow(r, (buttons) => [...buttons, button]))
    },
    removeButton: (at: Position) => change(editRow(at.row, (buttons) => buttons.filter((_, j) => j !== at.index))),
    /**
     * Take the button at `from` and put it before the button at `to` (`to.index` the row's length: at its end; `to.row`
     * the number of rows: on a row of its own after the last); the source row may be the target row. Answers where it
     * landed, or null when it did not move.
     */
    moveButton: (from: Position, to: Position): Position | null => {
      if (from.row === to.row && (from.index === to.index || from.index + 1 === to.index)) return null
      const adding = to.row === rows.length
      if (adding ? noRoom() : from.row !== to.row && tooMany(to.row)) return null
      const next = [...rows.map((row) => ({ ...row, buttons: [...row.buttons] })), ...(adding ? [newRow()] : [])]
      const [button] = next[from.row]?.buttons.splice(from.index, 1) ?? []
      const target = next[to.row]
      if (button === undefined || target === undefined) return null
      const index = from.row === to.row && from.index < to.index ? to.index - 1 : to.index
      target.buttons.splice(index, 0, button)
      change(next)
      return { row: to.row, index }
    },
    // Not a <form>'s submit: the button dialog's own form would sit inside it, and its submit would bubble to this one.
    save: async () => {
      const data = await form.submit(() => api.put(`/keyboards/${keyboard.name}`, layoutOf(form.values)), {
        onInvalid: () => toast.error('چیدمان ذخیره نشد؛ خطاها را زیر ردیف‌ها ببینید.'),
      })
      if (data) {
        toast.success('کیبورد ذخیره شد؛ از این به بعد ربات همین را نشان می‌دهد')
        onSaved(data.keyboard)
      }
    },
  }
}
