import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ManualOrderDialog } from '@/components/customer/manual-order-dialog'
import { userLabel } from '@/components/user-identity'
import type { ManualOrderResponse, PlanOptions, PlanRow } from '@/lib/api-types'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { orderRow, planRow, subscriptionRow, userRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «افزودن سفارش» on a customer's page (components/customer/manual-order-dialog): a plan — its server picked when it has
 * one that delivers it — or support's own numbers on any server that can take a service; the way to pay, an amount
 * unless free; a preview of what it delivers; what is sent, under one Idempotency-Key a request, a new one after a
 * refusal; a refusal under its field; a list that could not be read said over its picker, with a retry; and in an
 * agent's shop, the GB said to come out of the bot's traffic.
 */

const CUSTOMER = userRow({ balance: '200000.00' })
const URL = `/api/admin/users/${CUSTOMER.id}/orders`
const GOLD = planRow()
const OPTIONS: PlanOptions = {
  categories: [],
  servers: [
    { id: 1, name: 'آلمان', is_active: true, serves_subscriptions: true, driver_label: '3x-ui', inbounds: [], unsellable_reason: null },
    { id: 4, name: 'فنلاند', is_active: false, serves_subscriptions: true, driver_label: '3x-ui', inbounds: [], unsellable_reason: 'سرور خاموش است.' },
  ],
}
const MADE: ManualOrderResponse = {
  order: orderRow({ type: 'purchase', status: 'fulfilled', amount: '0.00', pricing: 'gift', manual: { by: 'root', payment: 'free', note: null } }),
  subscription: subscriptionRow(),
}

const PLANS = '/api/admin/plans'
const SERVERS = '/api/admin/plans/options'

/** The dialog open for the customer in the shop `session` shows, with `plans` to pick from — unless the read of `failing` fails. */
async function open(options: { session?: typeof AGENT_SHOP; traffic?: number; plans?: PlanRow[]; failing?: typeof PLANS | typeof SERVERS } = {}) {
  server()
    .on('GET', PLANS, json({ plans: options.plans ?? [GOLD] }))
    .on('GET', SERVERS, json(OPTIONS))
  if (options.failing) server().on('GET', options.failing, refusal(500, 'خطای سرور.'))
  const onClose = vi.fn()
  render(<ManualOrderDialog customer={CUSTOMER} traffic={options.traffic} onClose={onClose} />, { wrapper: signedIn({ session: options.session }).wrapper })
  await screen.findByRole('radiogroup', { name: 'نوع سرویس' })
  return { onClose }
}

/** Picks `option` of the select labelled `label`. */
async function pick(label: string, option: RegExp) {
  fireEvent.keyDown(await screen.findByRole('combobox', { name: label }), { key: 'Enter' })
  fireEvent.click(await screen.findByRole('option', { name: option }))
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

const make = () => fireEvent.click(screen.getByRole('button', { name: 'ثبت و تحویل سفارش' }))

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('an order of a plan', () => {
  it('picks the plan’s one server, previews it and sends it free under a key', async () => {
    server().on('POST', URL, json(MADE, 201))
    const { onClose } = await open()

    await pick('پلن', /طلایی/)

    expect(screen.getByRole('status').textContent).toContain('«طلایی»: ۳۰ گیگابایت · ۳۰ روز · ۱ کاربر · روی آلمان')
    expect(screen.getByRole('status').textContent).toContain('رایگان؛ هدیه است و خرید مشتری حساب نمی‌شود.')
    type(/یادداشت/, 'جبران قطعی')
    make()

    await until(() => expect(onClose).toHaveBeenCalled())
    const [sent] = server().sent('POST', URL)
    expect(sent?.body).toEqual({ plan_id: GOLD.id, server_id: 1, client_name: '', payment: 'free', note: 'جبران قطعی' })
    expect(sent?.headers['idempotency-key']).toMatch(/^[0-9a-f]{32}$/)
    expect(toast.success).toHaveBeenCalledWith(`سفارش برای ${userLabel(CUSTOMER)} ثبت و تحویل شد`)
  })

  it('offers a plan the owner keeps from an agent’s shop, not to pick', async () => {
    await open({ session: AGENT_SHOP, plans: [GOLD, planRow({ id: 9, name: 'پنهان', hidden_by_owner: true })] })

    fireEvent.keyDown(await screen.findByRole('combobox', { name: 'پلن' }), { key: 'Enter' })

    const hidden = await screen.findByRole('option', { name: /پنهان/ })
    expect(hidden.getAttribute('aria-disabled')).toBe('true')
    expect(hidden.textContent).toContain('پنهان‌شده توسط مدیر')
  })

  it('says before the form that a suspended agent’s shop takes no order', async () => {
    await open({ session: { ...AGENT_SHOP, shop: { ...AGENT_SHOP.shop, selling_suspended: 'manual' } } })

    expect(screen.getByText('فروش این فروشگاه متوقف است و تا دوباره باز نشود سفارشی در آن ثبت نمی‌شود.')).toBeTruthy()
  })

  it('says an order a retry found still on its way is on its way', async () => {
    vi.spyOn(toast, 'warning')
    server().on('POST', URL, json({ ...MADE, order: { ...MADE.order, status: 'processing' }, subscription: null }))
    const { onClose } = await open()

    await pick('پلن', /طلایی/)
    make()

    await until(() => expect(onClose).toHaveBeenCalled())
    expect(toast.warning).toHaveBeenCalledWith('سفارش ثبت شد و تحویلش در جریان است', { description: 'نتیجه را در صفحه سفارش‌ها ببینید.' })
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('asks for the plan and its server before anything is sent', async () => {
    await open()

    make()

    expect(await screen.findByText('یک پلن انتخاب کنید.')).toBeTruthy()
    expect(screen.getByText('سرور سرویس را انتخاب کنید.')).toBeTruthy()
    expect(server().sent('POST', URL)).toHaveLength(0)
  })
})

describe('an order of support’s own numbers', () => {
  it('sends the numbers, the server, the name and the wallet’s amount, as the preview says', async () => {
    server().on('POST', URL, json(MADE, 201))
    await open()

    fireEvent.click(screen.getByRole('radio', { name: 'سرویس دلخواه' }))
    type('حجم (گیگابایت)', '۵۰')
    type('مدت (روز)', '30')
    type('تعداد کاربر', '2')
    await pick('سرور', /آلمان/)
    type(/نام کاربری سرویس/, 'ali_vip')
    fireEvent.click(screen.getByRole('radio', { name: 'از کیف پول مشتری' }))
    type('مبلغ (تومان)', '۱۵۰٬۰۰۰')

    const preview = screen.getByRole('status').textContent
    expect(preview).toContain('سرویس دلخواه: ۵۰ گیگابایت · ۳۰ روز · ۲ کاربر · روی آلمان')
    expect(preview).toContain('ali_vip')
    expect(preview).toContain('۱۵۰٬۰۰۰ تومان از کیف پول مشتری کم می‌شود (موجودی ۲۰۰٬۰۰۰ تومان).')
    make()

    await until(() => expect(server().sent('POST', URL)).toHaveLength(1))
    expect(server().sent('POST', URL)[0]?.body).toEqual({ traffic_gb: '۵۰', days: '30', devices: '2', server_id: 1, client_name: 'ali_vip', payment: 'wallet', amount: '150000', note: '' })
  })

  it('offers a server that cannot take a service now with its reason, not to pick', async () => {
    await open()

    fireEvent.click(screen.getByRole('radio', { name: 'سرویس دلخواه' }))
    fireEvent.keyDown(await screen.findByRole('combobox', { name: 'سرور' }), { key: 'Enter' })

    const finland = await screen.findByRole('option', { name: /فنلاند/ })
    expect(finland.getAttribute('aria-disabled')).toBe('true')
    expect(finland.textContent).toContain('سرور خاموش است.')
  })

  it('says in an agent’s shop that the GB come out of the bot’s traffic', async () => {
    await open({ session: AGENT_SHOP, traffic: 100 * 1024 ** 3 })

    fireEvent.click(screen.getByRole('radio', { name: 'سرویس دلخواه' }))
    type('حجم (گیگابایت)', '20')
    type('مدت (روز)', '30')
    type('تعداد کاربر', '1')

    expect(screen.getByRole('status').textContent).toContain('۲۰ گیگابایت از حجم ربات این فروشگاه کم می‌شود (۱۰۰ گیگابایت مانده).')
  })
})

describe('a list that could not be read', () => {
  it('says the plans were not read over their picker, with a way to ask again', async () => {
    await open({ failing: PLANS })

    expect((await screen.findByRole('alert')).textContent).toContain('لیست پلن‌ها بارگذاری نشد.')

    server().on('GET', PLANS, json({ plans: [GOLD] }))
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    await pick('پلن', /طلایی/)
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('says the servers were not read where support’s own numbers pick one — not over a plan, which brings its own', async () => {
    await open({ failing: SERVERS })

    fireEvent.click(screen.getByRole('radio', { name: 'سرویس دلخواه' }))
    expect((await screen.findByRole('alert')).textContent).toContain('لیست سرورها بارگذاری نشد.')
    expect(screen.getByRole('button', { name: 'تلاش دوباره' })).toBeTruthy()

    fireEvent.click(screen.getByRole('radio', { name: 'پلن' }))
    expect(screen.queryByRole('alert')).toBeNull()
  })
})

describe('a refusal', () => {
  it('is said under its field, and the next try goes under a new key', async () => {
    const short = 'موجودی کیف پول مشتری ۲۰۰٬۰۰۰ تومان است و برای این مبلغ ۱۰۰٬۰۰۰ تومان کم دارد.'
    server().on('POST', URL, refusal(422, short, { amount: [short] }))
    const { onClose } = await open()

    await pick('پلن', /طلایی/)
    fireEvent.click(screen.getByRole('radio', { name: 'از کیف پول مشتری' }))
    type('مبلغ (تومان)', '300000')
    make()

    expect(await screen.findByText(short)).toBeTruthy()
    server().on('POST', URL, json(MADE, 201))
    type('مبلغ (تومان)', '200000')
    make()

    await until(() => expect(onClose).toHaveBeenCalled())
    const [first, second] = server().sent('POST', URL)
    expect(first?.headers['idempotency-key']).not.toBe(second?.headers['idempotency-key'])
  })
})
