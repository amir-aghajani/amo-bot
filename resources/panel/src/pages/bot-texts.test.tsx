import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { BotTextRow, BotTextsResponse } from '@/lib/api-types'
import { BotTextsPage } from '@/pages/bot-texts'
import { providers } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The bot texts (pages/bot-texts): the «همه» and «تغییر یافته» tabs count what the search finds, so a number never
 * promises texts the list below does not show.
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
