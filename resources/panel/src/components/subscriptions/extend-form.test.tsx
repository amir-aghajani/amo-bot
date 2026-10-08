import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import type { Session, SubscriptionRow } from '@/lib/api-types'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import { testQueryClient, until } from '@/test/render'
import { subscriptionRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «افزایش زمان و حجم» in a service's dialog (components/subscriptions/extend-form): its strip is a form — the days and the
 * GB each worded for this service, or off when it cannot take them —; what it sends, the service's numbers following in
 * place once the server took it, a refusal under the field it is about; in an agent's shop the GB is said to come out of
 * the bot's traffic, with what is left when the panel knows it.
 */

const SERVICE = subscriptionRow()
const URL = `/api/admin/subscriptions/${SERVICE.id}/extend`
const OWNER: Session = { name: 'root', shop: { id: 1, name: 'فروشگاه اصلی', username: null, status: 'active' } }
const AGENTS_SHOP: Session = { name: '@reza_shop_bot', shop: { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active' } }
const GB = 1024 ** 3

interface Options {
  subscription?: SubscriptionRow
  /** The shop the panel shows: the main bot's, unless an agent's. */
  session?: Session
  traffic?: number
}

/** The service's dialog over a signed-in panel, its «افزایش زمان و حجم» pressed: the form on its strip. */
async function extend({ subscription = SERVICE, session = OWNER, traffic }: Options = {}) {
  server().on('GET', '/api/admin/auth/me', json({ session }))
  const props = { onClose: vi.fn(), onChanged: vi.fn(), onDeleted: vi.fn(), onStale: vi.fn(), onMove: vi.fn() }
  render(
    <QueryClientProvider client={testQueryClient()}>
      <MemoryRouter>
        <AuthProvider>
          <Routes>
            <Route element={<RequireAuth />}>
              <Route path="*" element={<SubscriptionModal subscription={subscription} traffic={traffic} {...props} />} />
            </Route>
          </Routes>
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  fireEvent.click(await screen.findByRole('button', { name: 'افزایش زمان و حجم' }))
  await screen.findByLabelText('تعداد روز')
  return props
}

/** The amounts typed, then the form's own «افزایش زمان و حجم». */
function send(amounts: { days?: string; gb?: string }) {
  if (amounts.days !== undefined) fireEvent.change(screen.getByLabelText('تعداد روز'), { target: { value: amounts.days } })
  if (amounts.gb !== undefined) fireEvent.change(screen.getByLabelText('حجم (گیگابایت)'), { target: { value: amounts.gb } })
  fireEvent.click(screen.getByRole('button', { name: 'افزایش زمان و حجم' }))
}

/** The line under a field: its hint, or its error. */
const lineUnder = (label: string) => document.getElementById(screen.getByLabelText(label).getAttribute('aria-describedby') ?? '')?.textContent

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('extending one service', () => {
  it('sends what was typed, and the dialog follows the service as it is now', async () => {
    const fresh = subscriptionRow({ duration_days: 33, traffic: { limit: 40 * GB, used: 5 * GB } })
    server().on('POST', URL, json({ subscription: fresh }))
    const { onChanged } = await extend()

    fireEvent.change(screen.getByLabelText(/توضیح برای مشتری/), { target: { value: 'جبران قطعی دیروز' } })
    send({ days: '۳', gb: '10' })

    await until(() => expect(onChanged).toHaveBeenCalledWith(fresh))
    expect(server().sent('POST', URL)[0]?.body).toEqual({ days: '۳', traffic_gb: '10', note: 'جبران قطعی دیروز', notify: true })
    expect(toast.success).toHaveBeenCalledWith('زمان و حجم به سرویس amir_1 اضافه شد')
    expect(screen.queryByLabelText('تعداد روز')).toBeNull()
    expect(screen.getByRole('button', { name: 'افزایش زمان و حجم' })).toBeTruthy()
  })

  it('keeps the customer out of it when its box is unticked, and words what alone was given', async () => {
    server().on('POST', URL, json({ subscription: SERVICE }))
    await extend()

    fireEvent.click(screen.getByRole('checkbox', { name: 'پیام به مشتری' }))
    send({ gb: '۰٫۵' })

    await until(() => expect(toast.success).toHaveBeenCalledWith('حجم به سرویس amir_1 اضافه شد'))
    expect(server().sent('POST', URL)[0]?.body).toEqual({ days: '', traffic_gb: '۰٫۵', note: '', notify: false })
  })

  it('says a refusal under the field it is about and stays on the form', async () => {
    const short = 'حجم نمایندگی کافی نیست: این افزایش ۵۰ گیگابایت می‌خواهد و ۲۰ گیگابایت مانده است؛ حجم بیشتر را از ربات اصلی بخرید.'
    server().on('POST', URL, refusal(422, short, { traffic_gb: [short] }))
    const { onChanged, onStale } = await extend()

    send({ gb: '50' })

    await until(() => expect(lineUnder('حجم (گیگابایت)')).toBe(short))
    expect(screen.getByLabelText('حجم (گیگابایت)').getAttribute('aria-invalid')).toBe('true')
    expect([onChanged.mock.calls.length, onStale.mock.calls.length]).toEqual([0, 0])
  })

  it('reads the list again when the service moved on meanwhile', async () => {
    const ended = 'فقط به سرویس فعال زمان و حجم اضافه می‌شود؛ سرویس منقضی تمدید لازم دارد.'
    server().on('POST', URL, refusal(422, ended, { status: [ended] }))
    const { onStale } = await extend()

    send({ days: '3' })

    await until(() => expect(screen.getByText(ended)).toBeTruthy())
    expect(onStale).toHaveBeenCalled()
  })
})

describe('the amounts, worded for the service', () => {
  it('move a running service’s deadline and go on top of its quota', async () => {
    await extend()

    expect(lineUnder('تعداد روز')).toBe('به تاریخ پایان سرویس اضافه می‌شود.')
    expect(lineUnder('حجم (گیگابایت)')).toBe('روی حجم سرویس اضافه می‌شود.')
    expect(document.activeElement).toBe(screen.getByLabelText('تعداد روز'))
  })

  it('lengthen the term of one still waiting for its first connection', async () => {
    await extend({ subscription: subscriptionRow({ expires_at: null, starts_at: null }) })

    expect(lineUnder('تعداد روز')).toBe('به مدت سرویس اضافه می‌شود؛ زمانش از اولین اتصال مشتری شروع می‌شود.')
  })

  it('leave out the days of one that never ends, the GB taking the focus', async () => {
    await extend({ subscription: subscriptionRow({ expires_at: null, starts_at: null, duration_days: 0 }) })

    expect([screen.getByLabelText('تعداد روز').hasAttribute('disabled'), lineUnder('تعداد روز')]).toEqual([true, 'این سرویس تاریخ پایان ندارد.'])
    expect(document.activeElement).toBe(screen.getByLabelText('حجم (گیگابایت)'))
  })

  it('leave out the GB of one without a quota', async () => {
    await extend({ subscription: subscriptionRow({ traffic: { limit: 0, used: 3 * GB } }) })

    expect([screen.getByLabelText('حجم (گیگابایت)').hasAttribute('disabled'), lineUnder('حجم (گیگابایت)')]).toEqual([true, 'حجم این سرویس نامحدود است.'])
  })

  it('say, in an agent’s shop, that the GB comes out of the bot’s traffic — and how much is left, where the panel knows it', async () => {
    await extend({ session: AGENTS_SHOP, traffic: 20 * GB })

    expect(lineUnder('حجم (گیگابایت)')).toBe('روی حجم سرویس اضافه و از حجم ربات این فروشگاه کم می‌شود (۲۰ گیگابایت مانده).')
  })

  it('say it without the amount where the panel does not know it', async () => {
    await extend({ session: AGENTS_SHOP })

    expect(lineUnder('حجم (گیگابایت)')).toBe('روی حجم سرویس اضافه و از حجم ربات این فروشگاه کم می‌شود.')
  })
})
