import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { AccountPage } from '@/apps/agent/pages/account'
import type { AccountResponse, AgencyStanding, AgentBot, TrafficLinesResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { agencyStanding } from '@/test/rows'
import { json, noContent, refusal, server } from '@/test/server'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * The agent's «حساب نمایندگی» (apps/agent/pages/account): an account that could not be read says so, its figures and
 * its bot «—» — nothing pulsing for ever; the bot's date is when its token was first handed over, and a bot whose token
 * is gone since is asked for it again — a warning: it stopped —, one never connected for its first; where traffic is
 * bought is said once, by the notice while the bot's traffic sells nothing; the agency's renewal is asked for beside
 * its end while the agent may, after a second look, and its latest request said — waiting, or what was decided —; and
 * every other browser signed in to the agent's panel is signed out from here, after a second look, this one staying.
 */

const GB = 1024 ** 3

const BOT: AgentBot = { id: 2, username: 'agent_shop_bot', title: 'فروشگاه نماینده', status: 'active', connected: true, problem: null, traffic_balance: 50 * GB, connected_at: '2026-10-03T09:00:00Z' }

/** The account as the server answers it, its bot as `bot` says; no renewal asked for, none to ask unless `more` says so. */
function account(
  bot: Partial<AgentBot> = {},
  shortage: AccountResponse['traffic_shortage'] = null,
  standing: AgencyStanding = agencyStanding(),
  more: Partial<AccountResponse['account']> = {},
): AccountResponse {
  return {
    traffic_shortage: shortage,
    account: {
      bot: { ...BOT, ...bot },
      agent: { id: 10, name: 'رضا', username: 'reza', telegram_id: 123456789, email: null },
      level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
      credit_limit: '0.00',
      balance: '25000.00',
      standing,
      renewal: null,
      actions: { request_renewal: false },
      ...more,
    },
  }
}

const skeletons = () => document.querySelectorAll('[data-slot="skeleton"]')

function open() {
  server().on('GET', '/api/agent/account/traffic', json({ lines: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } } satisfies TrafficLinesResponse))
  render(<AccountPage />, { wrapper: providers().wrapper })
}

describe('an account that could not be read', () => {
  it('says so, its figures and its bot «—», nothing pulsing', async () => {
    server().on('GET', '/api/agent/account', refusal(500, 'خطای سرور.'))
    open()

    expect(await screen.findByText('حساب نمایندگی بارگذاری نشد.')).toBeTruthy()
    // Three figures, the bot's card and the terms of selling's.
    expect(screen.getAllByText('—')).toHaveLength(5)
    expect(screen.queryByText('نمایندگی فعال نیست')).toBeNull()
    await until(() => expect(skeletons()).toHaveLength(0))
  })

  it('stays said while it is asked again — its button keeping the focus, spinning —, until the answer', async () => {
    server().on('GET', '/api/agent/account', refusal(500, 'خطای سرور.'))
    open()
    const retry = await screen.findByRole('button', { name: 'تلاش دوباره' })
    const again = server().hold('GET', '/api/agent/account')

    retry.focus()
    fireEvent.click(retry)
    await until(() => expect(retry.getAttribute('aria-busy')).toBe('true'))
    expect(document.activeElement).toBe(retry)
    expect(screen.getByText('حساب نمایندگی بارگذاری نشد.')).toBeTruthy()

    again.answer(json(account()))
    await until(() => expect(screen.queryByText('حساب نمایندگی بارگذاری نشد.')).toBeNull())
  })
})

describe('the agent’s bot', () => {
  it('says when its token was first handed over', async () => {
    server().on('GET', '/api/agent/account', json(account()))
    open()

    expect(await screen.findByText('اولین اتصال')).toBeTruthy()
    expect(screen.queryByText('وصل شده')).toBeNull()
    expect(screen.queryByText(/وصل نشده/)).toBeNull()
  })

  it('whose token is gone since is asked for it again, as a warning — the bot stopped', async () => {
    server().on('GET', '/api/agent/account', json(account({ connected: false })))
    open()

    const stopped = await screen.findByText(/ربات شما دیگر وصل نیست/)
    expect(stopped.closest('[class*="border-warning-line"]')).toBeTruthy()
    expect(screen.getByText('اولین اتصال')).toBeTruthy()
    expect(screen.getByText('وصل نشده')).toBeTruthy()
  })

  it('never connected is asked for its first token, as the next step', async () => {
    server().on('GET', '/api/agent/account', json(account({ connected: false, connected_at: null, username: null, title: null })))
    open()

    const first = await screen.findByText(/ربات شما هنوز وصل نشده است/)
    expect(first.closest('[class*="border-info-line"]')).toBeTruthy()
  })
})

