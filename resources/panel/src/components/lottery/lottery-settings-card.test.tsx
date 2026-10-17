import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WithBotSettings } from '@/components/bot/settings/with-bot-settings'
import { LotterySettingsCard } from '@/components/lottery/lottery-settings-card'
import type { BotSettingsSaved } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The nightly draw's rules (components/lottery/lottery-settings-card) while the draw is switched off: the rules wait,
 * held as they are kept — but one changed stays open, since the save carries it and the server may refuse it, and typed
 * back it is not taken from under the typist's focus: it waits again once the draw is switched on, or the card is saved
 * or reverted and the focus is out of it.
 */

const TOO_MANY = 'تعداد برنده‌ها باید بین 1 تا 20 باشد.'

describe('the draw switched off while its rules are edited', () => {
  it('holds the rules as they are kept, and leaves one changed open to fixing once the server refused it', async () => {
    server().on('PUT', '/api/admin/bot/settings/lottery', refusal(422, TOO_MANY, { lottery_winners: [TOO_MANY] }))
    render(<LotterySettingsCard settings={botSettingsRow({ lottery_enabled: true })} />, { wrapper: signedIn().wrapper })
    const winners = await screen.findByLabelText('تعداد برنده‌ها')
    // The hint's bounds in Latin digits, as the owner types them.
    expect(screen.getByText(/^بین 1 تا 20؛/)).toBeTruthy()

    fireEvent.change(winners, { target: { value: '25' } })
    fireEvent.click(screen.getByRole('switch', { name: 'قرعه‌کشی شبانه فعال باشد' }))
    expect(screen.getByRole('combobox', { name: 'ساعت قرعه‌کشی' }).hasAttribute('disabled')).toBe(true)
    expect(winners.hasAttribute('disabled')).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText(TOO_MANY)).toBeTruthy()
    expect(winners.hasAttribute('disabled')).toBe(false)
    expect(winners.getAttribute('aria-invalid')).toBe('true')
  })

  it('leaves a rule typed back to its kept value open under the typist’s focus, until the draw is switched on', async () => {
    render(<LotterySettingsCard settings={botSettingsRow({ lottery_enabled: true, lottery_winners: 1 })} />, { wrapper: signedIn().wrapper })
    const winners = await screen.findByLabelText('تعداد برنده‌ها')
    const draw = screen.getByRole('switch', { name: 'قرعه‌کشی شبانه فعال باشد' })

    fireEvent.change(winners, { target: { value: '3' } })
    fireEvent.click(draw)
    winners.focus()
    fireEvent.change(winners, { target: { value: '1' } })
    expect(winners.hasAttribute('disabled')).toBe(false)
    expect(document.activeElement).toBe(winners)

    // On and off again, nothing typed since: the rule waits as it is kept.
    fireEvent.click(draw)
    fireEvent.click(draw)
    expect(winners.hasAttribute('disabled')).toBe(true)
  })

  it('holds a rule changed and saved with Enter from its field once the focus leaves it, never under it', async () => {
    const PATH = '/api/admin/bot/settings/lottery'
    server()
      .on('GET', '/api/admin/bot/settings', json(botSettingsResponse(botSettingsRow({ lottery_enabled: true, lottery_winners: 1 }))))
      .on('PUT', PATH, json({ settings: botSettingsRow({ lottery_enabled: false, lottery_winners: 3 }) } satisfies BotSettingsSaved))
    render(<WithBotSettings what="تنظیمات قرعه‌کشی">{(settings) => <LotterySettingsCard settings={settings} />}</WithBotSettings>, { wrapper: signedIn().wrapper })
    const winners = await screen.findByLabelText('تعداد برنده‌ها')

    fireEvent.change(winners, { target: { value: '3' } })
    fireEvent.click(screen.getByRole('switch', { name: 'قرعه‌کشی شبانه فعال باشد' }))
    winners.focus()
    fireEvent.submit(winners.closest('form') as HTMLFormElement)

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    await until(() => expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull())
    expect(winners.hasAttribute('disabled')).toBe(false)
    expect(document.activeElement).toBe(winners)

    act(() => winners.blur())
    expect(winners.hasAttribute('disabled')).toBe(true)
  })

  it('holds a rule again once the card is reverted', async () => {
    render(<LotterySettingsCard settings={botSettingsRow({ lottery_enabled: false, lottery_winners: 1 })} />, { wrapper: signedIn().wrapper })
    const winners = await screen.findByLabelText('تعداد برنده‌ها')
    const draw = screen.getByRole('switch', { name: 'قرعه‌کشی شبانه فعال باشد' })
    expect(winners.hasAttribute('disabled')).toBe(true)

    fireEvent.click(draw)
    fireEvent.change(winners, { target: { value: '3' } })
    fireEvent.click(draw)
    fireEvent.change(winners, { target: { value: '1' } })
    fireEvent.change(winners, { target: { value: '5' } })
    expect(winners.hasAttribute('disabled')).toBe(false)

    const revert = screen.getByRole('button', { name: 'بازگردانی تغییرات' })
    revert.focus()
    fireEvent.click(revert)
    expect((winners as HTMLInputElement).value).toBe('1')
    expect(winners.hasAttribute('disabled')).toBe(true)
  })
})
