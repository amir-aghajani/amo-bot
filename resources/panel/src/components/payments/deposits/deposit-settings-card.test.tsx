import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { DepositSettings } from '@/components/payments/deposits/deposit-settings-card'
import type { DepositSettingsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { depositSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «تایید خودکار واریز» on the payment methods page (components/payments/deposits): its switch saved as
 * `PUT /deposits/settings`; once on, ForApp's steps — its download opening in a new tab —, the address and the key to
 * paste in it, each copied whole (the key hidden until asked); a new key asked first, then made; and whether ForApp's
 * messages have been coming — no warning beside Rikhtam —, with the week's deposits that approved nothing linked by the
 * switch; the settings read again only while ForApp's first message is awaited. Rikhtam, on or off: its steps and its key
 * sent (switching the approval on, the focus then on the gateway, the payments read again), a key refused under its
 * field, the gateway once connected with the shop's address there, registered again, and disconnected once asked (the
 * focus then on the key's field).
 */

const SETTINGS = '/api/admin/deposits/settings'
const KEY = '/api/admin/deposits/settings/key'
const OFF = depositSettingsRow({ enabled: false, key: null, last_test_at: null })
const ADDRESS = 'https://shop.example/webhooks/rikhtam/1'
/** Rikhtam connected, its gateway made for shop.example. */
const CONNECTED = { connected: true, domain: 'shop.example', project_id: 12, callback_url: ADDRESS, connected_at: '2026-10-06T08:55:00Z' }

function show(settings: DepositSettingsResponse) {
  server().on('GET', SETTINGS, json(settings))
  const { client, wrapper } = providers()
  render(<DepositSettings />, { wrapper })
  return client
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the switch', () => {
  it('is saved on its own, and once on the steps, the address and the key show, each with its copy button', async () => {
    show(OFF)
    server().on('PUT', SETTINGS, json(depositSettingsRow()))

    fireEvent.click(await screen.findByRole('switch', { name: 'تایید خودکار واریزها روشن باشد' }))
    expect(screen.queryByRole('button', { name: 'کپی کلید API' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', SETTINGS)[0]?.body).toEqual({ enabled: true }))
    expect(await screen.findByRole('button', { name: 'کپی آدرس وب‌هوک' })).toBeTruthy()
    expect(screen.getByText('https://shop.example/webhooks/deposits/forapp/1')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'کپی کلید API' })).toBeTruthy()
    expect(toast.success).toHaveBeenCalledWith('تنظیمات تایید خودکار واریز ذخیره شد')

    const download = screen.getByRole('link', { name: 'دانلود فوراپ' })
    expect(download.getAttribute('href')).toBe('https://github.com/erfjab/forapp/releases/latest')
    expect(download.getAttribute('target')).toBe('_blank')
  })

  it('switched off asks it of the server, and leaves no address or key behind', async () => {
    show(depositSettingsRow())
    server().on('PUT', SETTINGS, json(OFF))

    fireEvent.click(await screen.findByRole('switch', { name: 'تایید خودکار واریزها روشن باشد' }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', SETTINGS)[0]?.body).toEqual({ enabled: false }))
    await until(() => expect(screen.queryByRole('button', { name: 'کپی کلید API' })).toBeNull())
    expect(screen.queryByText('اتصال فوراپ')).toBeNull()
  })
})

describe('the connection', () => {
  it('copies the address and the key whole — the key shown only when asked', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    show(depositSettingsRow())

    fireEvent.click(await screen.findByRole('button', { name: 'کپی آدرس وب‌هوک' }))
    await until(() => expect(write).toHaveBeenCalledWith('https://shop.example/webhooks/deposits/forapp/1'))
    expect(toast.success).toHaveBeenCalledWith('آدرس وب‌هوک کپی شد')

    expect(screen.queryByText('dpk_7f3a9c2e1b4d')).toBeNull()
    expect(screen.getByText('••••••••••••1b4d')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'کپی کلید API' }))
    await until(() => expect(write).toHaveBeenLastCalledWith('dpk_7f3a9c2e1b4d'))

    fireEvent.click(screen.getByRole('button', { name: 'نمایش کلید API' }))
    expect(screen.getByText('dpk_7f3a9c2e1b4d')).toBeTruthy()
  })

  it('makes a new key only once asked, and shows the one made', async () => {
    show(depositSettingsRow())
    server().on('POST', KEY, json(depositSettingsRow({ key: 'dpk_new0000a9e2' })))

    fireEvent.click(await screen.findByRole('button', { name: 'ساخت کلید تازه' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/کلید فعلی همان لحظه از کار می‌افتد/)).toBeTruthy()
    expect(server().sent('POST', KEY)).toEqual([])

    fireEvent.click(within(dialog).getByRole('button', { name: 'ساخت کلید تازه' }))

    await until(() => expect(screen.getByText('••••••••••••a9e2')).toBeTruthy())
    expect(server().sent('POST', KEY)).toHaveLength(1)
    expect(toast.success).toHaveBeenCalledWith('کلید تازه ساخته شد؛ آن را در فوراپ بگذارید')
  })

  it('keeps the key when the new one is not asked for after all', async () => {
    show(depositSettingsRow())

    fireEvent.click(await screen.findByRole('button', { name: 'ساخت کلید تازه' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'انصراف' }))

    expect(screen.getByText('••••••••••••1b4d')).toBeTruthy()
    expect(server().sent('POST', KEY)).toEqual([])
  })

  it('says when the last test and the last bank SMS came — none yet said so', async () => {
    show(depositSettingsRow({ last_test_at: '2026-10-06T08:55:00Z', last_received_at: null }))

    expect((await screen.findByText(/آخرین پیام آزمایشی:/)).textContent).toMatch(/قبل$/)
    expect(screen.getByText(/آخرین پیامک بانک:/).textContent).toBe('آخرین پیامک بانک: هنوز نرسیده')
  })

  it('says nothing has come while nothing has', async () => {
    show(depositSettingsRow({ last_test_at: null, last_received_at: null }))

    expect(await screen.findByText('هنوز پیامی نرسیده است.')).toBeTruthy()
  })

  it('links the deposits while some of the week’s approved nothing — said with the switch, either way’s', async () => {
    show(depositSettingsRow({ waiting: 3 }))

    const waiting = await screen.findByText(/۳ پیامک واریز در هفت روز گذشته/)
    expect(waiting.textContent).toContain('هیچ پرداختی را تایید نکرده است')
    expect(screen.getByRole('link', { name: 'دیدن واریزها' }).getAttribute('href')).toBe('/payments/deposits')
    const card = waiting.closest<HTMLElement>('[data-slot="card"]')
    expect(card && within(card).getByRole('switch', { name: 'تایید خودکار واریزها روشن باشد' })).toBeTruthy()
  })

  it('says of ForApp alone that its SMS come only once it is given the address and the key', async () => {
    show(depositSettingsRow({ last_test_at: null }))

    expect(await screen.findByText('فوراپ هر پیامک بانک را با کلید API به آدرس وب‌هوک می‌فرستد؛ تا آدرس و کلید زیر را در فوراپ نگذارید، پیامکی از آن به فروشگاه نمی‌رسد.')).toBeTruthy()
    expect(screen.getByText('هنوز پیامی نرسیده است.').querySelector('.bg-warning')).toBeTruthy()
  })

  it('beside Rikhtam, makes nothing from ForApp’s silence a warning', async () => {
    show(depositSettingsRow({ last_test_at: null, rikhtam: CONNECTED }))

    expect((await screen.findByText('هنوز پیامی نرسیده است.')).querySelector('.bg-warning')).toBeNull()
  })
})

describe('reading it again', () => {
  const reads = () => server().sent('GET', SETTINGS).length
  const tick = () => act(() => vi.advanceTimersByTimeAsync(5_000))

  it('every few seconds while ForApp’s first message is awaited, and no more once it came', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    show(depositSettingsRow({ last_test_at: null }))
    await screen.findByText('هنوز پیامی نرسیده است.')

    await tick()
    await until(() => expect(reads()).toBe(2))
    server().on('GET', SETTINGS, json(depositSettingsRow()))
    await tick()
    await screen.findByText(/آخرین پیام آزمایشی:/)
    await tick()
    await tick()
    expect(reads()).toBe(3)
  })

  it('not for an owner who uses Rikhtam alone — until ForApp’s address or key is copied here', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    show(depositSettingsRow({ last_test_at: null, rikhtam: CONNECTED }))
    await screen.findByText('اتصال فوراپ')

    await tick()
    await tick()
    expect(reads()).toBe(1)
    fireEvent.click(screen.getByRole('button', { name: 'کپی آدرس وب‌هوک' }))
    await tick()
    await until(() => expect(reads()).toBe(2))
  })

  it('not while it is off', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    show(OFF)
    await screen.findByText('اتصال ریختم')

    await tick()
    await tick()
    expect(reads()).toBe(1)
  })
})

