import { QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TicketDetail, TicketResponse } from '@/lib/api-types'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import { idLabel } from '@/lib/direction'
import { formatDate } from '@/lib/format'
import { TicketPage } from '@/pages/ticket'
import { OWNER, testQueryClient, until } from '@/test/render'
import { ticketDetail, ticketMessage } from '@/test/rows'
import { json, refusal, server, type Answer } from '@/test/server'

/*
 * A ticket's page (pages/ticket): what it is about and where it stands — since when, once closed —, whose it is and the
 * service it is about; the conversation, the first message first — the customer's and support's apart, each with where it
 * was written and support's writer —, a picture opening at full size and, when the browser cannot draw one, the server's
 * word on why; the answer, as JSON or — with a picture — as a form, the ticket read again from it; closing after a second
 * look, opening again — after a second look when it takes the customer's rating with it —; and a ticket the shop does
 * not have not found.
 */

const TICKET = '/api/admin/tickets/12'
const ANSWER = '/api/admin/tickets/12/messages'
const PICTURE = '/api/admin/tickets/12/messages/31/attachment'

/** The page at `at`, the server answering the ticket's read with `detail` — as the panels mount it, behind RequireAuth. */
function open(detail: Answer = json({ ticket: ticketDetail() } satisfies TicketResponse), at = '/tickets/12') {
  server()
    .on('GET', '/api/admin/auth/me', json({ session: OWNER }))
    .on('GET', TICKET, detail)
  const signedIn = (
    <AuthProvider>
      <RequireAuth />
    </AuthProvider>
  )
  const router = createMemoryRouter([{ element: signedIn, children: [{ path: 'tickets/:id', element: <TicketPage /> }] }], { initialEntries: [at] })
  render(
    <QueryClientProvider client={testQueryClient()}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
  return router
}

/** The ticket as the server answers a write with it. */
const answer = (ticket: TicketDetail) => json({ ticket } satisfies TicketResponse)

const title = () => screen.findByRole('heading', { level: 1 })
const messages = () => within(screen.getByRole('list', { name: 'پیام‌های تیکت' })).getAllByRole('article')
const words = () => screen.getByRole<HTMLTextAreaElement>('textbox', { name: 'متن پاسخ' })
const send = () => fireEvent.click(screen.getByRole('button', { name: 'ارسال پاسخ' }))

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('a ticket’s page', () => {
  it('says what it is about and where it stands, whose it is and the service it is about', async () => {
    open()

    const heading = await title()
    expect(heading.textContent).toContain('سرعت سرویس پایین است')
    expect(heading.textContent).toContain('در انتظار پاسخ')
    expect(screen.getByText('#12').getAttribute('dir')).toBe('ltr')
    expect(screen.getByRole('link', { name: 'امیر' }).getAttribute('href')).toBe('/users/10')
    expect(screen.getByRole('link', { name: 'amir_1' }).getAttribute('href')).toBe('/subscriptions?search=%231')
    expect(screen.getByRole('link', { name: 'تیکت‌ها' }).getAttribute('href')).toBe('/tickets')
    expect(document.body.textContent).toContain(`باز شده در ${formatDate('2026-10-06T09:00:00Z')}`)
    expect(document.body.textContent).not.toContain('بسته شده در')
  })

  it('says since when a closed one is closed', async () => {
    open(answer(ticketDetail({ status: 'closed', closed_at: '2026-10-07T14:30:00Z' })))
    await title()

    expect(document.body.textContent).toContain(`باز شده در ${formatDate('2026-10-06T09:00:00Z')} · بسته شده در ${formatDate('2026-10-07T14:30:00Z')}`)
  })

  it('shows the conversation first message first — the customer’s at the start, support’s at the end with its writer —, each with where it was written', async () => {
    open(
      answer(
        ticketDetail({
          messages: [
            ticketMessage(),
            ticketMessage({ id: 32, author: 'support', reviewer: 'root', body: 'بررسی می‌کنیم.', channel: 'panel', created_at: '2026-10-06T10:00:00Z' }),
            ticketMessage({ id: 33, author: 'support', reviewer: '@reza_admin', body: 'درست شد.', channel: 'group', created_at: '2026-10-06T11:00:00Z' }),
            ticketMessage({ id: 34, body: 'ممنون', channel: 'bot', created_at: '2026-10-06T12:00:00Z' }),
          ],
        }),
      ),
    )
    await title()

    const shown = messages()
    expect(shown.map((message) => message.getAttribute('aria-label'))).toEqual(['پیام مشتری', 'پاسخ پشتیبانی', 'پاسخ پشتیبانی', 'پیام مشتری'])
    expect(shown.map((message) => message.closest('li')?.className.includes('justify-end'))).toEqual([false, true, true, false])
    expect(shown.map((message) => within(message).getByText(/^(وب‌سایت|ربات|پنل|گروه گزارش‌ها)$/).textContent)).toEqual(['وب‌سایت', 'پنل', 'گروه گزارش‌ها', 'ربات'])
    const [first, panel, group] = shown
    expect(within(first ?? document.body).getByText('امیر')).toBeTruthy()
    expect(
      within(panel ?? document.body)
        .getByText('root')
        .getAttribute('dir'),
    ).toBe('ltr')
    expect(
      within(group ?? document.body)
        .getByText('@reza_admin')
        .getAttribute('dir'),
    ).toBe('ltr')
  })

  it('names support once where its writer is support itself — the owner’s answer in an agent’s shop', async () => {
    open(answer(ticketDetail({ messages: [ticketMessage(), ticketMessage({ id: 32, author: 'support', reviewer: 'پشتیبانی', channel: 'panel' })] })))
    await title()

    const header = messages()[1]?.querySelector('header')
    expect(header?.textContent).toMatch(/^پشتیبانیپنل/)
  })

  it('shows the customer’s rating, with what they said, once given', async () => {
    open(answer(ticketDetail({ status: 'closed', rating: 4, rating_note: 'پاسخ سریع بود' })))
    await title()

    expect(screen.getByText('۴ از ۵')).toBeTruthy()
    expect(screen.getByText('پاسخ سریع بود')).toBeTruthy()
  })
})

describe('a message’s picture', () => {
  /** The picture of message 31, as its thumbnail draws it. */
  const thumbnail = () => within(messages()[1] ?? document.body).getByRole('img')

  it('opens at full size, to download under its name', async () => {
    open()
    await title()

    fireEvent.click(screen.getByRole('button', { name: /نمایش در اندازه کامل/ }))

    const dialog = await screen.findByRole('dialog', { name: /^تصویر .*speedtest\.png/ })
    expect(within(dialog).getByRole('img').getAttribute('src')).toBe(`${PICTURE}?shop=1`)
    expect(
      within(dialog)
        .getByRole('link', { name: /دانلود تصویر/ })
        .getAttribute('download'),
    ).toBe('speedtest.png')
  })

  it('the browser could not draw is explained in the server’s words, with nothing to download, once it is gone', async () => {
    server().on('GET', PICTURE, refusal(404, 'این تصویر دیگر در تلگرام نیست.'))
    open()
    await title()

    fireEvent.error(thumbnail())

    expect(await screen.findByText('تصویر پیدا نشد.')).toBeTruthy()
    expect(screen.getByText('این تصویر دیگر در تلگرام نیست.')).toBeTruthy()
    expect(screen.queryByRole('link', { name: /دانلود تصویر/ })).toBeNull()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })

  it('can be asked for again while Telegram is out of reach', async () => {
    server().on('GET', PICTURE, refusal(502, 'تلگرام در دسترس نبود؛ چند لحظه بعد دوباره امتحان کنید.'))
    open()
    await title()

    fireEvent.error(thumbnail())
    fireEvent.click(await screen.findByRole('button', { name: 'تلاش دوباره' }))

    expect(thumbnail().getAttribute('src')).toBe(`${PICTURE}?shop=1`)
  })

  it('no longer kept is said so, and not asked for', async () => {
    open(json({ ticket: ticketDetail({ messages: [ticketMessage({ attachment: { name: 'speedtest.png', kept: false } })] }) } satisfies TicketResponse))
    await title()

    const message = messages()[0] ?? document.body
    expect(within(message).getByText(/تصویر این پیام دیگر نگه داشته نمی‌شود/)).toBeTruthy()
    expect(within(message).queryByRole('img')).toBeNull()
    expect(screen.queryByRole('button', { name: /نمایش در اندازه کامل/ })).toBeNull()
  })

  it('is offered as a download when it is there and the browser just cannot draw it', async () => {
    server().on('GET', PICTURE, { kind: 'response', status: 200, body: 'heic-bytes', type: 'image/heic' })
    open()
    await title()

    fireEvent.error(thumbnail())

    expect((await screen.findByRole('link', { name: /دانلود تصویر/ })).getAttribute('download')).toBe('speedtest.png')
  })
})

describe('the answer', () => {
  const answered = ticketDetail({
    status: 'answered',
    messages_count: 3,
    messages: [...ticketDetail().messages, ticketMessage({ id: 32, author: 'support', reviewer: 'root', body: 'بررسی می‌کنیم.', channel: 'panel', created_at: '2026-10-06T10:00:00Z' })],
  })

  it('goes as JSON with words alone, and the ticket is read again from it — the form afresh, the focus back in its words', async () => {
    server().on('POST', ANSWER, answer(answered))
    open()
    await title()

    fireEvent.change(words(), { target: { value: 'بررسی می‌کنیم.' } })
    send()

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('پاسخ‌داده‌شده'))
    expect(
      server()
        .sent('POST', ANSWER)
        .map((request) => request.body),
    ).toEqual([{ body: 'بررسی می‌کنیم.' }])
    expect(messages().at(-1)?.textContent).toContain('بررسی می‌کنیم.')
    expect(words().value).toBe('')
    expect(document.activeElement).toBe(words())
    expect(toast.success).toHaveBeenCalledWith('پاسخ فرستاده شد')
  })

  it('goes as a form with its picture', async () => {
    server().on('POST', ANSWER, answer(answered))
    open()
    await title()
    const picture = new File(['png-bytes'], 'speed.png', { type: 'image/png' })

    fireEvent.change(screen.getByLabelText('انتخاب تصویر'), { target: { files: [picture] } })
    expect(screen.getByText('speed.png')).toBeTruthy()
    fireEvent.change(words(), { target: { value: 'این هم تصویر.' } })
    send()

    await until(() => expect(server().sent('POST', ANSWER)).toHaveLength(1))
    const [request] = server().sent('POST', ANSWER)
    expect(request?.body instanceof FormData ? [request.body.get('body'), request.body.get('file')] : null).toEqual(['این هم تصویر.', picture])
    await until(() => expect(screen.queryByText('speed.png')).toBeNull())
  })

  it('lets go of a picture picked', async () => {
    open()
    await title()

    fireEvent.change(screen.getByLabelText('انتخاب تصویر'), { target: { files: [new File(['png-bytes'], 'speed.png', { type: 'image/png' })] } })
    fireEvent.click(screen.getByRole('button', { name: 'برداشتن تصویر' }))

    expect(screen.queryByText('speed.png')).toBeNull()
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'افزودن تصویر' }))
  })

  it('refuses a picture past the server’s limit as it is picked, in the server’s words — nothing sent', async () => {
    open()
    await title()
    const big = new File(['png-bytes'], 'big.png', { type: 'image/png' })
    Object.defineProperty(big, 'size', { value: 10 * 1024 * 1024 + 1 })

    fireEvent.change(screen.getByLabelText('انتخاب تصویر'), { target: { files: [big] } })

    expect(screen.getByText('حجم تصویر حداکثر ۱۰ مگابایت می‌تواند باشد.')).toBeTruthy()
    expect(screen.queryByText('big.png')).toBeNull()
    expect(server().sent('POST', ANSWER)).toEqual([])
  })

  it('says a picture the server refused under it, the field to fix in focus', async () => {
    const wrong = 'تصویر باید JPG، PNG یا WebP باشد.'
    server().on('POST', ANSWER, refusal(422, wrong, { file: [wrong] }))
    open()
    await title()

    fireEvent.change(screen.getByLabelText('انتخاب تصویر'), { target: { files: [new File(['gif'], 'a.png', { type: 'image/png' })] } })
    fireEvent.change(words(), { target: { value: 'تصویر' } })
    send()

    expect(await screen.findByText(wrong)).toBeTruthy()
    await until(() => expect(document.activeElement?.getAttribute('role')).toBe('group'))
    expect(document.activeElement?.hasAttribute('data-invalid')).toBe(true)
  })

  it('cannot go without words', async () => {
    open()
    await title()

    expect(screen.getByRole<HTMLButtonElement>('button', { name: 'ارسال پاسخ' }).disabled).toBe(true)
    fireEvent.change(words(), { target: { value: '   ' } })
    expect(screen.getByRole<HTMLButtonElement>('button', { name: 'ارسال پاسخ' }).disabled).toBe(true)
  })

  it('says, on a closed ticket, that it opens it again — and that the customer’s rating goes', async () => {
    open(answer(ticketDetail({ status: 'closed', rating: 4, rating_note: null })))
    await title()

    expect(screen.getByText(/پاسخ شما دوباره بازش می‌کند و در وضعیت «پاسخ‌داده‌شده» می‌گذارد/)).toBeTruthy()
    expect(screen.getByText(/امتیازی که مشتری به آن داده بود کنار گذاشته می‌شود/)).toBeTruthy()
  })
})

