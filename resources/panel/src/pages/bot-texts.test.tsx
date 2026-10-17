import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { BotTextRow, BotTextsResponse } from '@/lib/api-types'
import { BotTextsPage } from '@/pages/bot-texts'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The bot texts (pages/bot-texts): the «همه» and «تغییر یافته» tabs count what the search finds, so a number never
 * promises texts the list below does not show; a read that failed is asked again once, the retry spinning meanwhile.
 */

function text(key: string, title: string, customized: boolean): BotTextRow {
  return { key, group: 'shop', kind: 'message', html: true, limit: 4096, title, description: '', variables: [], default: title, value: title, customized }
}

const TEXTS: BotTextsResponse = {
  groups: [
    {
      key: 'shop',
      title: 'فروشگاه',
      texts: [text('welcome', 'خوش‌آمد', true), text('plans', 'لیست پلن‌ها', false), text('plan_details', 'جزئیات پلن', true)],
    },
  ],
}

/** The count a tab shows beside its name. */
const count = (name: string) => screen.getByRole('tab', { name: new RegExp(name) }).textContent?.replace(name, '')

describe('the bot texts’ tabs', () => {
  it('count what the search finds', async () => {
    server().on('GET', '/api/admin/bot/texts', json(TEXTS))
    render(<BotTextsPage />, { wrapper: providers().wrapper })
    await screen.findByText('خوش‌آمد', { selector: 'button' })
    expect([count('همه'), count('تغییر یافته')]).toEqual(['۳', '۲'])

    fireEvent.change(screen.getByRole('searchbox', { name: 'جستجوی متن' }), { target: { value: 'پلن' } })

    expect([count('همه'), count('تغییر یافته')]).toEqual(['۲', '۱'])
  })
})

describe('the bot texts’ read', () => {
  it('failed, stays said while it is asked again — its button keeping the focus, spinning, asking once —, until the answer', async () => {
    server().on('GET', '/api/admin/bot/texts', refusal(500, 'خطای سرور.'))
    render(<BotTextsPage />, { wrapper: providers().wrapper })
    const retry = await screen.findByRole('button', { name: 'تلاش دوباره' })
    const again = server().hold('GET', '/api/admin/bot/texts')

    retry.focus()
    fireEvent.click(retry)
    await until(() => expect(retry.getAttribute('aria-busy')).toBe('true'))
    expect(document.activeElement).toBe(retry)
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0)
    fireEvent.click(retry)
    expect(server().sent('GET', '/api/admin/bot/texts')).toHaveLength(2)

    again.answer(json(TEXTS))
    expect(await screen.findByRole('button', { name: 'خوش‌آمد' })).toBeTruthy()
  })
})

describe('the bot texts’ list', () => {
  it('shows what the search and the tab find, and nothing else', async () => {
    server().on('GET', '/api/admin/bot/texts', json(TEXTS))
    render(<BotTextsPage />, { wrapper: providers().wrapper })
    await screen.findByRole('button', { name: 'خوش‌آمد' })
    const search = screen.getByRole('searchbox', { name: 'جستجوی متن' })

    fireEvent.change(search, { target: { value: 'پلن' } })
    expect(screen.queryByRole('button', { name: 'خوش‌آمد' })).toBeNull()
    expect(screen.getByRole('button', { name: 'لیست پلن‌ها' })).toBeTruthy()

    fireEvent.click(screen.getByRole('tab', { name: /تغییر یافته/ }))
    expect(screen.queryByRole('button', { name: 'لیست پلن‌ها' })).toBeNull()
    expect(screen.getByRole('button', { name: 'جزئیات پلن' })).toBeTruthy()

    fireEvent.change(search, { target: { value: 'هیچ' } })
    expect(screen.getByText('متنی پیدا نشد')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'جزئیات پلن' })).toBeNull()

    fireEvent.change(search, { target: { value: '' } })
    expect(screen.getByRole('button', { name: 'خوش‌آمد' })).toBeTruthy()
    expect(screen.queryByText('متنی پیدا نشد')).toBeNull()
  })
})

describe('the bot texts’ search', () => {
  it('finds a default by its «#» or «/menu» typed without the invisible left-to-right mark before it, or pasted with it', async () => {
    const paid = { ...text('pay_processing', 'پرداخت انجام شد', false), value: '✅ پرداخت سفارش \u{200E}#%order% انجام شد' }
    const waiting = { ...text('receipt_waiting', 'منتظر رسید', false), value: 'برای کار دیگری \u{200E}/menu را بفرستید.' }
    server().on('GET', '/api/admin/bot/texts', json({ groups: [{ key: 'shop', title: 'فروشگاه', texts: [paid, waiting] }] }))
    render(<BotTextsPage />, { wrapper: providers().wrapper })
    await screen.findByRole('button', { name: 'پرداخت انجام شد' })
    const search = screen.getByRole('searchbox', { name: 'جستجوی متن' })

    for (const typed of ['سفارش #', 'سفارش \u{200E}#', 'سفارش \u{200F}#']) {
      fireEvent.change(search, { target: { value: typed } })
      expect(screen.getByRole('button', { name: 'پرداخت انجام شد' })).toBeTruthy()
      expect(screen.queryByRole('button', { name: 'منتظر رسید' })).toBeNull()
    }

    fireEvent.change(search, { target: { value: 'دیگری /menu' } })
    expect(screen.getByRole('button', { name: 'منتظر رسید' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'پرداخت انجام شد' })).toBeNull()
  })
})
