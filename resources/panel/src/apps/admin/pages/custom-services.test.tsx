import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ADMIN_NAV } from '@/apps/admin/nav'
import { CustomServicesPage } from '@/apps/admin/pages/custom-services'
import { AGENT_NAV } from '@/apps/agent/nav'
import { isGroup, navOfShop, type PanelNav } from '@/components/shell/nav'
import type { BotSettingsData, BotSettingsSaved, Session } from '@/lib/api-types'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow, serverRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «سرویس دلخواه» (apps/admin/pages/custom-services): a page of its own under «فروشگاه», the main shop's alone. Its card
 * saves the `custom_services` group — the switch, a tier editor for the GB and one for the days, their bounds and
 * buttons, the devices and the servers ticked among the shop's (said while they are read, or why they could not be) —,
 * and a calculator prices what is typed from the tiers as they stand.
 */

const PUT = '/api/admin/bot/settings/custom_services'
const SWITCH = 'سرویس دلخواه فعال باشد'

/** The builder as the PHP tests' fixture sets it: a GB 3,000 (from 50: 2,500), a day 2,000 (from 30: 1,500), on server 1. */
const ON = botSettingsRow({
  custom_enabled: true,
  custom_traffic_tiers: [
    { from: 1, price: '3000.00' },
    { from: 50, price: '2500.00' },
  ],
  custom_days_tiers: [
    { from: 1, price: '2000.00' },
    { from: 30, price: '1500.00' },
  ],
  custom_servers: [1],
})

/** The page, signed in to the main shop (or `session`'s), its settings and the shop's two servers read. */
async function page(settings: BotSettingsData = botSettingsRow(), session?: Session) {
  server()
    .on('GET', '/api/admin/bot/settings', json(botSettingsResponse(settings)))
    .on('GET', '/api/admin/servers', json({ servers: [serverRow({ id: 1, name: 'آلمان' }), serverRow({ id: 2, name: 'هلند', unsellable_reason: 'سرور غیرفعال است.' })] }))
  render(<CustomServicesPage />, { wrapper: signedIn({ at: '/custom-services', session }).wrapper })
  await screen.findByRole('heading', { name: 'سرویس دلخواه' })
}

const part = (name: string) => screen.getByRole('group', { name })
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

