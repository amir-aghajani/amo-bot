import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PremiumTextsCard } from '@/components/bot-texts/premium-texts-card'
import type { CustomEmojiRow, CustomEmojisResponse, PremiumTextsResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { premiumTextsResponse } from '@/test/rows'
import { json, noContent, refusal, server } from '@/test/server'

/*
 * «ایموجی پرمیوم متن‌ها» on the bot texts page (components/bot-texts/premium-texts-card): whether the bot can send premium
 * emoji — able, unable, not checked yet — and «بررسی دوباره», why it could not check said in place; the switch held off
 * until the bot was found able, one on always turned off; the list of the emoji the texts carry, the most carried first
 * with where each is, each swapped for a premium emoji picked under its row (those other rows have not offered), changed
 * or taken off, filtered by swapped or not and folded after the first — a row being edited or refused shown whatever
 * the filter —; «ایموجی دیگر» for one the texts do not carry; saved whole, a row's refusal under it.
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
const list = () => screen.getByRole('list', { name: 'ایموجی‌های متن‌ها' })
/** The plain emoji of the rows shown, top to bottom. */
const shown = () => [...list().children].map((item) => item.querySelector('[id$="-plain"]')?.textContent)
/** A row of the list, by its plain emoji. */
const row = (plain: string) => [...list().children].find((item) => item.querySelector('[id$="-plain"]')?.textContent === plain) as HTMLElement
/** A row, once the list is drawn. */
async function findRow(plain: string) {
  await screen.findByRole('list', { name: 'ایموجی‌های متن‌ها' })
  return row(plain)
}
/** The picker open under a row, once the bot's premium emoji are read. */
const picker = (plain: string) => within(row(plain)).findByRole('list', { name: 'ایموجی‌های پرمیوم' })
const filter = (name: RegExp) => within(screen.getByRole('radiogroup', { name: 'ایموجی‌های نمایش‌داده‌شده' })).getByRole('radio', { name })
/** The saved map as the fixture has it: ❤️ as 41, 🔥 as 42; and as the PUT takes it. */
const MAP = premiumTextsResponse().premium_texts.items
const SENT = MAP.map((item) => ({ emoji: item.emoji, emoji_id: item.emoji_id }))

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

describe('the list', () => {
  it('shows every emoji the texts carry, the most carried first, with where it is and its swap', async () => {
    show()

    await findRow('✅')
    expect(shown()).toEqual(['✅', '❤️', '📅', '🛒', '🔥'])
    expect(within(row('✅')).getByText('در ۲۹ متن')).toBeTruthy()
    expect(within(row('📅')).getByText('در ۲ متن و ۱ دکمه منو')).toBeTruthy()
    expect(within(row('🛒')).getByText('در ۱ دکمه منو')).toBeTruthy()
    expect(within(row('🔥')).getByText('فعلا در هیچ متنی نیست')).toBeTruthy()
    expect(within(row('❤️')).getByRole('img', { name: '❤️' }).getAttribute('src')).toBe('/api/admin/bot/custom-emojis/41/image?shop=1')
    expect(within(row('✅')).queryByRole('img')).toBeNull()
    expect(within(row('✅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ✅' })).toBeTruthy()
    expect(within(row('✅')).queryByRole('button', { name: 'برداشتن ایموجی پرمیوم ✅' })).toBeNull()
    expect(within(row('❤️')).getByRole('button', { name: 'تغییر ایموجی پرمیوم ❤️' })).toBeTruthy()
    expect(within(row('❤️')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم ❤️' })).toBeTruthy()
  })

  it('swaps an emoji for a premium one picked under its row — those other rows have not offered —, kept with the save', async () => {
    show()
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ items: [...MAP, { emoji: '✅', emoji_id: '43', texts: 29 }] })))

    const choose = within(await findRow('✅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ✅' })
    fireEvent.click(choose)
    expect(choose.getAttribute('aria-expanded')).toBe('true')
    const offered = await picker('✅')
    expect(
      within(offered)
        .getAllByRole('button', { pressed: false })
        .map((button) => button.getAttribute('aria-label')),
    ).toEqual(['ایموجی پرمیوم ⭐'])

    fireEvent.click(within(offered).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    expect(within(row('✅')).queryByRole('list', { name: 'ایموجی‌های پرمیوم' })).toBeNull()
    expect(within(row('✅')).getByRole('img', { name: '✅' }).getAttribute('src')).toBe('/api/admin/bot/custom-emojis/43/image?shop=1')
    expect(within(row('✅')).getByText('با ذخیره به کار می‌رود')).toBeTruthy()
    expect(document.activeElement).toBe(within(row('✅')).getByRole('button', { name: 'تغییر ایموجی پرمیوم ✅' }))
    save()

    await until(() => expect(server().sent('PUT', PREMIUM)[0]?.body).toEqual({ enabled: true, items: [...SENT, { emoji: '✅', emoji_id: '43' }] }))
    await until(() => expect(within(row('✅')).queryByText('با ذخیره به کار می‌رود')).toBeNull())
  })

  it('changes a swap in its place and takes one off, the row staying with its swap to pick again', async () => {
    show()
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ items: [{ emoji: '❤️', emoji_id: '43', texts: 3 }] })))

    fireEvent.click(within(await findRow('❤️')).getByRole('button', { name: 'تغییر ایموجی پرمیوم ❤️' }))
    const offered = await picker('❤️')
    expect(within(offered).getByRole('button', { name: 'ایموجی پرمیوم ❤️' }).getAttribute('aria-pressed')).toBe('true')
    expect(within(offered).queryByRole('button', { name: 'ایموجی پرمیوم 🔥' })).toBeNull()
    fireEvent.click(within(offered).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    expect(within(row('❤️')).getByRole('img', { name: '❤️' }).getAttribute('src')).toBe('/api/admin/bot/custom-emojis/43/image?shop=1')

    fireEvent.click(within(row('🔥')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم 🔥' }))
    expect(shown()).toEqual(['✅', '❤️', '📅', '🛒', '🔥'])
    expect(within(row('🔥')).getByText('با ذخیره برداشته می‌شود')).toBeTruthy()
    expect(within(row('🔥')).queryByRole('img')).toBeNull()
    expect(document.activeElement).toBe(within(row('🔥')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم 🔥' }))
    save()

    await until(() => expect(server().sent('PUT', PREMIUM)[0]?.body).toEqual({ enabled: true, items: [{ emoji: '❤️', emoji_id: '43' }] }))
    // Saved, the emoji nothing carries leaves the list.
    await until(() => expect(shown()).toEqual(['✅', '❤️', '📅', '🛒']))
  })

  it('opens one picker at a time, given up by Escape — the focus back on its button', async () => {
    show()

    fireEvent.click(within(await findRow('✅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ✅' }))
    await picker('✅')
    fireEvent.click(within(row('📅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم 📅' }))
    expect(within(row('✅')).queryByRole('list', { name: 'ایموجی‌های پرمیوم' })).toBeNull()

    fireEvent.keyDown(within(await picker('📅')).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }), { key: 'Escape' })
    expect(within(row('📅')).queryByRole('list', { name: 'ایموجی‌های پرمیوم' })).toBeNull()
    expect(document.activeElement).toBe(within(row('📅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم 📅' }))

    // Escape on the swap that just opened it, too.
    const choose = within(row('🛒')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم 🛒' })
    fireEvent.click(choose)
    fireEvent.keyDown(choose, { key: 'Escape' })
    expect(choose.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(choose)
    expect(server().sent('PUT', PREMIUM)).toEqual([])
  })

  it('offers no cross on the premium emoji a row has: it is in use', async () => {
    show()

    fireEvent.click(within(await findRow('❤️')).getByRole('button', { name: 'تغییر ایموجی پرمیوم ❤️' }))
    const offered = await picker('❤️')
    expect(within(offered).queryByRole('button', { name: 'برداشتن ❤️ از لیست' })).toBeNull()
    expect(within(offered).getByRole('button', { name: 'برداشتن ⭐ از لیست' })).toBeTruthy()
  })

  it('once the bot’s last premium emoji is taken off in an open picker, hands the focus to why there is none', async () => {
    show(premiumTextsResponse({ items: [] }), { emojis: [emoji('43', '⭐')], status: null })
    server().on('DELETE', `${EMOJIS}/43`, noContent)

    fireEvent.click(within(await findRow('✅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ✅' }))
    const cross = within(await picker('✅')).getByRole('button', { name: 'برداشتن ⭐ از لیست' })
    cross.focus()
    fireEvent.click(cross)

    expect(await screen.findByText('هنوز ایموجی پرمیومی در فهرست ربات نیست')).toBeTruthy()
    expect(screen.queryByRole('list', { name: 'ایموجی‌های پرمیوم' })).toBeNull()
    expect(document.activeElement).toBe(screen.getByText('هنوز ایموجی پرمیومی در فهرست ربات نیست').closest('[data-add]'))
  })

  it('filters by swapped or not, a row swapped there staying until the filter changes — saved or not', async () => {
    show()
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ items: [...MAP, { emoji: '📅', emoji_id: '43', texts: 3 }] })))

    await findRow('✅')
    fireEvent.click(filter(/^عوض‌شده/))
    expect(shown()).toEqual(['❤️', '🔥'])
    fireEvent.click(filter(/^عوض‌نشده/))
    expect(shown()).toEqual(['✅', '📅', '🛒'])
    expect([filter(/^همه/), filter(/^عوض‌شده/), filter(/^عوض‌نشده/)].map((radio) => radio.textContent)).toEqual(['همه۵', 'عوض‌شده۲', 'عوض‌نشده۳'])

    fireEvent.click(within(row('📅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم 📅' }))
    fireEvent.click(within(await picker('📅')).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    expect(shown()).toEqual(['✅', '📅', '🛒'])
    expect(filter(/^عوض‌نشده/).textContent).toBe('عوض‌نشده۲')
    save()

    await until(() => expect(within(row('📅')).queryByText('با ذخیره به کار می‌رود')).toBeNull())
    expect(shown()).toEqual(['✅', '📅', '🛒'])
    fireEvent.click(filter(/^همه/))
    fireEvent.click(filter(/^عوض‌نشده/))
    expect(shown()).toEqual(['✅', '🛒'])
  })

  it('keeps a row edited back to as saved in sight — the swap put back in its saved place, nothing to save', async () => {
    show()
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ items: [{ emoji: '❤️', emoji_id: '43', texts: 3 }, MAP[1]!] })))

    fireEvent.click(within(await findRow('❤️')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم ❤️' }))
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
    fireEvent.click(filter(/^عوض‌نشده/))
    expect(shown()).toEqual(['✅', '❤️', '📅', '🛒'])
    fireEvent.click(within(row('❤️')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ❤️' }))
    fireEvent.click(within(await picker('❤️')).getByRole('button', { name: 'ایموجی پرمیوم ❤️' }))

    expect(shown()).toEqual(['✅', '❤️', '📅', '🛒'])
    expect(document.activeElement).toBe(within(row('❤️')).getByRole('button', { name: 'تغییر ایموجی پرمیوم ❤️' }))
    expect(within(row('❤️')).queryByText('با ذخیره به کار می‌رود')).toBeNull()
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()

    // Taken off and given another, it is back first, where it was saved.
    fireEvent.click(within(row('❤️')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم ❤️' }))
    fireEvent.click(within(row('❤️')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ❤️' }))
    fireEvent.click(within(await picker('❤️')).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    save()

    await until(() =>
      expect(server().sent('PUT', PREMIUM)[0]?.body).toEqual({
        enabled: true,
        items: [
          { emoji: '❤️', emoji_id: '43' },
          { emoji: '🔥', emoji_id: '42' },
        ],
      }),
    )
  })

  it('holds the rows while the card is being saved — a refusal is about the map sent', async () => {
    show()
    const saving = server().hold('PUT', PREMIUM)

    await findRow('❤️')
    fireEvent.click(screen.getByRole('switch', { name: 'ایموجی پرمیوم متن‌ها روشن باشد' }))
    save()
    await until(() => expect(saving.waiting).toBe(1))
    const held = [
      within(row('✅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ✅' }),
      within(row('❤️')).getByRole('button', { name: 'تغییر ایموجی پرمیوم ❤️' }),
      within(row('❤️')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم ❤️' }),
      screen.getByRole('button', { name: 'ایموجی دیگر' }),
    ]
    expect(held.map((button) => button.getAttribute('aria-disabled'))).toEqual(['true', 'true', 'true', 'true'])
    fireEvent.click(held[2]!)
    expect(within(row('❤️')).getByRole('img', { name: '❤️' })).toBeTruthy()

    saving.answer(json(premiumTextsResponse({ enabled: false })))
    await until(() => expect(held[0]?.getAttribute('aria-disabled')).toBeNull())
  })

  it('folds a long list after the most carried, «نمایش همه» showing the rest — a row swapped staying once folded again', async () => {
    const many = ['😀', '😁', '😂', '🤣', '😃', '😄', '😅', '😆', '😉', '😊', '😋', '😎', '😍', '😘', '🥰', '😗', '😙', '😚', '🙂', '🤗']
    show(premiumTextsResponse({ items: [], emojis: many.map((plain, place) => ({ emoji: plain, texts: many.length - place, menu: 0, emoji_id: null })) }))

    await findRow('😀')
    expect(shown()).toEqual(many.slice(0, 12))
    const more = screen.getByRole('button', { name: 'نمایش همه ۲۰ ایموجی' })
    expect(more.getAttribute('aria-expanded')).toBe('false')

    fireEvent.click(more)
    expect(shown()).toEqual(many)
    expect(more.getAttribute('aria-expanded')).toBe('true')
    expect(more.textContent).toBe('نمایش کمتر')

    fireEvent.click(within(row('🤗')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم 🤗' }))
    fireEvent.click(within(await picker('🤗')).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    fireEvent.click(more)
    expect(shown()).toEqual([...many.slice(0, 12), '🤗'])

    // Its swap taken off again — the row as saved —, it stays where the admin is, the focus on it.
    fireEvent.click(within(row('🤗')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم 🤗' }))
    expect(shown()).toEqual([...many.slice(0, 12), '🤗'])
    expect(document.activeElement).toBe(within(row('🤗')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم 🤗' }))
  })

  it('says so when the texts carry no emoji', async () => {
    show(premiumTextsResponse({ items: [], emojis: [] }))

    expect(await screen.findByText('متن‌های ربات فعلا ایموجی معمولی ندارند.')).toBeTruthy()
    expect(screen.queryByRole('radiogroup', { name: 'ایموجی‌های نمایش‌داده‌شده' })).toBeNull()
    expect(screen.getByRole('button', { name: 'ایموجی دیگر' })).toBeTruthy()
  })

  it('says a row’s refusal under that row, whatever the filter — still with it once a row before it is taken off, gone once it is swapped again', async () => {
    const unknown = 'این ایموجی پرمیوم در فهرست ربات نیست؛ اول آن را با /emoji به ربات نشان دهید.'
    show()
    server().on('PUT', PREMIUM, refusal(422, 'اطلاعات واردشده معتبر نیست.', { 'items.1.emoji_id': [unknown] }))

    await findRow('🔥')
    fireEvent.click(screen.getByRole('switch', { name: 'ایموجی پرمیوم متن‌ها روشن باشد' }))
    save()

    expect(await within(row('🔥')).findByText(unknown)).toBeTruthy()
    expect(within(row('❤️')).queryByText(unknown)).toBeNull()
    expect(row('🔥').hasAttribute('data-invalid')).toBe(true)
    fireEvent.click(filter(/^عوض‌نشده/))
    expect(shown()).toEqual(['✅', '📅', '🛒', '🔥'])
    fireEvent.click(filter(/^همه/))

    fireEvent.click(within(row('❤️')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم ❤️' }))
    expect(within(row('🔥')).getByText(unknown)).toBeTruthy()
    // Put back, ❤️ is first again (its saved place): the refusal moves down with 🔥.
    fireEvent.click(within(row('❤️')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ❤️' }))
    fireEvent.click(within(await picker('❤️')).getByRole('button', { name: 'ایموجی پرمیوم ❤️' }))
    expect(within(row('🔥')).getByText(unknown)).toBeTruthy()
    expect(within(row('❤️')).queryByText(unknown)).toBeNull()

    fireEvent.click(within(row('🔥')).getByRole('button', { name: 'تغییر ایموجی پرمیوم 🔥' }))
    fireEvent.click(within(await picker('🔥')).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    expect(within(row('🔥')).queryByText(unknown)).toBeNull()
    expect(row('🔥').hasAttribute('data-invalid')).toBe(false)
  })

  it('at its most, takes no new swap — one swapped may still change', async () => {
    show(premiumTextsResponse({ max: 2 }))

    const choose = within(await findRow('✅')).getByRole('button', { name: 'انتخاب ایموجی پرمیوم ✅' })
    expect(choose.getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByText(/^حداکثر ۲ ایموجی/)).toBeTruthy()
    fireEvent.click(choose)
    expect(choose.getAttribute('aria-expanded')).toBe('false')
    const other = screen.getByRole('button', { name: 'ایموجی دیگر' })
    expect(other.getAttribute('aria-disabled')).toBe('true')
    // Each held one points at why.
    for (const held of [choose, other]) expect(document.getElementById(held.getAttribute('aria-describedby') ?? '')?.textContent).toMatch(/^حداکثر ۲ ایموجی/)
    expect(within(row('❤️')).getByRole('button', { name: 'تغییر ایموجی پرمیوم ❤️' }).getAttribute('aria-disabled')).toBeNull()
  })

  it('with no premium emoji in the bot yet, says to send /emoji there first — nothing to pick from', async () => {
    show(premiumTextsResponse(), { emojis: [], status: null })

    expect(await screen.findByText('هنوز ایموجی پرمیومی در فهرست ربات نیست')).toBeTruthy()
    expect(screen.getByText(/را بفرستید و بعد پیامی با ایموجی‌های پرمیومی که می‌خواهید/).textContent).toContain('/emoji')
    expect(shown()).toEqual(['✅', '❤️', '📅', '🛒', '🔥'])
    expect(screen.queryByRole('button', { name: /^انتخاب ایموجی پرمیوم/ })).toBeNull()
    expect(screen.queryByRole('button', { name: /^تغییر ایموجی پرمیوم/ })).toBeNull()
    expect(screen.queryByRole('button', { name: 'ایموجی دیگر' })).toBeNull()

    // A swap may still be taken off, the focus to why there is nothing to pick.
    fireEvent.click(within(row('❤️')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم ❤️' }))
    expect(document.activeElement).toBe(screen.getByText('هنوز ایموجی پرمیومی در فهرست ربات نیست').closest('[data-add]'))
  })
})

describe('«ایموجی دیگر»', () => {
  it('swaps an emoji the texts do not carry, its plain emoji prefilled and edited — a row of its own, saved with the rest', async () => {
    show()
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ items: [...MAP, { emoji: '🌟', emoji_id: '43', texts: 0 }] })))

    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی دیگر' }))
    const adding = within(screen.getByRole('group', { name: 'ایموجی دیگر' }))
    const offered = await adding.findByRole('list', { name: 'ایموجی‌های پرمیوم' })
    expect(
      within(offered)
        .getAllByRole('button', { pressed: false })
        .map((button) => button.getAttribute('aria-label')),
    ).toEqual(['ایموجی پرمیوم ⭐'])

    fireEvent.click(within(offered).getByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    const plain = adding.getByRole('textbox', { name: 'ایموجی معمولی' }) as HTMLInputElement
    expect(plain.value).toBe('⭐')
    fireEvent.change(plain, { target: { value: '🌟' } })
    fireEvent.click(adding.getByRole('button', { name: 'افزودن' }))

    expect(shown()).toEqual(['✅', '❤️', '📅', '🛒', '🔥', '🌟'])
    expect(within(row('🌟')).getByText('با ذخیره به کار می‌رود')).toBeTruthy()
    expect(document.activeElement).toBe(within(row('🌟')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم 🌟' }))
    save()

    await until(() => expect(server().sent('PUT', PREMIUM)[0]?.body).toEqual({ enabled: true, items: [...SENT, { emoji: '🌟', emoji_id: '43' }] }))
    expect(await within(row('🌟')).findByText('فعلا در هیچ متنی نیست')).toBeTruthy()
  })

  it('swaps one the texts carry in its own row', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی دیگر' }))
    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'ایموجی معمولی' }), { target: { value: '📅' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن' }))

    expect(shown()).toEqual(['✅', '❤️', '📅', '🛒', '🔥'])
    expect(within(row('📅')).getByRole('img', { name: '📅' }).getAttribute('src')).toBe('/api/admin/bot/custom-emojis/43/image?shop=1')
    expect(document.activeElement).toBe(within(row('📅')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم 📅' }))
  })

  it('keeps the focus as it opens and is given up, and once a swap of its own is taken off', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی دیگر' }))
    expect(document.activeElement).toBe(screen.getByRole('group', { name: 'ایموجی دیگر' }))
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    expect(screen.queryByRole('group', { name: 'ایموجی دیگر' })).toBeNull()
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'ایموجی دیگر' }))

    fireEvent.click(screen.getByRole('button', { name: 'ایموجی دیگر' }))
    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'ایموجی معمولی' }), { target: { value: '🌟' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن' }))
    fireEvent.click(within(row('🌟')).getByRole('button', { name: 'برداشتن ایموجی پرمیوم 🌟' }))

    expect(shown()).toEqual(['✅', '❤️', '📅', '🛒', '🔥'])
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'ایموجی دیگر' }))
  })

  it('takes one emoji, and each plain one once — ❤ and ❤️ the same', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی دیگر' }))
    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    const plain = screen.getByRole('textbox', { name: 'ایموجی معمولی' })

    fireEvent.change(plain, { target: { value: 'قلب' } })
    fireEvent.keyDown(plain, { key: 'Enter' })
    expect(screen.getByText('یک ایموجی معمولی بنویسید؛ همان که ایموجی پرمیوم در متن‌ها جای آن می‌نشیند.')).toBeTruthy()

    fireEvent.change(plain, { target: { value: '❤' } })
    fireEvent.keyDown(plain, { key: 'Enter' })
    expect(screen.getByText(/این ایموجی در فهرست بالا عوض شده است/)).toBeTruthy()
    expect(within(row('❤️')).getByRole('img', { name: '❤️' }).getAttribute('src')).toBe('/api/admin/bot/custom-emojis/41/image?shop=1')
    expect(server().sent('PUT', PREMIUM)).toEqual([])
  })

  it('takes, as the server does, only one drawn as an emoji (★ and ━ are signs; ❤ is one), a half-space after it no part of it', async () => {
    const halfSpace = String.fromCodePoint(0x200c)
    show(premiumTextsResponse({ items: [] }))
    server().on('PUT', PREMIUM, json(premiumTextsResponse({ items: [{ emoji: '🌟', emoji_id: '41', texts: 0 }] })))

    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی دیگر' }))
    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی پرمیوم ❤️' }))
    const plain = screen.getByRole('textbox', { name: 'ایموجی معمولی' })
    for (const sign of ['★', '━']) {
      fireEvent.change(plain, { target: { value: sign } })
      fireEvent.keyDown(plain, { key: 'Enter' })
      expect(screen.getByText('یک ایموجی معمولی بنویسید؛ همان که ایموجی پرمیوم در متن‌ها جای آن می‌نشیند.'), sign).toBeTruthy()
    }
    fireEvent.change(plain, { target: { value: '❤' } })
    fireEvent.keyDown(plain, { key: 'Enter' })
    expect(within(row('❤️')).getByRole('img', { name: '❤️' })).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'ایموجی دیگر' }))
    fireEvent.click(await screen.findByRole('button', { name: 'ایموجی پرمیوم ⭐' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'ایموجی معمولی' }), { target: { value: `🌟${halfSpace}` } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن' }))
    save()

    await until(() =>
      expect(server().sent('PUT', PREMIUM)[0]?.body).toEqual({
        enabled: true,
        items: [
          { emoji: '❤', emoji_id: '41' },
          { emoji: '🌟', emoji_id: '43' },
        ],
      }),
    )
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
