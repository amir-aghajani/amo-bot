import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ReplyForm } from '@/components/tickets/reply-form'
import type { TicketResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { ticketDetail } from '@/test/rows'
import { json, offline, server } from '@/test/server'

/*
 * Support's answer to a ticket (components/tickets/reply-form), while it goes: nothing typed or picked meanwhile is
 * taken in — the form empties once the answer went, and would take it with it. Pressed again after no answer, the same
 * answer goes under the same Idempotency-Key, so the customer gets it once; another answer goes under a key of its own.
 */

const ANSWER = '/api/admin/tickets/12/messages'

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

function open() {
  const answered: string[] = []
  render(<ReplyForm ticket={ticketDetail()} invalidates={[]} onAnswered={(ticket) => answered.push(ticket.status)} />, { wrapper: providers().wrapper })
  return answered
}

const words = () => screen.getByRole<HTMLTextAreaElement>('textbox', { name: 'متن پاسخ' })

describe('an answer on its way', () => {
  it('holds the words and the picture until it went, then starts afresh', async () => {
    const sending = server().hold('POST', ANSWER)
    const answered = open()
    fireEvent.change(screen.getByLabelText('انتخاب تصویر'), { target: { files: [new File(['png-bytes'], 'speed.png', { type: 'image/png' })] } })
    fireEvent.change(words(), { target: { value: 'بررسی می‌کنیم.' } })

    fireEvent.click(screen.getByRole('button', { name: 'ارسال پاسخ' }))
    await until(() => expect(sending.waiting).toBe(1))

    expect(words().readOnly).toBe(true)
    expect(screen.getByRole('button', { name: 'تغییر تصویر' }).getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByRole('button', { name: 'برداشتن تصویر' }).getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(screen.getByRole('button', { name: 'برداشتن تصویر' }))
    expect(screen.getByText('speed.png')).toBeTruthy()

    sending.answer(json({ ticket: ticketDetail({ status: 'answered' }) } satisfies TicketResponse))

    await until(() => expect(answered).toEqual(['answered']))
    expect(words().readOnly).toBe(false)
    expect(words().value).toBe('')
    expect(screen.queryByText('speed.png')).toBeNull()
  })
})

/** The Idempotency-Key each answer went under, in the order sent. */
const keys = () =>
  server()
    .sent('POST', ANSWER)
    .map((request) => request.headers['idempotency-key'])

/** Send the form, and wait until the answer came back or failed. */
async function press() {
  const before = server().sent('POST', ANSWER).length
  fireEvent.click(screen.getByRole('button', { name: 'ارسال پاسخ' }))
  await until(() => expect(server().sent('POST', ANSWER)).toHaveLength(before + 1))
  await until(() => expect(words().readOnly).toBe(false))
}

const pick = (file: File) => fireEvent.change(screen.getByLabelText('انتخاب تصویر'), { target: { files: [file] } })

describe('an answer pressed again', () => {
  it('after no answer goes under the same key — the customer gets it once — and the next answer under a new one', async () => {
    server().on('POST', ANSWER, offline)
    open()
    fireEvent.change(words(), { target: { value: 'بررسی می‌کنیم.' } })

    await press()
    server().on('POST', ANSWER, json({ ticket: ticketDetail({ status: 'answered' }) } satisfies TicketResponse))
    await press()
    expect(words().value).toBe('')
    fireEvent.change(words(), { target: { value: 'بررسی می‌کنیم.' } })
    await press()

    const [first, again, next] = keys()
    expect(first).toMatch(/^[0-9a-f]{32}$/)
    expect(again).toBe(first)
    expect(next).not.toBe(first)
  })

  it('with other words or another picture goes under a new key', async () => {
    server().on('POST', ANSWER, offline)
    open()
    fireEvent.change(words(), { target: { value: 'بررسی می‌کنیم.' } })
    await press()
    fireEvent.change(words(), { target: { value: 'بررسی کردیم.' } })
    await press()
    pick(new File(['png-bytes'], 'speed.png', { type: 'image/png' }))
    await press()
    await press()
    pick(new File(['other-bytes'], 'speed.png', { type: 'image/png' }))
    await press()

    const [first, edited, pictured, again, repicked] = keys()
    expect(new Set([first, edited, pictured, repicked]).size).toBe(4)
    expect(again).toBe(pictured)
  })
})
