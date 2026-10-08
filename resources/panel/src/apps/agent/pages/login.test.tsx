import { StrictMode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Navigate, Route, Routes, useLocation, useNavigate, type InitialEntry } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { LoginPage } from '@/apps/agent/pages/login'
import type { AppInfo, Session, SessionResponse } from '@/lib/api-types'
import { AuthProvider, useAuth } from '@/lib/auth'
import { testQueryClient, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * An agent's only way in (apps/agent/pages/login): the one-time link from their account in the main bot carries its code
 * in the fragment, which the page takes off the address bar at once and spends once — after the panel's first question
 * about its session has been answered, even when React mounts the page twice (StrictMode) —; signed in, the panel goes
 * back to the page the session ended on, as the owner's does.
 */

const AGENT: Session = { name: '@agent_shop_bot', shop: { id: 2, name: 'فروشگاه نماینده', username: 'agent_shop_bot', status: 'active' } }

function Address() {
  const { pathname, search, hash } = useLocation()
  return <p data-testid="address">{pathname + search + hash}</p>
}

/** A link followed once the panel knows the session — the login page then mounts with nothing left to wait for. */
function Later() {
  const { loading } = useAuth()
  return loading ? null : <Navigate to="/login#code=late" />
}

/** A fresh link opened in the same tab, which shows the login page already. */
function FreshLink() {
  const navigate = useNavigate()
  return (
    <button type="button" onClick={() => void navigate('/login#code=fresh')}>
      لینک تازه
    </button>
  )
}

/** The agent's panel opened at `at`, as main.tsx mounts it: in StrictMode. */
function open(at: InitialEntry) {
  render(
    <StrictMode>
      <QueryClientProvider client={testQueryClient()}>
        <MemoryRouter initialEntries={[at]}>
          <AuthProvider>
            <Routes>
              <Route path="/login" element={<LoginPage />} />
              <Route path="/later" element={<Later />} />
              <Route path="/" element={<p>داشبورد</p>} />
              <Route path="*" element={<p>صفحه دیگر پنل</p>} />
            </Routes>
            <Address />
            <FreshLink />
          </AuthProvider>
        </MemoryRouter>
      </QueryClientProvider>
    </StrictMode>,
  )
}

const address = () => screen.getByTestId('address').textContent

beforeEach(() => {
  server().on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true, timezone: 'UTC' } satisfies AppInfo))
})

describe('a login link', () => {
  it('leaves the address bar at once, and is spent once the session question has its answer', async () => {
    const me = server().hold('GET', '/api/agent/auth/me')
    server().on('POST', '/api/agent/auth/link', json({ session: AGENT } satisfies SessionResponse))

    open('/login#code=Zx81-qq')

    await until(() => expect(me.waiting).toBeGreaterThan(0))
    expect(address()).toBe('/login')
    expect(screen.getByRole('status').textContent).toContain('در حال ورود به پنل نمایندگی')
    expect(server().sent('POST', '/api/agent/auth/link')).toEqual([])

    while (me.waiting > 0) me.answer(refusal(401, 'ابتدا وارد شوید.'))

    expect(await screen.findByText('داشبورد')).toBeTruthy()
    const spent = server().sent('POST', '/api/agent/auth/link')
    expect(spent).toHaveLength(1)
    expect(spent[0]?.body).toEqual({ code: 'Zx81-qq' })
    expect(spent[0]?.headers['x-requested-with']).toBe('XMLHttpRequest')
  })

  it('is spent once when the page mounts twice with nothing to wait for', async () => {
    server().on('GET', '/api/agent/auth/me', refusal(401, 'ابتدا وارد شوید.'))
    server().on('POST', '/api/agent/auth/link', json({ session: AGENT }))

    open('/later')

    expect(await screen.findByText('داشبورد')).toBeTruthy()
    expect(
      server()
        .sent('POST', '/api/agent/auth/link')
        .map((request) => request.body),
    ).toEqual([{ code: 'late' }])
  })

  it('that no longer works says so, and where to get a fresh one', async () => {
    server().on('GET', '/api/agent/auth/me', refusal(401, 'ابتدا وارد شوید.'))
    server().on('POST', '/api/agent/auth/link', refusal(422, 'این لینک دیگر کار نمی‌کند؛ از ربات لینک تازه بگیرید.', { code: ['این لینک دیگر کار نمی‌کند؛ از ربات لینک تازه بگیرید.'] }))

    open('/login#code=used')

    expect(await screen.findByText('این لینک دیگر کار نمی‌کند؛ از ربات لینک تازه بگیرید.')).toBeTruthy()
    expect(screen.getByText(/در ربات اصلی به «نمایندگی» بروید/)).toBeTruthy()
    expect(server().sent('POST', '/api/agent/auth/link')).toHaveLength(1)
    expect(address()).toBe('/login')
  })

  it('opened fresh in a tab that shows the page already — after one that no longer worked — is spent too', async () => {
    const used = 'این لینک دیگر کار نمی‌کند؛ از ربات لینک تازه بگیرید.'
    server().on('GET', '/api/agent/auth/me', refusal(401, 'ابتدا وارد شوید.'))
    server().on('POST', '/api/agent/auth/link', (request) => ((request.body as { code: string }).code === 'fresh' ? json({ session: AGENT }) : refusal(422, used, { code: [used] })))

    open('/login#code=used')
    expect(await screen.findByText(used)).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'لینک تازه' }))

    expect(await screen.findByText('داشبورد')).toBeTruthy()
    expect(
      server()
        .sent('POST', '/api/agent/auth/link')
        .map((request) => request.body),
    ).toEqual([{ code: 'used' }, { code: 'fresh' }])
  })

  it('that no longer works leaves a session open in this browser as it was, and the way back to it', async () => {
    const refused = 'این لینک ورود دیگر کار نمی‌کند؛ از ربات، «نمایندگی» ← «ورود به پنل»، لینک تازه بگیرید.'
    server().on('GET', '/api/agent/auth/me', json({ session: AGENT }))
    server().on('POST', '/api/agent/auth/link', refusal(401, refused))

    open('/login#code=spent')

    expect(await screen.findByText(refused)).toBeTruthy()
    fireEvent.click(screen.getByRole('link', { name: 'رفتن به پنل' }))
    expect(await screen.findByText('داشبورد')).toBeTruthy()
  })

  it('opened fresh in the tab the session ended in goes back, signed in, to the page it ended on — its query string too', async () => {
    server().on('GET', '/api/agent/auth/me', refusal(401, 'ابتدا وارد شوید.'))
    server().on('POST', '/api/agent/auth/link', json({ session: AGENT }))

    open({ pathname: '/login', state: { from: '/referrals/invitees?page=2', expired: true } })
    expect(await screen.findByText(/زمان ورود شما تمام شد/)).toBeTruthy()
    // The fresh link lands in this tab: its own history entry carries nothing of where the session ended.
    fireEvent.click(screen.getByRole('button', { name: 'لینک تازه' }))

    expect(await screen.findByText('صفحه دیگر پنل')).toBeTruthy()
    expect(address()).toBe('/referrals/invitees?page=2')
  })

  it('never goes back to the login page itself', async () => {
    server().on('GET', '/api/agent/auth/me', refusal(401, 'ابتدا وارد شوید.'))
    server().on('POST', '/api/agent/auth/link', json({ session: AGENT }))

    open({ pathname: '/login', hash: '#code=fresh', state: { from: '/login' } })

    expect(await screen.findByText('داشبورد')).toBeTruthy()
  })

  it('signs in a panel that is open in another session already — the link names who signs in', async () => {
    server().on('GET', '/api/agent/auth/me', json({ session: { ...AGENT, name: '@other_bot' } }))
    server().on('POST', '/api/agent/auth/link', json({ session: AGENT }))

    open('/login#code=fresh')

    expect(await screen.findByText('داشبورد')).toBeTruthy()
    expect(server().sent('POST', '/api/agent/auth/link')).toHaveLength(1)
  })
})

