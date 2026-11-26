import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { BotAdminsSection } from '@/components/bot/settings/bot-admins-section'
import { BOT_SETTINGS_SECTIONS, sectionsOfShop } from '@/components/shell/nav'
import type { Session } from '@/lib/api-types'
import { openBotSettings } from '@/test/bot-settings'
import { AGENT_SHOP, signedIn } from '@/test/render'

/*
 * «مدیران ربات» in the bot settings (components/bot/settings/bot-admins-section): what the shop's admins do from the
 * bot's private chat — the whole management, with the owner's access in the main bot and the agent's in an agent's —,
 * where they are given and taken (the users list, its admins), and the warning before the role is given. Nothing to
 * set, nothing saved. Every shop's: an agent's has its own.
 */

const TITLE = 'مدیریت فروشگاه از ربات'

/** The section, once the signed-in panel draws it — the main shop's, or `session`'s. */
async function show(session?: Session) {
  render(<BotAdminsSection />, { wrapper: signedIn({ session }).wrapper })
  return screen.findByText(TITLE)
}

describe('the bot’s admins', () => {
  it('manage the whole main shop from the bot with the owner’s access, under their own name', async () => {
    await show()

    expect(screen.getByText(/با دکمه «⚙️ مدیریت» یا/)).toBeTruthy()
    expect(screen.getByText(/به نام خودشان ثبت می‌شود و هیچ مدیری درباره حساب خودش تصمیم نمی‌گیرد/)).toBeTruthy()
    expect(screen.getByText(/همان دسترسی مالک را دارد/)).toBeTruthy()
    expect(screen.getByText(/به خودش تخفیف اختصاصی یا گروه «همکار» نمی‌دهد/)).toBeTruthy()
    expect(screen.getByText('تنظیمات پنل، از جمله توکن ربات و دیتابیس؛ جز ورود به پنل، حساب‌های تلگرام مالک، آدرس Bot API و رمز Webhook که با خود مالک می‌ماند')).toBeTruthy()
    expect(screen.getByText('پشتیبان‌گیری و بازگردانی، و به‌روزرسانی')).toBeTruthy()
    expect(screen.queryByText(/همان دسترسی نماینده/)).toBeNull()
  })

  it('manage an agent’s shop with the agent’s access, while the agency stands', async () => {
    await show(AGENT_SHOP)

    expect(screen.getByText(/همان دسترسی نماینده را دارد: همه بخش‌هایی که نماینده در پنل خودش دارد، فقط برای همین فروشگاه/)).toBeTruthy()
    expect(screen.getByText(/با پایان نمایندگی، مدیریت از ربات برای نماینده و مدیرانش بسته می‌شود/)).toBeTruthy()
    expect(screen.queryByText(/همان دسترسی مالک/)).toBeNull()
    expect(screen.queryByText(/توکن ربات/)).toBeNull()
  })

  it('are given and taken on the users list, the role trusted with everything, and set nothing here', async () => {
    await show()

    expect(screen.getByRole('link', { name: 'لیست کاربران' }).getAttribute('href')).toBe('/users?role=admin')
    expect(screen.getByText(/از پیام بعدی‌اش مدیریت فروشگاه در ربات برایش بسته می‌شود/)).toBeTruthy()
    expect(screen.getByText(/فقط به کسانی بدهید که در همه چیز به آن‌ها اعتماد دارید/)).toBeTruthy()
    expect(screen.getByText(/دو مدیر این کارها را برای هم انجام می‌دهند/)).toBeTruthy()
    expect(screen.queryByRole('switch')).toBeNull()
    expect(screen.queryByRole('button', { name: 'ذخیره' })).toBeNull()
  })
})

describe('the section', () => {
  it('is the bot settings’ last, in the main shop', async () => {
    await openBotSettings('/bot-settings/bot-admins')

    expect(await screen.findByText(TITLE)).toBeTruthy()
    expect(screen.getByRole('region', { name: 'مدیران ربات' }).hidden).toBe(false)
    expect(BOT_SETTINGS_SECTIONS.at(-1)?.value).toBe('bot_admins')
  })

  it('is an agent’s shop’s too', async () => {
    await openBotSettings('/bot-settings/bot-admins', { session: AGENT_SHOP })

    expect(await screen.findByText(/همان دسترسی نماینده را دارد/)).toBeTruthy()
    expect(sectionsOfShop(BOT_SETTINGS_SECTIONS, false).map((section) => section.value)).toContain('bot_admins')
  })
})
