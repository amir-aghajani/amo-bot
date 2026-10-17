import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import { fillSamples, plainText, TelegramHtml, TelegramText, visibleLength } from '@/components/bot-texts/telegram-html'
import type { CustomEmojisResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The bot texts' preview draws Telegram HTML as Telegram shows it — the tags Telegram knows become their look, anything
 * else is only its text; the entities Telegram reads, any other as typed; each paragraph in its own direction — from the
 * parsed tree, never injected: what an admin typed can never run, load or navigate.
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

  it('shows a link’s text and where it points, without following it', () => {
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

  it('reads the entities Telegram reads — its four names and numeric ones —, any other as typed, as the customer sees it', () => {
    const typed = '&lt;&gt;&amp;&quot; &#128512; &nbsp;&copy; &LT; &ltx'
    const seen = '<>&" 😀 &nbsp;&copy; &LT; &ltx'

    expect(preview(typed).textContent).toBe(seen)
    expect(plainText(typed)).toBe(seen)
  })

  it('lays each paragraph out in its own first letter’s direction, as Telegram does — a quote’s and a code block’s too', () => {
    const { container } = render(<TelegramText html={'سلام\nhttps://sub.example.com/s/1<blockquote>quote</blockquote><pre>code</pre>'} />, { wrapper: providers().wrapper })

    const paragraphs = '[unicode-bidi:plaintext]'
    expect(container.firstElementChild?.classList.contains(paragraphs)).toBe(true)
    expect(container.querySelector('.tg-quote')?.classList.contains(paragraphs)).toBe(true)
    expect(container.querySelector('.tg-pre')?.classList.contains(paragraphs)).toBe(true)
  })

  it('shows a date and time as its words, formatting inside it drawn', () => {
    const shown = preview('تمدید تا <tg-time unix="1647531900" format="wDT"><b>۲۶</b> اسفند</tg-time>')

    expect(shown.textContent).toBe('تمدید تا ۲۶ اسفند')
    expect(shown.querySelector('strong')?.textContent).toBe('۲۶')
    expect(shown.querySelector('tg-time')).toBeNull()
  })

  it('draws a premium emoji from its id, its plain emoji standing in for the picture', async () => {
    const shown = preview('<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji> عالی <tg-emoji>🔥</tg-emoji>')

    const picture = screen.getByRole('img', { name: '👍' })
    expect(picture.getAttribute('src')).toBe('/api/admin/bot/custom-emojis/5368324170671202286/image?shop=1')
    expect(shown.textContent).toContain('🔥')
    await until(() => expect(server().sent('GET', '/api/admin/bot/custom-emojis')).toHaveLength(1))
  })
})

/** Plain samples by name: what the bot escapes in an HTML text. */
const plain = (samples: Record<string, string>) => Object.fromEntries(Object.entries(samples).map(([name, sample]) => [name, { sample, html: false }]))

describe('the samples in a wording', () => {
  it('fill each variable once — a sample is never read again for variables', () => {
    expect(fillSamples('سلام %name% — %plan%', plain({ name: '%plan%', plan: 'Gold' }), false)).toBe('سلام %plan% — Gold')
  })

  it('are escaped for a text Telegram reads as HTML, as the bot escapes a real value', () => {
    const filled = fillSamples('<b>%name%</b>', plain({ name: '<i>Amir</i> & "co"' }), true)

    expect(filled).toBe('<b>&lt;i&gt;Amir&lt;/i&gt; &amp; &quot;co&quot;</b>')
    expect(preview(filled).querySelector('em')).toBeNull()
  })

  it('go in as they are where the bot fills the variable with another text’s wording — its markup drawn, not shown as tags', () => {
    const filled = fillSamples('💳 %card%%card_holder%', { card: { sample: '6037 & co', html: false }, card_holder: { sample: '\n👤 به نام <b>سارا احمدی</b>', html: true } }, true)

    expect(filled).toBe('💳 6037 &amp; co\n👤 به نام <b>سارا احمدی</b>')
    const shown = preview(filled)
    expect(shown.querySelector('strong')?.textContent).toBe('سارا احمدی')
    expect(shown.textContent).not.toContain('<b>')
  })

  it('take the left-to-right mark the bot puts before a handle or a number with words before it on its line, and only there', () => {
    expect(fillSamples('🤖 ربات شما: %bot%\n👤 %bot%', plain({ bot: '@my_shop_bot' }), true)).toBe('🤖 ربات شما: \u{200E}@my_shop_bot\n👤 @my_shop_bot')
    expect(fillSamples('<b>به</b> %name% و %name%', plain({ name: '#12' }), false)).toBe('<b>به</b> \u{200E}#12 و \u{200E}#12')
    expect(fillSamples('<b>%client%</b> %name%', plain({ client: 'ali_1', name: '@sara' }), true), 'a tag or a variable is no word').toBe('<b>ali_1</b> @sara')
    expect(fillSamples('به \u{200E}%name% و %plan%', plain({ name: '@sara', plan: 'یک‌ماهه' }), false), 'one mark, and none before words').toBe('به \u{200E}@sara و یک‌ماهه')
  })

  it('go in as typed for a popup or a button', () => {
    expect(fillSamples('%name%!', plain({ name: '<Amir>' }), false)).toBe('<Amir>!')
  })

  it('leave a variable without a sample as it is', () => {
    expect(fillSamples('%unknown% %name%', plain({ name: 'Amir' }), true)).toBe('%unknown% Amir')
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
    // As the server's check counts it (TelegramHtml::visibleLength()): an entity as Telegram reads it — one it does not
    // read is shown as typed, and counted so; one it reads is one character, its «;» or not.
    expect(visibleLength('<b>سلام</b> <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji> &lt;')).toBe(9)
    expect(visibleLength('⚠️')).toBe(2)
    expect(visibleLength('&nbsp;a')).toBe(7)
    expect(visibleLength('&lt&#65&#x42;')).toBe(3)
    expect(visibleLength('<tg-time unix="1647531900" format="wDT">۲۲:۴۵ فردا</tg-time>')).toBe(10)
    // A «>» inside a quoted value is the tag's, as strip_tags() reads it.
    expect(visibleLength('<a href="https://x.example/?a>b">لینک</a>')).toBe(4)
  })
})
