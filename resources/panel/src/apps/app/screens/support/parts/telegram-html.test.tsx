import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { linkTarget, TelegramHtml } from '@/apps/app/screens/support/parts/telegram-html'
import * as app from '@/apps/app/test/app'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The shop's tutorial in Telegram's HTML (screens/support/parts/telegram-html): Telegram's own tags built again, nothing
 * else — no script, picture, frame or handler, no link that is not a web address (a tg:// one by its t.me form) — and a
 * spoiler shown when tapped, a custom emoji as its character, line breaks kept, entities read as Telegram reads them.
 */

/** The text drawn: the renderer's own element. */
function draw(html: string): Element {
  const drawn = render(<TelegramHtml html={html} />).container.firstElementChild
  if (drawn === null) throw new Error('nothing drawn')

  return drawn
}

describe('the Telegram HTML renderer', () => {
  it('keeps Telegram’s formatting tags', () => {
    const page = draw(
      '<b>پررنگ</b> <strong>قوی</strong> <i>کج</i> <u>زیرخط</u> <s>خط‌خورده</s> <code>vless://abc</code>\n<pre><code class="language-text">line 1\nline 2</code></pre><blockquote>نقل قول</blockquote>',
    )

    expect(page.querySelectorAll('strong')).toHaveLength(2)
    expect(page.querySelector('em')?.textContent).toBe('کج')
    expect(page.querySelector('u')?.textContent).toBe('زیرخط')
    expect(page.querySelector('s')?.textContent).toBe('خط‌خورده')
    expect(page.querySelector('code')?.textContent).toBe('vless://abc')
    expect(page.querySelector('pre')?.textContent).toBe('line 1\nline 2')
    expect(page.querySelector('pre code')).toBeNull()
    expect(page.querySelector('blockquote')?.textContent).toBe('نقل قول')
  })

  it('drops scripts, pictures, frames, handlers and every attribute but a checked link', () => {
    // happy-dom, unlike a browser, gives a parsed document's frame a page to load, and logs that it may not: no matter.
    const page = draw(
      '<script>alert("x")</script><img src="x" onerror="alert(1)"><iframe name="frame"></iframe><style>body{display:none}</style>' +
        '<b onclick="alert(2)" style="color:red" class="x">ایمن</b><svg onload="alert(3)"><text>svg</text></svg><a href="javascript:alert(4)">دام</a>' +
        '<a href="data:text/html,hi">داده</a><a href="/local">نسبی</a><div onmouseover="alert(5)">متن ساده</div><object data="x"></object>',
    )

    for (const tag of ['script', 'img', 'iframe', 'style', 'svg', 'object', 'div']) expect(page.querySelector(tag), tag).toBeNull()
    expect(page.querySelectorAll('a')).toHaveLength(0)
    expect(page.textContent).not.toContain('alert')
    expect(page.textContent).not.toContain('svg')
    expect(page.textContent).toContain('دام')
    expect(page.textContent).toContain('متن ساده')
    const bold = page.querySelector('strong')
    expect(bold?.textContent).toBe('ایمن')
    expect(bold?.attributes).toHaveLength(0)
    for (const element of page.querySelectorAll('*')) {
      for (const attribute of element.attributes) expect(attribute.name.startsWith('on'), `${element.tagName} ${attribute.name}`).toBe(false)
    }
  })

  it('opens a web link in Telegram’s browser and a t.me or tg:// one inside Telegram', () => {
    const { app: webApp } = app.telegram()
    const page = draw('<a href="https://example.com/help?x=1">راهنما</a> <a href="tg://resolve?domain=sample_support">پشتیبانی</a> <a href="http://t.me/sample_bot?start=ref">ربات</a>')

    const links = page.querySelectorAll('a')
    expect([...links].map((link) => link.getAttribute('href'))).toEqual(['https://example.com/help?x=1', 'https://t.me/sample_support', 'https://t.me/sample_bot?start=ref'])
    fireEvent.click(screen.getByText('راهنما'))
    expect(webApp.openLink).toHaveBeenCalledWith('https://example.com/help?x=1')
    fireEvent.click(screen.getByText('پشتیبانی'))
    expect(webApp.openTelegramLink).toHaveBeenCalledWith('https://t.me/sample_support')
  })

  it('reads only addresses that lead somewhere safe', () => {
    expect(linkTarget('https://t.me/sample_bot')).toBe('https://t.me/sample_bot')
    expect(linkTarget('https://telegram.me/sample_bot')).toBe('https://t.me/sample_bot')
    expect(linkTarget('tg://join?invite=AbC-12')).toBe('https://t.me/+AbC-12')
    expect(linkTarget('tg://resolve?domain=sample_bot&startapp=x')).toBe('https://t.me/sample_bot?startapp=x')
    expect(linkTarget('tg://user?id=42')).toBeNull()
    expect(linkTarget(' JavaScript:alert(1)')).toBeNull()
    expect(linkTarget('vbscript:x')).toBeNull()
    expect(linkTarget('https://user:pass@example.com')).toBeNull()
    expect(linkTarget(null)).toBeNull()
  })

  it('hides a spoiler until it is tapped, and draws a custom emoji as its character', () => {
    draw('رمز: <tg-spoiler>1234</tg-spoiler> و <span class="tg-spoiler">5678</span> <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>')

    const spoilers = screen.getAllByRole('button', { name: 'نمایش متن پنهان' })
    expect(spoilers).toHaveLength(2)
    fireEvent.click(spoilers[0]!)
    expect(screen.getAllByRole('button', { name: 'نمایش متن پنهان' })).toHaveLength(1)
    expect(screen.getByText('1234')).toBeTruthy()
    expect(screen.getByText(/👍/)).toBeTruthy()
  })

  it('shows a spoiler from the keyboard, Space scrolling nothing', () => {
    draw('رمز: <tg-spoiler>1234</tg-spoiler>')

    const space = fireEvent.keyDown(screen.getByRole('button', { name: 'نمایش متن پنهان' }), { key: ' ' })

    expect(space).toBe(false)
    expect(screen.queryByRole('button', { name: 'نمایش متن پنهان' })).toBeNull()
    expect(screen.getByText('1234')).toBeTruthy()
  })

  it('copies inline code from the keyboard too', async () => {
    const write = vi.fn(() => Promise.resolve())
    vi.spyOn(navigator, 'clipboard', 'get').mockReturnValue({ writeText: write } as unknown as Clipboard)
    draw('کانفیگ: <code>vless://abc</code>')

    const code = screen.getByRole('button', { name: 'vless://abc' })
    expect(fireEvent.keyDown(code, { key: 'Enter' })).toBe(false)
    expect(fireEvent.keyDown(code, { key: ' ' })).toBe(false)

    expect(write).toHaveBeenCalledTimes(2)
    expect(write).toHaveBeenCalledWith('vless://abc')
  })

  it('opens a folded quote from the keyboard, as a button; a link inside it keeps its Enter', () => {
    draw('<blockquote expandable>یک نقل قول بلند <a href="https://example.com/guide">راهنما</a></blockquote>')

    const quote = screen.getByRole('button', { name: /یک نقل قول بلند/ })
    expect(quote.getAttribute('aria-expanded')).toBe('false')
    expect(quote.getAttribute('tabindex')).toBe('0')
    fireEvent.keyDown(screen.getByRole('link', { name: 'راهنما' }), { key: 'Enter' })
    expect(screen.getByRole('button', { name: /یک نقل قول بلند/ })).toBeTruthy()

    expect(fireEvent.keyDown(quote, { key: ' ' })).toBe(false)
    expect(screen.queryByRole('button', { name: /یک نقل قول بلند/ })).toBeNull()
    expect(document.querySelector('blockquote')?.getAttribute('tabindex')).toBeNull()
  })

  it('keeps line breaks, and reads entities as Telegram does', () => {
    const page = draw('خط اول\nخط دوم<br>خط سوم &lt;b&gt; &amp; &#1587; &nbsp;')

    expect(page.textContent).toBe('خط اول\nخط دوم\nخط سوم <b> & س &nbsp;')
    expect(page.querySelector('b')).toBeNull()
  })
})