describe('the custom builder’s page', () => {
  it('starts switched off and without tiers, and saves the group as typed', async () => {
    const saved: BotSettingsSaved = { settings: ON }
    server().on('PUT', PUT, json(saved))
    await page()

    const toggle = await screen.findByRole('switch', { name: SWITCH })
    expect(toggle.getAttribute('aria-checked')).toBe('false')
    expect(within(part('حجم')).getByText('بدون پله قیمت، سرویس دلخواه فروخته نمی‌شود.')).toBeTruthy()
    // The calculator says it cannot price without tiers; that is no field of the form to fix.
    expect(within(part('ماشین حساب قیمت')).getAllByText('هنوز پله قیمتی ندارد؛ بدون آن فروخته نمی‌شود.')).toHaveLength(2)
    expect(screen.queryByText(/فیلد نیاز به اصلاح دارد/)).toBeNull()

    fireEvent.click(toggle)
    const traffic = part('حجم')
    fireEvent.click(within(traffic).getByRole('button', { name: 'افزودن پله' }))
    fireEvent.change(within(traffic).getByRole('textbox', { name: 'پله ۱: از (گیگابایت)' }), { target: { value: '1' } })
    fireEvent.change(within(traffic).getByRole('textbox', { name: 'پله ۱: قیمت هر گیگابایت (تومان)' }), { target: { value: '3000' } })
    fireEvent.click(within(part('مدت')).getByRole('button', { name: 'افزودن پله' }))
    fireEvent.change(within(part('مدت')).getByRole('textbox', { name: 'پله ۱: از (روز)' }), { target: { value: '1' } })
    fireEvent.change(within(part('مدت')).getByRole('textbox', { name: 'پله ۱: قیمت هر روز (تومان)' }), { target: { value: '2000' } })
    fireEvent.click(await screen.findByRole('checkbox', { name: 'آلمان' }))
    save()

    await until(() => expect(server().sent('PUT', PUT)).toHaveLength(1))
    expect(server().sent('PUT', PUT)[0]?.body).toEqual({
      custom_enabled: true,
      custom_traffic_tiers: [{ from: '1', price: '3000' }],
      custom_traffic_min: '1',
      custom_traffic_max: '500',
      custom_traffic_options: '10, 20, 50, 100',
      custom_days_tiers: [{ from: '1', price: '2000' }],
      custom_days_min: '1',
      custom_days_max: '90',
      custom_days_options: '30, 60, 90',
      custom_devices: '2',
      custom_servers: [1],
    })
  })

  it('shows a refusal under its field', async () => {
    const refused = 'شروع هر پله قیمت هر گیگابایت باید بین 1 تا 10000 گیگابایت باشد.'
    server().on('PUT', PUT, refusal(422, refused, { custom_traffic_tiers: [refused] }))
    await page(ON)

    fireEvent.change(within(part('حجم')).getByRole('textbox', { name: 'پله ۲: از (گیگابایت)' }), { target: { value: '20000' } })
    save()

    expect(await within(part('حجم')).findByText(refused)).toBeTruthy()
  })

  it('ticks the builder’s servers among the shop’s, one that cannot sell now said so', async () => {
    await page(ON)

    expect((await screen.findByRole('checkbox', { name: 'آلمان' })).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByRole('checkbox', { name: /هلند/ }).getAttribute('aria-checked')).toBe('false')
    expect(screen.getByText('(الان نمی‌فروشد)')).toBeTruthy()
  })

  it('says while the shop’s servers are read, and why they could not be — never that there is nothing to pick', async () => {
    const servers = server().hold('GET', '/api/admin/servers')
    server().on('GET', '/api/admin/bot/settings', json(botSettingsResponse(ON)))
    render(<CustomServicesPage />, { wrapper: signedIn({ at: '/custom-services' }).wrapper })

    expect(await screen.findByText('در حال خواندن سرورها…')).toBeTruthy()
    expect(screen.queryByText('چیزی برای انتخاب نیست.')).toBeNull()

    servers.answer(refusal(403, 'دسترسی ندارید.'))
    expect(await screen.findByText('لیست سرورها خوانده نشد: دسترسی ندارید.')).toBeTruthy()
    expect(screen.queryByText('چیزی برای انتخاب نیست.')).toBeNull()
  })

  it('prices what is typed in its calculator from the tiers as they stand', async () => {
    await page(ON)
    const calculator = part('ماشین حساب قیمت')

    expect(within(calculator).getByText('۱۷۰٬۰۰۰ تومان')).toBeTruthy()
    expect(within(calculator).getByText('سرویس ۵۰ گیگابایتی ۳۰ روزه')).toBeTruthy()

    fireEvent.change(within(calculator).getByRole('textbox', { name: 'حجم (گیگابایت)' }), { target: { value: '49' } })
    expect(within(calculator).getByText(`۴۹ گیگابایت × ۳٬۰۰۰ تومان`)).toBeTruthy()
    expect(within(calculator).getByText('۱۹۲٬۰۰۰ تومان')).toBeTruthy()

    // Not saved yet: a tier typed now prices at once.
    fireEvent.change(within(part('حجم')).getByRole('textbox', { name: 'پله ۲: قیمت هر گیگابایت (تومان)' }), { target: { value: '2000' } })
    fireEvent.change(within(calculator).getByRole('textbox', { name: 'حجم (گیگابایت)' }), { target: { value: '100' } })
    expect(within(calculator).getByText('۲۴۵٬۰۰۰ تومان')).toBeTruthy()

    fireEvent.change(within(calculator).getByRole('textbox', { name: 'مدت (روز)' }), { target: { value: '91' } })
    expect(within(calculator).getByText('مشتری بین 1 تا 90 روز می‌سازد.')).toBeTruthy()
    expect(within(calculator).queryByRole('definition')).toBeNull()
  })

  it('is the main shop’s alone', async () => {
    await page(botSettingsRow(), AGENT_SHOP)

    expect(screen.getByText('سرویس دلخواه فقط در فروشگاه اصلی است؛ فروشگاه نماینده آن را ندارد.')).toBeTruthy()
    expect(screen.queryByRole('switch', { name: SWITCH })).toBeNull()
  })
})

/** The menu's addresses under «فروشگاه». */
function shopPages(nav: PanelNav): string[] {
  const shop = nav.menu.find((entry) => entry.id === 'shop')
  return shop && isGroup(shop) ? shop.items.map((item) => item.to) : []
}

describe('its menu entry', () => {
  it('stands under «فروشگاه» after the test accounts in the main shop, and in no agent’s shop', () => {
    expect(shopPages(navOfShop(ADMIN_NAV, true)).slice(1, 3)).toEqual(['/tests', '/custom-services'])
    expect(shopPages(navOfShop(ADMIN_NAV, false))).not.toContain('/custom-services')
    expect(shopPages(navOfShop(AGENT_NAV, false))).not.toContain('/custom-services')
    expect(shopPages(navOfShop(AGENT_NAV, false))).toContain('/tests')
  })
})
