import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { StepBar } from '@/apps/admin/install/step-bar'
import type { Step } from '@/apps/admin/install/steps'
import type { InstallStatus } from '@/lib/api-types'

/*
 * The installer's steps in a row (apps/admin/install/step-bar): a done step is pressed to go back to, and becomes the
 * current one under the press — held, never disabled, so the focus stays where it was; a step not reached yet waits.
 */

/** Everything but the site and the end done: the requirements met, the tables made, the login set. */
const STATUS: InstallStatus = {
  requirements: [],
  ready: true,
  database: { driver: 'mysql', drivers: [], values: {}, connected: true, tables: true, error: null },
  admin: { configured: true, username: 'owner' },
  site: { name: 'AmoBot', url: '' },
  telegram: { configured: false, username: '' },
}

/** The bar as the installer holds it, on `start`; what was picked lands in `picked`. */
function Bar({ start, picked }: { start: Step; picked: (step: Step) => void }) {
  const [current, setCurrent] = useState(start)
  return (
    <StepBar
      current={current}
      status={STATUS}
      onPick={(step) => {
        picked(step)
        setCurrent(step)
      }}
    />
  )
}

const step = (name: RegExp) => screen.getByRole('button', { name }) as HTMLButtonElement

describe('the installer’s steps', () => {
  it('go back to a done one, which keeps the focus as it becomes the current one — pressed again, it does nothing', () => {
    const picked = vi.fn()
    render(<Bar start="site" picked={picked} />)
    const database = step(/دیتابیس/)

    database.focus()
    fireEvent.click(database)

    expect(picked).toHaveBeenCalledWith('database')
    expect(database.getAttribute('aria-current')).toBe('step')
    expect([database.disabled, database.getAttribute('aria-disabled')]).toEqual([false, 'true'])
    expect(document.activeElement).toBe(database)
    fireEvent.click(database)
    expect(picked).toHaveBeenCalledTimes(1)
  })

  it('keep a step not reached yet out of reach', () => {
    render(<Bar start="site" picked={vi.fn()} />)

    expect(step(/پایان/).disabled).toBe(true)
    expect(step(/سایت و ربات/).getAttribute('aria-current')).toBe('step')
  })
})
