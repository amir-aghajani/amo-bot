import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ServerForm } from '@/components/servers/server-form'
import type { Probe, ProbeResponse, ServerCreatedResponse, ServerResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { serverDriverRow, serverRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A server's form (components/servers/server-form), drawn from its connector's description alone: it sends what each of
 * its requests takes — the connector and the fields shown —, a new server's to the list, a saved one's to its own
 * address, and a test of a saved server under its id, so the secrets left blank are its stored ones; it shows the way
 * in picked, and says a secret kept for another host is typed again before it is asked.
 */

const PROBE: Probe = { ok: true, error: null, error_detail: null, status: null, inbounds: [], serves_subscriptions: true, subscription_probed: true }

/** Server 1's form as it opens and is sent untouched: its connector, its fields as it keeps them, its token kept. */
const SAVED = {
  driver: '3x-ui',
  name: 'آلمان',
  base_url: 'https://de.example.com:2053/panel-path',
  auth_mode: 'token',
  clear_api_token: false,
  verify_tls: true,
  timeout: '30',
  subscription_url: '',
  proxy_enabled: false,
  capacity: '',
  notes: '',
  is_active: true,
}

function form(props: { saved?: boolean } = {}) {
  const onSaved = vi.fn()
  render(<ServerForm driver={serverDriverRow()} server={props.saved === false ? undefined : serverRow()} onSaved={onSaved} onCancel={vi.fn()} />, { wrapper: providers().wrapper })
  return onSaved
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

/** What the server says of a kept token left blank once the panel's address, its proxy or its TLS check changed. */
const TOKEN_MOVED = 'آدرس پنل، پراکسی یا بررسی گواهی TLS عوض شده است؛ توکن API را دوباره وارد کنید.'

describe('a saved server’s form', () => {
  it('changes the server at its own address with its connector and the fields shown — no id', async () => {
    server().on('PUT', '/api/admin/servers/1', json({ server: serverRow({ name: 'آلمان ۲' }) } satisfies ServerResponse))
    const onSaved = form()

    type('نام سرور', 'آلمان ۲')
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(onSaved).toHaveBeenCalledWith(serverRow({ name: 'آلمان ۲' }), null))
    expect(server().sent('PUT', '/api/admin/servers/1')[0]?.body).toEqual({ ...SAVED, name: 'آلمان ۲' })
  })

  it('tests its connection under its id, with its connector', async () => {
    server().on('POST', '/api/admin/servers/test', json({ probe: PROBE } satisfies ProbeResponse))
    form()

    fireEvent.click(screen.getByRole('button', { name: 'تست اتصال' }))

    await until(() => expect(server().sent('POST', '/api/admin/servers/test')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/servers/test')[0]?.body).toEqual({ ...SAVED, id: 1 })
    // The verdict is said as it appears, a success as a failure is.
    expect((await screen.findByRole('status')).textContent).toContain('اتصال برقرار شد')
  })

  it('reaches its panel through a SOCKS5 proxy once it is switched on: its fields shown and sent', async () => {
    server().on('PUT', '/api/admin/servers/1', json({ server: serverRow() } satisfies ServerResponse))
    const onSaved = form()
    fireEvent.click(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }))
    expect(screen.queryByLabelText('آدرس پراکسی')).toBeNull()

    fireEvent.click(screen.getByRole('switch', { name: /اتصال از طریق پراکسی SOCKS5/ }))
    type('آدرس پراکسی', 'proxy.example.com')
    type(/^نام کاربری پراکسی/, 'shop')
    type(/^رمز پراکسی/, 'p@ss')
    expect((screen.getByLabelText('پورت پراکسی') as HTMLInputElement).value).toBe('1080')
    expect(screen.getByLabelText('آدرس پراکسی').getAttribute('dir')).toBe('ltr')
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(onSaved).toHaveBeenCalled())
    expect(server().sent('PUT', '/api/admin/servers/1')[0]?.body).toEqual({
      ...SAVED,
      proxy_enabled: true,
      proxy_host: 'proxy.example.com',
      proxy_port: '1080',
      proxy_username: 'shop',
      proxy_password: 'p@ss',
      clear_proxy_password: false,
    })
  })

  it('says a kept proxy password is typed again once the proxy moves, before it is asked', () => {
    const kept = serverRow().form ?? {}
    render(
      <ServerForm
        driver={serverDriverRow()}
        server={serverRow({ form: { ...kept, proxy_enabled: true, proxy_host: 'proxy.example.com', proxy_port: 1080, proxy_username: 'shop', proxy_password: { set: true, hint: '••••••••' } } })}
        onSaved={vi.fn()}
        onCancel={vi.fn()}
      />,
      { wrapper: providers().wrapper },
    )
    fireEvent.click(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }))
    const moved = 'آدرس یا پورت پراکسی عوض شده است؛ رمز پراکسی را دوباره وارد کنید.'
    expect(screen.queryByText(moved)).toBeNull()

    type('پورت پراکسی', '1081')
    expect(screen.getByText(moved)).toBeTruthy()
  })

  it('says a kept token is typed again once the address moves to another host, before it is asked', () => {
    form()
    expect(screen.queryByText(TOKEN_MOVED)).toBeNull()

    type('آدرس پنل', 'https://de.example.com:2053/another-path')
    expect(screen.queryByText(TOKEN_MOVED)).toBeNull()

    type('آدرس پنل', 'https://nl.example.com:2053/panel-path')
    expect(screen.getByText(TOKEN_MOVED)).toBeTruthy()
    expect((screen.getByLabelText('توکن API') as HTMLInputElement).placeholder, 'asked as if none were kept').toBe('')
  })

  it('says a kept token is typed again once the way to its panel changes — the TLS check, a proxy —, before it is asked', () => {
    form()
    fireEvent.click(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }))

    fireEvent.click(screen.getByRole('switch', { name: /بررسی گواهی TLS/ }))
    expect(screen.getByText(TOKEN_MOVED)).toBeTruthy()
    fireEvent.click(screen.getByRole('switch', { name: /بررسی گواهی TLS/ }))
    expect(screen.queryByText(TOKEN_MOVED)).toBeNull()

    fireEvent.click(screen.getByRole('switch', { name: /اتصال از طریق پراکسی SOCKS5/ }))
    type('آدرس پراکسی', 'proxy.example.com')
    expect(screen.getByText(TOKEN_MOVED)).toBeTruthy()
  })
})

