import type { ReactNode } from 'react'
import { act, fireEvent, render, renderHook } from '@testing-library/react'
import { MemoryRouter, useNavigate } from 'react-router'
import { describe, expect, it } from 'vitest'
import { AGENT_NAV } from '@/apps/agent/nav'
import { Modal } from '@/components/modal'
import { ShellProvider, useShell, type PanelShell } from '@/components/shell/shell-context'
import { STORAGE_KEYS } from '@/lib/storage'
import { resizeWindow } from '@/test/browser'

/*
 * The shell's state (components/shell/shell-context): the desktop sidebar collapsed to a rail or not — the admin's
 * choice kept for the next visit, else a rail on a tablet's window —, Ctrl+B to switch it (not in a field, not under a
 * dialog), and the phone's drawer, which any navigation closes, and a window grown past a phone's too.
 */

const PANEL: PanelShell = { nav: AGENT_NAV, role: 'نماینده' }

function Shell({ children }: { children: ReactNode }) {
  return (
    <MemoryRouter initialEntries={['/plans']}>
      <ShellProvider panel={PANEL}>{children}</ShellProvider>
    </MemoryRouter>
  )
}

const shell = () => renderHook(() => ({ shell: useShell(), navigate: useNavigate() }), { wrapper: Shell })

describe('the sidebar', () => {
  it('starts as the admin left it last time', () => {
    localStorage.setItem(STORAGE_KEYS.sidebar, 'collapsed')

    expect(shell().result.current.shell.collapsed).toBe(true)
  })

  it('starts as a rail on a tablet’s window while the admin has not chosen', () => {
    resizeWindow(900)

    expect(shell().result.current.shell.collapsed).toBe(true)
  })

  it('follows the window, while the admin has not chosen, as it narrows below 64rem and widens again', () => {
    resizeWindow(1280)
    const { result } = shell()
    expect(result.current.shell.collapsed).toBe(false)

    act(() => resizeWindow(1000))
    expect(result.current.shell.collapsed).toBe(true)
    act(() => resizeWindow(1280))
    expect(result.current.shell.collapsed).toBe(false)
  })

  it('stays as the admin chose on a tablet’s window too', () => {
    resizeWindow(900)
    localStorage.setItem(STORAGE_KEYS.sidebar, 'expanded')
    const { result } = shell()
    expect(result.current.shell.collapsed).toBe(false)

    fireEvent.keyDown(window, { key: 'b', code: 'KeyB', ctrlKey: true })
    expect(result.current.shell.collapsed).toBe(true)
    act(() => resizeWindow(1280))
    expect(result.current.shell.collapsed).toBe(true)
  })

  it('collapses with Ctrl+B and back — on a Persian keyboard too, by the key’s place — and is remembered', () => {
    const { result } = shell()
    expect(result.current.shell.collapsed).toBe(false)

    fireEvent.keyDown(window, { key: 'b', code: 'KeyB', ctrlKey: true })
    expect(result.current.shell.collapsed).toBe(true)
    expect(localStorage.getItem(STORAGE_KEYS.sidebar)).toBe('collapsed')

    fireEvent.keyDown(window, { key: 'ذ', code: 'KeyB', ctrlKey: true })
    expect(result.current.shell.collapsed).toBe(false)
    expect(localStorage.getItem(STORAGE_KEYS.sidebar)).toBe('expanded')
  })

  it('leaves Ctrl+B with another modifier to the browser', () => {
    const { result } = shell()

    fireEvent.keyDown(window, { key: 'b', code: 'KeyB', ctrlKey: true, shiftKey: true })

    expect(result.current.shell.collapsed).toBe(false)
  })

  it('leaves Ctrl+B to a field it is pressed in, and to a dialog over the page', () => {
    const { result } = shell()
    const field = document.createElement('textarea')
    document.body.append(field)

    fireEvent.keyDown(field, { key: 'b', code: 'KeyB', ctrlKey: true })
    expect(result.current.shell.collapsed).toBe(false)
    field.remove()

    render(
      <Modal open onClose={() => undefined} title="ویرایش">
        <p>فرم</p>
      </Modal>,
    )
    fireEvent.keyDown(window, { key: 'b', code: 'KeyB', ctrlKey: true })
    expect(result.current.shell.collapsed).toBe(false)
  })
})

describe('the phone’s drawer', () => {
  it('closes on any navigation', () => {
    resizeWindow(400)
    const { result } = shell()
    act(() => result.current.shell.setMobileOpen(true))
    expect(result.current.shell.mobileOpen).toBe(true)

    act(() => void result.current.navigate('/orders'))

    expect(result.current.shell.mobileOpen).toBe(false)
  })

  it('stays shut when the phone, turned on its side while it was open, is turned back', () => {
    // Narrowed once drawn: happy-dom tells a query's change only from a window it did not match at first.
    const { result } = shell()
    act(() => resizeWindow(400))
    act(() => result.current.shell.setMobileOpen(true))
    expect(result.current.shell.mobileOpen).toBe(true)

    act(() => resizeWindow(900))
    act(() => resizeWindow(400))

    expect(result.current.shell.isMobile).toBe(true)
    expect(result.current.shell.mobileOpen).toBe(false)
  })
})