describe('a login link of another agent’s', () => {
  const ANOTHER = 'در این مرورگر پنل نمایندگی دیگری باز است؛ ورود با این لینک از آن پنل خارج می‌شود.'

  /** The browser signed in to @other_bot's panel; the link is another agent's, which the server takes in its place only when told to. */
  function signedInElsewhere() {
    server()
      .on('GET', '/api/agent/auth/me', json({ session: { ...AGENT, name: '@other_bot' } }))
      .on('POST', '/api/agent/auth/link', (request) => ((request.body as { replace?: boolean }).replace ? json({ session: AGENT }) : refusal(409, ANOTHER, { replace: [ANOTHER] })))
  }

  it('asks before it takes the place of the session open here — staying keeps that session, and its panel', async () => {
    signedInElsewhere()
    open('/login#code=fresh')

    const question = await screen.findByRole('dialog', { name: 'ورود به پنل نمایندگی دیگر' })
    expect(question.textContent).toContain('@other_bot')
    fireEvent.click(within(question).getByRole('button', { name: /^ماندن در پنل/ }))

    expect(await screen.findByText('داشبورد')).toBeTruthy()
    expect(
      server()
        .sent('POST', '/api/agent/auth/link')
        .map((request) => request.body),
    ).toEqual([{ code: 'fresh' }])
  })

  it('signs in in its place once the agent says so — the link was not spent by the question', async () => {
    signedInElsewhere()
    open('/login#code=fresh')

    fireEvent.click(within(await screen.findByRole('dialog', { name: 'ورود به پنل نمایندگی دیگر' })).getByRole('button', { name: 'ورود با این لینک' }))

    expect(await screen.findByText('داشبورد')).toBeTruthy()
    expect(
      server()
        .sent('POST', '/api/agent/auth/link')
        .map((request) => request.body),
    ).toEqual([{ code: 'fresh' }, { code: 'fresh', replace: true }])
  })
})

describe('the login page without a link', () => {
  it('says where an agent gets one, and spends nothing', async () => {
    server().on('GET', '/api/agent/auth/me', refusal(401, 'ابتدا وارد شوید.'))

    open('/login')

    expect(await screen.findByText(/در ربات اصلی به «نمایندگی» بروید/)).toBeTruthy()
    await until(() => expect(document.title).toBe('ورود به پنل نمایندگی · فروشگاه امو'))
    expect(server().sent('POST', '/api/agent/auth/link')).toEqual([])
  })

  it('says the session ended when the guard sent the agent here for that', async () => {
    server().on('GET', '/api/agent/auth/me', refusal(401, 'ابتدا وارد شوید.'))

    open({ pathname: '/login', state: { from: '/orders', expired: true } })

    expect(await screen.findByText('زمان ورود شما تمام شد؛ با لینک تازه‌ای از ربات دوباره وارد شوید.')).toBeTruthy()
  })

  it('sends an agent who is signed in already to the panel', async () => {
    server().on('GET', '/api/agent/auth/me', json({ session: AGENT }))

    open('/login')

    expect(await screen.findByText('داشبورد')).toBeTruthy()
  })
})
