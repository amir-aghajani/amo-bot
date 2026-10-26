import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { Creating } from '@/components/form-footer'
import { PlanForm } from '@/components/plans/plan-form'
import type { BotSettingsData, ExtraPrices, PlanOptions, PlanRow } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow, planRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * A plan's form (plan-form, server-entries, plan-extras): its four tabs and where a refusal lands, the servers section
 * when there is nothing to pick, the term in days or hours, the first-purchase and agent prices, the extras' price
 * list, the checkout note and the bot button — and `dirty` reading every field as the server would.
 */

const OPTIONS: PlanOptions = {
  categories: [],
  servers: [
    { id: 1, name: 'آلمان', is_active: true, serves_subscriptions: true, driver_label: '3X-UI', inbounds: [], unsellable_reason: null },
    { id: 2, name: 'هلند', is_active: false, serves_subscriptions: true, driver_label: '3X-UI', inbounds: [], unsellable_reason: 'سرور غیرفعال است.' },
  ],
}

/** A new plan's form, as its dialog opens it (EditorModal without a row: Creating). */
function showForm(options: PlanOptions | 'failing' = OPTIONS, addServers?: string) {
  server().on('GET', '/api/admin/plans/options', options === 'failing' ? refusal(500, 'خطای سرور.') : json(options))
  render(
    <Creating value>
      <PlanForm plan={null} addServers={addServers} onSaved={vi.fn()} onCancel={vi.fn()} />
    </Creating>,
    { wrapper: signedIn().wrapper },
  )
}

const panels = () => [...document.querySelectorAll('[role="tabpanel"]')]

async function openServers() {
  fireEvent.click(await screen.findByRole('tab', { name: /سرورها/ }))
}

describe('a plan’s form', () => {
  it('keeps every section, its drafts with it, but lays out only the one shown — each tab as long as its own', async () => {
    showForm()
    await screen.findByRole('tab', { name: /مشخصات/ })

    expect(panels()).toHaveLength(4)
    expect(panels().map((panel) => panel.hasAttribute('hidden'))).toEqual([false, true, true, true])

    fireEvent.change(screen.getByLabelText('نام پلن'), { target: { value: 'یک‌ماهه' } })
    await openServers()
    expect(panels().map((panel) => panel.hasAttribute('hidden'))).toEqual([true, true, false, true])

    fireEvent.click(screen.getByRole('tab', { name: /مشخصات/ }))
    expect(screen.getByLabelText<HTMLInputElement>('نام پلن').value).toBe('یک‌ماهه')
  })

  it('lists every refusal about its servers, on the section that holds them', async () => {
    const twice = '«آلمان» بیش از یک بار اضافه شده است؛ اینباندهایش را در یک ردیف جمع کنید.'
    const disabled = 'اینباند «Old» روی «هلند» غیرفعال است و قابل فروش نیست.'
    server().on('POST', '/api/admin/plans', refusal(422, twice, { servers: [twice, disabled] }))
    showForm()

    fireEvent.click(await screen.findByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByText(disabled)).toBeTruthy())
    expect(screen.getByText(twice)).toBeTruthy()
    expect(screen.getByRole('tab', { name: /سرورها/ }).getAttribute('aria-selected')).toBe('true')
    // The servers are one field to fix, and the first one: the focus is on them.
    expect(screen.getByText('یک فیلد نیاز به اصلاح دارد')).toBeTruthy()
    expect(document.activeElement).toBe(screen.getByRole('group', { name: 'سرورها' }))
  })

  it('counts its servers among the fields to fix, with the others', async () => {
    const none = 'پلن باید دست‌کم روی یک سرور فروخته شود.'
    server().on('POST', '/api/admin/plans', refusal(422, 'Refused.', { name: ['نام پلن را وارد کنید.'], price: ['قیمت را وارد کنید.'], servers: [none] }))
    showForm()

    fireEvent.click(await screen.findByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByText('۳ فیلد نیاز به اصلاح دارد')).toBeTruthy())
    await openServers()
    expect(screen.getByRole('group', { name: 'سرورها' }).getAttribute('aria-describedby')).toBeTruthy()
  })

  it('says the bot asks for a category first as soon as one is active', async () => {
    showForm({ ...OPTIONS, categories: [{ id: 1, name: 'ماهانه', is_active: true }] })

    expect(await screen.findByText(/تا وقتی دسته فعالی هست، مشتری در ربات اول دسته را انتخاب می‌کند/)).toBeTruthy()
  })
})

/** A server picked in the servers section's adder — its list closed, the focus back on its field. */
async function pick(name: string) {
  const field = await screen.findByRole('combobox', { name: 'سرور' })
  fireEvent.keyDown(field, { key: 'Enter' })
  fireEvent.click(await screen.findByRole('option', { name: new RegExp(name) }))
  await until(() => expect(document.activeElement).toBe(field))
}

