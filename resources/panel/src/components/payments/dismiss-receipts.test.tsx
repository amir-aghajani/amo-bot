import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { DismissReceiptsModal } from '@/components/payments/dismiss-receipts'
import type { DismissReceiptsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * Rejecting the receipts ticked (components/payments/dismiss-receipts): ticks kept across a list's pages may be more than
 * a request names, so they go a hundred a request, said as one; a double click rejects them once; a refusal on the way
 * is said, and what went through before it is read again.
 */

const DISMISS = '/api/admin/payments/dismiss'

/** Receipts 1 to 130 ticked. */
const IDS = Array.from({ length: 130 }, (_, i) => i + 1)

/** The dialog over the 130 ticked, confirmed; its cache, watched for what it reads again. */
async function confirm(onDone: () => void) {
  const { client, wrapper } = providers()
  const invalidated = vi.spyOn(client, 'invalidateQueries')
  render(<DismissReceiptsModal scope={{ ids: IDS }} onClose={vi.fn()} onDone={onDone} />, { wrapper })
  fireEvent.click(within(await screen.findByRole('dialog', { name: 'رد ۱۳۰ رسید' })).getByRole('button', { name: 'رد رسیدها' }))
  return invalidated
}

describe('rejecting the receipts ticked', () => {
  it('sends more than a hundred a hundred a request, and says them all', async () => {
    vi.spyOn(toast, 'success')
    server().on('POST', DISMISS, (request) => json({ dismissed: (request.body as { ids: number[] }).ids.length - 1, skipped: 1, left: 0, until: null } satisfies DismissReceiptsResponse))
    const onDone = vi.fn()
    await confirm(onDone)

    await until(() => expect(toast.success).toHaveBeenCalledWith('۱۲۸ رسید رد شد', { description: '۲ رسید پیش‌تر تعیین تکلیف شده بود.' }))
    expect(
      server()
        .sent('POST', DISMISS)
        .map((request) => request.body),
    ).toEqual([
      { ids: IDS.slice(0, 100), note: '' },
      { ids: IDS.slice(100), note: '' },
    ])
    expect(onDone).toHaveBeenCalledOnce()
  })

  it('pressed twice before the dialog shows it running — a double click —, rejects them once', async () => {
    const answer = server().hold('POST', DISMISS)
    const onDone = vi.fn()
    render(<DismissReceiptsModal scope={{ ids: [1, 2] }} onClose={vi.fn()} onDone={onDone} />, { wrapper: providers().wrapper })
    const reject = within(await screen.findByRole('dialog', { name: 'رد ۲ رسید' })).getByRole('button', { name: 'رد رسیدها' })

    fireEvent.click(reject)
    fireEvent.click(reject)

    await until(() => expect(answer.waiting).toBe(1))
    await act(async () => answer.answer(json({ dismissed: 2, skipped: 0, left: 0, until: null } satisfies DismissReceiptsResponse)))
    await until(() => expect(onDone).toHaveBeenCalledOnce())
    expect(server().sent('POST', DISMISS)).toHaveLength(1)
  })

  it('says a refusal on the way, and reads again what went through before it', async () => {
    const refused = 'یادداشت بیش از حد بلند است.'
    server().on('POST', DISMISS, (request) =>
      (request.body as { ids: number[] }).ids[0] === 1 ? json({ dismissed: 100, skipped: 0, left: 0, until: null } satisfies DismissReceiptsResponse) : refusal(422, refused, { note: [refused] }),
    )
    const onDone = vi.fn()
    const invalidated = await confirm(onDone)

    expect(await screen.findByText(refused)).toBeTruthy()
    expect(server().sent('POST', DISMISS)).toHaveLength(2)
    expect(onDone).not.toHaveBeenCalled()
    expect(invalidated).toHaveBeenCalledWith({ queryKey: queryKeys.payments })
  })
})
