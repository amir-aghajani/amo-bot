import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SettingsPage } from '@/apps/admin/pages/settings'
import { OwnerTelegramCard } from '@/apps/admin/settings/owner-telegram-card'
import type { OwnerTelegramLinkResponse, OwnerTelegramResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { ThemeProvider } from '@/lib/theme'
import { signedIn, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * «مدیریت از ربات» (apps/admin/settings/owner-telegram-card, «تنظیمات پنل › ورود به پنل»): the Telegram accounts that
 * are the owner in the main bot, a one-time link made once the panel's password is typed in place — shown once, copied
 * whole or opened, while the server says it is open —, the server's refusals said on the card (the password's under its
 * field), and an account let go after asking. On the login's section.
 */

const URL = '/api/admin/settings/owner-telegram'
const LINK = `${URL}/link`
const OPEN_UNTIL = '2026-11-15T10:10:00+00:00'
const BOT_LINK = 'https://t.me/amo_shop_bot?start=owner-k2J8sQ0x3f9aVb7Lm1Np4Qr6St8Uv0Wx'

const AMIR = { telegram_id: 7001, name: 'Amir', username: 'amir' }
const PHONE = { telegram_id: 7002, name: null, username: null }

/** A link made: open until OPEN_UNTIL, Amir's account the one linked. */
const MADE: OwnerTelegramLinkResponse = { link: { url: BOT_LINK, expires_at: OPEN_UNTIL }, accounts: [AMIR], link_open_until: OPEN_UNTIL }

/** The card, the accounts read as `answer`; its query client. */
async function show(answer: OwnerTelegramResponse = { accounts: [AMIR], link_open_until: null }) {
  server().on('GET', URL, json(answer))
  const { client, wrapper } = signedIn()
  render(<OwnerTelegramCard />, { wrapper })
  await screen.findByRole('button', { name: /ساخت لینک/ })
  return client
}

/** «ساخت لینک» pressed, the panel's password typed and the link asked for. */
function makeLink(password = 'the panel password') {
  fireEvent.click(screen.getByRole('button', { name: /^ساخت لینک/ }))
  fireEvent.change(screen.getByLabelText('رمز عبور فعلی'), { target: { value: password } })
  fireEvent.submit(screen.getByRole('form', { name: 'رمز عبور برای ساخت لینک' }))
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the owner’s Telegram accounts', () => {
  it('are listed by their name, handle and id — one with no name yet as such', async () => {
    await show({ accounts: [AMIR, PHONE], link_open_until: null })

    const list = screen.getByRole('list', { name: 'حساب‌های وصل‌شده' })
    expect(within(list).getByText('Amir')).toBeTruthy()
    expect(within(list).getByText(/@amir/)).toBeTruthy()
    expect(within(list).getByText('7001')).toBeTruthy()
    expect(within(list).getByText('حساب بی‌نام')).toBeTruthy()
    expect(screen.getByText(/حداکثر ۵ حساب وصل می‌ماند/)).toBeTruthy()
  })

  it('say none is linked yet', async () => {
    await show({ accounts: [], link_open_until: null })

    expect(screen.getByText(/هنوز حسابی وصل نشده است/)).toBeTruthy()
  })
})