describe('adding a server', () => {
  it('holds «افزودن به پلن» while there is nothing to add — never disabled —, so the button just pressed keeps the focus', async () => {
    showForm()
    await openServers()
    await pick('آلمان')
    const add = screen.getByRole('button', { name: 'افزودن به پلن' })

    add.focus()
    fireEvent.click(add)

    expect(await screen.findByText(/کل سرور ·/)).toBeTruthy()
    expect([add.hasAttribute('disabled'), add.getAttribute('aria-disabled')]).toEqual([false, 'true'])
    expect(document.activeElement).toBe(add)
  })

  it('that takes the last server leaves the focus on the word that every one is on the plan', async () => {
    showForm({ ...OPTIONS, servers: OPTIONS.servers.slice(0, 1) })
    await openServers()
    await pick('آلمان')
    const add = screen.getByRole('button', { name: 'افزودن به پلن' })

    add.focus()
    fireEvent.click(add)

    const word = await screen.findByText('همه سرورها به این پلن اضافه شده‌اند.')
    expect(document.activeElement).toBe(word)
  })
})

describe('a server’s inbounds', () => {
  const plan = planRow({ servers: [{ server: { id: 1, name: 'آلمان', is_active: true, serves_subscriptions: true }, all_inbounds: true, inbounds: [], unsellable_reason: null }] })

  it('are marked for sale on the server’s page, in the owner’s panel', async () => {
    server().on('GET', '/api/admin/plans/options', json(OPTIONS))
    render(<PlanForm plan={plan} addServers="/servers" onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: signedIn().wrapper })
    await openServers()

    expect(await screen.findByText(/در صفحه سرور اینباندها را برای فروش علامت بزنید/)).toBeTruthy()
  })

  it('are support’s to mark for sale in an agent’s panel, which has no server’s page', async () => {
    server().on('GET', '/api/admin/plans/options', json(OPTIONS))
    render(<PlanForm plan={plan} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: signedIn().wrapper })
    await openServers()

    expect(await screen.findByText(/از پشتیبانی بخواهید اینباندهایش را برای فروش علامت بزند/)).toBeTruthy()
    expect(screen.queryByText(/صفحه سرور/)).toBeNull()
  })
})

describe('its servers section', () => {
  it('says the servers were not read, with a way to ask again', async () => {
    showForm('failing')
    await openServers()

    expect(await screen.findByText('سرورها بارگذاری نشد.')).toBeTruthy()

    server().on('GET', '/api/admin/plans/options', json(OPTIONS))
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    expect(await screen.findByRole('combobox', { name: 'سرور' })).toBeTruthy()
  })

  it('sends the owner to add a server while there is none', async () => {
    showForm({ categories: [], servers: [] }, '/servers')
    await openServers()

    const link = await screen.findByRole('link', { name: 'اول یک سرور اضافه کنید' })
    expect(link.getAttribute('href')).toBe('/servers')
  })

  it('tells an agent whom to ask, their panel adding no server', async () => {
    showForm({ categories: [], servers: [] })
    await openServers()

    expect(await screen.findByText(/از پشتیبانی بخواهید سروری اضافه کند/)).toBeTruthy()
    expect(within(screen.getByRole('group', { name: 'سرورها' })).queryByRole('link')).toBeNull()
  })

  it('says why an entry’s server cannot sell, in one sentence', async () => {
    server().on('GET', '/api/admin/plans/options', json(OPTIONS))
    const plan = planRow({ servers: [{ server: { id: 2, name: 'هلند', is_active: false, serves_subscriptions: true }, all_inbounds: true, inbounds: [], unsellable_reason: 'سرور غیرفعال است.' }] })
    render(<PlanForm plan={plan} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: signedIn().wrapper })
    await openServers()

    expect(await screen.findByText('سرور غیرفعال است. تا درست نشود، مشتری این سرور را نمی‌بیند.')).toBeTruthy()
  })
})

/** A plan's own prices of its extras: 3٬000 a GB, 10 GB offered at that; no time nor devices sold. */
const OWN: ExtraPrices = {
  traffic_price: '3000.00',
  traffic_options: [{ quantity: 10, price: null }],
  traffic_min: 1,
  traffic_max: 100,
  time_price: null,
  time_options: [],
  time_min: 1,
  time_max: 30,
  devices_price: null,
  devices_options: [],
  devices_min: 1,
  devices_max: 5,
}

/** The form on `plan` (null: a new one, as its dialog opens it), the shop's extras switched on — or off —, its price list `shop`. */
function showPlan(plan: PlanRow | null, extrasEnabled = true, shop: Partial<BotSettingsData> = {}) {
  server()
    .on('GET', '/api/admin/plans/options', json(OPTIONS))
    .on('GET', '/api/admin/bot/settings', json(botSettingsResponse(botSettingsRow({ ...shop, extras_enabled: extrasEnabled }))))
  render(
    <Creating value={plan === null}>
      <PlanForm plan={plan} onSaved={vi.fn()} onCancel={vi.fn()} />
    </Creating>,
    { wrapper: signedIn().wrapper },
  )
}