describe('Rikhtam', () => {
  const RIKHTAM = '/api/admin/deposits/settings/rikhtam'
  const HOOK = '/api/admin/deposits/settings/rikhtam/hook'
  const card = () => screen.getByText('اتصال ریختم').closest<HTMLElement>('[data-slot="card"]') ?? document.body

  it('is offered while the approval is off: its steps on the shop’s domain, and the key sent switches the approval on', async () => {
    const client = show(OFF)
    client.setQueryData(queryKeys.payments, { payments: [] })
    server().on('PUT', RIKHTAM, json(depositSettingsRow({ rikhtam: CONNECTED })))

    expect(await screen.findByText('اتصال ریختم')).toBeTruthy()
    expect(within(card()).getByText(/یک درگاه با دامنه این فروشگاه/).textContent).toContain('shop.example')
    expect(within(card()).getByRole('link', { name: 'پنل ریختم' }).getAttribute('href')).toBe('https://rikhtam.com')
    expect(within(card()).getByText('تایید خودکار واریز الان خاموش است؛ با اتصال ریختم روشن می‌شود.')).toBeTruthy()

    const connect = within(card()).getByRole('button', { name: 'اتصال' })
    expect(connect.getAttribute('aria-disabled')).toBe('true')
    fireEvent.change(within(card()).getByLabelText('کلید امنیتی درگاه ریختم'), { target: { value: ' rk_5e1f0c9a7b3d4e2f8a6c ' } })
    connect.focus()
    fireEvent.click(connect)

    await until(() => expect(server().sent('PUT', RIKHTAM)[0]?.body).toEqual({ secret: 'rk_5e1f0c9a7b3d4e2f8a6c' }))
    await until(() => expect(within(card()).getByText('وصل است')).toBeTruthy())
    expect(toast.success).toHaveBeenCalledWith('ریختم وصل شد و تایید خودکار واریز روشن شد')
    // «اتصال» went with the form: the focus is on the gateway connected, read with «وصل است».
    await until(() => expect(document.activeElement?.textContent).toContain('وصل است'))
    expect(document.activeElement?.textContent).toContain('shop.example')
    // Open card payments offer «پیوست واریز ریختم» now: their list is read again.
    expect(client.getQueryState(queryKeys.payments)?.isInvalidated).toBe(true)
    expect((screen.getByRole('switch', { name: 'تایید خودکار واریزها روشن باشد' }) as HTMLButtonElement).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByText('اتصال فوراپ')).toBeTruthy()
    expect(server().sent('PUT', SETTINGS)).toEqual([])
  })

  it('on a shop whose address is not https, says Rikhtam cannot be connected instead of asking a key it would refuse', async () => {
    show(depositSettingsRow({ rikhtam: { connected: false, domain: null, project_id: null, callback_url: 'http://shop.example/webhooks/rikhtam/1', connected_at: null } }))

    expect(await screen.findByText('اتصال ریختم')).toBeTruthy()
    expect(within(card()).getByText(/ریختم فقط به آدرس https خبر می‌دهد/)).toBeTruthy()
    expect(within(card()).queryByLabelText('کلید امنیتی درگاه ریختم')).toBeNull()
    expect(within(card()).queryByRole('button', { name: 'اتصال' })).toBeNull()
  })

  it('says a key Rikhtam refuses under it, and stays as it was', async () => {
    const wrong = 'کلید امنیتی درگاه اشتباه است؛ آن را دوباره از پنل ریختم کپی کنید.'
    show(depositSettingsRow())
    server().on('PUT', RIKHTAM, refusal(422, wrong, { secret: [wrong] }))

    fireEvent.change(await screen.findByLabelText('کلید امنیتی درگاه ریختم'), { target: { value: 'rk_wrong' } })
    fireEvent.click(within(card()).getByRole('button', { name: 'اتصال' }))

    expect(await within(card()).findByText(wrong)).toBeTruthy()
    expect(within(card()).getByLabelText('کلید امنیتی درگاه ریختم').getAttribute('aria-invalid')).toBe('true')
    expect(within(card()).queryByText('وصل است')).toBeNull()
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('refused as made for another domain, offers the shop’s address to put in the gateway', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    const elsewhere = `آدرس فروشگاه (‎shop.example) روی دامنه‌ای نیست که درگاه ریختم برای آن ساخته شده است؛ درگاه را با همین دامنه بسازید، یا این آدرس را در «آدرس کال‌بک» درگاه در پنل ریختم بگذارید: ‎${ADDRESS}`
    show(depositSettingsRow())
    server().on('PUT', RIKHTAM, refusal(422, elsewhere, { secret: [elsewhere] }))

    fireEvent.change(await screen.findByLabelText('کلید امنیتی درگاه ریختم'), { target: { value: 'rk_5e1f0c9a7b3d4e2f8a6c' } })
    expect(within(card()).queryByRole('button', { name: 'کپی آدرس فروشگاه برای ریختم' })).toBeNull()
    fireEvent.click(within(card()).getByRole('button', { name: 'اتصال' }))

    fireEvent.click(await within(card()).findByRole('button', { name: 'کپی آدرس فروشگاه برای ریختم' }))
    await until(() => expect(write).toHaveBeenCalledWith(ADDRESS))
  })

  it('connected shows its gateway and the shop’s address there, copied whole, and registers it again', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    show(depositSettingsRow({ rikhtam: CONNECTED }))
    server().on('POST', HOOK, json(depositSettingsRow({ rikhtam: CONNECTED })))

    expect(await screen.findByText('shop.example')).toBeTruthy()
    expect(within(card()).getByText(/درگاه شماره/).textContent).toContain('12')
    expect(within(card()).queryByLabelText('کلید امنیتی درگاه ریختم')).toBeNull()
    expect(within(card()).getByText(/لازم نیست جایی بگذارید/)).toBeTruthy()

    fireEvent.click(within(card()).getByRole('button', { name: 'کپی آدرس فروشگاه در ریختم' }))
    await until(() => expect(write).toHaveBeenCalledWith(ADDRESS))

    fireEvent.click(within(card()).getByRole('button', { name: 'ثبت دوباره آدرس' }))
    await until(() => expect(server().sent('POST', HOOK)).toHaveLength(1))
    await until(() => expect(toast.success).toHaveBeenCalledWith('آدرس فروشگاه دوباره در ریختم ثبت شد'))
  })

  it('says in the card why the address could not be registered again', async () => {
    const away = 'ریختم در دسترس نبود؛ کمی بعد دوباره امتحان کنید.'
    show(depositSettingsRow({ rikhtam: CONNECTED }))
    server().on('POST', HOOK, refusal(503, away))

    fireEvent.click(await screen.findByRole('button', { name: 'ثبت دوباره آدرس' }))

    expect(await within(card()).findByText(new RegExp(away))).toBeTruthy()
    expect(within(card()).queryByText(/کلید تازه را با «تعویض کلید» بگذارید/)).toBeNull()
  })

  it('refused on the key as it registers again, points to «تعویض کلید», which takes a new key and registers with it', async () => {
    const wrong = 'کلید امنیتی درگاه اشتباه است؛ آن را دوباره از پنل ریختم کپی کنید.'
    show(depositSettingsRow({ rikhtam: CONNECTED }))
    server().on('POST', HOOK, refusal(422, wrong, { secret: [wrong] }))
    server().on('PUT', RIKHTAM, json(depositSettingsRow({ rikhtam: { ...CONNECTED, project_id: 13 } })))

    fireEvent.click(await screen.findByRole('button', { name: 'ثبت دوباره آدرس' }))
    expect(await within(card()).findByText(new RegExp(wrong))).toBeTruthy()
    expect(within(card()).getByText('اگر کلید درگاه در پنل ریختم عوض یا حذف شده است، کلید تازه را با «تعویض کلید» بگذارید.')).toBeTruthy()

    const replace = within(card()).getByRole('button', { name: 'تعویض کلید' })
    replace.focus()
    fireEvent.click(replace)
    expect(replace.getAttribute('aria-expanded')).toBe('true')
    // The pointer went with the refusal; the field takes the focus.
    expect(within(card()).queryByText(new RegExp(wrong))).toBeNull()
    const field = within(card()).getByLabelText('کلید امنیتی درگاه ریختم')
    expect(document.activeElement).toBe(field)

    fireEvent.change(field, { target: { value: 'rk_new0000c4d2e6f8' } })
    fireEvent.click(within(card()).getByRole('button', { name: 'ذخیره کلید' }))

    await until(() => expect(server().sent('PUT', RIKHTAM)[0]?.body).toEqual({ secret: 'rk_new0000c4d2e6f8' }))
    await until(() => expect(within(card()).queryByLabelText('کلید امنیتی درگاه ریختم')).toBeNull())
    expect(toast.success).toHaveBeenCalledWith('کلید ریختم عوض شد و آدرس فروشگاه دوباره در ریختم ثبت شد')
    expect(within(card()).getByText(/درگاه شماره/).textContent).toContain('13')
    await until(() => expect(document.activeElement).toBe(within(card()).getByRole('button', { name: 'تعویض کلید' })))
  })

  it('keeps the key when replacing it is put away, the focus back on «تعویض کلید»', async () => {
    show(depositSettingsRow({ rikhtam: CONNECTED }))

    fireEvent.click(await screen.findByRole('button', { name: 'تعویض کلید' }))
    fireEvent.click(within(card()).getByRole('button', { name: 'انصراف' }))

    expect(within(card()).queryByLabelText('کلید امنیتی درگاه ریختم')).toBeNull()
    expect(document.activeElement).toBe(within(card()).getByRole('button', { name: 'تعویض کلید' }))
    expect(server().sent('PUT', RIKHTAM)).toEqual([])
  })

  it('is disconnected only once asked, which forgets the key and shows the steps again, the focus on the key’s field', async () => {
    const client = show(depositSettingsRow({ rikhtam: CONNECTED }))
    client.setQueryData(queryKeys.payments, { payments: [] })
    server().on('DELETE', RIKHTAM, json(depositSettingsRow()))

    fireEvent.click(await screen.findByRole('button', { name: 'قطع اتصال' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/کلید امنیتی از فروشگاه پاک می‌شود/)).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('button', { name: 'انصراف' }))
    expect(server().sent('DELETE', RIKHTAM)).toEqual([])

    fireEvent.click(screen.getByRole('button', { name: 'قطع اتصال' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'قطع اتصال' }))

    await until(() => expect(within(card()).getByLabelText('کلید امنیتی درگاه ریختم')).toBeTruthy())
    expect(server().sent('DELETE', RIKHTAM)).toHaveLength(1)
    expect(toast.success).toHaveBeenCalledWith('اتصال ریختم قطع شد')
    await until(() => expect(document.activeElement).toBe(within(card()).getByLabelText('کلید امنیتی درگاه ریختم')))
    expect(client.getQueryState(queryKeys.payments)?.isInvalidated).toBe(true)
  })

  it('connected while the approval is off says its deposits approve nothing by themselves', async () => {
    show({ ...OFF, rikhtam: CONNECTED })

    expect(await screen.findByText(/واریزهای ریختم خودکار هیچ پرداختی را تایید نمی‌کنند/)).toBeTruthy()
  })
})