/** Server 1 as another tab saved it meanwhile: its panel at another address. */
function movedElsewhere() {
  const row = serverRow()
  return serverRow({ base_url: 'https://de2.example.com:2053/panel-path', form: { ...row.form, base_url: 'https://de2.example.com:2053/panel-path' } })
}

describe('a saved server changed elsewhere', () => {
  it('takes the newer copy while nothing is typed', () => {
    const { rerender } = render(<ServerForm driver={serverDriverRow()} server={serverRow()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    rerender(<ServerForm driver={serverDriverRow()} server={movedElsewhere()} onSaved={vi.fn()} />)

    expect((screen.getByLabelText('آدرس پنل') as HTMLInputElement).value).toBe('https://de2.example.com:2053/panel-path')
    expect(screen.queryByRole('button', { name: 'دیدن نسخه تازه' })).toBeNull()
  })

  it('says so over a draft, which stays — and puts the newer copy on screen when asked, the draft set aside', () => {
    const { rerender } = render(<ServerForm driver={serverDriverRow()} server={serverRow()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })
    type('نام سرور', 'آلمان ۲')

    rerender(<ServerForm driver={serverDriverRow()} server={movedElsewhere()} onSaved={vi.fn()} />)

    expect((screen.getByLabelText('نام سرور') as HTMLInputElement).value).toBe('آلمان ۲')
    expect((screen.getByLabelText('آدرس پنل') as HTMLInputElement).value).toBe('https://de.example.com:2053/panel-path')
    fireEvent.click(screen.getByRole('button', { name: 'دیدن نسخه تازه' }))

    expect((screen.getByLabelText('نام سرور') as HTMLInputElement).value).toBe('آلمان')
    expect((screen.getByLabelText('آدرس پنل') as HTMLInputElement).value).toBe('https://de2.example.com:2053/panel-path')
    expect(screen.queryByRole('button', { name: 'دیدن نسخه تازه' })).toBeNull()
  })
})

describe('a new server’s form', () => {
  it('opens on its first field, the server’s name', () => {
    form({ saved: false })

    expect(document.activeElement).toBe(screen.getByLabelText('نام سرور'))
  })

  it('adds the server with its connector', async () => {
    server().on('POST', '/api/admin/servers', json({ server: serverRow(), probe: PROBE } satisfies ServerCreatedResponse, 201))
    const onSaved = form({ saved: false })

    type('نام سرور', 'آلمان')
    type('آدرس پنل', 'https://de.example.com:2053/panel-path')
    type('توکن API', 'tok-1')
    fireEvent.click(screen.getByRole('button', { name: 'افزودن سرور' }))

    await until(() => expect(onSaved).toHaveBeenCalledWith(serverRow(), PROBE))
    expect(server().sent('POST', '/api/admin/servers')[0]?.body).toEqual({
      driver: '3x-ui',
      name: 'آلمان',
      base_url: 'https://de.example.com:2053/panel-path',
      auth_mode: 'token',
      api_token: 'tok-1',
      clear_api_token: false,
      verify_tls: true,
      timeout: '30',
      subscription_url: '',
      proxy_enabled: false,
      capacity: '',
      notes: '',
      is_active: true,
    })
  })

  it('asks the way in picked, and sends that way’s fields alone', async () => {
    server().on('POST', '/api/admin/servers', json({ server: serverRow(), probe: PROBE } satisfies ServerCreatedResponse, 201))
    const onSaved = form({ saved: false })
    expect(screen.queryByLabelText('نام کاربری')).toBeNull()

    fireEvent.click(screen.getByRole('radio', { name: 'نام کاربری و رمز' }))
    expect(screen.queryByLabelText('توکن API')).toBeNull()
    type('نام سرور', 'آلمان')
    type('آدرس پنل', 'https://de.example.com:2053/panel-path')
    type('نام کاربری', 'admin')
    type('رمز عبور', 'pw')
    type(/^کلید TOTP/, 'JBSW Y3DP EHPK 3PXP')
    fireEvent.click(screen.getByRole('button', { name: 'افزودن سرور' }))

    await until(() => expect(onSaved).toHaveBeenCalled())
    const body = server().sent('POST', '/api/admin/servers')[0]?.body
    expect(body).toMatchObject({ auth_mode: 'password', username: 'admin', password: 'pw', clear_password: false, totp_secret: 'JBSW Y3DP EHPK 3PXP', clear_totp_secret: false })
    expect(body).not.toHaveProperty('api_token')
    expect(body).not.toHaveProperty('clear_api_token')
  })
})