async function openExtras() {
  fireEvent.click(await screen.findByRole('tab', { name: /خرید اضافه/ }))
}

/** Where the plan's extras are priced, picked. */
function pricing(name: 'قیمت‌های فروشگاه' | 'قیمت‌های این پلن') {
  fireEvent.click(screen.getByRole('radio', { name }))
}

/** The extras the first save to `method path` sent. */
function sentExtras(method: string, path: string): unknown {
  return (server().sent(method, path)[0]?.body as { extras?: unknown } | undefined)?.extras
}

describe('its extra traffic and time', () => {
  it('sell at the shop’s prices for a plan without its own — sent as none —, and say when the shop sells none at all', async () => {
    server().on('POST', '/api/admin/plans', json({ plan: planRow() }))
    showPlan(null, false)
    await openExtras()

    expect(screen.getByRole('radio', { name: 'قیمت‌های فروشگاه' }).getAttribute('aria-checked')).toBe('true')
    expect(await screen.findByText(/خاموش است؛ تا روشن نشود، مشتری‌ها هیچ‌کدام را نمی‌خرند/)).toBeTruthy()
    expect(screen.queryByLabelText('قیمت هر گیگابایت (تومان)')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans')).toHaveLength(1))
    expect(sentExtras('POST', '/api/admin/plans')).toBeNull()
  })

  it('at the plan’s own prices start from nothing priced, and are sent as typed', async () => {
    server().on('POST', '/api/admin/plans', json({ plan: planRow() }))
    showPlan(null)
    await openExtras()

    pricing('قیمت‌های این پلن')
    expect(screen.getByText('با این قیمت‌ها حجم اضافه فروخته نمی‌شود.')).toBeTruthy()
    fireEvent.change(screen.getByLabelText('قیمت هر روز (تومان)'), { target: { value: '2000' } })
    fireEvent.change(screen.getByLabelText('قیمت هر کاربر برای ۳۰ روز (تومان)'), { target: { value: '30000' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans')).toHaveLength(1))
    expect(sentExtras('POST', '/api/admin/plans')).toEqual({
      traffic_price: '',
      traffic_options: [],
      traffic_min: '1',
      traffic_max: '100',
      time_price: '2000',
      time_options: [],
      time_min: '1',
      time_max: '30',
      devices_price: '30000',
      devices_options: [],
      devices_min: '1',
      devices_max: '5',
    })
  })

  it('at the plan’s own prices start within the shop’s bounds once those are read — still nothing priced', async () => {
    showPlan(null, false, { extra_traffic_price: '3000.00', extra_traffic_options: [{ quantity: 10, price: null }], extra_traffic_min: 5, extra_traffic_max: 500, extra_time_max: 90 })
    await openExtras()
    await screen.findByText(/خاموش است؛ تا روشن نشود/)

    pricing('قیمت‌های این پلن')

    expect(screen.getByLabelText<HTMLInputElement>('قیمت هر گیگابایت (تومان)').value).toBe('')
    expect(screen.queryByLabelText('حجم پیشنهادی ۱ (گیگابایت)')).toBeNull()
    expect(screen.getByLabelText<HTMLInputElement>('کمترین (گیگابایت)').value).toBe('5')
    expect(screen.getByLabelText<HTMLInputElement>('بیشترین (گیگابایت)').value).toBe('500')
    expect(screen.getByLabelText<HTMLInputElement>('بیشترین (روز)').value).toBe('90')
  })

  it('of a plan with its own start from them, kept while the shop’s are chosen — which are then sent', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow({ extras: OWN }))
    await openExtras()

    expect(screen.getByRole('radio', { name: 'قیمت‌های این پلن' }).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByLabelText<HTMLInputElement>('قیمت هر گیگابایت (تومان)').value).toBe('3000')

    pricing('قیمت‌های فروشگاه')
    expect(screen.queryByLabelText('قیمت هر گیگابایت (تومان)')).toBeNull()
    pricing('قیمت‌های این پلن')
    expect(screen.getByLabelText<HTMLInputElement>('حجم پیشنهادی ۱ (گیگابایت)').value).toBe('10')

    pricing('قیمت‌های فروشگاه')
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentExtras('PUT', '/api/admin/plans/3')).toBeNull()
  })

  it('changed only by what the server would keep: a blank row, or the shop’s prices chosen and the plan’s again, is none', async () => {
    showPlan(planRow({ extras: OWN }))
    await openExtras()
    const save = screen.getByRole('button', { name: 'ذخیره تغییرات' })

    fireEvent.click(within(screen.getByRole('group', { name: 'زمان اضافه' })).getByRole('button', { name: 'افزودن مقدار' }))
    expect(save.getAttribute('aria-disabled')).toBe('true')
    pricing('قیمت‌های فروشگاه')
    expect(save.getAttribute('aria-disabled')).toBeNull()
    pricing('قیمت‌های این پلن')
    expect(save.getAttribute('aria-disabled')).toBe('true')

    fireEvent.change(screen.getByLabelText('قیمت هر گیگابایت (تومان)'), { target: { value: '۳٬۰۰۰' } })
    expect(save.getAttribute('aria-disabled')).toBe('true')
    fireEvent.change(screen.getByLabelText('قیمت هر گیگابایت (تومان)'), { target: { value: '3500' } })
    expect(save.getAttribute('aria-disabled')).toBeNull()
  })

  it('refused, open their section — the field the refusal names marked and focused —, which goes as it is typed', async () => {
    const tooMuch = 'قیمت هر گیگابایت باید مبلغی بین ۱ تومان تا ۱۰۰٬۰۰۰٬۰۰۰ تومان باشد، یا خالی بماند.'
    server().on('POST', '/api/admin/plans', refusal(422, tooMuch, { 'extras.traffic_price': [tooMuch] }))
    showPlan(null)
    await openExtras()
    pricing('قیمت‌های این پلن')
    fireEvent.change(screen.getByLabelText('قیمت هر گیگابایت (تومان)'), { target: { value: '999999999999' } })
    fireEvent.click(screen.getByRole('tab', { name: /مشخصات/ }))

    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByRole('tab', { name: /خرید اضافه/ }).getAttribute('aria-selected')).toBe('true'))
    const price = screen.getByLabelText('قیمت هر گیگابایت (تومان)')
    expect(screen.getByRole('tab', { name: /خرید اضافه.*دارای خطا/ })).toBeTruthy()
    expect(price.getAttribute('aria-invalid')).toBe('true')
    expect(screen.getByText(tooMuch)).toBeTruthy()
    await until(() => expect(document.activeElement).toBe(price))

    fireEvent.change(price, { target: { value: '5000' } })
    expect(screen.queryByText(tooMuch)).toBeNull()
    expect(screen.queryByRole('tab', { name: /دارای خطا/ })).toBeNull()
  })

  it('refused, mark their tab only while the plan’s own prices are on the form', async () => {
    const twice = '۱۰ گیگابایت دو بار آمده است.'
    server().on('PUT', '/api/admin/plans/3', refusal(422, twice, { 'extras.traffic_options': [twice] }))
    showPlan(planRow({ extras: OWN }))
    await openExtras()
    fireEvent.change(screen.getByLabelText('قیمت هر گیگابایت (تومان)'), { target: { value: '3500' } })

    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(screen.getByRole('tab', { name: /خرید اضافه.*دارای خطا/ })).toBeTruthy())
    pricing('قیمت‌های فروشگاه')
    expect(screen.queryByRole('tab', { name: /دارای خطا/ })).toBeNull()
    pricing('قیمت‌های این پلن')
    expect(screen.getByRole('tab', { name: /خرید اضافه.*دارای خطا/ })).toBeTruthy()
    expect(screen.getByRole('group', { name: 'حجم‌های پیشنهادی' }).hasAttribute('data-invalid')).toBe(true)
  })
})

