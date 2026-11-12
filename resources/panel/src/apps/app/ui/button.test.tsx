import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { Button } from '@/apps/app/ui/button'
import { Chip, Segmented } from '@/apps/app/ui/fields'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The app's small controls (ui/button, ui/fields) keep their look and are tapped over 44px: app.css's tap-target, an
 * unseen area centred on the control, which is positioned for it — unless a screen places it itself. Segments are one
 * choice: a radio group to a screen reader.
 */

describe('small controls', () => {
  it('give a small button, a chip and a segment a 44px tap area', () => {
    render(
      <>
        <Button size="sm">خروج</Button>
        <Chip active={false} onClick={() => {}}>
          کمترین
        </Chip>
        <Segmented value="a" options={[{ value: 'a', label: 'یک' }]} onChange={() => {}} />
      </>,
    )

    for (const name of ['خروج', 'کمترین', 'یک']) {
      const control = screen.getByRole(name === 'یک' ? 'radio' : 'button', { name })
      expect(control.className).toContain('tap-target')
      expect(control.className).toContain('relative')
    }
    expect(screen.getByRole('button', { name: 'خروج' }).className).toContain('h-9')
  })

  it('offers segments as one choice: a radio group, the choice checked', () => {
    const change = vi.fn()
    render(
      <Segmented
        value="active"
        options={[
          { value: 'all', label: 'همه' },
          { value: 'active', label: 'فعال' },
        ]}
        onChange={change}
      />,
    )

    expect(screen.getByRole('radiogroup')).toBeTruthy()
    expect(screen.getByRole('radio', { name: 'فعال' }).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByRole('radio', { name: 'همه' }).getAttribute('aria-checked')).toBe('false')
    fireEvent.click(screen.getByRole('radio', { name: 'همه' }))
    expect(change).toHaveBeenCalledWith('all')
  })

  it('leaves a button a screen places itself where it is put', () => {
    render(
      <Button size="sm" className="absolute top-2 left-2">
        تعویض
      </Button>,
    )
    const button = screen.getByRole('button', { name: 'تعویض' })

    expect(button.className).toContain('absolute')
    expect(button.className).not.toContain('relative')
  })
})
