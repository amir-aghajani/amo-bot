import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router'
import { describe, expect, it } from 'vitest'
import { SectionActions, SectionedPage } from '@/components/sectioned-page'
import { USER_SECTIONS } from '@/components/shell/nav'

/*
 * A page made of sections (components/sectioned-page): the section the address names is on screen under a header worded
 * for it, every other one mounted and hidden — so a search, a page, a draft survives a switch —, a section's own actions
 * in the header while it is shown, and any other address under the page goes to its first section.
 */

function Go({ to }: { to: string }) {
  const navigate = useNavigate()
  return (
    <button type="button" onClick={() => void navigate(to)}>
      برو به {to}
    </button>
  )
}

function UsersPage() {
  return (
    <SectionedPage sections={USER_SECTIONS} header={(current) => ({ title: current.title })}>
      {{
        users: <input aria-label="جستجوی کاربران" />,
        groups: (
          <>
            <SectionActions>
              <button type="button">افزودن گروه</button>
            </SectionActions>
            <p>گروه‌ها اینجا هستند</p>
          </>
        ),
      }}
    </SectionedPage>
  )
}

function open(at: string) {
  render(
    <MemoryRouter initialEntries={[at]}>
      <Routes>
        <Route path="/users/*" element={<UsersPage />} />
      </Routes>
      <Go to="/users" />
      <Go to="/users/groups" />
    </MemoryRouter>,
  )
}

/** A section of the page by its name, shown or not. */
function section(name: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`section[aria-label="${name}"]`)
  if (found === null) throw new Error(`No section «${name}».`)
  return found
}

describe('a sectioned page', () => {
  it('shows the section its address names, its header worded for it, the others mounted and hidden', () => {
    open('/users/groups')

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('گروه‌ها')
    expect(section('گروه‌ها').hidden).toBe(false)
    expect(section('کاربران').hidden).toBe(true)
  })

  it('keeps what was typed in a section across a switch to another and back', () => {
    open('/users')
    fireEvent.change(screen.getByLabelText('جستجوی کاربران'), { target: { value: 'amir' } })

    fireEvent.click(screen.getByText('برو به /users/groups'))
    expect(section('کاربران').hidden).toBe(true)
    fireEvent.click(screen.getByText('برو به /users'))

    expect((screen.getByLabelText('جستجوی کاربران') as HTMLInputElement).value).toBe('amir')
  })

  it('draws a section’s own actions in the header only while that section is shown', () => {
    open('/users')
    expect(screen.queryByRole('button', { name: 'افزودن گروه' })).toBeNull()

    fireEvent.click(screen.getByText('برو به /users/groups'))

    expect(screen.getByRole('banner').contains(screen.getByRole('button', { name: 'افزودن گروه' }))).toBe(true)
  })

  it('goes to its first section from any other address under it', () => {
    open('/users/archive')

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('کاربران')
    expect(section('کاربران').hidden).toBe(false)
  })
})