describe('where traffic is bought', () => {
  it('is shown under the remaining traffic', async () => {
    server().on('GET', '/api/agent/account', json(account()))
    open()

    expect(await screen.findAllByText(/خرید حجم: ربات اصلی/)).toHaveLength(1)
  })

  it('is shown only once, in the notice, while the traffic is too low to sell', async () => {
    server().on('GET', '/api/agent/account', json(account({ traffic_balance: 0 }, { balance: 0, smallest_plan: 20 * GB })))
    open()

    expect(await screen.findByText('حجم ربات تمام شده است.')).toBeTruthy()
    expect(screen.getAllByText(/خرید حجم: ربات اصلی/)).toHaveLength(1)
  })
})

describe('the terms of selling', () => {
  it('show the end, this month’s traffic bought against the minimum, and that the bot sells', async () => {
    server().on(
      'GET',
      '/api/agent/account',
      json(
        account(
          {},
          null,
          agencyStanding({ expires_at: '2027-01-01T10:00:00Z', monthly_minimum: '500000.00', month: { name: 'مهر ۱۴۰۵', purchased: '150000.00', ends_at: '2026-10-22T20:30:00Z', judged: true } }),
        ),
      ),
    )
    open()

    expect(await screen.findByText('پایان نمایندگی')).toBeTruthy()
    expect(screen.getByText('۱۵۰٬۰۰۰ تومان از ۵۰۰٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByText('باز')).toBeTruthy()
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('say why the bot sells nothing, and what lifts it', async () => {
    const standing = agencyStanding({
      monthly_minimum: '500000.00',
      month: { name: 'مهر ۱۴۰۵', purchased: '100000.00', ends_at: '2026-10-22T20:30:00Z', judged: true },
      suspension: { reason: 'minimum', since: '2026-09-23T00:00:00Z' },
    })
    server().on('GET', '/api/agent/account', json(account({}, null, standing)))
    open()

    const notice = await screen.findByRole('alert')
    expect(notice.textContent).toContain('فروش ربات شما متوقف است: خرید حجم ماه قبل به کف خرید ماهانه نرسید.')
    expect(notice.textContent).toContain('با خرید حجم در ربات اصلی تا رسیدن خرید مهر ۱۴۰۵ به کف (۱۰۰٬۰۰۰ تومان از ۵۰۰٬۰۰۰ تومان)، فروش خودکار باز می‌شود.')
    expect(screen.getByText('متوقف: خرید حجم ماه قبل به کف خرید ماهانه نرسید')).toBeTruthy()
  })
})

describe('the wallet’s credit', () => {
  it.each([
    ['in use, some left to spend', '-20000.00', 'اعتبار خرید: ۱۰۰٬۰۰۰ تومان (در حال استفاده)'],
    ['used up, the debt as much as the credit', '-100000.00', 'اعتبار خرید: ۱۰۰٬۰۰۰ تومان (تمام شده)'],
    ['past, a debt beyond it by hand — never «in use»', '-280000.00', 'اعتبار خرید: ۱۰۰٬۰۰۰ تومان (تمام شده؛ بدهی از اعتبار گذشته است)'],
    ['untouched without a debt', '25000.00', 'اعتبار خرید: ۱۰۰٬۰۰۰ تومان'],
  ])('says whether any is left (%s)', async (_, balance, words) => {
    server().on('GET', '/api/agent/account', json(account({}, null, agencyStanding(), { credit_limit: '100000.00', balance })))
    open()

    expect(await screen.findByText(words)).toBeTruthy()
  })

  it('under half a Toman below zero is no debt', async () => {
    server().on('GET', '/api/agent/account', json(account({}, null, agencyStanding(), { credit_limit: '100000.00', balance: '-0.40' })))
    open()

    expect(await screen.findByText('اعتبار خرید: ۱۰۰٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.queryByText(/بدهی/)).toBeNull()
  })
})

describe('the other browsers signed in to the panel', () => {
  it('are signed out after confirmation while this one stays, and a refusal is shown in the dialog', async () => {
    vi.spyOn(toast, 'success')
    server().on('GET', '/api/agent/account', json(account()))
    let answer = refusal(422, 'این کار الان انجام نشد.')
    server().on('POST', '/api/agent/auth/sessions/end', () => answer)
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'خروج از مرورگرهای دیگر' }))
    const dialog = await screen.findByRole('dialog', { name: 'خروج از مرورگرهای دیگر' })
    expect(server().sent('POST', '/api/agent/auth/sessions/end')).toEqual([])

    fireEvent.click(within(dialog).getByRole('button', { name: 'خروج از بقیه' }))
    expect(await within(dialog).findByText('این کار الان انجام نشد.')).toBeTruthy()

    answer = noContent
    fireEvent.click(within(dialog).getByRole('button', { name: 'خروج از بقیه' }))
    await until(() => expect(toast.success).toHaveBeenCalledWith('از همه مرورگرهای دیگر خارج شدید'))
    expect(
      server()
        .sent('POST', '/api/agent/auth/sessions/end')
        .map((request) => request.body),
    ).toEqual([undefined, undefined])
  })
})

describe('renewing the agency', () => {
  const END = agencyStanding({ expires_at: '2027-01-10T20:00:00Z' })
  const PASSED = agencyStanding({ expires_at: '2026-09-23T10:00:00Z', suspension: { reason: 'expired', since: '2026-09-23T10:00:00Z' } })
  const renewal = (changes: Partial<NonNullable<AccountResponse['account']['renewal']>>): AccountResponse['account']['renewal'] => ({
    id: 3,
    status: 'pending',
    extended_to: null,
    reason: null,
    created_at: '2026-10-05T09:00:00Z',
    decided_at: null,
    ...changes,
  })

  it('is asked for beside the end, after a confirmation — the answer is the account, the request waiting and nothing more to ask', async () => {
    vi.spyOn(toast, 'success')
    server()
      .on('GET', '/api/agent/account', json(account({}, null, END, { actions: { request_renewal: true } })))
      .on('POST', '/api/agent/account/renewal', json(account({}, null, END, { renewal: renewal({}), actions: { request_renewal: false } }), 201))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'درخواست تمدید نمایندگی' }))
    const dialog = await screen.findByRole('dialog', { name: 'درخواست تمدید نمایندگی' })
    expect(server().sent('POST', '/api/agent/account/renewal')).toEqual([])
    fireEvent.click(within(dialog).getByRole('button', { name: 'فرستادن درخواست' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('درخواست تمدید نمایندگی فرستاده شد'))
    expect(
      server()
        .sent('POST', '/api/agent/account/renewal')
        .map((request) => request.body),
    ).toEqual([undefined])
    expect(await screen.findByText(/^درخواست تمدید شما در انتظار بررسی است؛ .+ فرستاده شد\.$/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'درخواست تمدید نمایندگی' })).toBeNull()
  })

  it('is not offered while the agent may not ask — no end, or one waiting', async () => {
    server().on('GET', '/api/agent/account', json(account()))
    open()

    expect(await screen.findByText('پایان نمایندگی')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'درخواست تمدید نمایندگی' })).toBeNull()
  })

  it('refused (one sent meanwhile from the main bot), says so in the dialog and reads the account again', async () => {
    const waiting = 'درخواست تمدید نمایندگی شما در انتظار بررسی است؛ نتیجه به شما اعلام می‌شود.'
    server()
      .on('GET', '/api/agent/account', json(account({}, null, END, { actions: { request_renewal: true } })))
      .on('POST', '/api/agent/account/renewal', refusal(422, waiting, { status: [waiting] }))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'درخواست تمدید نمایندگی' }))
    const dialog = await screen.findByRole('dialog', { name: 'درخواست تمدید نمایندگی' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'فرستادن درخواست' }))

    expect(await within(dialog).findByText(waiting)).toBeTruthy()
    await until(() => expect(server().sent('GET', '/api/agent/account')).toHaveLength(2))
  })

  // Said of the decision, in the past — what it made the end then —, never as the end now: support may have moved it since
  // (END is not the end that approval gave).
  it.each([
    ['approved with an end', renewal({ status: 'approved', extended_to: '2026-12-01T20:00:00Z', decided_at: '2026-10-06T09:00:00Z' }), /^درخواست تمدید شما .+ تایید شد و پایان نمایندگی .+ شد\.$/],
    ['approved with none', renewal({ status: 'approved', decided_at: '2026-10-06T09:00:00Z' }), /^درخواست تمدید شما .+ تایید شد و نمایندگی بدون پایان شد\.$/],
    ['rejected', renewal({ status: 'rejected', reason: 'خرید ماهانه کم بود', decided_at: '2026-10-06T09:00:00Z' }), /^درخواست تمدید شما .+ رد شد\. توضیح پشتیبانی: خرید ماهانه کم بود$/],
  ])('says the last decision (%s), beside the way to ask again', async (_, last, words) => {
    server().on('GET', '/api/agent/account', json(account({}, null, END, { renewal: last, actions: { request_renewal: true } })))
    open()

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'درخواست تمدید نمایندگی' })).toBeTruthy()
  })

  it('is where the selling notice of an end passed leads — or it says the request waits', async () => {
    server().on('GET', '/api/agent/account', json(account({}, null, PASSED, { actions: { request_renewal: true } })))
    open()

    expect((await screen.findByRole('alert')).textContent).toContain('برای تمدید، «درخواست تمدید نمایندگی» را در بخش «شرایط فروش» همین صفحه بزنید.')
    cleanup()

    server().on('GET', '/api/agent/account', json(account({}, null, PASSED, { renewal: renewal({}) })))
    open()

    const notice = await screen.findByRole('alert')
    expect(notice.textContent).toContain('درخواست تمدید شما در انتظار بررسی است.')
    expect(notice.textContent).not.toContain('پشتیبانی فروشگاه اصلی تماس')
  })
})