async function openQuota() {
  fireEvent.click(await screen.findByRole('tab', { name: /قیمت و سهمیه/ }))
}

/** The term's amount field. */
const termField = () => screen.getByLabelText<HTMLInputElement>('مدت اعتبار')

/** The term as the form shows it: its amount, and the unit picked. */
function shownTerm(): [string, string | null] {
  const unit = within(screen.getByRole('radiogroup', { name: 'واحد مدت اعتبار' })).getByRole('radio', { checked: true })
  return [termField().value, unit.textContent]
}

/** The term's unit picked. */
function pickUnit(name: 'روز' | 'ساعت') {
  fireEvent.click(screen.getByRole('radio', { name }))
}

/** The body the first save to `method path` sent. */
function sentPlan(method: string, path: string): Record<string, unknown> | undefined {
  return server().sent(method, path)[0]?.body as Record<string, unknown> | undefined
}

describe('its term', () => {
  it('is typed in days to start with, and goes in hours — never as days', async () => {
    server().on('POST', '/api/admin/plans', json({ plan: planRow() }))
    showForm()
    await openQuota()

    expect(shownTerm()).toEqual(['30', 'روز'])
    expect(screen.getByText('0 = نامحدود؛ روزها از اولین اتصال مشتری شمرده می‌شوند.')).toBeTruthy()
    fireEvent.change(termField(), { target: { value: '۳' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans')).toHaveLength(1))
    expect(sentPlan('POST', '/api/admin/plans')?.duration_hours).toBe('72')
    expect(sentPlan('POST', '/api/admin/plans')).not.toHaveProperty('duration_days')
  })

  it('goes as the hours typed for an hourly plan — a one-hour test —, counted from the first connection too', async () => {
    server().on('POST', '/api/admin/plans', json({ plan: planRow() }))
    showForm()
    await openQuota()

    pickUnit('ساعت')
    fireEvent.change(termField(), { target: { value: '1' } })
    expect(screen.getByText('0 = نامحدود؛ ساعت‌ها از اولین اتصال مشتری شمرده می‌شوند.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans')).toHaveLength(1))
    expect(sentPlan('POST', '/api/admin/plans')?.duration_hours).toBe('1')
  })

  it.each([
    ['a daily plan', 30 * 24, ['30', 'روز']],
    ['an hourly plan', 6, ['6', 'ساعت']],
    ['a plan of a day and some hours', 30, ['30', 'ساعت']],
    ['a plan that never ends', 0, ['0', 'روز']],
  ] as const)('of %s opens in the unit it was made in', async (_plan, hours, shown) => {
    showPlan(planRow({ duration_hours: hours }))
    await openQuota()

    expect(shownTerm()).toEqual(shown)
  })

  it('is measured in hours: «۱ روز» and «۲۴ ساعت» are no change, and a saved hourly plan goes in its hours', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow({ duration_hours: 24 }))
    await openQuota()
    const save = screen.getByRole('button', { name: 'ذخیره تغییرات' })
    expect(shownTerm()).toEqual(['1', 'روز'])

    pickUnit('ساعت')
    expect(save.getAttribute('aria-disabled')).toBeNull()
    fireEvent.change(termField(), { target: { value: '۲۴' } })
    expect(save.getAttribute('aria-disabled')).toBe('true')

    fireEvent.change(termField(), { target: { value: '12' } })
    fireEvent.click(save)

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentPlan('PUT', '/api/admin/plans/3')?.duration_hours).toBe('12')
  })

  it('refused, opens its section — its field marked, focused and saying why, once —, which a change of its unit lets go of', async () => {
    const tooLong = 'مدت اعتبار باید عددی بین 0 (بدون محدودیت) تا 87600 ساعت باشد.'
    server().on('POST', '/api/admin/plans', refusal(422, tooLong, { duration_hours: [tooLong] }))
    showForm()
    await openQuota()
    fireEvent.change(termField(), { target: { value: '5000' } })
    fireEvent.click(screen.getByRole('tab', { name: /مشخصات/ }))

    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByRole('tab', { name: /قیمت و سهمیه/ }).getAttribute('aria-selected')).toBe('true'))
    expect(screen.getByRole('tab', { name: /قیمت و سهمیه.*دارای خطا/ })).toBeTruthy()
    expect(termField().getAttribute('aria-invalid')).toBe('true')
    expect(document.getElementById(termField().getAttribute('aria-describedby') ?? '')?.textContent).toBe(tooLong)
    expect(screen.getAllByText(tooLong)).toHaveLength(1)
    await until(() => expect(document.activeElement).toBe(termField()))

    pickUnit('ساعت')
    expect(termField().getAttribute('aria-invalid')).toBe('false')
    expect(screen.queryByText(tooLong)).toBeNull()
    expect(screen.queryByRole('tab', { name: /دارای خطا/ })).toBeNull()
  })
})

