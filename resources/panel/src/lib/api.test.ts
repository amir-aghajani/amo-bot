import { describe, expect, it, vi } from 'vitest'
import { api, apiClient, ApiError, isTransportError, mediaUrl, onUnauthorized, rootApi, shopMissing, stateChanged } from '@/lib/api'
import type { CustomerGroupResponse, PanelWrite } from '@/lib/api-types'
import { html, json, noContent, offline, refusal, server, silent, withHeaders } from '@/test/server'

/**
 * How the panel talks to PHP (lib/api): what every request carries — the shop the owner's tab shows among it —, how a
 * write is typed by its operation, the files the browser asks for itself, and what every kind of failure becomes — its
 * status, the API's words when it answered in its shape, and the facts lib/failure words it by and shows in its details.
 */

const VIP = { group: { id: 7, name: 'VIP', sort: 1, counts: { users: 0 } } }

/** Writes as the compiler holds them to their operations — never run: `pnpm typecheck` is their test. */
async function typedWrites() {
  const answer: CustomerGroupResponse = await api.post('/customer-groups', { name: 'VIP' })
  // @ts-expect-error a field the operation does not read
  await api.post('/customer-groups', { name: 'VIP', color: 'gold' })
  const draft = { name: 'VIP', color: 'gold' }
  // @ts-expect-error a form's values with a field the operation does not read
  await api.post('/customer-groups', draft)
  // @ts-expect-error a field the operation needs, left out
  await api.post('/customer-groups', {})
  // @ts-expect-error a body to a write that takes none
  await api.post('/plans/3/duplicate', {})
  // @ts-expect-error no write at that address
  await api.post('/customer-group', { name: 'VIP' })
  // @ts-expect-error a reading API writes nothing
  await rootApi.post('/app')
  return answer
}

describe('a request', () => {
  it('goes to the panel’s own API as JSON, with the session cookie and the CSRF header', async () => {
    server().on('POST', '/api/admin/customer-groups', json(VIP, 201))

    await expect(api.post('/customer-groups', { name: 'VIP' })).resolves.toEqual(VIP)

    const [request] = server().sent('POST', '/api/admin/customer-groups')
    expect(request?.headers['x-requested-with']).toBe('XMLHttpRequest')
    expect(request?.headers['content-type']).toBe('application/json')
    expect(request?.headers.accept).toBe('application/json')
    expect(request?.credentials).toBe('same-origin')
    expect(request?.body).toEqual({ name: 'VIP' })
  })

  it('sends a body with a file as multipart, a part a field, leaving the boundary header to the browser', async () => {
    server().on('POST', '/api/admin/bot/qr-background', json({ ok: true }))
    const file = new File(['png'], 'background.png', { type: 'image/png' })

    await api.post('/bot/qr-background', { file })

    const [request] = server().sent('POST', '/api/admin/bot/qr-background')
    expect(request?.body instanceof FormData ? request.body.get('file') : null).toBe(file)
    expect(request?.headers['content-type']).toBeUndefined()
    expect(request?.headers['x-requested-with']).toBe('XMLHttpRequest')
  })

  it('sends no body to a write that takes none', async () => {
    server().on('POST', '/api/admin/plans/3/duplicate', json({ plan: { id: 4 } }, 201))

    await api.post('/plans/3/duplicate')

    const [request] = server().sent('POST', '/api/admin/plans/3/duplicate')
    expect(request?.body).toBeUndefined()
    expect(request?.headers['content-type']).toBeUndefined()
  })

  it('takes, for a write, the body its operation reads and gives its answer — held by the compiler (pnpm typecheck)', () => {
    expect(typedWrites).toBeTypeOf('function')
  })

  it('puts a read’s search, filters and page after the address, and nothing when there are none', async () => {
    server().on('GET', '/api/admin/orders', json({ orders: [] }))

    await api.get('/orders', new URLSearchParams({ search: '#12', page: '2' }))
    await api.get('/orders', new URLSearchParams())

    const [narrowed, plain] = server().sent('GET', '/api/admin/orders')
    expect(narrowed?.query.get('search')).toBe('#12')
    expect(narrowed?.query.get('page')).toBe('2')
    expect(plain?.query.size).toBe(0)
  })

  it('keeps a read’s query, and a file’s shop, in a browser that knows no URLSearchParams.size (Safari 16.4, Chrome 111: the build serves them)', async () => {
    vi.spyOn(URLSearchParams.prototype, 'size', 'get').mockReturnValue(undefined as unknown as number)
    server().on('GET', '/api/admin/orders', json({ orders: [] }))

    await api.get('/orders', new URLSearchParams({ page: '2' }))

    expect(server().sent('GET', '/api/admin/orders')[0]?.query.get('page')).toBe('2')
    expect(mediaUrl('/payments/7/receipt')).toBe('/api/admin/payments/7/receipt?shop=1')
  })

  it('reads the shop’s name from the API’s root, beside the panels’ own', async () => {
    server().on('GET', '/api/app', json({ name: 'AmoBot', installed: true }))

    await expect(rootApi.get('/app')).resolves.toEqual({ name: 'AmoBot', installed: true })
  })

  it('asks for its extra headers anew for each request', async () => {
    let key = 'first'
    const install = apiClient('/api/install', () => ({ 'X-Install-Key': key }))
    server().on('GET', '/api/install', json({}))

    await install.get('')
    key = 'second'
    await install.get('')

    expect(
      server()
        .sent('GET', '/api/install')
        .map((request) => request.headers['x-install-key']),
    ).toEqual(['first', 'second'])
  })

  it('reads nothing from an answer without content', async () => {
    server().on('DELETE', '/api/admin/plans/3', noContent)

    await expect(api.delete('/plans/3')).resolves.toBeUndefined()
  })
})

