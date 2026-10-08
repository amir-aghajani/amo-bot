import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { KeyboardEditor } from '@/components/keyboards/keyboard-editor'
import type { CustomEmojisResponse, KeyboardAction, KeyboardButtonSpec, KeyboardLayoutData } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { providers } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * A keyboard in its editor (components/keyboards/keyboard-editor): a row moved by its ▲▼ keeps the focus on its arrow —
 * the row is the same row in its new place —; a button dragged has a new row to land on under the last; and «افزودن
 * دکمه» holds once every action is on the keyboard, saying why.
 */

const ACTIONS: KeyboardAction[] = [
  { key: 'buy', title: 'خرید', label: '🛍️ خرید', screen: 'menu:buy' },
  { key: 'wallet', title: 'کیف پول', label: '💰 کیف پول', screen: 'menu:wallet' },
  { key: 'support', title: 'پشتیبانی', label: '🎧 پشتیبانی', screen: 'menu:support' },
]

const button = (action: KeyboardAction): KeyboardButtonSpec => ({ action: action.key, label: action.label, style: null, icon: null })

function showEditor(rows: KeyboardButtonSpec[][]) {
  const keyboard: KeyboardLayoutData = { name: 'start', title: 'منوی اصلی', is_default: false, type: 'reply', rows }
  render(<KeyboardEditor keyboard={keyboard} actions={ACTIONS} styles={['primary', 'success', 'danger']} limits={{ rows: 3, per_row: 3, label: 32 }} welcome={undefined} onSaved={vi.fn()} />, {
    wrapper: providers().wrapper,
  })
}

const [BUY, WALLET, SUPPORT] = ACTIONS.map(button) as [KeyboardButtonSpec, KeyboardButtonSpec, KeyboardButtonSpec]

/** What a drag carries — the browser's DataTransfer, as far as the editor reads it. */
const transfer = () => ({ dataTransfer: { setData: vi.fn(), effectAllowed: 'all', dropEffect: 'none' } })

const rowName = (n: number) => `ردیف ${formatNumber(n)}`

beforeEach(() => {
  server().on('GET', '/api/admin/bot/custom-emojis', json({ emojis: [], status: null } satisfies CustomEmojisResponse))
})

describe('a keyboard’s rows', () => {
  it('keep the focus on a moved row’s arrow — the other one once it reached the end', () => {
    showEditor([[BUY], [WALLET]])
    const down = screen.getByRole('button', { name: `انتقال ${rowName(1)} به پایین` })

    down.focus()
    fireEvent.click(down)

    expect(document.activeElement).toBe(screen.getByRole('button', { name: `انتقال ${rowName(2)} به بالا` }))
    expect(screen.getAllByRole('listitem').map((row) => row.textContent?.includes('🛍️ خرید'))).toEqual([false, true])
  })

  it('take a dragged button onto a new row under the last', () => {
    showEditor([[BUY, WALLET]])
    expect(screen.queryByText('برای ساختن ردیف تازه اینجا رها کنید')).toBeNull()

    fireEvent.dragStart(screen.getByRole('button', { name: /کیف پول/ }), transfer())
    const zone = screen.getByText('برای ساختن ردیف تازه اینجا رها کنید')
    fireEvent.dragOver(zone, transfer())
    fireEvent.drop(zone, transfer())

    const rows = screen.getAllByRole('listitem')
    expect(rows).toHaveLength(2)
    expect(rows[1]?.textContent).toContain('💰 کیف پول')
    expect(rows[0]?.textContent).not.toContain('💰 کیف پول')
    expect(screen.queryByText('برای ساختن ردیف تازه اینجا رها کنید')).toBeNull()
  })
})

describe('adding a button', () => {
  it('holds once every action is on the keyboard — keeping the focus, taking no press — and says why', () => {
    showEditor([[BUY, WALLET], [SUPPORT]])

    for (const add of screen.getAllByRole('button', { name: 'افزودن دکمه' })) {
      // Held, not disabled: the dialog that placed the last action hands the focus back to it as it closes.
      expect([(add as HTMLButtonElement).disabled, add.getAttribute('aria-disabled')]).toEqual([false, 'true'])
      fireEvent.click(add)
    }
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(screen.getByText(/همه دکمه‌های ربات روی کیبورد هستند/)).toBeTruthy()
  })

  it('is open while an action is left to place', () => {
    showEditor([[BUY, WALLET]])

    expect(screen.getByRole('button', { name: 'افزودن دکمه' }).getAttribute('aria-disabled')).toBeNull()
    expect(screen.queryByText(/همه دکمه‌های ربات روی کیبورد هستند/)).toBeNull()
  })
})
