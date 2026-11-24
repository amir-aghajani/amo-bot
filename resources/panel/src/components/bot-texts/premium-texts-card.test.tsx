import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PremiumTextsCard } from '@/components/bot-texts/premium-texts-card'
import type { CustomEmojiRow, CustomEmojisResponse, PremiumTextsResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { premiumTextsResponse } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «ایموجی پرمیوم متن‌ها» on the bot texts page (components/bot-texts/premium-texts-card): whether the bot can send premium
 * emoji — able, unable, not checked yet — and «بررسی دوباره», why it could not check said in place; the switch held off
 * until the bot was found able, one on always turned off; the map's rows, each added from the bot's premium emoji (those
 * in the map not offered, the plain emoji prefilled and editable) or taken off, saved whole — a row's refusal under it.
 */

const PREMIUM = '/api/admin/bot/premium-texts'
const CHECK = '/api/admin/bot/premium-texts/check'
const EMOJIS = '/api/admin/bot/custom-emojis'

const emoji = (id: string, plain: string): CustomEmojiRow => ({ id, emoji: plain, format: 'static', repaint: false, updated_at: '2026-10-06T09:00:00Z' })

/** The bot's premium emoji: the two the map has, and ⭐ (43). */
const LIBRARY: CustomEmojisResponse = { emojis: [emoji('41', '❤️'), emoji('42', '🔥'), emoji('43', '⭐')], status: null }

function show(premium: PremiumTextsResponse = premiumTextsResponse(), library: CustomEmojisResponse = LIBRARY) {
  server().on('GET', PREMIUM, json(premium)).on('GET', EMOJIS, json(library))
  render(<PremiumTextsCard />, { wrapper: providers().wrapper })
}

const toggle = () => screen.findByRole('switch', { name: 'ایموجی پرمیوم متن‌ها روشن باشد' })
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
/** A row of the map, by its plain emoji. */
const row = (plain: string) => screen.getByRole('button', { name: `برداشتن ${plain} از فهرست` }).closest('li') as HTMLElement

beforeEach(() => {
  vi.spyOn(toast, 'success')
  vi.spyOn(toast, 'warning')
})

describe('the bot’s ability', () => {
  it('found able: said with when, the switch free', async () => {
    show(premiumTextsResponse({ enabled: false }))

    expect(await screen.findByText('ربات می‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
    expect(screen.getByText(/^آخرین بررسی/).textContent).toMatch(/قبل$/)
    const enabled = await toggle()
    expect(enabled.getAttribute('aria-disabled')).toBeNull()

    server().on('PUT', PREMIUM, json(premiumTextsResponse()))
    fireEvent.click(enabled)
    save()
    await until(() =>
      expect(server().sent('PUT', PREMIUM)[0]?.body).toEqual({
        enabled: true,
        items: [
          { emoji: '❤️', emoji_id: '41' },
          { emoji: '🔥', emoji_id: '42' },
        ],
      }),
    )
    expect(toast.success).toHaveBeenCalledWith('ایموجی پرمیوم متن‌ها ذخیره شد')
  })

  it('found unable: says why, the switch held off', async () => {
    show(premiumTextsResponse({ enabled: false, can_enable: false, status: { ok: false, checked_at: '2026-10-06T08:55:00Z' } }))

    expect(await screen.findByText('ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
    expect(screen.getByText(/تلگرام پرمیوم داشته باشد؛ تا آن موقع مشتری‌ها جایشان ایموجی معمولی می‌بینند/)).toBeTruthy()
    expect(screen.getByText(/تا وقتی ربات نتواند ایموجی پرمیوم بفرستد، این بخش روشن نمی‌شود/)).toBeTruthy()
    const enabled = await toggle()
    expect(enabled.getAttribute('aria-disabled')).toBe('true')

    fireEvent.click(enabled)
    expect(enabled.getAttribute('aria-checked')).toBe('false')
  })

  it('not checked yet: says what the check does, the switch held off', async () => {
    show(premiumTextsResponse({ enabled: false, can_enable: false, status: null }))

    expect(await screen.findByText('هنوز بررسی نشده که ربات می‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
    expect(screen.queryByText(/^آخرین بررسی/)).toBeNull()
    expect(screen.getByText(/تا وقتی ربات نتواند ایموجی پرمیوم بفرستد، این بخش روشن نمی‌شود/)).toBeTruthy()
    expect((await toggle()).getAttribute('aria-disabled')).toBe('true')
  })

  it('on already, may be turned off whatever was found since', async () => {
    show(premiumTextsResponse({ can_enable: false, status: { ok: false, checked_at: '2026-10-06T08:55:00Z' } }))
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ enabled: false, can_enable: false })))
    const enabled = await toggle()

    expect(enabled.getAttribute('aria-disabled')).toBeNull()
    expect(screen.queryByText(/این بخش روشن نمی‌شود/)).toBeNull()
    fireEvent.click(enabled)
    save()

    await until(() => expect(server().sent('PUT', PREMIUM)[0]?.body).toMatchObject({ enabled: false }))
  })

  it('refused on, says why under the switch and reads the finding again', async () => {
    const notAble =
      'ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد، یا بررسی نشده است: اول «بررسی دوباره» را بزنید. ربات فقط وقتی ایموجی پرمیوم می‌فرستد که حسابی که آن را در @BotFather ساخته تلگرام پرمیوم داشته باشد.'
    show(premiumTextsResponse({ enabled: false }))
    server().on('PUT', PREMIUM, refusal(422, notAble, { enabled: [notAble] }))
    const enabled = await toggle()
    server().on('GET', PREMIUM, json(premiumTextsResponse({ enabled: false, can_enable: false, status: { ok: false, checked_at: '2026-10-06T09:30:00Z' } })))

    fireEvent.click(enabled)
    save()

    expect(await screen.findByText(notAble)).toBeTruthy()
    expect(enabled.getAttribute('aria-invalid')).toBe('true')
    expect(await screen.findByText('ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
    expect(server().sent('GET', PREMIUM)).toHaveLength(2)
  })

  it('checked again: the bot found able, the switch freed and the picker’s finding read again', async () => {
    show(premiumTextsResponse({ enabled: false, can_enable: false, status: null }))
    const check = server().hold('POST', CHECK)
    const button = await screen.findByRole('button', { name: 'بررسی دوباره' })
    await until(() => expect(server().sent('GET', EMOJIS)).toHaveLength(1))

    fireEvent.click(button)
    await until(() => expect(button.getAttribute('aria-busy')).toBe('true'))
    check.answer(json(premiumTextsResponse({ enabled: false })))

    expect(await screen.findByText('ربات می‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
    expect((await toggle()).getAttribute('aria-disabled')).toBeNull()
    expect(server().sent('POST', CHECK)[0]?.body).toBeUndefined()
    expect(toast.success).toHaveBeenCalledWith('ربات می‌تواند ایموجی پرمیوم بفرستد')
    await until(() => expect(server().sent('GET', EMOJIS)).toHaveLength(2))
  })

  it('checked again and found unable: said, with a warning', async () => {
    show(premiumTextsResponse({ enabled: false, can_enable: false, status: null }))
    server().on('POST', CHECK, json(premiumTextsResponse({ enabled: false, can_enable: false, status: { ok: false, checked_at: '2026-10-06T08:55:00Z' } })))

    fireEvent.click(await screen.findByRole('button', { name: 'بررسی دوباره' }))

    expect(await screen.findByText('ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
    expect(toast.warning).toHaveBeenCalledWith('ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد')
  })

  it('could not be checked: why, in place', async () => {
    const nowhere = 'ربات جایی برای فرستادن پیام آزمایشی ندارد: حساب تلگرام خود را در «تنظیمات پنل › ورود به پنل» به پنل وصل کنید، یا /emoji را در ربات بفرستید.'
    show(premiumTextsResponse({ enabled: false, can_enable: false, status: null }))
    server().on('POST', CHECK, refusal(422, nowhere, { check: [nowhere] }))

    fireEvent.click(await screen.findByRole('button', { name: 'بررسی دوباره' }))

    expect(await screen.findByText(nowhere)).toBeTruthy()
    expect(screen.getByText('هنوز بررسی نشده که ربات می‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
  })

  it('refused, reads the finding again — the card and the picker follow what the server has now', async () => {
    const notSent = 'ربات نتوانست پیام آزمایشی را بفرستد: یک بار ربات را در تلگرام باز کنید و /start بزنید، یا /emoji را در ربات بفرستید.'
    show(premiumTextsResponse({ enabled: false }))
    server().on('POST', CHECK, refusal(422, notSent, { check: [notSent] }))
    const button = await screen.findByRole('button', { name: 'بررسی دوباره' })
    expect((await toggle()).getAttribute('aria-disabled')).toBeNull()
    await until(() => expect(server().sent('GET', EMOJIS)).toHaveLength(1))
    // Whatever the server holds by then — the finding it had kept, or a newer one (the bot found unable since): shown.
    server().on('GET', PREMIUM, json(premiumTextsResponse({ enabled: false, can_enable: false, status: { ok: false, checked_at: '2026-10-06T09:30:00Z' } })))

    fireEvent.click(button)

    expect(await screen.findByText(notSent)).toBeTruthy()
    expect(await screen.findByText('ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
    expect((await toggle()).getAttribute('aria-disabled')).toBe('true')
    expect(server().sent('GET', PREMIUM)).toHaveLength(2)
    await until(() => expect(server().sent('GET', EMOJIS)).toHaveLength(2))
  })
})

describe('the map', () => {
  it('shows each plain emoji with its premium one and how many texts it is in', async () => {
    show()

    await screen.findByRole('button', { name: 'برداشتن ❤️ از فهرست' })
    expect(within(row('❤️')).getByText('در ۳ متن')).toBeTruthy()
    expect(within(row('❤️')).getByRole('img', { name: '❤️' }).getAttribute('src')).toBe('/api/admin/bot/custom-emojis/41/image?shop=1')
    expect(within(row('🔥')).getByText('فعلا در هیچ متنی نیست')).toBeTruthy()
  })

  it('takes a premium emoji not in it yet, its plain emoji prefilled and edited, saved with the rest', async () => {
    show()
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ items: [...premiumTextsResponse().premium_texts.items, { emoji: '🌟', emoji_id: '43', texts: 1 }] })))

    fireEvent.click(await screen.findByRole('button', { name: 'افزودن ایموجی' }))
    const picker = screen.getByRole('list', { name: 'ایموجی‌های پرمیوم' })
    expect(
      within(picker)
        .getAllByRole('button', { pressed: false })
        .map((button) => button.getAttribute('aria-label')),
    ).toEqual(['ایموجی پرمیوم ⭐'])

    fireEvent.click(within(picker).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    const plain = screen.getByRole('textbox', { name: 'ایموجی معمولی' }) as HTMLInputElement
    expect(plain.value).toBe('⭐')
    fireEvent.change(plain, { target: { value: '🌟' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن' }))

    expect(within(row('🌟')).getByText('با ذخیره به کار می‌رود')).toBeTruthy()
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'برداشتن 🌟 از فهرست' }))
    save()

    await until(() =>
      expect(server().sent('PUT', PREMIUM)[0]?.body).toEqual({
        enabled: true,
        items: [
          { emoji: '❤️', emoji_id: '41' },
          { emoji: '🔥', emoji_id: '42' },
          { emoji: '🌟', emoji_id: '43' },
        ],
      }),
    )
    expect(await within(row('🌟')).findByText('در ۱ متن')).toBeTruthy()
  })

  it('keeps the focus as the making of a row opens and is given up', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: 'افزودن ایموجی' }))
    expect(document.activeElement).toBe(screen.getByRole('group', { name: 'افزودن ایموجی' }))

    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    expect(screen.queryByRole('group', { name: 'افزودن ایموجی' })).toBeNull()
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'افزودن ایموجی' }))
  })

  it('hands the focus to a row in the making once the last row is taken off', async () => {
    show(premiumTextsResponse({ items: [{ emoji: '❤️', emoji_id: '41', texts: 3 }] }))

    fireEvent.click(await screen.findByRole('button', { name: 'افزودن ایموجی' }))
    const remove = screen.getByRole('button', { name: 'برداشتن ❤️ از فهرست' })
    remove.focus()
    fireEvent.click(remove)

    expect(document.activeElement).toBe(screen.getByRole('group', { name: 'افزودن ایموجی' }))
  })

  it('takes one emoji, and each plain one once — ❤ and ❤️ the same', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: 'افزودن ایموجی' }))
    fireEvent.click(screen.getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    const plain = screen.getByRole('textbox', { name: 'ایموجی معمولی' })

    fireEvent.change(plain, { target: { value: 'قلب' } })
    fireEvent.keyDown(plain, { key: 'Enter' })
    expect(screen.getByText('یک ایموجی معمولی بنویسید؛ همان که ایموجی پرمیوم در متن‌ها جای آن می‌نشیند.')).toBeTruthy()

    fireEvent.change(plain, { target: { value: '❤' } })
    fireEvent.keyDown(plain, { key: 'Enter' })
    expect(screen.getByText(/این ایموجی در فهرست هست/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'برداشتن ❤ از فهرست' })).toBeNull()
    expect(server().sent('PUT', PREMIUM)).toEqual([])
  })

  it('drops a row taken off, the save sending the rest', async () => {
    show()
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ items: [{ emoji: '🔥', emoji_id: '42', texts: 0 }] })))

    fireEvent.click(await screen.findByRole('button', { name: 'برداشتن ❤️ از فهرست' }))
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'برداشتن 🔥 از فهرست' }))
    save()

    await until(() => expect(server().sent('PUT', PREMIUM)[0]?.body).toEqual({ enabled: true, items: [{ emoji: '🔥', emoji_id: '42' }] }))
  })

  it('says a row’s refusal under that row — still with it once a row before it is taken off', async () => {
    const unknown = 'این ایموجی پرمیوم در فهرست ربات نیست؛ اول آن را با /emoji به ربات نشان دهید.'
    show()
    server().on('PUT', PREMIUM, refusal(422, 'اطلاعات واردشده معتبر نیست.', { 'items.1.emoji_id': [unknown] }))

    await screen.findByRole('button', { name: 'برداشتن 🔥 از فهرست' })
    fireEvent.click(screen.getByRole('switch', { name: 'ایموجی پرمیوم متن‌ها روشن باشد' }))
    save()

    expect(await within(row('🔥')).findByText(unknown)).toBeTruthy()
    expect(within(row('❤️')).queryByText(unknown)).toBeNull()
    expect(row('🔥').hasAttribute('data-invalid')).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'برداشتن ❤️ از فهرست' }))
    expect(within(row('🔥')).getByText(unknown)).toBeTruthy()
  })

  it('at its most, takes no more', async () => {
    show(premiumTextsResponse({ max: 2 }))

    const add = await screen.findByRole('button', { name: 'افزودن ایموجی' })
    expect(add.getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByText(/^حداکثر ۲ ایموجی/)).toBeTruthy()
    fireEvent.click(add)
    expect(screen.queryByRole('textbox', { name: 'ایموجی معمولی' })).toBeNull()
  })

  it('with no premium emoji in the bot yet, says to send /emoji there first', async () => {
    show(premiumTextsResponse({ items: [] }), { emojis: [], status: null })

    expect(await screen.findByText('هنوز ایموجی پرمیومی در فهرست ربات نیست')).toBeTruthy()
    expect(screen.getByText(/را بفرستید و بعد پیامی با ایموجی‌های پرمیومی که می‌خواهید/).textContent).toContain('/emoji')
    expect(screen.queryByRole('button', { name: 'افزودن ایموجی' })).toBeNull()
  })
})

describe('the read', () => {
  it('failed, says so with a retry in the card’s place', async () => {
    server().on('GET', PREMIUM, refusal(500, 'خطای سرور.'))
    render(<PremiumTextsCard />, { wrapper: providers().wrapper })

    expect(await screen.findByRole('button', { name: 'تلاش دوباره' })).toBeTruthy()
    expect(screen.queryByRole('switch')).toBeNull()
  })
})
