import { describe, expect, it } from 'vitest'
import { contractProblem } from '@/test/contract'
import { FakeServer, json, type SentRequest } from '@/test/server'

/*
 * The API description as the panels' tests hold their requests to it (src/test/contract), as the PHP tests hold theirs:
 * an operation it has, its parameters and its body as it describes them — and nothing for what is no API request.
 */

function request(method: string, address: string, body?: unknown): SentRequest {
  const url = new URL(address, 'http://localhost')
  return {
    method,
    path: url.pathname,
    query: url.searchParams,
    headers: body === undefined || body instanceof FormData ? {} : { 'content-type': 'application/json' },
    body,
    credentials: 'same-origin',
  }
}

describe('a request held to the API description', () => {
  it('passes as it is described: its operation, its parameters, its body', () => {
    expect(contractProblem(request('POST', '/api/admin/customer-groups', { name: 'VIP' }))).toBeNull()
    expect(contractProblem(request('POST', '/api/agent/plans/3/duplicate'))).toBeNull()
    expect(contractProblem(request('GET', '/api/admin/orders?page=2&dir=asc&from=2026-10-01&search=%2312'))).toBeNull()
    expect(contractProblem(request('PATCH', '/api/admin/users/10', { status: 'banned' }))).toBeNull()
  })

  it('is no operation at an address the description does not have, or with a method it does not take there', () => {
    expect(contractProblem(request('POST', '/api/admin/customer-group', { name: 'VIP' }))).toBe('POST /api/admin/customer-group: no such operation in the API description')
    expect(contractProblem(request('DELETE', '/api/admin/orders/3'))).toBe('DELETE /api/admin/orders/3: no such operation in the API description')
  })

  it('reads the most literal operation a path fits, its path’s parameters by their schemas', () => {
    expect(contractProblem(request('GET', '/api/admin/servers/drivers'))).toBeNull()
    expect(
      contractProblem(
        request('PUT', '/api/admin/servers/abc', { driver: '3x-ui', name: 'آلمان', base_url: 'https://de.example.com', auth_mode: 'token', verify_tls: true, timeout: 30, is_active: true }),
      ),
    ).toBe('PUT /api/admin/servers/abc: {id}: "abc", where it takes a whole number')
    expect(contractProblem(request('GET', '/api/owner/plans'))).toBe('GET /api/owner/plans: {panel}: "owner", none of "admin", "agent"')
  })

  it('reads a query’s described parameters as the server casts them, and passes the others by', () => {
    expect(contractProblem(request('GET', '/api/admin/orders?page=0'))).toBe('GET /api/admin/orders: ?page: 0, below 1')
    expect(contractProblem(request('GET', '/api/admin/orders?page=two'))).toBe('GET /api/admin/orders: ?page: "two", where it takes a whole number')
    expect(contractProblem(request('GET', '/api/admin/orders?from=2026-10'))).toBe('GET /api/admin/orders: ?from: "2026-10", not a date')
    expect(contractProblem(request('GET', '/api/admin/orders?colour=red'))).toBeNull()
  })

  it('reads a header’s described parameters as the server casts them — the shop the owner’s tab names — and passes the others by', () => {
    const named = (shop: string, address = '/api/admin/orders'): SentRequest => ({ ...request('GET', address), headers: { 'x-shop': shop, 'x-anything': 'else' } })

    expect(contractProblem(named('7'))).toBeNull()
    expect(contractProblem(named('7', '/api/agent/orders'))).toBeNull()
    expect(contractProblem(named('seven'))).toBe('GET /api/admin/orders: X-Shop: "seven", where it takes a whole number')
    expect(contractProblem(named('0'))).toBe('GET /api/admin/orders: X-Shop: 0, below 1')
    expect(contractProblem(request('GET', '/api/admin/payments/3/receipt?shop=7'))).toBeNull()
  })

  it('takes no body where the operation takes none, and wants one where it takes one', () => {
    expect(contractProblem(request('POST', '/api/admin/plans/3/duplicate', {}))).toBe('POST /api/admin/plans/3/duplicate: a body, where the operation takes none')
    expect(contractProblem(request('POST', '/api/admin/customer-groups'))).toBe('POST /api/admin/customer-groups: no body, where the operation takes one')
  })

  it('holds a body to its schema: closed objects, required fields, types, nulls', () => {
    expect(contractProblem(request('POST', '/api/admin/customer-groups', { name: 'VIP', colour: 'gold' }))).toBe('POST /api/admin/customer-groups: body.colour: a field the operation does not take')
    expect(contractProblem(request('POST', '/api/admin/customer-groups', {}))).toBe('POST /api/admin/customer-groups: body.name: required, not sent')
    expect(contractProblem(request('POST', '/api/admin/subscriptions/1/move', { server_id: '4' }))).toBe('POST /api/admin/subscriptions/1/move: body.server_id: "4", where it takes a whole number')
    expect(contractProblem(request('POST', '/api/admin/customer-groups', { name: null }))).toBe('POST /api/admin/customer-groups: body.name: null, which it does not take')
    // A list no longer than it may be: a reorder names 1000 rows at most.
    expect(contractProblem(request('POST', '/api/admin/plans/reorder', { ids: [3, 1, 2] }))).toBeNull()
    expect(contractProblem(request('POST', '/api/admin/plans/reorder', { ids: Array.from({ length: 1001 }, (_, index) => index + 1) }))).toBe(
      'POST /api/admin/plans/reorder: body.ids: 1001 items, more than 1000',
    )
    // A button's colour may be null — none.
    expect(contractProblem(request('PUT', '/api/admin/keyboards/start', { type: 'inline', rows: [[{ action: 'buy', label: 'خرید', style: null, icon: null }]] }))).toBeNull()
    expect(contractProblem(request('PUT', '/api/admin/keyboards/start', { type: 'inline', rows: [[{ action: 'buy', label: 'خرید', style: 'gold' }]] }))).toBe(
      'PUT /api/admin/keyboards/start: body.rows[0][0].style: "gold", none of "primary", "success", "danger"',
    )
  })

  it('takes a body that fits exactly one of its forms', () => {
    // A number as a form sends it: a JSON number, or its text.
    expect(contractProblem(request('POST', '/api/admin/agency/levels', { name: 'طلایی', price_per_gb: 3000 }))).toBeNull()
    expect(contractProblem(request('POST', '/api/admin/agency/levels', { name: 'طلایی', price_per_gb: '۳٬۰۰۰' }))).toBeNull()
    // One group of the bot's settings, not two groups' fields mixed.
    expect(contractProblem(request('PUT', '/api/admin/bot/settings/channels', { join_required: true }))).toBeNull()
    expect(contractProblem(request('PUT', '/api/admin/bot/settings/channels', { join_required: true, qr_enabled: true }))).toMatch(
      /^PUT \/api\/admin\/bot\/settings\/channels: body: fits none of its forms/,
    )
  })

  it('takes a file as the multipart form its operation describes, and refuses one sent as JSON', () => {
    const form = new FormData()
    form.append('file', new File(['png'], 'background.png', { type: 'image/png' }))

    expect(contractProblem(request('POST', '/api/admin/bot/qr-background', form))).toBeNull()
    expect(contractProblem(request('POST', '/api/admin/bot/qr-background', {}))).toBe(
      'POST /api/admin/bot/qr-background: a body sent as application/json, where the operation takes multipart/form-data',
    )
  })

  it('leaves alone what is no API request — a page, an asset', () => {
    expect(contractProblem(request('GET', '/admin/assets/index.js'))).toBeNull()
  })
})

