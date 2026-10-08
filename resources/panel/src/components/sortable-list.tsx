import type { ReactNode } from 'react'
import type { UseMutationResult } from '@tanstack/react-query'
import { Pencil, Trash2, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { RowMenu } from '@/components/list-view'
import { Modal } from '@/components/modal'
import { OrderControls } from '@/components/order-controls'
import { Button } from '@/components/ui/button'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { TableCell, TableHead } from '@/components/ui/table'
import { messageOf } from '@/lib/failure'

/*
 * The pieces every admin-ordered list page shares (plans, categories, payment methods, customer groups, agency levels,
 * channels — on useRows): the order column, the ⋮ menu with edit and delete, and the delete's second look.
 */

/** What the order column needs of the list. */
interface Ordered {
  rows: readonly unknown[]
  reorder: { isPending: boolean }
  move: (index: number, delta: -1 | 1) => void
}

/** The order column's head: no word on screen, its name for assistive tech. */
export function OrderHead() {
  return (
    <TableHead className="w-16">
      <span className="sr-only">ترتیب</span>
    </TableHead>
  )
}

/**
 * A row's ▲▼: one step in the list's order, a request each — while one runs, every arrow holds (keeping the focus it
 * has), and the arrow pressed keeps the focus as its row moves.
 */
export function OrderCell({ list, index, label }: { list: Ordered; index: number; label: string }) {
  return (
    <TableCell>
      <OrderControls label={label} index={index} count={list.rows.length} held={list.reorder.isPending} onMove={(delta) => list.move(index, delta)} />
    </TableCell>
  )
}

/** The actions column's head, as the order column's. */
export function ActionsHead() {
  return (
    <TableHead className="w-10">
      <span className="sr-only">عملیات</span>
    </TableHead>
  )
}

interface EditMenuProps {
  /** The row's name, for the menu's button («عملیات طلایی»). */
  label: string
  /** Absent for a row that cannot be edited here (a payment method whose driver has no form): the menu only deletes. */
  onEdit?: () => void
  editLabel?: string
  onRemove: () => void
  /** What else the row offers, between the edit and the delete. */
  children?: ReactNode
}

/** A row's ⋮ menu: edit, what else the row offers, then delete in red. */
export function EditMenu({ label, onEdit, editLabel = 'ویرایش', onRemove, children }: EditMenuProps) {
  return (
    <RowMenu label={label}>
      {onEdit && (
        <DropdownMenuItem onSelect={onEdit}>
          <Pencil aria-hidden />
          {editLabel}
        </DropdownMenuItem>
      )}
      {children}
      {(onEdit || children) && <DropdownMenuSeparator />}
      <DropdownMenuItem variant="destructive" onSelect={onRemove}>
        <Trash2 aria-hidden />
        حذف
      </DropdownMenuItem>
    </RowMenu>
  )
}

interface RemoveConfirmProps<Row> {
  /** The row about to go; null = closed. */
  row: Row | null
  /** The list's delete (useRows().remove, quiet): the row leaves the list once it is gone, and what else showed it is read again. */
  remove: UseMutationResult<void, Error, Row>
  title: string
  /** What deleting it means: «"طلایی" حذف می‌شود.» */
  description: (row: Row) => string
  /** What else to know — what stays, what goes with it. */
  details: (row: Row) => ReactNode
  /**
   * Why it cannot go, when the row already says so (agents on a level, payments made with a method) — the server would
   * refuse it: the dialog says why and offers nothing to delete. Null for a row that may go.
   */
  kept?: (row: Row) => string | null
  /** The toast once it is gone. */
  removed: string
  onClose: () => void
}

/**
 * A row's delete, asked first — cancel has the focus. A row that cannot go (`kept`) is not offered a delete: the dialog
 * says why. Refused anyway (in use since the list was read, or no answer), the dialog says why and stays, the row too.
 */
export function RemoveConfirm<Row>({ row, remove, title, description, details, kept, removed, onClose }: RemoveConfirmProps<Row>) {
  const reason = row === null ? null : (kept?.(row) ?? null)
  // What the last press was refused with belongs to that opening alone.
  const close = () => {
    remove.reset()
    onClose()
  }

  return (
    <>
      <ConfirmModal
        open={row !== null && reason === null}
        onClose={close}
        title={title}
        description={row ? description(row) : undefined}
        confirmLabel="حذف"
        destructive
        pending={remove.isPending}
        error={remove.error && messageOf(remove.error)}
        onConfirm={() =>
          row &&
          remove.mutate(row, {
            onSuccess: () => {
              toast.success(removed)
              close()
            },
          })
        }
      >
        {row && details(row)}
      </ConfirmModal>

      <Modal open={reason !== null} onClose={close} size="sm" title={title}>
        <div className="grid gap-5">
          <Callout tone="warning" icon={TriangleAlert}>
            {reason}
          </Callout>
          <div className="flex justify-end">
            <Button variant="secondary" onClick={close} data-autofocus>
              بستن
            </Button>
          </div>
        </div>
      </Modal>
    </>
  )
}
