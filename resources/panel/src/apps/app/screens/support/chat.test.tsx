import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import ChatScreen from '@/apps/app/screens/support/chat'
import { message, ticketDetail } from '@/apps/app/screens/support/test-rows'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreTicketDetail } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, offline, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * A ticket's conversation (screens/support/chat): marked read as it opens, support read again when a later read finds
 * an answer unread; the customer's messages with one tick, two once support has read them; a message sent shows at once
 * with its clock, then its tick — failed, it can go again (looked for first after no answer, never sent twice) —; closing
 * it (asked first), then its stars and a note, once, shown after. A panel's preview only reads it.
 */

const TICKET = `${API}/tickets/12`

function open(ticket: StoreTicketDetail = ticketDetail()) {
  server().on('POST', `${TICKET}/read`, json({ ticket }))
  render(app.screen(<ChatScreen />, { at: '/support/12', route: '/support/:id' }))
}

/** The customer types `text` and sends it. */
function type(text: string) {
  fireEvent.change(screen.getByLabelText('متن پیام'), { target: { value: text } })
  fireEvent.click(screen.getByRole('button', { name: 'ارسال' }))
}

describe('a ticket’s conversation', () => {
  it('marks it read as it opens, and shows one tick for a message support has not read, two for one it has', async () => {
    open()

    await screen.findByText('لطفا یک بار اتصال را قطع و دوباره وصل کنید.')
    expect(server().sent('POST', `${TICKET}/read`)).toHaveLength(1)
    expect(server().sent('GET', TICKET)).toHaveLength(0)
    expect(screen.getAllByLabelText('دیده شد')).toHaveLength(1)
    expect(screen.getAllByLabelText('ارسال شد')).toHaveLength(1)
    expect(screen.getByRole('heading', { name: 'قطعی اتصال' })).toBeTruthy()
  })

  it('marks it read again when a later read finds support’s answer unread', async () => {
    open()
    await screen.findByText('درست نشد.')
    const answered = ticketDetail({
      unread: true,
      messages: [...ticketDetail().messages, message({ id: 38, author: 'support', body: 'سرور عوض شد.', created_at: '2026-10-08T11:00:00+00:00', seen: null })],
    })
    server().on('GET', TICKET, json({ ticket: answered }))
    server().on('POST', `${TICKET}/read`, json({ ticket: { ...answered, unread: false } }))

    await queryClient.refetchQueries({ queryKey: keys.ticket(12) })
    await screen.findByText('سرور عوض شد.')
    await until(() => expect(server().sent('POST', `${TICKET}/read`)).toHaveLength(2))
  })

  it('in a panel’s preview, only reads it: support’s answer stays unread for the customer', async () => {
    app.preview()
    server().on('GET', TICKET, json({ ticket: ticketDetail({ unread: true }) }))
    render(app.screen(<ChatScreen />, { at: '/support/12', route: '/support/:id' }))

    await screen.findByText('درست نشد.')
    await queryClient.refetchQueries({ queryKey: keys.ticket(12) })
    await until(() => expect(server().sent('GET', TICKET)).toHaveLength(2))
    expect(server().sent('POST', `${TICKET}/read`)).toHaveLength(0)
  })

  it('shows a message at once with its clock, sends it, and gives it its tick once the shop has it', async () => {
    open()
    await screen.findByText('درست نشد.')
    const write = server().hold('POST', `${TICKET}/messages`)

    type('  هنوز وصل نمی‌شود. ')
    await screen.findByText('هنوز وصل نمی‌شود.')
    expect(screen.getByLabelText('در حال ارسال')).toBeTruthy()
    expect((screen.getByLabelText('متن پیام') as HTMLTextAreaElement).value).toBe('')
    await until(() => expect(write.waiting).toBe(1))
    expect(server().sent('POST', `${TICKET}/messages`)[0]?.body).toEqual({ body: 'هنوز وصل نمی‌شود.' })

    write.answer(
      json({ ticket: ticketDetail({ status: 'open', messages: [...ticketDetail().messages, message({ id: 37, body: 'هنوز وصل نمی‌شود.', created_at: new Date().toISOString(), seen: false })] }) }),
    )
    await until(() => expect(screen.queryByLabelText('در حال ارسال')).toBeNull())
    expect(screen.getAllByLabelText('ارسال شد')).toHaveLength(2)
    expect(screen.getAllByText('هنوز وصل نمی‌شود.')).toHaveLength(1)
  })

  it('keeps a refused message with the shop’s words, and sends it again', async () => {
    open()
    await screen.findByText('درست نشد.')
    let asked = 0
    server().on('POST', `${TICKET}/messages`, () =>
      ++asked === 1
        ? refusal(429, 'پیام زیادی فرستاده‌اید؛ کمی بعد دوباره امتحان کنید.')
        : json({ ticket: ticketDetail({ messages: [...ticketDetail().messages, message({ id: 37, body: 'سلام', created_at: new Date().toISOString(), seen: false })] }) }),
    )

    type('سلام')
    await screen.findByText('پیام زیادی فرستاده‌اید؛ کمی بعد دوباره امتحان کنید.')
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    await until(() => expect(screen.queryByText('پیام زیادی فرستاده‌اید؛ کمی بعد دوباره امتحان کنید.')).toBeNull())
    await until(() => expect(screen.getAllByLabelText('ارسال شد')).toHaveLength(2))
    expect(server().sent('POST', `${TICKET}/messages`)).toHaveLength(2)
  })

  it('after no answer, looks for the message in the ticket before sending it again — found, it is not sent twice', async () => {
    open()
    await screen.findByText('درست نشد.')
    server().on('POST', `${TICKET}/messages`, offline)

    type('سلام')
    fireEvent.click(await screen.findByRole('button', { name: 'تلاش دوباره' }))
    server().on('GET', TICKET, json({ ticket: ticketDetail({ messages: [...ticketDetail().messages, message({ id: 37, body: 'سلام', created_at: new Date().toISOString(), seen: false })] }) }))

    await until(() => expect(screen.getAllByLabelText('ارسال شد')).toHaveLength(2))
    expect(server().sent('POST', `${TICKET}/messages`)).toHaveLength(1)
    expect(screen.getAllByText('سلام')).toHaveLength(1)
  })

  it('keeps the conversation and the message being written when a read again fails', async () => {
    open()
    await screen.findByText('درست نشد.')
    fireEvent.change(screen.getByLabelText('متن پیام'), { target: { value: 'پیش‌نویس من' } })

    // One read again answered by a server failure (not asked again: it is no transport error).
    server().on('GET', TICKET, refusal(500, 'خطای سرور'))
    await queryClient.refetchQueries({ queryKey: keys.ticket(12) }).catch(() => undefined)

    expect(screen.getByText('درست نشد.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
    expect((screen.getByLabelText('متن پیام') as HTMLTextAreaElement).value).toBe('پیش‌نویس من')
  })

  it('a ticket gone since it opened says it was not found', async () => {
    open()
    await screen.findByText('درست نشد.')

    server().on('GET', TICKET, refusal(404, 'این تیکت پیدا نشد.'))
    await queryClient.refetchQueries({ queryKey: keys.ticket(12) }).catch(() => undefined)

    await screen.findByText('این تیکت پیدا نشد')
  })

  it('keeps a message sent after a mark went out, when the mark’s answer comes after it', async () => {
    open()
    await screen.findByText('درست نشد.')

    const answered = ticketDetail({
      unread: true,
      messages: [...ticketDetail().messages, message({ id: 38, author: 'support', body: 'سرور عوض شد.', created_at: '2026-10-08T11:00:00+00:00', seen: null })],
    })
    server().on('GET', TICKET, json({ ticket: answered }))
    const read = server().hold('POST', `${TICKET}/read`)
    await queryClient.refetchQueries({ queryKey: keys.ticket(12) })
    await until(() => expect(read.waiting).toBe(1))

    const sent = message({ id: 39, body: 'ممنون', created_at: new Date().toISOString(), seen: false })
    server().on('POST', `${TICKET}/messages`, json({ ticket: { ...answered, unread: false, messages: [...answered.messages, sent] } }))
    type('ممنون')
    await until(() => expect(screen.queryByLabelText('در حال ارسال')).toBeNull())

    // The mark's answer, the ticket as it was before the message went, arrives now.
    read.answer(json({ ticket: { ...answered, unread: false } }))
    await until(() => expect(queryClient.isMutating()).toBe(0))
    expect(screen.getByText('ممنون')).toBeTruthy()
    expect(queryClient.getQueryData<{ ticket: StoreTicketDetail }>(keys.ticket(12))?.ticket.messages.at(-1)?.id).toBe(39)
  })

  it('keeps the bubble of a message sent as the shop takes it: one element, said once', async () => {
    open()
    await screen.findByText('درست نشد.')
    const write = server().hold('POST', `${TICKET}/messages`)

    type('سلام')
    const bubble = await screen.findByText('سلام')
    await until(() => expect(write.waiting).toBe(1))
    write.answer(json({ ticket: ticketDetail({ messages: [...ticketDetail().messages, message({ id: 37, body: 'سلام', created_at: new Date().toISOString(), seen: false })] }) }))

    await until(() => expect(screen.queryByLabelText('در حال ارسال')).toBeNull())
    expect(screen.getByText('سلام')).toBe(bubble)
  })

  it('closes it once the customer confirms, then takes their stars and note once and shows them', async () => {
    const confirm = vi.fn((_message: string, callback?: (confirmed: boolean) => void) => callback?.(true))
    app.telegram({ showConfirm: confirm })
    open()
    const closed = ticketDetail({ status: 'closed', closed_at: '2026-10-08T12:00:00+00:00' })
    server().on('POST', `${TICKET}/close`, json({ ticket: closed }))
    server().on('POST', `${TICKET}/rating`, json({ ticket: { ...closed, rating: 4, rating_note: 'سریع جواب دادید.' } }))

    fireEvent.click(await screen.findByRole('button', { name: 'بستن تیکت' }))
    await screen.findByText('از پاسخ پشتیبانی راضی بودید؟')
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('button', { name: 'بستن تیکت' })).toBeNull()

    fireEvent.click(screen.getByRole('radio', { name: '۴ ستاره' }))
    fireEvent.change(screen.getByLabelText('توضیح (اختیاری)'), { target: { value: ' سریع جواب دادید. ' } })
    fireEvent.click(screen.getByRole('button', { name: 'ثبت امتیاز' }))

    await screen.findByText('امتیاز شما به این گفتگو')
    expect(server().sent('POST', `${TICKET}/rating`)[0]?.body).toEqual({ rating: 4, note: 'سریع جواب دادید.' })
    expect(screen.getByLabelText('۴ از ۵ ستاره')).toBeTruthy()
    expect(screen.getByText('سریع جواب دادید.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'ثبت امتیاز' })).toBeNull()
  })

  it('forgets a refusal of the stars once they are changed', async () => {
    open(ticketDetail({ status: 'closed', closed_at: '2026-10-08T12:00:00+00:00' }))
    const words = 'امتیاز باید از ۱ تا ۵ باشد.'
    server().on('POST', `${TICKET}/rating`, refusal(422, words, { rating: [words] }))

    fireEvent.click(await screen.findByRole('radio', { name: '۴ ستاره' }))
    fireEvent.click(screen.getByRole('button', { name: 'ثبت امتیاز' }))
    await screen.findByText(words)

    fireEvent.click(screen.getByRole('radio', { name: '۵ ستاره' }))
    expect(screen.queryByText(words)).toBeNull()
  })

  it('does not close it when the customer says no', async () => {
    app.telegram({ showConfirm: (_message, callback) => callback?.(false) })
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'بستن تیکت' }))
    await until(() => expect(screen.getByRole('button', { name: 'بستن تیکت' })).toBeTruthy())
    expect(server().sent('POST', `${TICKET}/close`)).toHaveLength(0)
  })

  it('says a ticket that is not the customer’s was not found', async () => {
    server().on('POST', `${TICKET}/read`, refusal(404, 'این تیکت پیدا نشد.'))
    render(app.screen(<ChatScreen />, { at: '/support/12', route: '/support/:id' }))

    await screen.findByText('این تیکت پیدا نشد')
  })
})