describe('the shop a request names', () => {
  it('is the one the owner’s tab shows, on every request — a read, a write (the suite’s tab: the main shop’s)', async () => {
    server()
      .on('GET', '/api/admin/orders', json({ orders: [] }))
      .on('POST', '/api/admin/customer-groups', json(VIP, 201))

    await api.get('/orders')
    await api.post('/customer-groups', { name: 'VIP' })

    expect(server().requests.map((request) => request.headers['x-shop'])).toEqual(['1', '1'])
  })

  it('is none from an agent’s panel: their shop is their bot', async () => {
    // The agent's page, as its index.html sets it up before anything loads.
    window.history.replaceState(null, '', '/agent/orders')
    document.head.append(Object.assign(document.createElement('base'), { href: '/agent/' }))
    vi.resetModules()
    const agents = await import('@/lib/api')
    server().on('GET', '/api/agent/orders', json({ orders: [] }))

    await agents.api.get('/orders')

    expect(server().sent('GET', '/api/agent/orders')[0]?.headers['x-shop']).toBeUndefined()
    expect(agents.mediaUrl('/payments/7/receipt')).toBe('/api/agent/payments/7/receipt')
  })

  it('is told apart when it is not there: the tab is at the address of no shop', () => {
    expect(shopMissing(new ApiError(404, 'این فروشگاه پیدا نشد.', { shop: ['این فروشگاه پیدا نشد.'] }))).toBe(true)
    expect(shopMissing(new ApiError(404, 'مورد درخواستی پیدا نشد.'))).toBe(false)
    expect(shopMissing(new ApiError(422, 'شماره درستی ندارد.', { shop: ['شماره درستی ندارد.'] }))).toBe(false)
  })
})

