import { QueryClient } from '@tanstack/react-query'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { RejectModal } from '@/components/agency/reject-modal'
import type { AgencyRequestResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { agencyRequestRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * Rejecting an agency request (components/agency/reject-modal): pressed twice before the dialog shows it running — a
 * double click —, it is sent once, so no «decided already» of a second press waits in the dialog for the next request.
 */

const REQUEST = agencyRequestRow()

describe('rejecting a request', () => {
  it('pressed twice before the dialog shows it running, sends it once', async () => {
    const answer = server().hold('POST', '/api/admin/agency/requests/4/reject')
    const onDone = vi.fn()
    render(<RejectModal request={REQUEST} onClose={vi.fn()} onDone={onDone} />, { wrapper: providers().wrapper })
    const reject = within(await screen.findByRole('dialog', { name: 'رد درخواست نمایندگی' })).getByRole('button', { name: 'رد درخواست' })

    fireEvent.click(reject)
    fireEvent.click(reject)

    await until(() => expect(answer.waiting).toBe(1))
    await act(async () => answer.answer(json({ request: agencyRequestRow({ status: 'rejected', actions: { approve: false, reject: false } }) } satisfies AgencyRequestResponse)))
    await until(() => expect(onDone).toHaveBeenCalledOnce())
    expect(server().sent('POST', '/api/admin/agency/requests/4/reject')).toHaveLength(1)
  })

  it('decided meanwhile, says so and reads the list and the count of those waiting again', async () => {
    const invalidated = vi.spyOn(QueryClient.prototype, 'invalidateQueries')
    const message = 'این درخواست تایید شده است.'
    server().on('POST', '/api/admin/agency/requests/4/reject', refusal(422, message, { status: [message] }))
    render(<RejectModal request={REQUEST} onClose={vi.fn()} onDone={vi.fn()} />, { wrapper: providers().wrapper })
    const dialog = within(await screen.findByRole('dialog', { name: 'رد درخواست نمایندگی' }))

    fireEvent.click(dialog.getByRole('button', { name: 'رد درخواست' }))

    expect(await dialog.findByText(message)).toBeTruthy()
    expect(invalidated).toHaveBeenCalledWith({ queryKey: queryKeys.agencyRequests })
    expect(invalidated).toHaveBeenCalledWith({ queryKey: queryKeys.agency })
  })
})

describe('rejecting a renewal', () => {
  it('says the agent keeps their end, and that the note is for the agent', async () => {
    render(<RejectModal request={agencyRequestRow({ kind: 'renewal', agent: true, expires_at: '2026-09-23T10:00:00Z' })} onClose={vi.fn()} onDone={vi.fn()} />, { wrapper: providers().wrapper })
    const dialog = within(await screen.findByRole('dialog', { name: 'رد درخواست تمدید نمایندگی' }))

    expect(dialog.getByText(/پایان نمایندگی‌اش تغییری نمی‌کند/)).toBeTruthy()
    expect(dialog.getByLabelText('توضیح برای نماینده (اختیاری)')).toBeTruthy()
  })
})
