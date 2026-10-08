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
  })

  it('says a kept token is typed again once the address moves to another host, before it is asked', () => {
    form()
    expect(screen.queryByText('آدرس پنل عوض شده است؛ توکن API را دوباره وارد کنید.')).toBeNull()

    type('آدرس پنل', 'https://de.example.com:2053/another-path')
    expect(screen.queryByText('آدرس پنل عوض شده است؛ توکن API را دوباره وارد کنید.')).toBeNull()

    type('آدرس پنل', 'https://nl.example.com:2053/panel-path')
    expect(screen.getByText('آدرس پنل عوض شده است؛ توکن API را دوباره وارد کنید.')).toBeTruthy()
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