/** The line under a field: its hint (no refusal on it). */
const hintOf = (field: HTMLElement) => document.getElementById(`${field.id}-hint`)?.textContent

describe('its price', () => {
  it('is measured by its digits: typed again with its thousands set apart, it is no change', async () => {
    showPlan(planRow())
    await openQuota()
    const price = screen.getByLabelText('قیمت (تومان)')
    const save = screen.getByRole('button', { name: 'ذخیره تغییرات' })

    fireEvent.change(price, { target: { value: '۱۲۰٬۰۰۰' } })
    expect(save.getAttribute('aria-disabled')).toBe('true')
    fireEvent.change(price, { target: { value: '125000' } })
    expect(save.getAttribute('aria-disabled')).toBeNull()
  })

  it('is typed on a phone’s number pad, as its first-purchase and agent prices are: whole Toman, no decimal point', async () => {
    showPlan(planRow())
    await openQuota()

    expect([screen.getByLabelText('قیمت (تومان)'), firstPurchaseField(), agentPriceField()].map((field) => field.getAttribute('inputmode'))).toEqual(['numeric', 'numeric', 'numeric'])
  })

  it('says what the amount typed comes to, as its first-purchase and agent prices do, with what each is for', async () => {
    showPlan(planRow())
    await openQuota()

    const price = screen.getByLabelText('قیمت (تومان)')
    expect(hintOf(price)).toBe('= ۱۲۰٬۰۰۰ تومان؛ قیمت عادی پلن، برای هر خرید و تمدید.')
    fireEvent.change(price, { target: { value: '0' } })
    expect(hintOf(price)).toBe('رایگان، برای هر خرید و تمدید.')

    fireEvent.change(firstPurchaseField(), { target: { value: '۹۰۰۰۰' } })
    expect(hintOf(firstPurchaseField())).toMatch(/^= ۹۰٬۰۰۰ تومان؛ مشتری‌ای که هنوز سرویسی از فروشگاه نخریده/)
    fireEvent.change(agentPriceField(), { target: { value: '100,000' } })
    expect(hintOf(agentPriceField())).toMatch(/^= ۱۰۰٬۰۰۰ تومان؛ نماینده‌ها و مشتری‌های گروه‌های همکار/)
    fireEvent.change(agentPriceField(), { target: { value: '' } })
    expect(hintOf(agentPriceField())).toMatch(/خالی یعنی ندارد\.$/)
  })
})

