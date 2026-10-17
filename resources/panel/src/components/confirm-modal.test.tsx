import { useMutation } from '@tanstack/react-query'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ConfirmModal } from '@/components/confirm-modal'
import { ApiError } from '@/lib/api'
import { providers, until } from '@/test/render'

/*
 * Every "are you sure" (components/confirm-modal): a confirm whose press starts a write is held from that press until the
 * write settles — react-query tells the screen it runs only on a later tick, so a double click would otherwise send it
 * twice —, and pressed again once it failed; a confirm that writes nothing is held by nothing.
 */

/** A deletion's question whose confirm runs `send` as a mutation, the dialog showing it running as its users do. */
function Deleting({ send }: { send: () => Promise<unknown> }) {
  const remove = useMutation({ mutationFn: send, meta: { quiet: true } })
  return <ConfirmModal open onClose={vi.fn()} title="حذف پلن" confirmLabel="حذف" destructive pending={remove.isPending} onConfirm={() => remove.mutate()} />
}

/** A request held until the test answers it. */
function held() {
  const answers: { resolve: () => void; reject: (error: Error) => void }[] = []
  const send = vi.fn(() => new Promise<void>((resolve, reject) => answers.push({ resolve, reject })))
  return { send, answers }
}

const confirmButton = () => screen.getByRole('button', { name: 'حذف' })

describe('a confirm', () => {
  it('pressed twice before the screen shows it running — a double click —, sends it once, spinning from the first press', async () => {
    const { send, answers } = held()
    render(<Deleting send={send} />, { wrapper: providers().wrapper })

    fireEvent.click(confirmButton())
    fireEvent.click(confirmButton())

    expect(confirmButton().getAttribute('aria-busy')).toBe('true')
    await until(() => expect(answers).toHaveLength(1))
    expect(send).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('button', { name: 'انصراف' }).hasAttribute('disabled')).toBe(true)
    await act(async () => answers[0]?.resolve())
  })

  it('whose write failed is pressed again, and sends again', async () => {
    const { send, answers } = held()
    render(<Deleting send={send} />, { wrapper: providers().wrapper })

    fireEvent.click(confirmButton())
    await until(() => expect(answers).toHaveLength(1))
    await act(async () => answers[0]?.reject(new ApiError(409, 'این پلن فروش دارد.')))
    await until(() => expect(confirmButton().getAttribute('aria-busy')).toBeNull())

    fireEvent.click(confirmButton())
    await until(() => expect(answers).toHaveLength(2))
    await act(async () => answers[1]?.resolve())
  })

  it('gone mid-write — its dialog closed — lets go of the write: nothing left listening to the cache', async () => {
    const { send, answers } = held()
    const { client, wrapper } = providers()
    const { unmount } = render(<Deleting send={send} />, { wrapper })

    fireEvent.click(confirmButton())
    await until(() => expect(answers).toHaveLength(1))
    expect(client.getMutationCache().hasListeners()).toBe(true)
    unmount()

    expect(client.getMutationCache().hasListeners()).toBe(false)
    await act(async () => answers[0]?.resolve())
  })

  it('that writes nothing is held by nothing: each press is its own', () => {
    const onConfirm = vi.fn()
    render(<ConfirmModal open onClose={vi.fn()} title="تغییرات ذخیره نشده‌اند" confirmLabel="رها کردن تغییرات" onConfirm={onConfirm} />, { wrapper: providers().wrapper })

    fireEvent.click(screen.getByRole('button', { name: 'رها کردن تغییرات' }))
    fireEvent.click(screen.getByRole('button', { name: 'رها کردن تغییرات' }))

    expect(onConfirm).toHaveBeenCalledTimes(2)
    expect(screen.getByRole('button', { name: 'رها کردن تغییرات' }).getAttribute('aria-busy')).toBeNull()
  })
})
