import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { App } from '@/apps/app/app'
import { keys, queryClient } from '@/apps/app/lib/query'
import { SessionProvider, useSession } from '@/apps/app/lib/session'
import { store } from '@/apps/app/lib/store'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreAccountResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, offline, refusal, server, withHeaders } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * Who the customer is (lib/session): Telegram's launch data signs them in — with the invite code a referral link
 * brought — and the token is kept on the device for the next launch, which goes on with it while it opens this Telegram
 * account still; a refusal says why, and offers to try again only where no answer came or the shop failed. A session
 * that ends while the app is open is traded for a new one beneath the screen, which stays as it was. A panel's preview
 * is signed in by its own token from the address — read as the customer, never traded for another nor kept —, and once
 * it ended (its 30 minutes, a 401), says the panel opens another, with no way to try again.
 */

const ENDED = 'پیش‌نمایش تمام شده است؛ آن را در پنل دوباره باز کنید.'

/** The preview's account: customer 7, as the panel's preview session reads them. */
const PREVIEWED: StoreAccountResponse = app.account({ session: { method: 'preview', biometric: false, recent_until: null } })

/** What the app says when the shop's mini app is switched off. */
const CLOSED = 'مینی‌اپ این فروشگاه فعلا خاموش است؛ کمی بعد دوباره از ربات باز کنید.'

/** The session's state, in words a test reads; a failure that may be tried again is a button that does. */
function Probe() {
  const { session, retry } = useSession()
  if (session.state === 'failed' && session.retry) {
    return (
      <button type="button" onClick={retry}>
        {`${session.message} · retry`}
      </button>
    )
  }

  return <p>{session.state === 'failed' ? `${session.message} · final` : session.state}</p>
}

function signIn() {
  render(
    <QueryClientProvider client={queryClient}>
      <SessionProvider>
        <Probe />
      </SessionProvider>
    </QueryClientProvider>,
  )
}

/** The session ended: a 401 with the Store API's challenge. */
const ended = withHeaders(refusal(401, 'نشست شما تمام شده است.'), { 'WWW-Authenticate': 'Bearer' })

beforeEach(() => {
  queryClient.clear()
  queryClient.setDefaultOptions({ ...queryClient.getDefaultOptions(), queries: { ...queryClient.getDefaultOptions().queries, retry: false } })
})

