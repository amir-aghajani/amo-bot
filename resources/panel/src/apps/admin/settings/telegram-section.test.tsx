import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { TelegramSection } from '@/apps/admin/settings/telegram-section'
import type { ConfigSettingsResponse, ConfigTelegramSettings, SystemResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The main bot's settings card (apps/admin/settings/telegram-section): named the main bot's, whichever shop the tab
 * shows; when a save reaches it said by the way every bot gets its updates — the main bot's, not the open shop's —; what
 * «بررسی توکن» said is about the token typed — «بازگردانی تغییرات» throws both away.
 */

const SETTINGS: ConfigTelegramSettings = {
  token: { set: true, hint: '1234…wxyz' },
  username: 'amo_bot',
  api_url: 'https://api.telegram.org',
  poll_timeout: 25,
  webhook_secret: { set: true, hint: 'ab…yz' },
}

const META: ConfigSettingsResponse['meta'] = { timezones: [], log_levels: [], cron_url: null, webhook_url: 'https://shop.example/webhooks/telegram/…' }

const REFUSED = 'تلگرام این توکن را نمی‌پذیرد؛ آن را دوباره از @BotFather بگیرید.'

/** The system, an agent's shop open: its bot without a token, the main bot on bot:poll. */
const SYSTEM = json({
  system: {
    version: '1.0.0',
    php: '8.2.12',
    bot: { id: 7, configured: false, enabled: true, username: null, mode: 'offline', last_update_at: null },
    main_bot: { configured: true, enabled: true, username: 'amo_bot', mode: 'polling', last_update_at: null },
    cron: { last_run_at: null, tasks: 9 },
  },
} satisfies SystemResponse)

describe('the Telegram card', () => {
  it('is the main bot’s, and says a save reaches it by the main bot’s way, whichever shop is open', async () => {
    server().on('GET', '/api/admin/system', SYSTEM)
    render(<TelegramSection settings={SETTINGS} meta={META} disabled={false} />, { wrapper: providers().wrapper })

    expect(screen.getByRole('heading', { name: 'ربات اصلی' })).toBeTruthy()
    await until(() => expect(screen.getByText(/ربات با bot:poll کار می‌کند/)).toBeTruthy())
    expect(screen.queryByText(/ربات الان پیامی نمی‌گیرد/)).toBeNull()
  })

  it('drops what the token check said along with the token reverted', async () => {
    server()
      .on('GET', '/api/admin/system', SYSTEM)
      .on('POST', '/api/admin/settings/config/telegram/test', refusal(422, REFUSED, { token: [REFUSED] }))
    render(<TelegramSection settings={SETTINGS} meta={META} disabled={false} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByLabelText('توکن ربات اصلی'), { target: { value: '123456789:AAHbadtokenbadtokenbadtokenbadtoken' } })
    fireEvent.click(screen.getByRole('button', { name: 'بررسی توکن' }))
    await until(() => expect(screen.getByText(REFUSED)).toBeTruthy())

    fireEvent.click(screen.getByRole('button', { name: 'بازگردانی تغییرات' }))

    expect(screen.queryByText(REFUSED)).toBeNull()
    expect((screen.getByLabelText('توکن ربات اصلی') as HTMLInputElement).value).toBe('')
  })

  it('says before the save that the kept token is typed again once the Bot API’s address moves to another origin', async () => {
    server().on('GET', '/api/admin/system', SYSTEM)
    render(<TelegramSection settings={SETTINGS} meta={META} disabled={false} />, { wrapper: providers().wrapper })
    await until(() => expect(screen.getByText(/ربات با bot:poll کار می‌کند/)).toBeTruthy())
    const typeAgain = /توکن ربات اصلی را دوباره وارد کنید/

    fireEvent.click(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }))
    fireEvent.change(screen.getByLabelText('آدرس API تلگرام'), { target: { value: 'https://api.telegram.org/mirror' } })
    expect(screen.queryByText(typeAgain)).toBeNull()

    fireEvent.change(screen.getByLabelText('آدرس API تلگرام'), { target: { value: 'https://bot-api.example.com' } })
    expect(screen.getByText(typeAgain)).toBeTruthy()

    fireEvent.change(screen.getByLabelText('توکن ربات اصلی'), { target: { value: '123456789:AAHnewtokennewtokennewtokennewtoken' } })
    expect(screen.queryByText(typeAgain)).toBeNull()
  })

  it('has nothing unsaved once the Bot API’s address is back where it was — the owner’s password typed for the move is none of it', async () => {
    server().on('GET', '/api/admin/system', SYSTEM)
    render(<TelegramSection settings={SETTINGS} meta={META} disabled={false} />, { wrapper: providers().wrapper })
    await until(() => expect(screen.getByText(/ربات با bot:poll کار می‌کند/)).toBeTruthy())
    fireEvent.click(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }))

    fireEvent.change(screen.getByLabelText('آدرس API تلگرام'), { target: { value: 'https://bot-api.example.com' } })
    fireEvent.change(screen.getByLabelText('رمز عبور فعلی پنل'), { target: { value: 'filled in by the browser' } })
    fireEvent.change(screen.getByLabelText('آدرس API تلگرام'), { target: { value: SETTINGS.api_url } })

    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(screen.getByRole('button', { name: 'ذخیره' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('asks the owner’s password while the Bot API’s address moves to another origin — every bot’s token goes there —, and sends it with that save alone', async () => {
    const saved: ConfigSettingsResponse = { file: { path: 'config.php', exists: true, writable: true }, groups: {} as ConfigSettingsResponse['groups'], meta: META }
    const wrong = 'رمز عبور فعلی درست نیست.'
    server()
      .on('GET', '/api/admin/system', SYSTEM)
      .on('PUT', '/api/admin/settings/config/telegram', refusal(422, wrong, { current_password: [wrong] }))
    render(<TelegramSection settings={SETTINGS} meta={META} disabled={false} />, { wrapper: providers().wrapper })
    await until(() => expect(screen.getByText(/ربات با bot:poll کار می‌کند/)).toBeTruthy())
    fireEvent.click(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }))

    fireEvent.change(screen.getByLabelText('آدرس API تلگرام'), { target: { value: 'https://api.telegram.org/mirror' } })
    expect(screen.queryByLabelText('رمز عبور فعلی پنل')).toBeNull()

    fireEvent.change(screen.getByLabelText('آدرس API تلگرام'), { target: { value: 'https://bot-api.example.com' } })
    expect(screen.getByText(/توکن همه ربات‌ها، ربات‌های نماینده‌ها هم، به آن فرستاده می‌شود/)).toBeTruthy()
    fireEvent.change(screen.getByLabelText('توکن ربات اصلی'), { target: { value: '123456789:AAHnewtokennewtokennewtokennewtoken' } })
    fireEvent.change(screen.getByLabelText('رمز عبور فعلی پنل'), { target: { value: 'not it' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(screen.getByText(wrong)).toBeTruthy())
    expect(screen.getByLabelText('رمز عبور فعلی پنل').getAttribute('aria-invalid')).toBe('true')
    expect(server().sent('PUT', '/api/admin/settings/config/telegram')[0]?.body).toMatchObject({ api_url: 'https://bot-api.example.com', current_password: 'not it' })

    server().on('PUT', '/api/admin/settings/config/telegram', json(saved))
    fireEvent.change(screen.getByLabelText('آدرس API تلگرام'), { target: { value: 'https://api.telegram.org/v2' } })
    expect(screen.queryByLabelText('رمز عبور فعلی پنل')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
    await until(() => expect(server().sent('PUT', '/api/admin/settings/config/telegram')).toHaveLength(2))
    expect(server().sent('PUT', '/api/admin/settings/config/telegram')[1]?.body).not.toHaveProperty('current_password')
  })
})
