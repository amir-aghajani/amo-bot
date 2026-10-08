import { describe, expect, it } from 'vitest'
import { forgetStored, readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'
import { blockStorage } from '@/test/browser'

/** The panels' conveniences in browser storage (lib/storage): kept where the browser keeps them, and never a failure where it will not. */

describe('the browser’s storage', () => {
  it('keeps a value in the tab’s or the browser’s storage, as asked', () => {
    writeStored(STORAGE_KEYS.theme, 'light')
    writeStored(STORAGE_KEYS.reloadedAt, '1', 'session')

    expect(readStored(STORAGE_KEYS.theme)).toBe('light')
    expect(readStored(STORAGE_KEYS.reloadedAt, 'session')).toBe('1')
    expect(readStored(STORAGE_KEYS.reloadedAt)).toBeNull()

    forgetStored(STORAGE_KEYS.theme)
    expect(readStored(STORAGE_KEYS.theme)).toBeNull()
  })

  it('that refuses (private mode, blocked site data) leaves the panel on its defaults, without an error', () => {
    blockStorage()

    expect(readStored(STORAGE_KEYS.sidebar)).toBeNull()
    expect(() => writeStored(STORAGE_KEYS.sidebar, 'collapsed')).not.toThrow()
    expect(() => forgetStored(STORAGE_KEYS.installKey, 'session')).not.toThrow()
  })

  it('is kept under the panels’ own names', () => {
    for (const key of Object.values(STORAGE_KEYS)) expect(key).toMatch(/^amobot-/)
  })
})
