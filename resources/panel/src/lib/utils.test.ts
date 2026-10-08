import { describe, expect, it } from 'vitest'
import { cn, originOf, sameData } from '@/lib/utils'

/**
 * The helpers everything leans on (lib/utils): data compared by value, class names merged by the panel's own scale, and
 * where an address goes.
 */

describe('two pieces of data', () => {
  it('are the same when they hold the same, whatever their identity or key order', () => {
    expect(sameData({ a: 1, b: [1, { c: 'x' }] }, { b: [1, { c: 'x' }], a: 1 })).toBe(true)
    expect(sameData('120000', '120000')).toBe(true)
    expect(sameData(null, null)).toBe(true)
    expect(sameData(Number.NaN, Number.NaN)).toBe(true)
  })

  it('differ by a value, a key more or less, or an array against an object', () => {
    expect(sameData({ a: 1 }, { a: 2 })).toBe(false)
    expect(sameData({ a: 1 }, { a: 1, b: undefined })).toBe(false)
    expect(sameData([1, 2], [2, 1])).toBe(false)
    expect(sameData([], {})).toBe(false)
    expect(sameData({ a: null }, { a: {} })).toBe(false)
    expect(sameData(0, '0')).toBe(false)
  })
})

describe('an address’s origin', () => {
  it('is its scheme, host and port — a path, a case or a default port no other origin', () => {
    expect(originOf(' https://API.telegram.org/mirror/ ')).toBe('https://api.telegram.org')
    expect(originOf('https://api.telegram.org:443')).toBe('https://api.telegram.org')
    expect(originOf('http://api.telegram.org')).toBe('http://api.telegram.org')
    expect(originOf('https://panel.example.com:2053/x')).toBe('https://panel.example.com:2053')
  })

  it('is the text itself when it is no address', () => {
    expect(originOf(' panel.example.com ')).toBe('panel.example.com')
    expect(originOf('')).toBe('')
  })
})

describe('class names', () => {
  it('keep a size of the panel’s type scale beside a colour', () => {
    expect(cn('text-body', 'text-primary-foreground')).toBe('text-body text-primary-foreground')
  })

  it('let a later size win over an earlier one, and a later colour over an earlier one', () => {
    expect(cn('text-body text-muted-foreground', 'text-caption')).toBe('text-muted-foreground text-caption')
    expect(cn('text-danger', false, undefined, 'text-success')).toBe('text-success')
  })
})
