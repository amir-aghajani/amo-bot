import { describe, expect, it } from 'vitest'
import { externalHref, searchLink } from '@/components/text-link'

/**
 * Screens open each other on one row (components/text-link): the other list searched for its number. A link out of the
 * panel built from data leads to the web or to Telegram, and nowhere else.
 */

describe('a link to one row of another screen', () => {
  it('searches that list for the row’s number, the # kept through the address', () => {
    expect(searchLink('/payments', 12)).toBe('/payments?search=%2312')
    expect(new URLSearchParams(searchLink('/orders', 7).split('?')[1]).get('search')).toBe('#7')
  })
})

describe('a link out of the panel', () => {
  it('leads to the web or to Telegram’s apps', () => {
    expect(externalHref('https://t.me/amo_shop')).toBe('https://t.me/amo_shop')
    expect(externalHref('http://docs.example.com/3x-ui')).toBe('http://docs.example.com/3x-ui')
    expect(externalHref('tg://user?id=123456789')).toBe('tg://user?id=123456789')
  })

  it('is none for any other address — a script, data, no address at all', () => {
    expect(externalHref('javascript:alert(1)')).toBeNull()
    expect(externalHref(' JavaScript:alert(1)')).toBeNull()
    expect(externalHref('data:text/html,<b>x</b>')).toBeNull()
    expect(externalHref('/admin/users')).toBeNull()
    expect(externalHref('')).toBeNull()
  })
})