describe('a file the browser asks for itself — a picture, a video, a download', () => {
  it('names the shop in its address, beside the address’s own query: no header goes with it', () => {
    expect(mediaUrl('/payments/7/receipt')).toBe('/api/admin/payments/7/receipt?shop=1')
    expect(mediaUrl('/bot/qr-background', { v: '1759000000' })).toBe('/api/admin/bot/qr-background?v=1759000000&shop=1')
  })

  it('is there, or not and why — asked of its address, the shop named, its bytes not read', async () => {
    server()
      .on('GET', '/api/admin/payments/7/receipt', { kind: 'response', status: 200, body: 'HEIC bytes', type: 'application/octet-stream' })
      .on('GET', '/api/admin/payments/8/receipt', refusal(404, 'فایل این رسید دیگر در تلگرام نیست.'))

    await expect(api.probe('/payments/7/receipt')).resolves.toBeUndefined()
    await expect(api.probe('/payments/8/receipt')).rejects.toMatchObject({ status: 404, message: 'فایل این رسید دیگر در تلگرام نیست.' })
    expect(server().requests.map((request) => request.headers['x-shop'])).toEqual(['1', '1'])
  })

  it('is read as its bytes when the panel handles it itself, else the failure the API words', async () => {
    server()
      .on('GET', '/api/admin/bot/custom-emojis/5/animation', { kind: 'response', status: 200, body: 'tgs bytes', type: 'application/octet-stream' })
      .on('GET', '/api/admin/bot/custom-emojis/6/animation', refusal(404, 'این ایموجی تصویر متحرکی ندارد.'))

    await expect((await api.blob('/bot/custom-emojis/5/animation')).text()).resolves.toBe('tgs bytes')
    await expect(api.blob('/bot/custom-emojis/6/animation')).rejects.toMatchObject({ status: 404 })
  })
})

