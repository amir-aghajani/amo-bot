import { useState, type ReactNode } from 'react'
import { Creating } from '@/components/form-footer'
import { Modal } from '@/components/modal'

/** Which row a list page's form edits: none (closed), a new one, or an existing one. */
export function useEditor<Row>() {
  const [state, setState] = useState<{ row: Row | null } | null>(null)

  return {
    open: state !== null,
    /** The row being edited; null for a new one (or while closed). */
    row: state?.row ?? null,
    create: () => setState({ row: null }),
    edit: (row: Row) => setState({ row }),
    close: () => setState(null),
  }
}

type Editor<Row> = ReturnType<typeof useEditor<Row>>

interface EditorModalProps<Row extends { id: number }> {
  editor: Editor<Row>
  /** «افزودن پلن» for a new row, «ویرایش «X»» for one that exists. */
  title: (row: Row | null) => string
  description?: (row: Row | null) => ReactNode
  size?: 'sm' | 'md' | 'lg'
  /** The row's form — mounted afresh for each row it opens on. */
  children: (row: Row | null) => ReactNode
}

/** A list page's create/edit form in its dialog — a new row's with nothing saved to go back to (`Creating`): no revert. */
export function EditorModal<Row extends { id: number }>({ editor, title, description, size = 'sm', children }: EditorModalProps<Row>) {
  return (
    <Modal open={editor.open} onClose={editor.close} size={size} title={title(editor.row)} description={description?.(editor.row)}>
      {editor.open && (
        <Creating key={editor.row?.id ?? 'new'} value={editor.row === null}>
          {children(editor.row)}
        </Creating>
      )}
    </Modal>
  )
}