describe('closing and opening again', () => {
  it('closes after a second look, the button turning into the way to open it again', async () => {
    server().on('POST', '/api/admin/tickets/12/close', answer(ticketDetail({ status: 'closed' })))
    open()
    await title()

    fireEvent.click(screen.getByRole('button', { name: 'بستن تیکت' }))
    const dialog = await screen.findByRole('dialog', { name: 'بستن تیکت' })
    expect(within(dialog).getByText(`تیکت ${idLabel(12)} بسته می‌شود و مشتری خبردار می‌شود.`)).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('button', { name: 'بستن تیکت' }))

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('بسته‌شده'))
    expect(server().sent('POST', '/api/admin/tickets/12/close')).toHaveLength(1)
    expect(screen.getByRole('button', { name: 'باز کردن دوباره' })).toBeTruthy()
    expect(toast.success).toHaveBeenCalledWith('تیکت بسته شد')
  })

  it('says in the dialog a close refused because it was closed meanwhile, and reads the ticket again', async () => {
    const closed = 'این تیکت بسته شده است.'
    server().on('POST', '/api/admin/tickets/12/close', refusal(422, closed, { status: [closed] }))
    open()
    await title()

    fireEvent.click(screen.getByRole('button', { name: 'بستن تیکت' }))
    const dialog = await screen.findByRole('dialog', { name: 'بستن تیکت' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'بستن تیکت' }))

    expect(await within(dialog).findByText(closed)).toBeTruthy()
    await until(() => expect(server().sent('GET', TICKET)).toHaveLength(2))
  })

  it('opens a closed one the customer did not rate again at a press', async () => {
    server().on('POST', '/api/admin/tickets/12/reopen', answer(ticketDetail({ status: 'open' })))
    open(answer(ticketDetail({ status: 'closed', closed_at: '2026-10-07T14:30:00Z' })))
    await title()

    fireEvent.click(screen.getByRole('button', { name: 'باز کردن دوباره' }))

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('در انتظار پاسخ'))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(screen.getByRole('button', { name: 'بستن تیکت' })).toBeTruthy()
    expect(document.body.textContent).not.toContain('بسته شده در')
    expect(toast.success).toHaveBeenCalledWith('تیکت دوباره باز شد')
  })

  describe('one the customer rated', () => {
    const rated = () => answer(ticketDetail({ status: 'closed', closed_at: '2026-10-07T14:30:00Z', rating: 4, rating_note: 'پاسخ سریع بود' }))

    /** The page on the rated ticket, its «باز کردن دوباره» pressed: the dialog that asks first. */
    async function ask() {
      open(rated())
      await title()
      fireEvent.click(screen.getByRole('button', { name: 'باز کردن دوباره' }))
      return screen.findByRole('dialog', { name: 'باز کردن دوباره تیکت' })
    }

    it('is asked first — opening it again clears the rating — and nothing goes until it is confirmed', async () => {
      const dialog = await ask()

      expect(within(dialog).getByText('باز کردن دوباره امتیاز مشتری را پاک می‌کند.')).toBeTruthy()
      expect(server().sent('POST', '/api/admin/tickets/12/reopen')).toEqual([])
      fireEvent.click(within(dialog).getByRole('button', { name: 'انصراف' }))

      await until(() => expect(screen.queryByRole('dialog')).toBeNull())
      expect(server().sent('POST', '/api/admin/tickets/12/reopen')).toEqual([])
      expect(screen.getByText('۴ از ۵')).toBeTruthy()
    })

    it('opens once confirmed, the rating gone with it', async () => {
      server().on('POST', '/api/admin/tickets/12/reopen', answer(ticketDetail({ status: 'open' })))
      const dialog = await ask()

      fireEvent.click(within(dialog).getByRole('button', { name: 'باز کردن دوباره' }))

      await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('در انتظار پاسخ'))
      expect(server().sent('POST', '/api/admin/tickets/12/reopen')).toHaveLength(1)
      expect(screen.queryByRole('dialog')).toBeNull()
      expect(screen.queryByText('۴ از ۵')).toBeNull()
      expect(toast.success).toHaveBeenCalledWith('تیکت دوباره باز شد')
    })

    it('says in the dialog a reopen refused because it was opened meanwhile, and reads the ticket again', async () => {
      const notClosed = 'این تیکت بسته نیست.'
      server().on('POST', '/api/admin/tickets/12/reopen', refusal(422, notClosed, { status: [notClosed] }))
      const error = vi.spyOn(toast, 'error')
      const dialog = await ask()

      fireEvent.click(within(dialog).getByRole('button', { name: 'باز کردن دوباره' }))

      expect(await within(dialog).findByText(notClosed)).toBeTruthy()
      await until(() => expect(server().sent('GET', TICKET)).toHaveLength(2))
      expect(error).not.toHaveBeenCalled()
    })
  })
})

describe('a ticket the page cannot show', () => {
  it('is not found when the shop has none of that number', async () => {
    open(refusal(404, 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.'))

    expect(await screen.findByText('تیکت پیدا نشد.')).toBeTruthy()
    expect(screen.getByRole('link', { name: 'تیکت‌ها' }).getAttribute('href')).toBe('/tickets')
  })

  it('is the panel’s page not found for an address that names no ticket, without asking', async () => {
    open(undefined, '/tickets/someone')

    expect(await screen.findByText('این صفحه پیدا نشد')).toBeTruthy()
    expect(server().sent('GET', TICKET)).toEqual([])
  })

  it('starts afresh on another ticket — nothing of the one before left on it', async () => {
    server().on('GET', '/api/admin/tickets/13', answer(ticketDetail({ id: 13, subject: 'Another one' })))
    const router = open()
    await title()
    fireEvent.change(words(), { target: { value: 'نیمه‌کاره' } })

    await act(() => router.navigate('/tickets/13'))

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('Another one'))
    expect(words().value).toBe('')
  })
})
