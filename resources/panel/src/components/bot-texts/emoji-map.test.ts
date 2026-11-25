import { describe, expect, it } from 'vitest'
import { drawnAsEmoji, emojiKey, emojiLookup, mapLabel, mapWording } from '@/components/bot-texts/emoji-map'
import { premiumTextsResponse } from '@/test/rows'

/*
 * «ایموجی پرمیوم متن‌ها» in the editor's preview (components/bot-texts/emoji-map), as the server draws them
 * (EmojiMap::apply(), EmojiMap::label()): each mapped plain emoji of the shop's own words drawn premium — ❤ and ❤️ the
 * same, one as a reader sees it —, never inside a premium emoji, code, a link or a date, nor in HTML Telegram would not
 * take — a half-space after one kept outside its tag —; a button's leading one alone, its icon; none in a popup, none
 * while the map is off. And which emoji are drawn as emoji, not signs (EmojiMap::drawnAsEmoji()).
 */

const tag = (id: string, plain: string) => `<tg-emoji emoji-id="${id}">${plain}</tg-emoji>`
/** A zero-width non-joiner (a half-space) and joiner. */
const ZWNJ = String.fromCodePoint(0x200c)
const ZWJ = String.fromCodePoint(0x200d)

/** ❤️ → 41, 🔥 → 42. */
const MAP = emojiLookup(premiumTextsResponse({ enabled: true }))

describe('the map', () => {
  it('is on only while switched on', () => {
    expect([...MAP]).toEqual([
      ['❤', '41'],
      ['🔥', '42'],
    ])
    expect(emojiLookup(premiumTextsResponse({ enabled: false })).size).toBe(0)
    expect(emojiLookup(undefined).size).toBe(0)
  })

  it('compares an emoji without its variation selectors', () => {
    expect(emojiKey('❤️')).toBe('❤')
    expect(emojiKey('❤︎')).toBe('❤')
    expect(emojiKey('🔥')).toBe('🔥')
  })
})