/** A group added through a server of the test's own, apart from the one every test is held to. */
const send = (fake: FakeServer, body: unknown) =>
  fake.fetch('http://localhost/api/admin/customer-groups', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })

describe('a request let through unchecked()', () => {
  it('goes in as it is, once — the next is held again', async () => {
    const fake = new FakeServer().on('POST', '/api/admin/customer-groups', json({ group: { id: 1 } }, 201)).unchecked('POST', '/api/admin/customer-groups')

    await send(fake, { name: 'VIP', colour: 'gold' })
    expect(fake.contractFailures()).toEqual([])

    await send(fake, { colour: 'gold' })
    expect(fake.contractFailures()).toEqual(['POST /api/admin/customer-groups: body.name: required, not sent; body.colour: a field the operation does not take'])
  })

  it('fails the test when the description takes it after all, or when it was never sent', async () => {
    const taken = new FakeServer().on('POST', '/api/admin/customer-groups', json({ group: { id: 1 } }, 201)).unchecked('POST', '/api/admin/customer-groups')
    await send(taken, { name: 'VIP' })
    expect(taken.contractFailures()).toEqual(['POST /api/admin/customer-groups: sent unchecked(), but the API description takes it — send it checked'])

    expect(new FakeServer().unchecked('POST', '/api/admin/customer-groups').contractFailures()).toEqual(['POST /api/admin/customer-groups: marked unchecked(), but no such request was sent'])
  })
})
