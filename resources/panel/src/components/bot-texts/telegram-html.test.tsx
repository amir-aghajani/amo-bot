import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import { fillSamples, plainText, TelegramHtml, visibleLength } from '@/components/bot-texts/telegram-html'
import type { CustomEmojisResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The bot texts' preview draws Telegram HTML as Telegram shows it — the tags Telegram knows become their look, anything
 * else is only its text — from the parsed tree, never injected: what an admin typed can never run, load or navigate.
 */

/** The elements a preview may consist of. */
const DRAWN = new Set(['STRONG', 'EM', 'U', 'S', 'CODE', 'PRE', 'SPAN', 'IMG'])

function preview(html: string) {
  return render(<TelegramHtml html={html} />, { wrapper: providers().wrapper }).container
}

beforeEach(() => {
  server().on('GET', '/api/admin/bot/custom-emojis', json({ emojis: [], status: null } satisfies CustomEmojisResponse))
})

describe('the preview', () => {
  it('draws each tag Telegram knows as its look', () => {
    const shown = preview(
      '<b>b</b><strong>s</strong><i>i</i><em>e</em><u>u</u><ins>n</ins><s>s</s><strike>k</strike><del>d</del>' +
        '<code>c</code><pre>p</pre><tg-spoiler>x</tg-spoiler><span class="tg-spoiler">y</span><blockquote>q</blockquote>',
    )

    expect(shown.querySelectorAll('strong')).toHaveLength(2)
    expect(shown.querySelectorAll('em')).toHaveLength(2)
    expect(shown.querySelectorAll('u')).toHaveLength(2)
    expect(shown.querySelectorAll('s')).toHaveLength(3)
    expect(shown.querySelector('code.tg-code')?.textContent).toBe('c')
    expect(shown.querySelector('pre.tg-pre')?.textContent).toBe('p')
    expect([...shown.querySelectorAll('span.tg-spoiler')].map((spoiler) => spoiler.textContent)).toEqual(['x', 'y'])
    expect(shown.querySelector('span.tg-quote')?.textContent).toBe('q')
  })

  it('keeps tags nested as they were', () => {
    const shown = preview('<b>bold <i>and italic</i></b> plain')

    expect(shown.querySelector('strong > em')?.textContent).toBe('and italic')
    expect(shown.textContent).toBe('bold and italic plain')
  })

  it('shows a link’s words and where it points, without following it', () => {
    const shown = preview('<a href="https://t.me/amobot">ربات ما</a>')

    expect(shown.querySelector('a')).toBeNull()
    const link = shown.querySelector('span.tg-link')
    expect(link?.textContent).toBe('ربات ما')
    expect(link?.getAttribute('title')).toBe('https://t.me/amobot')
  })

  it('makes nothing that could run, load or navigate out of what the admin typed', () => {
    const shown = preview(
      '<script>window.pwned = true</script><img src="x" onerror="window.pwned = true">' +
        '<iframe src="javascript:alert(1)"></iframe><svg onload="window.pwned = true"><circle r="1"/></svg>' +
        '<a href="javascript:alert(1)" onclick="window.pwned = true">link</a>' +
        '<b onmouseover="window.pwned = true" style="color:red" class="evil">bold</b><div>block</div>' +
        '<form action="https://evil.example"><input name="x"></form>',
    )

    for (const element of shown.querySelectorAll('*')) {
      expect(DRAWN.has(element.tagName), element.tagName).toBe(true)
      expect(element.tagName).not.toBe('IMG')
      for (const attribute of element.getAttributeNames()) {
        expect(['class', 'title'], `${element.tagName} ${attribute}`).toContain(attribute)
      }
    }
    expect(shown.querySelector('strong')?.className).toBe('')
    expect(shown.textContent).toContain('bold')
    expect(shown.textContent).toContain('block')
    expect((window as { pwned?: boolean }).pwned).toBeUndefined()
  })

  it('shows an entity as the character it stands for, never as markup', () => {
    const shown = preview('&lt;b&gt;not bold&lt;/b&gt; &amp; more')

    expect(shown.querySelector('strong')).toBeNull()
    expect(shown.textContent).toBe('<b>not bold</b> & more')
  })

  it('draws a premium emoji from its id, its plain emoji standing in for the picture', async () => {
    const shown = preview('<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji> عالی <tg-emoji>🔥</tg-emoji>')

    const picture = screen.getByRole('img', { name: '👍' })
    expect(picture.getAttribute('src')).toBe('/api/admin/bot/custom-emojis/5368324170671202286/image?shop=1')
    expect(shown.textContent).toContain('🔥')
    await until(() => expect(server().sent('GET', '/api/admin/bot/custom-emojis')).toHaveLength(1))
  })
})

describe('the samples in a wording', () => {
  it('fill each variable once — a sample is never read again for variables', () => {
    expect(fillSamples('سلام %name% — %plan%', { name: '%plan%', plan: 'Gold' }, false)).toBe('سلام %plan% — Gold')
  })

  it('are escaped for a text Telegram reads as HTML, as the bot escapes a real value', () => {
    const filled = fillSamples('<b>%name%</b>', { name: '<i>Amir</i> & "co"' }, true)

    expect(filled).toBe('<b>&lt;i&gt;Amir&lt;/i&gt; &amp; &quot;co&quot;</b>')
    expect(preview(filled).querySelector('em')).toBeNull()
  })

  it('go in as typed for a popup or a button', () => {
    expect(fillSamples('%name%!', { name: '<Amir>' }, false)).toBe('<Amir>!')
  })

  it('leave a variable without a sample as it is', () => {
    expect(fillSamples('%unknown% %name%', { name: 'Amir' }, true)).toBe('%unknown% Amir')
  })
})

describe('a wording read as text', () => {
  it('has its tags out, its entities read and blank lines folded, for a list', () => {
    expect(plainText('<b>سلام</b> &amp; خوش آمدید\n\n\n<i>به ربات</i>\n')).toBe('سلام & خوش آمدید\nبه ربات')
  })

  it('counts what the customer sees as Telegram does: markup is free, an emoji beyond the basic plane two units', () => {
    expect(visibleLength('<b>abc</b>')).toBe(3)
    expect(visibleLength('&lt;&gt;')).toBe(2)
    expect(visibleLength('<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>')).toBe(2)
    expect(visibleLength('<b>سلام</b> 😀')).toBe(7)
    expect(visibleLength('\n\nab')).toBe(4)
  })
})