/* Optional: its label carries «(اختیاری)» after the name. */
const FIRST_PURCHASE = /^قیمت خرید اول/

/** The first-purchase price's field. */
const firstPurchaseField = () => screen.getByLabelText<HTMLInputElement>(FIRST_PURCHASE)

describe('its first-purchase price', () => {
  it('is blank for a new plan — none —, beside its price, says what it is, and goes as none', async () => {
    server().on('POST', '/api/admin/plans', json({ plan: planRow() }))
    showForm()
    await openQuota()
    const quota = within(screen.getByRole('tabpanel', { name: /قیمت و سهمیه/ }))

    expect(quota.getByLabelText<HTMLInputElement>(FIRST_PURCHASE).value).toBe('')
    expect(quota.getByLabelText('قیمت (تومان)')).toBeTruthy()
    expect(screen.getByText(/مشتری‌ای که هنوز سرویسی از فروشگاه نخریده، اولین بار این پلن را با این قیمت می‌خرد/)).toBeTruthy()
    expect(screen.getByText(/0 = خرید اول رایگان/)).toBeTruthy()
    expect(screen.getByText(/تمدید همیشه با قیمت عادی است/)).toBeTruthy()
    fireEvent.change(firstPurchaseField(), { target: { value: '   ' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans')).toHaveLength(1))
    expect(sentPlan('POST', '/api/admin/plans')).toHaveProperty('first_purchase_price', null)
  })

  it.each([
    ['a price', '50000.00', '50000'],
    ['a free first purchase', '0.00', '0'],
    ['none', null, ''],
  ] as const)('of a plan with %s opens as the form shows amounts', async (_price, kept, shown) => {
    showPlan(planRow({ first_purchase_price: kept }))
    await openQuota()

    expect(firstPurchaseField().value).toBe(shown)
  })

  it('typed, goes as typed — Persian digits too', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow())
    await openQuota()

    fireEvent.change(firstPurchaseField(), { target: { value: '۹۰۰۰۰' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentPlan('PUT', '/api/admin/plans/3')).toHaveProperty('first_purchase_price', '۹۰۰۰۰')
  })

  it('emptied, goes as none: everyone pays the plan’s price', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow({ first_purchase_price: '0.00' }))
    await openQuota()

    fireEvent.change(firstPurchaseField(), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentPlan('PUT', '/api/admin/plans/3')).toHaveProperty('first_purchase_price', null)
  })

  it('of a plan without one is no change left blank — spaces too —, but 0, a free first purchase, is one', async () => {
    showPlan(planRow())
    await openQuota()
    const save = screen.getByRole('button', { name: 'ذخیره تغییرات' })
    const unchanged = () => expect(save.getAttribute('aria-disabled')).toBe('true')
    const changed = () => expect(save.getAttribute('aria-disabled')).toBeNull()

    unchanged()
    fireEvent.change(firstPurchaseField(), { target: { value: '  ' } })
    unchanged()
    fireEvent.change(firstPurchaseField(), { target: { value: '0' } })
    changed()
    fireEvent.change(firstPurchaseField(), { target: { value: '' } })
    unchanged()
  })

  it('kept, is no change typed again in Persian digits or with its thousands set apart — another price, or none, is', async () => {
    showPlan(planRow({ first_purchase_price: '50000.00' }))
    await openQuota()
    const save = screen.getByRole('button', { name: 'ذخیره تغییرات' })

    for (const same of ['۵۰۰۰۰', '۵۰٬۰۰۰', '50,000', '50000']) {
      fireEvent.change(firstPurchaseField(), { target: { value: same } })
      expect(save.getAttribute('aria-disabled')).toBe('true')
    }
    fireEvent.change(firstPurchaseField(), { target: { value: '45000' } })
    expect(save.getAttribute('aria-disabled')).toBeNull()
    fireEvent.change(firstPurchaseField(), { target: { value: '' } })
    expect(save.getAttribute('aria-disabled')).toBeNull()
  })

  it('refused, opens its section — its field marked, focused and saying why —, which a change lets go of', async () => {
    const notBelow = 'قیمت خرید اول باید کمتر از قیمت پلن باشد.'
    server().on('POST', '/api/admin/plans', refusal(422, notBelow, { first_purchase_price: [notBelow] }))
    showForm()
    await openQuota()
    fireEvent.change(firstPurchaseField(), { target: { value: '200000' } })
    fireEvent.click(screen.getByRole('tab', { name: /مشخصات/ }))

    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByRole('tab', { name: /قیمت و سهمیه/ }).getAttribute('aria-selected')).toBe('true'))
    expect(screen.getByRole('tab', { name: /قیمت و سهمیه.*دارای خطا/ })).toBeTruthy()
    expect(firstPurchaseField().getAttribute('aria-invalid')).toBe('true')
    expect(document.getElementById(firstPurchaseField().getAttribute('aria-describedby') ?? '')?.textContent).toBe(notBelow)
    await until(() => expect(document.activeElement).toBe(firstPurchaseField()))

    fireEvent.change(firstPurchaseField(), { target: { value: '90000' } })
    expect(firstPurchaseField().getAttribute('aria-invalid')).toBe('false')
    expect(screen.queryByText(notBelow)).toBeNull()
    expect(screen.queryByRole('tab', { name: /دارای خطا/ })).toBeNull()
  })
})

