import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { MoveDialog } from '@/components/subscriptions/move-dialog'
import type { ServerChoices } from '@/components/subscriptions/server-choices'
import type { NamedRef, Session, SubscriptionRow } from '@/lib/api-types'
import { AGENT_SHOP, OWNER, signedIn, until } from '@/test/render'
import { subscriptionRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The move dialog (components/subscriptions/move-dialog) around its batch (use-move-batch, tested on its own): a batch
 * a refusal stopped says why once — on the service's own line — and what it meant for the rest; the focus is never
 * left on the dialog itself: the way out has it while the batch runs and once it is over, the prompt while it asks; and
 * a client left on the previous server is the owner's to remove — in an agent's shop, support's.
 */

const GERMANY: NamedRef = { id: 1, name: 'آلمان' }
const NETHERLANDS: NamedRef = { id: 3, name: 'هلند' }

const servers: ServerChoices = () =>
  Promise.resolve([
    { id: GERMANY.id, name: GERMANY.name, unsellable_reason: null, active: null },
    { id: NETHERLANDS.id, name: NETHERLANDS.name, unsellable_reason: null, active: null },
  ])

const service = (id: number) => subscriptionRow({ id, name: `amir_${id}`, server: GERMANY })

const arrived = (moving: SubscriptionRow) => json({ subscription: { ...moving, server: NETHERLANDS } })

/** The dialog over `services` in the shop `session` shows, the target picked and the batch started. */
async function move(services: SubscriptionRow[], session: Session = OWNER) {
  render(<MoveDialog subscriptions={services} servers={servers} onClose={vi.fn()} onMoved={vi.fn()} />, { wrapper: signedIn({ session }).wrapper })
  fireEvent.keyDown(await screen.findByRole('combobox', { name: 'سرور مقصد' }), { key: 'Enter' })
  fireEvent.click(await screen.findByRole('option', { name: /هلند/ }))
  fireEvent.click(screen.getByRole('button', { name: /^انتقال .* سرویس$/ }))
}

const focused = () => document.activeElement?.textContent

describe('a batch a refusal stopped', () => {
  it('says why once, on the service’s line, and what it meant for the rest', async () => {
    const full = '«هلند»: ظرفیت سرور پر است.'
    server().on('POST', '/api/admin/subscriptions/1/move', refusal(422, full, { server_id: [full] }))
    await move([service(1), service(2)])

    expect(await screen.findByText(/سرویس‌های بعدی هم به همین دلیل منتقل نمی‌شدند/)).toBeTruthy()
    expect(screen.getAllByText(full)).toHaveLength(1)
    expect(focused()).toBe('بستن')
  })
})

describe('the focus', () => {
  it('is on the way out while the batch runs, and stays on it once the batch is over', async () => {
    const first = server().hold('POST', '/api/admin/subscriptions/1/move')
    await move([service(1)])

    await until(() => expect(first.waiting).toBe(1))
    expect(focused()).toBe('توقف بعد از این سرویس')

    await act(async () => first.answer(arrived(service(1))))

    await until(() => expect(focused()).toBe('بستن'))
  })

  it('goes to the prompt while it asks, and back to the way out once it is answered', async () => {
    const down = 'پنل «آلمان»: پاسخ نداد.'
    server().on('POST', '/api/admin/subscriptions/1/move', refusal(502, down, { previous: [down] }))
    await move([service(1), service(2)])

    await until(() => expect(focused()).toBe('لغو انتقال'))
    fireEvent.click(screen.getByRole('button', { name: 'لغو انتقال' }))

    await until(() => expect(focused()).toBe('بستن'))
  })
})

describe('a previous server that does not answer', () => {
  const down = 'پنل «آلمان»: پاسخ نداد.'

  it('leaves its client for the owner to remove, in the main bot’s shop', async () => {
    server().on('POST', '/api/admin/subscriptions/1/move', refusal(502, down, { previous: [down] }))
    await move([service(1)])

    expect(await screen.findByText(/کلاینت قبلی روی آن سرور می‌ماند و بعدا باید خودتان حذفش کنید/)).toBeTruthy()
  })

  it('leaves it to support in an agent’s shop — the agent reaches no panel', async () => {
    server().on('POST', '/api/admin/subscriptions/1/move', refusal(502, down, { previous: [down] }))
    await move([service(1), service(2)], AGENT_SHOP)

    expect(await screen.findByText(/کلاینت‌های قبلی روی آن سرور می‌مانند و حذفشان از سرور با پشتیبانی است/)).toBeTruthy()
    expect(screen.queryByText(/خودتان/)).toBeNull()
  })
})
