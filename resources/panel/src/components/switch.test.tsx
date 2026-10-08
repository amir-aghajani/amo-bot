import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { Switch } from '@/components/switch'

/*
 * The on/off control (components/switch): held while a change runs — its own or a sibling's in its list —, it keeps the
 * focus it has and takes no press, where a disabled one would drop the keyboard on the document.
 */

describe('a switch', () => {
  it('turns at a press', () => {
    const onCheckedChange = vi.fn()
    render(<Switch checked={false} onCheckedChange={onCheckedChange} aria-label="فروش روی اینباند" />)

    fireEvent.click(screen.getByRole('switch', { name: 'فروش روی اینباند' }))

    expect(onCheckedChange).toHaveBeenCalledWith(true)
  })

  it('held, keeps the focus and takes no press', () => {
    const onCheckedChange = vi.fn()
    render(<Switch checked held onCheckedChange={onCheckedChange} aria-label="فروش روی اینباند" />)
    const toggle = screen.getByRole('switch', { name: 'فروش روی اینباند' })

    toggle.focus()
    fireEvent.click(toggle)

    expect(onCheckedChange).not.toHaveBeenCalled()
    expect(toggle.getAttribute('aria-disabled')).toBe('true')
    expect(toggle).toHaveProperty('disabled', false)
    expect(document.activeElement).toBe(toggle)
  })
})