describe('a failure', () => {
  it('with no answer at all is a transport error, saying which request it was', async () => {
    server().on('GET', '/api/admin/plans', offline)

    const error = await api.get('/plans', new URLSearchParams({ search: 'رضا' })).catch((failure: unknown) => failure)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 0, facts: { endpoint: 'GET /api/admin/plans', foreign: true, timedOut: false, offline: false } })
    expect(isTransportError(error)).toBe(true)
  })

  it('with no answer while the browser is offline says so', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    server().on('GET', '/api/admin/plans', offline)

    await expect(api.get('/plans')).rejects.toMatchObject({ status: 0, facts: { offline: true } })
  })

  it('gives up on a stalled connection after 90 seconds, and not before', async () => {
    vi.useFakeTimers()
    server().on('GET', '/api/admin/servers', silent)
    let outcome: unknown = 'pending'
    void api.get('/servers').catch((failure: unknown) => (outcome = failure))

    await vi.advanceTimersByTimeAsync(89_999)
    expect(outcome).toBe('pending')

    await vi.advanceTimersByTimeAsync(1)
    expect(outcome).toBeInstanceOf(ApiError)
    expect(outcome).toMatchObject({ status: 0, facts: { timedOut: true } })
    expect(isTransportError(outcome)).toBe(true)
  })

  it('refuses a success that is not JSON — a host’s or a proxy’s page', async () => {
    server().on('GET', '/api/admin/plans', html('<html><body>Welcome to cPanel</body></html>'))

    const error = await api.get('/plans').catch((failure: unknown) => failure)

    expect(error).toMatchObject({ status: 200, facts: { foreign: true } })
    expect(isTransportError(error)).toBe(false)
  })

  it('carries the API’s words and what it says under each field', async () => {
    server().on('POST', '/api/admin/agency/levels', refusal(422, 'نام سطح را وارد کنید.', { name: ['نام سطح را وارد کنید.', 'second'], price_per_gb: ['قیمت معتبر نیست.'] }))

    const error = await api.post('/agency/levels', { name: '', price_per_gb: 'x' }).catch((failure: unknown) => failure)

    expect(error).toBeInstanceOf(ApiError)
    const refused = error as ApiError
    expect(refused.status).toBe(422)
    expect(refused.message).toBe('نام سطح را وارد کنید.')
    expect(refused.field('name')).toBe('نام سطح را وارد کنید.')
    expect(refused.field('price_per_gb')).toBe('قیمت معتبر نیست.')
    expect(refused.field('sort')).toBeUndefined()
  })

  it('marks an error answer that is not the API’s own shape as such — a proxy’s page', async () => {
    server().on('GET', '/api/admin/plans', html('<h1>Bad Gateway</h1>', 502))

    const error = await api.get('/plans').catch((failure: unknown) => failure)

    expect(error).toMatchObject({ status: 502, errors: {}, facts: { foreign: true, requestId: null } })
  })

  it('keeps the request’s id the server named it by, from the answer or its header', async () => {
    server()
      .on('POST', '/api/admin/customer-groups', json({ message: 'خطایی در سرور رخ داد.', request_id: '3f9a1c2b7d4e5f60' }, 500))
      .on('GET', '/api/admin/plans', withHeaders(html('<h1>Internal Server Error</h1>', 500), { 'X-Request-Id': '0123456789abcdef' }))

    await expect(api.post('/customer-groups', { name: 'VIP' })).rejects.toMatchObject({ status: 500, message: 'خطایی در سرور رخ داد.', facts: { requestId: '3f9a1c2b7d4e5f60', foreign: false } })
    await expect(api.get('/plans')).rejects.toMatchObject({ facts: { requestId: '0123456789abcdef', foreign: true } })
  })

  it('reads a 429’s wait, in seconds or as a moment', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-06T10:00:00Z'))
    server()
      .on('POST', '/api/admin/auth/login', withHeaders(refusal(429, 'تلاش‌های ناموفق زیاد بود.'), { 'Retry-After': '120' }))
      .on('POST', '/api/agent/auth/link', withHeaders(refusal(429, 'تلاش‌های ناموفق زیاد بود.'), { 'Retry-After': 'Tue, 06 Oct 2026 10:01:30 GMT' }))

    await expect(api.post('/auth/login', { username: 'owner', password: 'wrong' }, { signIn: true })).rejects.toMatchObject({ status: 429, facts: { retryAfter: 120 } })
    await expect(apiClient<PanelWrite>('/api/agent').post('/auth/link', { code: 'spent' }, { signIn: true })).rejects.toMatchObject({ facts: { retryAfter: 90 } })
  })

  it('keeps what the server threw when it says it (APP_DEBUG)', async () => {
    server().on('GET', '/api/admin/plans', json({ message: 'خطایی در سرور رخ داد.', request_id: 'ab', debug: { exception: 'RuntimeException', detail: 'disk on fire', trace: [] } }, 500))

    await expect(api.get('/plans')).rejects.toMatchObject({ facts: { debug: 'RuntimeException: disk on fire' } })
  })

  it('tells whoever listens that the session is gone on a 401, and only on a 401', async () => {
    const listener = vi.fn()
    const stop = onUnauthorized(listener)
    server().on('GET', '/api/admin/plans', refusal(401, 'ابتدا وارد شوید.')).on('GET', '/api/admin/servers', refusal(403, 'دسترسی ندارید.'))

    await api.get('/servers').catch(() => undefined)
    expect(listener).not.toHaveBeenCalled()

    await expect(api.get('/plans')).rejects.toMatchObject({ status: 401, message: 'ابتدا وارد شوید.' })
    expect(listener).toHaveBeenCalledTimes(1)

    stop()
    await api.get('/plans').catch(() => undefined)
    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('keeps a sign-in’s 401 to itself: it refuses the attempt, not a session open meanwhile', async () => {
    const listener = vi.fn()
    const stop = onUnauthorized(listener)
    server().on('POST', '/api/admin/auth/login', refusal(401, 'نام کاربری یا رمز عبور اشتباه است.'))

    await expect(api.post('/auth/login', { username: 'owner', password: 'wrong' }, { signIn: true })).rejects.toMatchObject({ status: 401 })

    expect(listener).not.toHaveBeenCalled()
    stop()
  })
})

describe('reading a failure', () => {
  const refused = new ApiError(422, 'Refused.', { note: ['Too long.'], status: ['Paid meanwhile.'] })

  it('tells a subject that moved on (a refusal about its state) from any other refusal', () => {
    expect(stateChanged(refused)).toBe(true)
    expect(stateChanged(new ApiError(422, 'x', { note: ['Too long.'] }))).toBe(false)
    expect(stateChanged(new Error('x'))).toBe(false)
  })

  it('tells a failure with no answer from one the server answered', () => {
    expect(isTransportError(new ApiError(0, 'x'))).toBe(true)
    expect(isTransportError(new ApiError(500, 'x'))).toBe(false)
    expect(isTransportError(new TypeError('Failed to fetch'))).toBe(false)
  })
})
