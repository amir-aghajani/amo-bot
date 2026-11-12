import type { ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import { setBearer } from '@/apps/app/lib/store'
import { useOutbox } from '@/apps/app/screens/support/parts/outbox'
import { message, ticketDetail } from '@/apps/app/screens/support/test-rows'
import { API } from '@/apps/app/test/rows'
import type { StoreTicketResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The messages being sent in a ticket (screens/support/parts/outbox) go on after the conversation is closed; the address
 * of a message's picture on the device is let go all the same — also when the shop takes it after the screen closed.
 */

/** The app's query client around the hook. */
function WithClient({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}

describe('the outbox', () => {
  it("lets go of a picture's address when its message is taken after the screen closed", async () => {
    let made = 0
    vi.spyOn(URL, 'createObjectURL').mockImplementation(() => `blob:picture-${++made}`)
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const write = server().hold('POST', `${API}/tickets/12/messages`)
    queryClient.clear()
    setBearer('a'.repeat(64))
    const { result, unmount } = renderHook(() => useOutbox(12), { wrapper: WithClient })

    act(() => result.current.add('رسید پرداخت', new File(['x'], 'receipt.png', { type: 'image/png' })))
    await until(() => expect(write.waiting).toBe(1))
    unmount()
    const sent = message({ id: 40, body: 'رسید پرداخت', created_at: new Date().toISOString(), attachment: { name: 'receipt.png', kept: true } })
    write.answer(json({ ticket: ticketDetail({ messages: [...ticketDetail().messages, sent] }) } satisfies StoreTicketResponse))

    await until(() => expect(queryClient.getQueryData<StoreTicketResponse>(keys.ticket(12))?.ticket.messages.at(-1)?.id).toBe(40))
    expect(made).toBe(1)
    expect(revoke).toHaveBeenCalledWith('blob:picture-1')
  })
})
