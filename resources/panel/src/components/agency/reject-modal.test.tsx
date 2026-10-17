import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { RejectModal } from '@/components/agency/reject-modal'
import type { AgencyRequestResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { agencyRequestRow } from '@/test/rows'
import { json, server } from '@/test/server'

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
})