describe('a text’s wording', () => {
  it('has each mapped emoji drawn premium, ❤ and ❤️ alike, as typed', () => {
    expect(mapWording('سلام ❤️ و ❤ و 🔥🔥', 'message', MAP)).toBe(`سلام ${tag('41', '❤️')} و ${tag('41', '❤')} و ${tag('42', '🔥')}${tag('42', '🔥')}`)
    expect(mapWording('\n❤️ تمدید', 'part', MAP)).toBe(`\n${tag('41', '❤️')} تمدید`)
    expect(mapWording('<b>❤️</b> %name%', 'caption', MAP)).toBe(`<b>${tag('41', '❤️')}</b> %name%`)
  })

  it('leaves an emoji that is part of another whole: a joined one, a toned one', () => {
    expect(mapWording('❤️‍🔥 و 👍🏽', 'message', new Map([...MAP, ['👍', '43']]))).toBe('❤️‍🔥 و 👍🏽')
  })

  it('never draws one inside a premium emoji, code, a link or a date — nor in a tag’s attributes', () => {
    const kept = [
      tag('9', '❤️'),
      '<code>❤️</code>',
      '<pre><code class="language-x">🔥</code></pre>',
      '<a href="https://shop.example/?q=❤️">❤️ <b>🔥</b></a>',
      '<tg-time unix="1767225600" format="D">🔥</tg-time>',
    ]
    for (const wording of kept) expect(mapWording(wording, 'message', MAP), wording).toBe(wording)
    expect(mapWording(`<a href="x">❤️</a> ❤️`, 'message', MAP)).toBe(`<a href="x">❤️</a> ${tag('41', '❤️')}`)
    expect(mapWording(`<blockquote expandable>❤️</blockquote>`, 'message', MAP)).toBe(`<blockquote expandable>${tag('41', '❤️')}</blockquote>`)
  })

  it('draws as the server’s own cases do (PremiumTextsTest)', () => {
    const check = new Map([['✅', '111']])
    expect(mapWording('ثبت شد ✅ و <code>✅</code> و <a href="https://x.test">✅</a> و <tg-emoji emoji-id="999">✅</tg-emoji>', 'message', check)).toBe(
      `ثبت شد ${tag('111', '✅')} و <code>✅</code> و <a href="https://x.test">✅</a> و ${tag('999', '✅')}`,
    )
    expect(mapWording('سلام %name% ❤️', 'message', new Map([['❤', '222']]))).toBe(`سلام %name% ${tag('222', '❤️')}`)
    expect(mapWording('🎁 x', 'part', new Map([['🎁', '123']]))).toBe(`${tag('123', '🎁')} x`)
    expect(mapWording('🎁 x', 'popup', new Map([['🎁', '123']]))).toBe('🎁 x')
  })

  it('reads a «>» inside a quoted attribute as the value’s', () => {
    expect(mapWording('<a href="https://x.test/?a>b">❤️</a> ❤️', 'message', MAP)).toBe(`<a href="https://x.test/?a>b">❤️</a> ${tag('41', '❤️')}`)
  })

  it('reads a tag as the server does: a quote in an attribute’s name is the name’s, a tag name’s capitals ASCII alone', () => {
    expect(mapWording('<b h"x="y">❤️</b>', 'message', MAP)).toBe(`<b h"x="y">${tag('41', '❤️')}</b>`)
    expect(mapWording(`<b x'y="z">❤️</b> ❤️`, 'message', MAP)).toBe(`<b x'y="z">${tag('41', '❤️')}</b> ${tag('41', '❤️')}`)
    expect(mapWording('<STRIKE>❤️</strike>', 'message', MAP)).toBe(`<STRIKE>${tag('41', '❤️')}</strike>`)
    // The Kelvin sign (U+212A) is no «k» to the server: no tag Telegram knows, so nothing is drawn.
    expect(mapWording('<stri\u212Ae>❤️</strike> ❤️', 'message', MAP)).toBe('<stri\u212Ae>❤️</strike> ❤️')
  })

  it('splits characters as the server’s PCRE does (\\X): a prepended mark alone before an emoji, a flag without a mark after it', () => {
    expect(mapWording('\u0600❤️', 'message', MAP)).toBe(`\u0600${tag('41', '❤️')}`)
    expect(mapWording('\u06DD🔥 x', 'message', MAP)).toBe(`\u06DD${tag('42', '🔥')} x`)
    const flags = new Map([['🇩🇪', '46']])
    expect(mapWording('🇩🇪\uFE0F', 'message', flags)).toBe(`${tag('46', '🇩🇪')}\uFE0F`)
    expect(mapWording('🇩🇪\u200C و 🇩🇪', 'message', flags)).toBe(`${tag('46', '🇩🇪')}\u200C و ${tag('46', '🇩🇪')}`)
    expect(mapWording('🇩🇪\u0301', 'message', flags)).toBe(`${tag('46', '🇩🇪')}\u0301`)
    expect(mapLabel('🇩🇪\uFE0F %name%', '🇩🇪\uFE0F آلمان', flags)).toBe(`${tag('46', '🇩🇪')}\uFE0F آلمان`)
  })

  it('keeps a half-space typed after an emoji outside its tag — a run of joiners alone as it is (EmojiMap::untailed())', () => {
    const check = new Map([['✅', '111']])
    expect(mapWording(`✅${ZWNJ}ثبت`, 'message', check)).toBe(`${tag('111', '✅')}${ZWNJ}ثبت`)
    expect(mapWording(`✅${ZWNJ}${ZWJ} و ❤️${ZWJ} x`, 'message', new Map([...check, ...MAP]))).toBe(`${tag('111', '✅')}${ZWNJ}${ZWJ} و ${tag('41', '❤️')}${ZWJ} x`)
    expect(mapWording(`${ZWNJ}${ZWJ} ✅`, 'message', check)).toBe(`${ZWNJ}${ZWJ} ${tag('111', '✅')}`)
    // A button's leading one ending in a half-space is left, as EmojiMap::label() leaves it.
    expect(mapLabel(`✅${ZWNJ} ثبت`, `✅${ZWNJ} ثبت`, check)).toBe(`✅${ZWNJ} ثبت`)
  })

  it('is drawn again as it is: a mapped one is drawn once', () => {
    const once = mapWording('❤️ <i>🔥</i>', 'message', MAP)
    expect(mapWording(once, 'message', MAP)).toBe(once)
  })

  it('stays as it is in HTML Telegram would not take', () => {
    expect(mapWording('<b>❤️', 'message', MAP)).toBe('<b>❤️')
    expect(mapWording('❤️ &nbsp; <b>x</b>', 'message', MAP)).toBe('❤️ &nbsp; <b>x</b>')
  })

  it('is as it is in a popup, which draws none, and while the map is off', () => {
    expect(mapWording('❤️ ذخیره شد', 'popup', MAP)).toBe('❤️ ذخیره شد')
    expect(mapWording('❤️ سلام', 'message', new Map())).toBe('❤️ سلام')
  })
})