/* Optional: its label carries «(اختیاری)» after the name. */
const AGENT_PRICE = /^قیمت نماینده/

/** The agent price's field. */
const agentPriceField = () => screen.getByLabelText<HTMLInputElement>(AGENT_PRICE)

describe('its agent price', () => {
  it('is blank for a new plan — none —, beside its other prices, says who pays it, and goes as none', async () => {
    server().on('POST', '/api/admin/plans', json({ plan: planRow() }))
    showForm()
    await openQuota()
    const quota = within(screen.getByRole('tabpanel', { name: /قیمت و سهمیه/ }))

    expect(quota.getByLabelText<HTMLInputElement>(AGENT_PRICE).value).toBe('')
    expect(screen.getByText('نماینده‌ها و مشتری‌های گروه‌های همکار این قیمت را می‌پردازند، در خرید و تمدید؛ باید کمتر از قیمت باشد. خالی یعنی ندارد.')).toBeTruthy()
    fireEvent.change(agentPriceField(), { target: { value: '  ' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans')).toHaveLength(1))
    expect(sentPlan('POST', '/api/admin/plans')).toHaveProperty('agent_price', null)
  })

  it.each([
    ['a price', '90000.00', '90000'],
    ['none', null, ''],
  ] as const)('of a plan with %s opens as the form shows amounts', async (_price, kept, shown) => {
    showPlan(planRow({ agent_price: kept }))
    await openQuota()

    expect(agentPriceField().value).toBe(shown)
  })

  it('typed, goes as typed — Persian digits too', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow())
    await openQuota()

    fireEvent.change(agentPriceField(), { target: { value: '۹۰۰۰۰' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentPlan('PUT', '/api/admin/plans/3')).toHaveProperty('agent_price', '۹۰۰۰۰')
  })

  it('kept, emptied goes as none: agents pay the plan’s price', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow({ agent_price: '90000.00' }))
    await openQuota()

    fireEvent.change(agentPriceField(), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentPlan('PUT', '/api/admin/plans/3')).toHaveProperty('agent_price', null)
  })

  it('kept, is no change typed again with its thousands set apart — another price is', async () => {
    showPlan(planRow({ agent_price: '90000.00' }))
    await openQuota()
    const save = screen.getByRole('button', { name: 'ذخیره تغییرات' })

    fireEvent.change(agentPriceField(), { target: { value: '۹۰٬۰۰۰' } })
    expect(save.getAttribute('aria-disabled')).toBe('true')
    fireEvent.change(agentPriceField(), { target: { value: '85000' } })
    expect(save.getAttribute('aria-disabled')).toBeNull()
  })

  it('refused, opens its section — its field marked, focused and saying why —, which a change lets go of', async () => {
    const notBelow = 'قیمت نماینده باید کمتر از قیمت پلن باشد.'
    server().on('POST', '/api/admin/plans', refusal(422, notBelow, { agent_price: [notBelow] }))
    showForm()
    await openQuota()
    fireEvent.change(agentPriceField(), { target: { value: '200000' } })
    fireEvent.click(screen.getByRole('tab', { name: /مشخصات/ }))

    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByRole('tab', { name: /قیمت و سهمیه/ }).getAttribute('aria-selected')).toBe('true'))
    expect(agentPriceField().getAttribute('aria-invalid')).toBe('true')
    expect(document.getElementById(agentPriceField().getAttribute('aria-describedby') ?? '')?.textContent).toBe(notBelow)
    await until(() => expect(document.activeElement).toBe(agentPriceField()))

    fireEvent.change(agentPriceField(), { target: { value: '90000' } })
    expect(screen.queryByText(notBelow)).toBeNull()
    expect(screen.queryByRole('tab', { name: /دارای خطا/ })).toBeNull()
  })
})

/* Optional: its label carries «(اختیاری)» after the name. */
const checkoutNoteField = () => screen.getByLabelText<HTMLTextAreaElement>(/^یادداشت پیش‌فاکتور/)

