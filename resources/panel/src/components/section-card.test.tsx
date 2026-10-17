import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SectionCard } from '@/components/section-card'
import { providers } from '@/test/render'

/*
 * A settings card (components/section-card) whose group was saved elsewhere under its unsaved draft (another tab, a live
 * refresh): the draft stays as typed, the card says so, and offers the new copy instead.
 */

function card(elsewhere: boolean) {
  const form = { save: vi.fn(() => Promise.resolve(true)), busy: false, dirty: true, elsewhere, revert: vi.fn(), formError: null, failure: null }
  render(
    <SectionCard form={form} title="کیف پول" description="شارژ و پرداخت با کیف پول.">
      <input aria-label="کمترین شارژ" defaultValue="۵۰٬۰۰۰" />
    </SectionCard>,
    { wrapper: providers().wrapper },
  )
  return form
}

describe('a settings card', () => {
  it('saved elsewhere under its draft says so, the draft kept, with a way to take the new copy', () => {
    const form = card(true)

    expect(screen.getByRole('status').textContent).toContain('این بخش جای دیگری هم ذخیره شد.')
    fireEvent.click(screen.getByRole('button', { name: 'دیدن نسخه تازه' }))
    expect(form.revert).toHaveBeenCalledTimes(1)
  })

  it('says nothing of it otherwise', () => {
    card(false)

    expect(screen.queryByRole('button', { name: 'دیدن نسخه تازه' })).toBeNull()
  })
})
