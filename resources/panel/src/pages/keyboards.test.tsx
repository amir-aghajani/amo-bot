import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { BotTextRow, BotTextsResponse, CustomEmojisResponse, KeyboardLayoutData, KeyboardResponse, KeyboardsResponse } from '@/lib/api-types'
import { KeyboardsPage } from '@/pages/keyboards'
import { providers, until } from '@/test/render'
import { premiumTextsResponse } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The keyboards page (pages/keyboards): the start menu's preview, under the greeting it goes out with — both as the bot
 * sends them, «ایموجی پرمیوم متن‌ها» (while on) drawing the greeting's mapped emoji premium as it does the menu's icons;
 * a menu saved reads «ایموجی پرمیوم متن‌ها» again (its list counts the inline menu's buttons).
 */

const START: KeyboardLayoutData = { name: 'start', title: 'منوی اصلی', is_default: true, type: 'reply', rows: [[{ action: 'buy', label: 'خرید', style: null, icon: null }]] }

const KEYBOARDS: KeyboardsResponse = {
  keyboards: [START],
  actions: [{ key: 'buy', title: 'خرید', label: 'خرید', screen: 'menu:buy' }],
  styles: [],
  limits: { rows: 3, per_row: 3, label: 32 },
}

const WELCOME: BotTextRow = {
  key: 'welcome',
  group: 'start',
  kind: 'message',
  html: true,
  limit: 4096,
  title: 'خوش‌آمد',
  description: '',
  variables: [],
  default: 'سلام ❤️',
  value: 'سلام ❤️',
  customized: false,
}

function show() {
  server()
    .on('GET', '/api/admin/keyboards', json(KEYBOARDS))
    .on('GET', '/api/admin/bot/texts', json({ groups: [{ key: 'start', title: 'شروع', texts: [WELCOME] }] } satisfies BotTextsResponse))
    .on('GET', '/api/admin/bot/premium-texts', json(premiumTextsResponse()))
    .on('GET', '/api/admin/bot/custom-emojis', json({ emojis: [], status: null } satisfies CustomEmojisResponse))
  render(<KeyboardsPage />, { wrapper: providers().wrapper })
}

describe('the keyboards page', () => {
  it('shows the greeting above the menu with the shop’s premium emoji drawn, as the bot sends it', async () => {
    show()

    const preview = await screen.findByRole('figure', { name: 'پیش‌نمایش در تلگرام' })
    await until(() => expect([...preview.querySelectorAll('img')].map((img) => img.getAttribute('src'))).toEqual(['/api/admin/bot/custom-emojis/41/image?shop=1']))
    expect(preview.textContent).toContain('سلام')
  })

  it('reads «ایموجی پرمیوم متن‌ها» again once the menu is saved — where each emoji is follows its buttons', async () => {
    show()
    server().on('PUT', '/api/admin/keyboards/start', json({ keyboard: { ...START, type: 'inline' } } satisfies KeyboardResponse))
    const types = await screen.findByRole('radiogroup', { name: 'نوع کیبورد' })
    await until(() => expect(server().sent('GET', '/api/admin/bot/premium-texts')).toHaveLength(1))

    fireEvent.click(within(types).getByRole('radio', { name: 'دکمه‌های شیشه‌ای' }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/keyboards/start')).toHaveLength(1))
    await until(() => expect(server().sent('GET', '/api/admin/bot/premium-texts')).toHaveLength(2))
  })
})
