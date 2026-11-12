import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import NewTicketScreen from '@/apps/app/screens/support/new-ticket'
import { ticketDetail } from '@/apps/app/screens/support/test-rows'
import * as app from '@/apps/app/test/app'
import { API, service } from '@/apps/app/test/rows'
import type { StoreSubscriptionsResponse, StoreTicketResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «تیکت تازه» (screens/support/new-ticket): the subject, the service (the one the service's page named picked already,
 * another picked in a sheet, or none), the message and an optional picture — JSON without one, a form with it —; the
 * shop's refusals under their fields; opened, its conversation takes the screen's place with the ticket kept for it.
 */

const OPENED: StoreTicketResponse = { ticket: ticketDetail({ id: 40, status: 'open', messages: [] }) }

function open(at = '/support/new') {
  render(app.screen(<NewTicketScreen />, { at, route: '/support/new' }))
}

const send = () => fireEvent.click(screen.getByRole('button', { name: 'ارسال تیکت' }))

function write(subject: string, body: string) {
  fireEvent.change(screen.getByLabelText('موضوع'), { target: { value: subject } })
  fireEvent.change(screen.getByLabelText('پیام'), { target: { value: body } })
}

describe('a new ticket', () => {
  it('opens one about the service its page named, as JSON, and goes to its conversation', async () => {
    server().on('GET', `${API}/subscriptions/9`, json({ subscription: service({ label: 'سرویس خانه' }) }))
    server().on('POST', `${API}/tickets`, json(OPENED, 201))
    open('/support/new?subscription=9')

    await screen.findByText('سرویس خانه')
    write('  قطعی اتصال ', 'از دیشب وصل نمی‌شود.\nروی دو گوشی امتحان کردم.')
    send()

    await until(() => expect(server().sent('POST', `${API}/tickets`)).toHaveLength(1))
    expect(server().sent('POST', `${API}/tickets`)[0]?.body).toEqual({ subject: 'قطعی اتصال', body: 'از دیشب وصل نمی‌شود.\nروی دو گوشی امتحان کردم.', subscription_id: 9 })
    await until(() => expect(screen.queryByLabelText('موضوع')).toBeNull())
    expect(queryClient.getQueryData(keys.ticket(40))).toEqual(OPENED)
  })

  it('sends a picture with it as a form, about the service picked in the sheet', async () => {
    server().on(
      'GET',
      `${API}/subscriptions`,
      json({ subscriptions: [service(), service({ id: 10, name: 'ali_2' })], meta: { page: 1, per_page: 25, total: 2, last_page: 1 } } satisfies StoreSubscriptionsResponse),
    )
    server().on('POST', `${API}/tickets`, json(OPENED, 201))
    open()

    fireEvent.click(screen.getByRole('button', { name: /هیچ‌کدام/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('radio', { name: /ali_2/ }))
    await until(() => expect(screen.queryByRole('dialog')).toBeNull())
    const picture = new File(['png-bytes'], 'error.png', { type: 'image/png' })
    fireEvent.change(screen.getByLabelText('انتخاب تصویر'), { target: { files: [picture] } })
    await screen.findByAltText('تصویر انتخاب‌شده')
    write('خطای اتصال', 'این خطا را می‌دهد.')
    send()

    await until(() => expect(server().sent('POST', `${API}/tickets`)).toHaveLength(1))
    const form = server().sent('POST', `${API}/tickets`)[0]?.body
    expect(form).toBeInstanceOf(FormData)
    const fields = form as FormData
    expect(fields.get('subject')).toBe('خطای اتصال')
    expect(fields.get('body')).toBe('این خطا را می‌دهد.')
    expect(fields.get('subscription_id')).toBe('10')
    expect((fields.get('file') as File).name).toBe('error.png')
  })

  it('refuses a picture of another kind as it is picked, and keeps none', async () => {
    open()

    fireEvent.change(screen.getByLabelText('انتخاب تصویر'), { target: { files: [new File(['gif'], 'a.gif', { type: 'image/gif' })] } })
    await screen.findByText('تصویر باید JPG، PNG یا WebP باشد.')
    expect(screen.queryByAltText('تصویر انتخاب‌شده')).toBeNull()
  })

  it('shows the shop’s refusals under their fields, and one of no field apart', async () => {
    let asked = 0
    server().on('POST', `${API}/tickets`, () =>
      ++asked === 1 ? refusal(422, 'اطلاعات واردشده معتبر نیست.', { subject: ['موضوع حداقل 3 کاراکتر است.'] }) : refusal(429, 'تیکت زیادی باز کرده‌اید؛ کمی بعد دوباره امتحان کنید.'),
    )
    open()

    write('abc', 'متن')
    send()
    await screen.findByText('موضوع حداقل 3 کاراکتر است.')
    expect(screen.queryByText('اطلاعات واردشده معتبر نیست.')).toBeNull()

    send()
    await screen.findByText('تیکت زیادی باز کرده‌اید؛ کمی بعد دوباره امتحان کنید.')
    expect(screen.queryByText('موضوع حداقل 3 کاراکتر است.')).toBeNull()
  })

  it('forgets a refusal once what it refused is changed', async () => {
    server().on('POST', `${API}/tickets`, refusal(422, 'اطلاعات واردشده معتبر نیست.', { subject: ['موضوع حداقل 3 کاراکتر است.'] }))
    open()

    write('abc', 'متن')
    send()
    await screen.findByText('موضوع حداقل 3 کاراکتر است.')

    fireEvent.change(screen.getByLabelText('موضوع'), { target: { value: 'abcd' } })
    expect(screen.queryByText('موضوع حداقل 3 کاراکتر است.')).toBeNull()
  })

  it('sends nothing until the service its page named is read — and, unread, asks for it to be picked', async () => {
    const read = server().hold('GET', `${API}/subscriptions/9`)
    open('/support/new?subscription=9')

    write('قطعی اتصال', 'از دیشب وصل نمی‌شود.')
    await until(() => expect(read.waiting).toBe(1))
    expect(screen.getByRole('button', { name: /در حال خواندن/ })).toBeTruthy()
    expect((screen.getByRole('button', { name: 'ارسال تیکت' }) as HTMLButtonElement).disabled).toBe(true)

    read.answer(refusal(500, 'خطای سرور'))
    await screen.findByText('سرویس این تیکت خوانده نشد؛ آن را از فهرست انتخاب کنید.')
    expect((screen.getByRole('button', { name: 'ارسال تیکت' }) as HTMLButtonElement).disabled).toBe(true)
    expect(server().sent('POST', `${API}/tickets`)).toHaveLength(0)
  })

  it('goes without a service its page named that is not the customer’s', async () => {
    server().on('GET', `${API}/subscriptions/9`, refusal(404, 'پیدا نشد.'))
    open('/support/new?subscription=9')

    write('قطعی اتصال', 'از دیشب وصل نمی‌شود.')
    await until(() => expect((screen.getByRole('button', { name: 'ارسال تیکت' }) as HTMLButtonElement).disabled).toBe(false))
    expect(screen.getByRole('button', { name: /هیچ‌کدام/ })).toBeTruthy()
  })

  it('keeps the services in the sheet when their next page fails, and says why', async () => {
    server().on('GET', `${API}/subscriptions`, (request) =>
      request.query.get('page') === '2'
        ? refusal(500, 'خطای سرور')
        : json({ subscriptions: [service()], meta: { page: 1, per_page: 25, total: 26, last_page: 2 } } satisfies StoreSubscriptionsResponse),
    )
    open()

    fireEvent.click(screen.getByRole('button', { name: /هیچ‌کدام/ }))
    const sheet = await screen.findByRole('dialog')
    fireEvent.click(await within(sheet).findByRole('button', { name: 'سرویس‌های بیشتر' }))

    await within(sheet).findByText(/خطای سرور/)
    expect(within(sheet).getByRole('radio', { name: /ali_1/ })).toBeTruthy()
    expect(within(sheet).queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })
})