describe('its checkout note', () => {
  it('is held to what the server keeps, counted as it is typed, and goes as typed', async () => {
    server().on('POST', '/api/admin/plans', json({ plan: planRow() }))
    showForm()
    await screen.findByRole('tab', { name: /مشخصات/ })

    expect(checkoutNoteField().maxLength).toBe(500)
    expect(screen.getByText('زیر پیش‌فاکتور خرید و تمدید این پلن در ربات نشان داده می‌شود.')).toBeTruthy()
    fireEvent.change(checkoutNoteField(), { target: { value: 'تحویل فوری' } })
    expect(screen.getByText('۱۰ از ۵۰۰ کاراکتر')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans')).toHaveLength(1))
    expect(sentPlan('POST', '/api/admin/plans')).toHaveProperty('checkout_note', 'تحویل فوری')
  })

  it('kept, opens with the plan, and emptied — spaces too — goes as none', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow({ checkout_note: 'تحویل فوری' }))
    await screen.findByRole('tab', { name: /مشخصات/ })

    expect(checkoutNoteField().value).toBe('تحویل فوری')
    fireEvent.change(checkoutNoteField(), { target: { value: '   ' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentPlan('PUT', '/api/admin/plans/3')).toHaveProperty('checkout_note', null)
  })
})

/** The colour picked for the plan's button, and its width, as the radios read. */
const pickedColour = () => within(screen.getByRole('radiogroup', { name: 'رنگ' })).getByRole('radio', { checked: true }).textContent
const pickedWidth = () => within(screen.getByRole('radiogroup', { name: 'پهنای دکمه' })).getByRole('radio', { checked: true }).textContent

describe('its button in the bot', () => {
  it('of a new plan is the client’s own look in a row of its own — sent as none and 1', async () => {
    server().on('POST', '/api/admin/plans', json({ plan: planRow() }))
    showForm()
    await screen.findByRole('tab', { name: /مشخصات/ })

    expect(pickedColour()).toMatch(/^پیش‌فرض/)
    expect(pickedWidth()).toBe('یک ردیف کامل')
    expect(screen.getByText('دکمه پلن‌های پشت سر هم با پهنای یکسان، در یک ردیف کنار هم می‌آیند.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans')).toHaveLength(1))
    expect(sentPlan('POST', '/api/admin/plans')).toMatchObject({ button_style: null, button_row: 1 })
  })

  it('takes one of Telegram’s colours and a width, sent as they are', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow())
    await screen.findByRole('tab', { name: /مشخصات/ })

    fireEvent.click(screen.getByRole('radio', { name: /سبز/ }))
    fireEvent.click(screen.getByRole('radio', { name: 'دو در ردیف' }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentPlan('PUT', '/api/admin/plans/3')).toMatchObject({ button_style: 'success', button_row: 2 })
  })

  it('kept, opens as it is, and the client’s own look picked again goes as none', async () => {
    server().on('PUT', '/api/admin/plans/3', json({ plan: planRow() }))
    showPlan(planRow({ button_style: 'danger', button_row: 3 }))
    await screen.findByRole('tab', { name: /مشخصات/ })

    expect(pickedColour()).toMatch(/قرمز/)
    expect(pickedWidth()).toBe('سه در ردیف')
    fireEvent.click(screen.getByRole('radio', { name: /^پیش‌فرض/ }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/plans/3')).toHaveLength(1))
    expect(sentPlan('PUT', '/api/admin/plans/3')).toMatchObject({ button_style: null, button_row: 3 })
  })

  it('refused, opens its section and marks its group, saying why under it', async () => {
    const tooWide = 'تعداد دکمه در هر ردیف باید ۱ تا ۳ باشد.'
    server().on('POST', '/api/admin/plans', refusal(422, tooWide, { button_row: [tooWide] }))
    showForm()
    await openQuota()

    fireEvent.click(screen.getByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByRole('tab', { name: /مشخصات/ }).getAttribute('aria-selected')).toBe('true'))
    const group = screen.getByRole('group', { name: 'دکمه در ربات' })
    expect(group.hasAttribute('data-invalid')).toBe(true)
    expect(document.getElementById(group.getAttribute('aria-describedby') ?? '')?.textContent).toBe(tooWide)
  })
})

describe('a saved plan opened again', () => {
  it('is no change, whatever it holds — its agent price, its checkout note, its button', async () => {
    showPlan(planRow({ first_purchase_price: '0.00', agent_price: '90000.00', checkout_note: 'تحویل فوری', button_style: 'primary', button_row: 2, extras: OWN }))
    await screen.findByRole('tab', { name: /مشخصات/ })

    expect(screen.getByRole('button', { name: 'ذخیره تغییرات' }).getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByRole('button', { name: 'بازگردانی تغییرات' }).getAttribute('aria-disabled')).toBe('true')
  })
})
