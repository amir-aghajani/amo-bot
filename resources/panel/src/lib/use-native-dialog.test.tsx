import { useRef } from 'react'
import { cleanup, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useNativeDialog } from '@/lib/use-native-dialog'
import { resizeWindow } from '@/test/browser'

/*
 * A native <dialog> driven from React state (lib/use-native-dialog), where the focus goes as it opens — its first field;
 * on a phone the dialog itself rather than a field typed into, whose keyboard would cover half the sheet, unless typing
 * is the dialog's point — and once it closes: back to what opened it — and to the page's main column when nothing did,
 * never to the document.
 */

/** The shell's main column and a dialog over it, open or shut. */
function Page({ open }: { open: boolean }) {
  const ref = useRef<HTMLDialogElement>(null)
  const handlers = useNativeDialog(ref, open, () => undefined)
  return (
    <>
      <main id="main" tabIndex={-1}>
        <button type="button">ویرایش</button>
      </main>
      <dialog ref={ref} aria-label="ویرایش" {...handlers}>
        <input aria-label="نام" />
      </dialog>
    </>
  )
}

describe('a dialog that closes', () => {
  it('gives the focus back to what opened it', () => {
    const { getByRole, rerender } = render(<Page open={false} />)
    const opener = getByRole('button', { name: 'ویرایش' })
    opener.focus()

    rerender(<Page open />)
    rerender(<Page open={false} />)

    expect(document.activeElement).toBe(opener)
  })

  it('whose opener can no longer take the focus — disabled meanwhile — gives it to the main column rather than the document', () => {
    const { getByRole, rerender } = render(<Page open={false} />)
    const opener = getByRole('button', { name: 'ویرایش' })
    opener.focus()

    rerender(<Page open />)
    opener.setAttribute('disabled', '')
    rerender(<Page open={false} />)

    expect(document.activeElement).toBe(document.getElementById('main'))
  })

  it('opened by the page itself — nothing had the focus —, gives it to the main column rather than the document', () => {
    const { rerender } = render(<Page open={false} />)
    expect(document.activeElement).toBe(document.body)

    rerender(<Page open />)
    rerender(<Page open={false} />)

    expect(document.activeElement).toBe(document.getElementById('main'))
  })
})

/** A dialog that opens on its first field — a text, or a choice's button —, typing its whole point or not. */
function Form({ open, typing = false, field = 'text' }: { open: boolean; typing?: boolean; field?: 'text' | 'choice' }) {
  const ref = useRef<HTMLDialogElement>(null)
  const handlers = useNativeDialog(ref, open, () => undefined, { typing })
  return (
    <dialog ref={ref} tabIndex={-1} aria-label="ویرایش پلن" {...handlers}>
      <button type="button">بستن</button>
      {field === 'text' ? <input aria-label="نام" /> : <button type="button" role="combobox" aria-controls="none" aria-expanded={false} aria-label="سرور" />}
    </dialog>
  )
}

/** The dialog opened at the window's width. */
function opened(width: number, props: Omit<Parameters<typeof Form>[0], 'open'> = {}) {
  resizeWindow(width)
  const { rerender } = render(<Form open={false} {...props} />)
  rerender(<Form open {...props} />)
}

describe('a dialog that opens', () => {
  it('gives its first field the focus', () => {
    opened(1280)

    expect(document.activeElement).toBe(screen.getByLabelText('نام'))
  })

  it('on a phone takes the focus itself, the keyboard down over the sheet — unless typing is its point', () => {
    opened(375)
    expect(document.activeElement).toBe(screen.getByRole('dialog', { name: 'ویرایش پلن' }))
    cleanup()

    opened(375, { typing: true })
    expect(document.activeElement).toBe(screen.getByLabelText('نام'))
  })

  it('on a phone gives a choice’s button the focus all the same: it brings up no keyboard', () => {
    opened(375, { field: 'choice' })

    expect(document.activeElement).toBe(screen.getByRole('combobox', { name: 'سرور' }))
  })
})