describe('a panel’s preview', () => {
  it('is signed in by its token from the address: the bearer on every request, no launch data traded, nothing kept', async () => {
    app.preview()
    server().on('GET', `${API}/me`, json(PREVIEWED))
    signIn()

    await screen.findByText('signed-in')
    expect(
      server()
        .sent('GET', `${API}/me`)
        .map((request) => request.headers.authorization),
    ).toEqual([`Bearer ${app.PREVIEW_TOKEN}`])
    expect(server().sent('POST', `${API}/auth/mini-app`)).toHaveLength(0)
    expect(queryClient.getQueryData<StoreAccountResponse>(keys.me)?.session.method).toBe('preview')
    expect(localStorage.length).toBe(0)
  })

  it('once ended, says the panel opens another — and offers no try again', async () => {
    app.preview()
    server().on('GET', `${API}/me`, ended)
    signIn()

    await screen.findByText(`${ENDED} · final`)
    expect(server().sent('POST', `${API}/auth/mini-app`)).toHaveLength(0)
  })

  it('ending while it is open says the same', async () => {
    app.preview()
    server().on('GET', `${API}/me`, json(PREVIEWED))
    signIn()
    await screen.findByText('signed-in')

    server().on('GET', `${API}/me`, ended).on('GET', `${API}/wallet`, ended)
    await queryClient.fetchQuery({ queryKey: keys.wallet, queryFn: () => store.get('/wallet') }).catch(() => undefined)

    await screen.findByText(`${ENDED} · final`)
    expect(server().sent('POST', `${API}/auth/mini-app`)).toHaveLength(0)
  })

  it('shows the ended preview on the app’s own failed screen, under the «پیش‌نمایش» pill', async () => {
    app.preview()
    server().on('GET', API, json(app.shop())).on('GET', `${API}/me`, ended)
    render(<App />)

    expect(await screen.findByText(ENDED)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
    expect(screen.getByText('پیش‌نمایش')).toBeTruthy()
  })
})

/** Where customer 42's token is kept on the device for the test's shop. */
const KEPT = 'amobot-app:0123456789abcdef01234567:42'

const TOKEN = 'c'.repeat(64)
const NEW_TOKEN = 'd'.repeat(64)

/** `GET /me` answered for whichever token holds: `tokens` maps each to its account, or to the session ended. */
function me(tokens: Record<string, StoreAccountResponse | 'ended'>) {
  server().on('GET', `${API}/me`, (request) => {
    const answer = tokens[(request.headers.authorization ?? '').replace(/^Bearer /, '')]
    return answer === undefined || answer === 'ended' ? ended : json(answer)
  })
}

/** The launch data signs in with `token`. */
const signsIn = (token = NEW_TOKEN) => json({ token, customer: app.account().customer })

/** A read answered "your session ended", as a screen's would be. */
const sessionEnds = () => {
  server().on('GET', `${API}/wallet`, ended)
  return queryClient.fetchQuery({ queryKey: keys.wallet, queryFn: () => store.get('/wallet') }).catch(() => undefined)
}

describe('Telegram’s own launch', () => {
  it('still signs in by its launch data and keeps the token on the device', async () => {
    app.telegram()
    server().on('POST', `${API}/auth/mini-app`, signsIn(TOKEN))
    me({ [TOKEN]: app.account() })
    signIn()

    await screen.findByText('signed-in')
    expect(server().sent('POST', `${API}/auth/mini-app`)).toHaveLength(1)
    expect(server().sent('GET', `${API}/me`)[0]?.headers.authorization).toBe(`Bearer ${TOKEN}`)
    expect(localStorage.getItem(KEPT)).toBe(TOKEN)
  })

  it('goes on with the token kept on the device: no launch data traded', async () => {
    app.telegram()
    localStorage.setItem(KEPT, TOKEN)
    me({ [TOKEN]: app.account() })
    signIn()

    await screen.findByText('signed-in')
    expect(server().sent('POST', `${API}/auth/mini-app`)).toHaveLength(0)
    expect(
      server()
        .sent('GET', `${API}/me`)
        .map((request) => request.headers.authorization),
    ).toEqual([`Bearer ${TOKEN}`])
    expect(localStorage.getItem(KEPT)).toBe(TOKEN)
  })

  it('lets go of a kept token that opens another Telegram account, and signs in by the launch data', async () => {
    app.telegram()
    localStorage.setItem(KEPT, TOKEN)
    const other = app.account()
    me({ [TOKEN]: { ...other, customer: { ...other.customer, id: 8, telegram: { id: 99, username: 'reza' } } }, [NEW_TOKEN]: app.account() })
    server().on('POST', `${API}/auth/mini-app`, signsIn())
    signIn()

    await screen.findByText('signed-in')
    expect(server().sent('POST', `${API}/auth/mini-app`)).toHaveLength(1)
    expect(localStorage.getItem(KEPT)).toBe(NEW_TOKEN)
    expect(queryClient.getQueryData<StoreAccountResponse>(keys.me)?.customer.telegram?.id).toBe(42)
  })

  it('lets go of a kept token whose session ended, and signs in afresh', async () => {
    app.telegram()
    localStorage.setItem(KEPT, TOKEN)
    me({ [TOKEN]: 'ended', [NEW_TOKEN]: app.account() })
    server().on('POST', `${API}/auth/mini-app`, signsIn())
    signIn()

    await screen.findByText('signed-in')
    const [signing] = server().sent('POST', `${API}/auth/mini-app`)
    expect(signing?.headers.authorization).toBeUndefined()
    expect(
      server()
        .sent('GET', `${API}/me`)
        .map((request) => request.headers.authorization),
    ).toEqual([`Bearer ${TOKEN}`, `Bearer ${NEW_TOKEN}`])
    expect(localStorage.getItem(KEPT)).toBe(NEW_TOKEN)
  })

  it('sends the invite code a referral link opened the app with', async () => {
    const { app: telegram } = app.telegram({ initDataUnsafe: { user: { id: 42 }, auth_date: 1790000000, start_param: 'ref_AB12cd' } })
    server().on('POST', `${API}/auth/mini-app`, signsIn())
    me({ [NEW_TOKEN]: app.account() })
    signIn()

    await screen.findByText('signed-in')
    expect(server().sent('POST', `${API}/auth/mini-app`)[0]?.body).toEqual({ init_data: telegram.initData, referral_code: 'AB12cd' })
  })

  it('sends no invite code for another start parameter', async () => {
    const { app: telegram } = app.telegram({ initDataUnsafe: { user: { id: 42 }, auth_date: 1790000000, start_param: 'buy' } })
    server().on('POST', `${API}/auth/mini-app`, signsIn())
    me({ [NEW_TOKEN]: app.account() })
    signIn()

    await screen.findByText('signed-in')
    expect(server().sent('POST', `${API}/auth/mini-app`)[0]?.body).toEqual({ init_data: telegram.initData })
  })

  it('says the shop’s mini app is switched off on a 404, and offers no try again', async () => {
    app.telegram()
    server().on('POST', `${API}/auth/mini-app`, refusal(404, 'این وب‌سایت پیدا نشد.'))
    signIn()

    await screen.findByText(`${CLOSED} · final`)
  })

  it.each([
    [401, 'اطلاعات ورود تلگرام معتبر نیست؛ مینی‌اپ را دوباره از ربات باز کنید.'],
    [403, 'حساب شما مسدود شده است.'],
  ])('takes a %i as final: the shop’s words, and no try again', async (status, words) => {
    app.telegram()
    server().on('POST', `${API}/auth/mini-app`, refusal(status, words))
    signIn()

    await screen.findByText(`${words} · final`)
    expect(server().sent('POST', `${API}/auth/mini-app`)).toHaveLength(1)
  })

  it('offers to try again when the shop failed, and signs in when it does', async () => {
    app.telegram()
    server().on('POST', `${API}/auth/mini-app`, withHeaders(refusal(500, 'خطای سرور.'), { 'X-Request-Id': 'r-77' }))
    signIn()

    const retry = await screen.findByRole('button', { name: 'خطای سرور. (کد پیگیری: r-77) · retry' })
    server().on('POST', `${API}/auth/mini-app`, signsIn())
    me({ [NEW_TOKEN]: app.account() })
    fireEvent.click(retry)

    await screen.findByText('signed-in')
    expect(server().sent('POST', `${API}/auth/mini-app`)).toHaveLength(2)
  })

  it('offers to try again when no answer came', async () => {
    app.telegram()
    server().on('POST', `${API}/auth/mini-app`, offline)
    signIn()

    expect((await screen.findByRole('button', { name: / · retry$/ })).textContent).toMatch(/^پاسخی از فروشگاه نرسید/)
  })
})

describe('a session that ends while the app is open', () => {
  it('is traded for a new one, every read but the account just read asked again — its sessions too', async () => {
    app.telegram()
    localStorage.setItem(KEPT, TOKEN)
    me({ [TOKEN]: app.account() })
    signIn()
    await screen.findByText('signed-in')
    queryClient.setQueryData(keys.sessions, { sessions: [] })

    me({ [TOKEN]: 'ended', [NEW_TOKEN]: app.account() })
    server().on('POST', `${API}/auth/mini-app`, signsIn())
    await sessionEnds()

    await until(() => expect(localStorage.getItem(KEPT)).toBe(NEW_TOKEN))
    await until(() => expect(queryClient.getQueryState(keys.sessions)?.isInvalidated).toBe(true))
    expect(queryClient.getQueryState(keys.me)?.isInvalidated).toBe(false)
    expect(screen.getByText('signed-in')).toBeTruthy()
  })

  it('says why when the new sign-in is refused: only a new launch helps', async () => {
    app.telegram()
    localStorage.setItem(KEPT, TOKEN)
    me({ [TOKEN]: app.account() })
    signIn()
    await screen.findByText('signed-in')

    const words = 'اطلاعات ورود تلگرام معتبر نیست؛ مینی‌اپ را دوباره از ربات باز کنید.'
    me({ [TOKEN]: 'ended' })
    server().on('POST', `${API}/auth/mini-app`, refusal(401, words))
    await sessionEnds()

    await screen.findByText(`${words} · final`)
    expect(localStorage.getItem(KEPT)).toBeNull()
  })

  it('leaves the screen standing — not the loading view — while the new sign-in runs beneath it', async () => {
    app.telegram()
    localStorage.setItem(KEPT, TOKEN)
    me({ [TOKEN]: app.account() })
    server().on('GET', API, json(app.shop()))
    // The page's address leads to no screen: «این صفحه پیدا نشد» is the screen open.
    render(<App />)
    const shown = await screen.findByText('این صفحه پیدا نشد')
    await until(() => expect(server().sent('GET', API)).toHaveLength(2))

    me({ [TOKEN]: 'ended', [NEW_TOKEN]: app.account() })
    const signing = server().hold('POST', `${API}/auth/mini-app`)
    await sessionEnds()

    await until(() => expect(signing.waiting).toBe(1))
    expect(screen.queryByRole('status', { name: 'در حال بارگذاری' })).toBeNull()
    expect(screen.getByText('این صفحه پیدا نشد')).toBe(shown)

    signing.answer(signsIn())
    await until(() => expect(localStorage.getItem(KEPT)).toBe(NEW_TOKEN))
    await until(() => expect(server().sent('GET', API)).toHaveLength(3))
    expect(screen.getByText('این صفحه پیدا نشد')).toBe(shown)
  })
})

describe('outside Telegram', () => {
  it('sends the customer to the bot, its handle read left to right', async () => {
    server().on('GET', API, json(app.shop()))
    render(<App />)

    const open = await screen.findByRole('button', { name: 'باز کردن @sample_bot' })
    expect(within(open).getByText('@sample_bot').className).toContain('ltr')
  })
})
