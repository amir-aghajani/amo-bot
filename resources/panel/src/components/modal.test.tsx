import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { FormActions } from '@/components/form-footer'
import { LeaveQuestion } from '@/components/leave-question'
import { Modal, useHoldOpen } from '@/components/modal'
import { Toaster } from '@/components/ui/sonner'
import { ThemeProvider } from '@/lib/theme'
import { useUnsavedGuard } from '@/lib/use-unsaved-guard'
import { until } from '@/test/render'

/*
 * The panels' dialog (components/modal, lib/use-native-dialog): it opens on its first field, keeps what it showed while it
 * fades out, waits while a request inside it runs, asks once per Escape before dropping a draft (and so does a form's
 * «انصراف»), gives the focus back to what opened it — a menu's trigger for the menu's item —, keeps the page behind from
 * moving sideways, and holds the toasts above its backdrop.
 */

function Draft() {
  useUnsavedGuard(true)
  return null
}

function Busy({ busy }: { busy: boolean }) {
  useHoldOpen(busy)
  return null
}

/** A row's ⋮ menu (its item, while the menu is open) and the dialog the item opens. */
function RowMenuPage({ open, menu }: { open: boolean; menu: boolean }) {
  return (
    <>
      <button type="button" id="row-menu">
        ⋮
      </button>
      {menu && (
        <div role="menu" aria-labelledby="row-menu">
          <button type="button" role="menuitem">
            ویرایش
          </button>
        </div>
      )}
      <Modal open={open} onClose={() => undefined} title="ویرایش">
        <p>فرم</p>
      </Modal>
    </>
  )
}

/** A page with the toasts, and a dialog open over it or not. */
function ToastPage({ open }: { open: boolean }) {
  return (
    <ThemeProvider>
      <Toaster />
      <Modal open={open} onClose={() => undefined} title="حذف پلن">
        <p>فرم</p>
      </Modal>
    </ThemeProvider>
  )
}

const dialog = () => document.querySelector('dialog') as HTMLDialogElement

/** The Escape a browser sends a modal dialog: refusable the first time, not on a second press in a row. */
const escape = (cancelable = true, to = dialog()) => fireEvent(to, new Event('cancel', { cancelable }))

/** The editing dialog, beside the unsaved-changes question's (components/leave-question). */
const editing = () => screen.getByRole('dialog', { name: 'ویرایش' }) as HTMLDialogElement

/** The unsaved-changes question, answered: stay, or drop the draft. */
async function answer(leave: boolean) {
  const question = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
  fireEvent.click(within(question).getByRole('button', { name: leave ? 'رها کردن تغییرات' : 'ماندن' }))
  await until(() => expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull())
}

describe('opening', () => {
  it('puts the focus on the dialog’s first field — one inside a hidden panel passed by', () => {
    render(
      <Modal open onClose={() => undefined} title="افزودن پلن">
        <div hidden>
          <input aria-label="پنهان" />
        </div>
        <input aria-label="نام پلن" />
        <textarea aria-label="توضیح" />
      </Modal>,
    )

    expect(document.activeElement).toBe(screen.getByLabelText('نام پلن'))
  })

  it('puts it on the control that asks for it, over the first field', () => {
    render(
      <Modal open onClose={() => undefined} title="لغو">
        <textarea aria-label="توضیح" />
        <button type="button" data-autofocus>
          انصراف
        </button>
      </Modal>,
    )

    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'انصراف' }))
  })
})

describe('closing', () => {
  it('keeps what it showed while it fades out, then lets it go', () => {
    const { rerender } = render(
      <Modal open onClose={() => undefined} title="سفارش #12">
        <p>جزئیات سفارش</p>
      </Modal>,
    )
    vi.useFakeTimers()

    rerender(
      <Modal open={false} onClose={() => undefined} title={null}>
        {null}
      </Modal>,
    )
    expect(dialog().open).toBe(false)
    expect(screen.getByText('سفارش #12')).toBeTruthy()
    expect(screen.getByText('جزئیات سفارش')).toBeTruthy()

    act(() => vi.advanceTimersByTime(200))
    expect(screen.queryByText('جزئیات سفارش')).toBeNull()
  })

  it('gives the focus back to the trigger of the menu whose item opened it — the item is gone by then', () => {
    const { rerender } = render(<RowMenuPage open={false} menu />)
    screen.getByRole('menuitem').focus()

    rerender(<RowMenuPage open menu />)
    rerender(<RowMenuPage open menu={false} />)
    rerender(<RowMenuPage open={false} menu={false} />)

    expect(document.activeElement).toBe(document.getElementById('row-menu'))
  })

  it('waits while a request inside it runs: its ✕ and Escape do nothing', () => {
    const onClose = vi.fn()
    const { rerender } = render(
      <Modal open onClose={onClose} title="تایید">
        <Busy busy />
      </Modal>,
    )

    expect((screen.getByRole('button', { name: 'بستن' }) as HTMLButtonElement).disabled).toBe(true)
    escape()
    expect(onClose).not.toHaveBeenCalled()

    rerender(
      <Modal open onClose={onClose} title="تایید">
        <Busy busy={false} />
      </Modal>,
    )
    escape()
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})

describe('a draft of its own', () => {
  it('is asked about — over the dialog — once per Escape, the second in a row, which the browser does not let refuse, too', async () => {
    const onClose = vi.fn()
    render(
      <>
        <LeaveQuestion />
        <Modal open onClose={onClose} title="ویرایش">
          <Draft />
        </Modal>
      </>,
    )

    escape(true, editing())
    await answer(false)

    escape(false, editing())
    editing().close()
    await answer(false)

    expect(editing().open).toBe(true)
    expect(onClose).not.toHaveBeenCalled()
  })

  it('is asked about before «انصراف» drops it as well', async () => {
    const onCancel = vi.fn()
    render(
      <>
        <LeaveQuestion />
        <Modal open onClose={onCancel} title="ویرایش">
          <form>
            <FormActions submitLabel="ذخیره" dirty onCancel={onCancel} />
          </form>
        </Modal>
      </>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    await answer(false)
    expect(onCancel).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    await answer(true)
    expect(onCancel).toHaveBeenCalledTimes(1)
  })
})

describe('the page behind', () => {
  it('stops scrolling meanwhile, keeping its scrollbar’s room so nothing moves sideways', () => {
    vi.spyOn(document.documentElement, 'clientWidth', 'get').mockReturnValue(window.innerWidth - 15)
    const { rerender } = render(
      <Modal open onClose={() => undefined} title="ویرایش">
        <p>فرم</p>
      </Modal>,
    )

    expect(document.documentElement.style.overflow).toBe('hidden')
    expect(document.documentElement.style.scrollbarGutter).toBe('stable')

    rerender(
      <Modal open={false} onClose={() => undefined} title="ویرایش">
        <p>فرم</p>
      </Modal>,
    )
    expect(document.documentElement.style.overflow).toBe('')
    expect(document.documentElement.style.scrollbarGutter).toBe('')
  })
})

describe('a toast', () => {
  it('is drawn in the topmost dialog while one is open — above its backdrop — and on the page again once it closes', async () => {
    const { rerender } = render(<ToastPage open />)

    act(() => void toast.error('این پلن فروش دارد.'))

    await until(() => expect(dialog().contains(screen.getByText('این پلن فروش دارد.'))).toBe(true))

    rerender(<ToastPage open={false} />)
    await until(() => expect(dialog().contains(screen.getByText('این پلن فروش دارد.'))).toBe(false))
  })
})