describe('a one-time link', () => {
  it('is made once the panel’s password is typed, shown with its end, copied whole or opened in Telegram', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    server().on('POST', LINK, json(MADE))
    await show()

    makeLink()

    expect(await screen.findByText(BOT_LINK)).toBeTruthy()
    expect(
      server()
        .sent('POST', LINK)
        .map((request) => request.body),
    ).toEqual([{ current_password: 'the panel password' }])
    expect(toast.success).toHaveBeenCalledWith('لینک ساخته شد')
    expect(screen.getByText(/با همان حساب تلگرامی باز کنید که باید مالک شود/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'باز کردن در تلگرام' }).getAttribute('href')).toBe(BOT_LINK)
    expect(screen.queryByLabelText('رمز عبور فعلی'), 'the password goes with the request').toBeNull()
    expect(screen.getByRole('status').contains(document.activeElement), 'the focus goes to the link made').toBe(true)
    expect(screen.getByRole('button', { name: 'ساخت لینک تازه' })).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'کپی لینک' }))
    await until(() => expect(write).toHaveBeenCalledWith(BOT_LINK))
    expect(toast.success).toHaveBeenCalledWith('لینک کپی شد')
  })

  it('asks the panel’s password in place first — nothing sent until it is confirmed, and cancelled it hands the focus back', async () => {
    await show()

    fireEvent.click(screen.getByRole('button', { name: 'ساخت لینک' }))

    const field = screen.getByLabelText('رمز عبور فعلی')
    expect(screen.getByText('برای ساخت لینک، رمز ورود پنل را وارد کنید.')).toBeTruthy()
    expect(document.activeElement).toBe(field)
    expect(server().sent('POST', LINK)).toHaveLength(0)

    fireEvent.change(field, { target: { value: 'typed' } })
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))

    expect(screen.queryByLabelText('رمز عبور فعلی')).toBeNull()
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'ساخت لینک' }))
    expect(server().sent('POST', LINK)).toHaveLength(0)

    // Asked again, it starts empty.
    fireEvent.click(screen.getByRole('button', { name: 'ساخت لینک' }))
    expect((screen.getByLabelText('رمز عبور فعلی') as HTMLInputElement).value).toBe('')
  })

  it('refused for the password says so under its field, which keeps the focus', async () => {
    const wrong = 'رمز عبور فعلی درست نیست.'
    server().on('POST', LINK, refusal(422, wrong, { current_password: [wrong] }))
    await show()

    makeLink('a guess')

    const field = screen.getByLabelText('رمز عبور فعلی')
    await until(() => expect(field.getAttribute('aria-invalid')).toBe('true'))
    expect(screen.getByText(wrong).id).toBe(field.getAttribute('aria-describedby'))
    expect(screen.queryByRole('alert'), 'said once, under the field').toBeNull()
    expect(document.activeElement).toBe(field)
    expect(screen.queryByRole('link', { name: 'باز کردن در تلگرام' })).toBeNull()
  })

  it('made here is put away once a newer one was made elsewhere — the bot’s own screen, another tab —, which ended it', async () => {
    server().on('POST', LINK, json(MADE))
    const client = await show()
    makeLink()
    expect(await screen.findByText(BOT_LINK)).toBeTruthy()

    // The same moment written another way is the same link still.
    server().on('GET', URL, json({ accounts: [AMIR], link_open_until: '2026-11-15T13:40:00+03:30' }))
    await client.refetchQueries({ queryKey: queryKeys.ownerTelegram })
    expect(screen.getByText(BOT_LINK)).toBeTruthy()

    server().on('GET', URL, json({ accounts: [AMIR], link_open_until: '2026-11-15T10:14:00+00:00' }))
    await client.refetchQueries({ queryKey: queryKeys.ownerTelegram })

    await until(() => expect(screen.queryByText(BOT_LINK)).toBeNull())
    expect(screen.getByText(/یک لینک تا .* باز است/)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'ساخت لینک تازه' })).toBeTruthy()
  })

  it('open from before is said, never shown again — a new one is made in its place', async () => {
    await show({ accounts: [AMIR], link_open_until: OPEN_UNTIL })

    expect(screen.getByText(/یک لینک تا .* باز است/)).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'باز کردن در تلگرام' })).toBeNull()
    expect(screen.getByRole('button', { name: 'ساخت لینک تازه' })).toBeTruthy()
  })

  it('refused is said on the card', async () => {
    const refused = 'یوزرنیم ربات اصلی هنوز معلوم نیست؛ اول در «تنظیمات پنل › ربات تلگرام» توکن ربات را بررسی کنید.'
    server().on('POST', LINK, refusal(422, refused, { link: [refused] }))
    await show()

    makeLink()

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(screen.getByRole('alert').textContent).toContain(refused)
    expect(screen.queryByRole('link', { name: 'باز کردن در تلگرام' })).toBeNull()
  })
})

describe('letting an account go', () => {
  it('is asked first, then the accounts are as the server answered — and the customers, whose admin role its link gave goes too, are read again', async () => {
    server().on('DELETE', `${URL}/7001`, json({ accounts: [], link_open_until: null }))
    const client = await show()
    client.setQueryData([...queryKeys.users, { role: 'admin' }], { users: [] })

    fireEvent.click(screen.getByRole('button', { name: 'برداشتن Amir' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/از پیام بعدی‌اش، مدیریت فروشگاه در ربات برایش بسته می‌شود/)).toBeTruthy()
    expect(server().sent('DELETE', `${URL}/7001`)).toHaveLength(0)

    fireEvent.click(within(dialog).getByRole('button', { name: 'برداشتن' }))

    await until(() => expect(screen.getByText(/هنوز حسابی وصل نشده است/)).toBeTruthy())
    expect(server().sent('DELETE', `${URL}/7001`)).toHaveLength(1)
    expect(toast.success).toHaveBeenCalledWith('حساب برداشته شد')
    await until(() => expect(client.getQueryState([...queryKeys.users, { role: 'admin' }])?.isInvalidated).toBe(true))
  })
})

describe('the card', () => {
  it('is on «تنظیمات پنل › ورود به پنل», under the login', async () => {
    server()
      .on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
      .on('GET', URL, json({ accounts: [AMIR], link_open_until: null }))
    render(
      <ThemeProvider>
        <SettingsPage />
      </ThemeProvider>,
      { wrapper: signedIn({ at: '/settings/login' }).wrapper },
    )

    const section = await screen.findByRole('region', { name: 'ورود به پنل' })
    await until(() => expect(within(section).getByText('مدیریت از ربات')).toBeTruthy())
    expect(within(section).getByText('Amir')).toBeTruthy()
  })
})