describe('a button’s label', () => {
  it('has its leading emoji drawn premium — the icon —, not one further on', () => {
    expect(mapWording('❤️ علاقه‌مندی', 'button', MAP), 'decided on the label filled').toBe('❤️ علاقه‌مندی')
    expect(mapLabel('❤️ علاقه‌مندی', '❤️ علاقه‌مندی', MAP)).toBe(`${tag('41', '❤️')} علاقه‌مندی`)
    expect(mapLabel('❤ %plan%', '❤ طلایی 🔥', MAP)).toBe(`${tag('41', '❤')} طلایی 🔥`)
    expect(mapLabel('خرید 🔥', 'خرید 🔥', MAP)).toBe('خرید 🔥')
  })

  it('draws as the server’s own cases do (PremiumTextsTest)', () => {
    const map = new Map([
      ['⬅', '333'],
      ['🌍', '444'],
    ])
    expect(mapLabel('⬅️ بازگشت', '⬅️ بازگشت', map)).toBe(`${tag('333', '⬅️')} بازگشت`)
    expect(mapLabel('🌍 %server%', '🌍 🇩🇪 آلمان', map)).toBe(`${tag('444', '🌍')} 🇩🇪 آلمان`)
    expect(mapLabel('🌍 %server%', `🌍 ${tag('555', '🇩🇪')} آلمان`, map)).toBe(`🌍 ${tag('555', '🇩🇪')} آلمان`)
  })

  it('keeps an icon of its own, a name’s premium emoji right after it, and a label of an emoji alone', () => {
    expect(mapLabel(`${tag('9', '🔥')} خرید`, `${tag('9', '🔥')} خرید`, MAP)).toBe(`${tag('9', '🔥')} خرید`)
    expect(mapLabel('🔥 %plan%', `🔥 ${tag('9', '⭐')} طلایی`, MAP)).toBe(`🔥 ${tag('9', '⭐')} طلایی`)
    expect(mapLabel('🔥', '🔥', MAP)).toBe('🔥')
    expect(mapLabel('🔥 %name%', '🔥  ', MAP)).toBe('🔥  ')
  })

  it('is as it is while the map does not have its emoji, or is off', () => {
    expect(mapLabel('⭐ امتیاز', '⭐ امتیاز', MAP)).toBe('⭐ امتیاز')
    expect(mapLabel('🔥 خرید', '🔥 خرید', new Map())).toBe('🔥 خرید')
  })
})

describe('an emoji drawn as one', () => {
  it('is one Unicode counts as emoji, or carries its variation selector or keycap mark; a sign of the same blocks is not', () => {
    for (const emoji of ['❤', '❤️', '✔', '☎', '⚠', '©', '✅', '⭐', '🔥', '1️⃣', '👍🏽', '🇩🇪']) expect(drawnAsEmoji(emoji), emoji).toBe(true)
    for (const sign of ['━', '★', '✓', '●', '■', '№']) expect(drawnAsEmoji(sign), sign).toBe(false)
  })

  // The list is typed by hand (EmojiMap::DRAWN, copied here): held to Unicode's own Emoji property, below U+1F000.
  it('takes every character Unicode counts as emoji below U+1F000, and no other but the two marks', () => {
    const apart: string[] = []
    for (let point = 0xa0; point < 0x1f000; point++) {
      if (point >= 0xd800 && point <= 0xdfff) continue
      const character = String.fromCodePoint(point)
      const emoji = /\p{Emoji}/u.test(character) || point === 0x20e3 || point === 0xfe0f
      if (drawnAsEmoji(character) !== emoji) apart.push(point.toString(16))
    }
    expect(apart).toEqual([])
  })
})
