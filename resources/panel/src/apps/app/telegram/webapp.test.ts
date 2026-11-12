import { describe, expect, it, vi } from 'vitest'
import { confirmBox, fitText, inPreview, inTelegram, onPhone, openAddress, openLink, previewToken, supports } from '@/apps/app/telegram/webapp'
import * as app from '@/apps/app/test/app'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * Telegram's side (telegram/webapp): words cut to what Telegram takes, at a whole character; the confirm box answering no
 * when Telegram refuses to show it, and its words cut to fit; an address the shop gave opened its own way — a t.me one
 * inside Telegram, a web one in its browser, a phone number or a tg:// link by the device, a script's never —, and
 * Telegram's browser given nothing but a web address. A panel's preview — its token in the address, no launch data —
 * counts as inside Telegram; beside launch data, the token is no preview.
 */

describe('words for Telegram', () => {
  it('cuts them at a whole character, ending in «…»', () => {
    expect(fitText('  پرداخت  ', 64)).toBe('پرداخت')
    expect(fitText('ا'.repeat(70), 64)).toBe(`${'ا'.repeat(63)}…`)
    // An emoji is two of the units Telegram counts: never split in half.
    const cut = fitText('🎁'.repeat(40), 64)
    expect(cut.length).toBeLessThanOrEqual(64)
    expect(cut).toBe(`${'🎁'.repeat(31)}…`)
  })
})

describe('the confirm box', () => {
  it('answers no when Telegram refuses to show it — one already open, from a second tap', async () => {
    app.telegram({
      showConfirm: () => {
        throw new Error('WebAppPopupOpened')
      },
    })

    await expect(confirmBox('این تیکت بسته شود؟')).resolves.toBe(false)
  })

  it('cuts words too long for Telegram', async () => {
    const showConfirm = vi.fn((_message: string, callback?: (confirmed: boolean) => void) => callback?.(true))
    app.telegram({ showConfirm })

    await expect(confirmBox(`${'ا'.repeat(300)}؟`)).resolves.toBe(true)
    const words = showConfirm.mock.calls[0]?.[0] ?? ''
    expect(words).toHaveLength(256)
    expect(words.endsWith('…')).toBe(true)
  })
})

describe('an address the shop gave', () => {
  it('opens a t.me one inside Telegram and a web one in its browser', () => {
    const { app: telegram } = app.telegram()

    openAddress('https://t.me/sample_support')
    openAddress('https://example.com/news')

    expect(telegram.openTelegramLink).toHaveBeenCalledWith('https://t.me/sample_support')
    expect(telegram.openLink).toHaveBeenCalledWith('https://example.com/news')
  })

  it('hands a phone number or a tg:// link to the device, and follows no script', () => {
    const { app: telegram } = app.telegram()
    const handed = vi.spyOn(window.location, 'href', 'set').mockImplementation(() => {})

    openAddress('tel:+989121234567')
    openAddress('tg://resolve?domain=sample_support')
    openAddress(['javascript', 'alert(1)'].join(':'))
    openAddress('support')

    expect(handed.mock.calls).toEqual([['tel:+989121234567'], ['tg://resolve?domain=sample_support']])
    expect(telegram.openLink).not.toHaveBeenCalled()
    expect(telegram.openTelegramLink).not.toHaveBeenCalled()
  })
})

describe('Telegram’s browser', () => {
  it('is given nothing but a web address', () => {
    const { app: telegram } = app.telegram()

    expect(openLink('tg://resolve?domain=sample_support')).toBe(false)
    expect(telegram.openLink).not.toHaveBeenCalled()
    expect(openLink('https://example.com/help')).toBe(true)
    expect(telegram.openLink).toHaveBeenCalledWith('https://example.com/help')
  })
})

describe('a panel’s preview', () => {
  it('counts as inside Telegram, a phone of the version its frame says, with the token from the address', () => {
    app.preview()

    expect(previewToken()).toBe(app.PREVIEW_TOKEN)
    expect(inPreview()).toBe(true)
    expect(inTelegram()).toBe(true)
    expect(onPhone()).toBe(true)
    expect(supports('8.0')).toBe(true)
  })

  it('is no preview beside launch data, which is Telegram’s own launch', () => {
    const { app: telegram } = app.telegram()
    vi.stubGlobal('Telegram', { WebApp: telegram, WebView: { initParams: { tgWebAppPreview: app.PREVIEW_TOKEN } } })

    expect(previewToken()).toBeNull()
    expect(inTelegram()).toBe(true)
  })

  it('takes nothing that is no bearer token, and a browser without launch data is outside Telegram', () => {
    app.preview('a b')
    expect(previewToken()).toBeNull()
    expect(inTelegram()).toBe(false)
  })
})
