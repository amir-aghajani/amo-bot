import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { DepositSettings } from '@/components/payments/deposits/deposit-settings-card'
import type { DepositSettingsResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { depositSettingsRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «تایید خودکار واریز» on the payment methods page (components/payments/deposits): its switch saved as
 * `PUT /deposits/settings`; once on, ForApp's steps — its download opening in a new tab —, the address and the key to
 * paste in it, each copied whole (the key hidden until asked); a new key asked first, then made; and whether ForApp's
 * messages have been coming, with the week's deposits that approved nothing linked.
 */

const SETTINGS = '/api/admin/deposits/settings'
const KEY = '/api/admin/deposits/settings/key'
const OFF = depositSettingsRow({ enabled: false, key: null, last_test_at: null })

function show(settings: DepositSettingsResponse) {
  server().on('GET', SETTINGS, json(settings))
  render(<DepositSettings />, { wrapper: providers().wrapper })
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

    fireEvent.click(screen.getByRole('button', { name: 'نمایش' }))
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

  it('links the deposits while some of the week’s approved nothing', async () => {
    show(depositSettingsRow({ waiting: 3 }))

    expect((await screen.findByText(/۳ پیامک واریز در هفت روز گذشته/)).textContent).toContain('هیچ پرداختی را تایید نکرده است')
    expect(screen.getByRole('link', { name: 'دیدن واریزها' }).getAttribute('href')).toBe('/payments/deposits')
  })
})
