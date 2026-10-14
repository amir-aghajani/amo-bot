import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import type { Session, SubscriptionRow } from '@/lib/api-types'
import { formatDate } from '@/lib/format'
import { AGENT_SHOP, OWNER, signedIn, until } from '@/test/render'
import { CLAIMED_AT, claimedServiceRow, subscriptionRow } from '@/test/rows'
import { json, noContent, refusal, server, type Answer, type SentRequest } from '@/test/server'

/*
 * A service's dialog (components/subscriptions/subscription-modal): a delete whose panel fails, then «حذف فقط از
 * فروشگاه» and its refusal; a service added by its link, deleted from the shop alone at the admin's choice, its client
 * kept; a dead link with nothing to copy; the focus after an operation; an hourly plan's terms; a free test's plan line,
 * and no auto-renewal; a claimed service's plan, picked when it is renewed, and when it was claimed; when an ended
 * service goes by itself, while the shop removes it; the device limit a renewal queued behind the period in use.
 */

const SERVICE = subscriptionRow()
const PANEL_DOWN = 'پنل «آلمان»: پاسخ نداد.'
const LEAVE_REFUSED = 'پنل سرور «آلمان» در دسترس است؛ کلاینت این سرویس فقط وقتی روی پنل می‌ماند که پنل جواب ندهد.'

const leaves = (request: SentRequest) => (request.body as { leave_panel?: boolean }).leave_panel === true

const props = () => ({ onClose: vi.fn(), onChanged: vi.fn(), onDeleted: vi.fn(), onStale: vi.fn(), onMove: vi.fn() })

/** The modal over `subscription`, in a panel signed in to the shop `session` shows. */
function show(subscription: SubscriptionRow = SERVICE, { gone = false, session = OWNER }: { gone?: boolean; session?: Session } = {}) {
  const handlers = props()
  render(<SubscriptionModal subscription={subscription} gone={gone} {...handlers} />, { wrapper: signedIn({ session }).wrapper })
  return handlers
}

/** The service's modal open, its delete answered by `withoutPanel` once the admin chooses to leave the panel out. */
function open(withoutPanel: Answer, session: Session = OWNER) {
  server().on('POST', `/api/admin/subscriptions/${SERVICE.id}/delete`, (request) => (leaves(request) ? withoutPanel : refusal(502, PANEL_DOWN, { panel: [PANEL_DOWN] })))
  return show(SERVICE, { session })
}

/** «حذف سرویس», then its strip's own «حذف سرویس»: the panel does not take it, and the modal asks. */
async function deleteOnPanel() {
  fireEvent.click(await screen.findByRole('button', { name: 'حذف سرویس' }))
  fireEvent.click(screen.getByRole('button', { name: 'حذف سرویس' }))
  await until(() => expect(screen.getByRole('button', { name: 'حذف فقط از فروشگاه' })).toBeTruthy())
  expect(screen.getByText(PANEL_DOWN)).toBeTruthy()
}

const deletes = () =>
  server()
    .sent('POST', `/api/admin/subscriptions/${SERVICE.id}/delete`)
    .map((request) => request.body)

beforeEach(() => {
  vi.spyOn(toast, 'warning')
  vi.spyOn(toast, 'success')
})

describe('a delete the panel did not take', () => {
  it('deletes the service in the shop alone when the admin chooses so, and says its client stayed on the panel for them to remove', async () => {
    const { onDeleted, onClose } = open(noContent)
    await deleteOnPanel()
    expect(screen.getByText(/تا خودتان حذفش نکنید کار می‌کند/)).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'حذف فقط از فروشگاه' }))

    await until(() => expect(onDeleted).toHaveBeenCalledWith(SERVICE))
    expect(onClose).toHaveBeenCalled()
    expect(toast.warning).toHaveBeenCalledWith('سرویس amir_1 حذف شد؛ کلاینتش روی سرور «آلمان» ماند و باید خودتان حذفش کنید')
    expect(deletes()).toEqual([{ note: '' }, { note: '', leave_panel: true }])
  })

  it('leaves the client to support in an agent’s shop — the agent reaches no panel', async () => {
    open(noContent, AGENT_SHOP)
    await deleteOnPanel()
    expect(screen.getByText(/تا پشتیبانی حذفش نکند کار می‌کند/)).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'حذف فقط از فروشگاه' }))

    await until(() => expect(toast.warning).toHaveBeenCalledWith('سرویس amir_1 حذف شد؛ کلاینتش روی سرور «آلمان» ماند و حذفش از سرور با پشتیبانی است'))
    expect(screen.queryByText(/خودتان/)).toBeNull()
  })

  it('goes back to the delete with the server’s message when the server refuses to leave the client on the panel', async () => {
    const { onDeleted, onStale } = open(refusal(422, LEAVE_REFUSED, { status: [LEAVE_REFUSED] }))
    await deleteOnPanel()

    fireEvent.click(screen.getByRole('button', { name: 'حذف فقط از فروشگاه' }))

    await until(() => expect(screen.getByText(LEAVE_REFUSED)).toBeTruthy())
    expect(screen.queryByRole('button', { name: 'حذف فقط از فروشگاه' })).toBeNull()
    expect(screen.getByRole('button', { name: 'حذف سرویس' })).toBeTruthy()
    expect(onDeleted).not.toHaveBeenCalled()
    expect(onStale).toHaveBeenCalled()
    // Said once, the focus on it: the button pressed is gone.
    expect(screen.getAllByText(LEAVE_REFUSED)).toHaveLength(1)
    expect(document.activeElement?.textContent).toContain(LEAVE_REFUSED)
  })

  it('stays where it is when the admin cancels the question', async () => {
    const { onDeleted } = open(noContent)
    await deleteOnPanel()

    fireEvent.click(screen.getByRole('button', { name: 'لغو حذف' }))

    expect(screen.queryByRole('button', { name: 'حذف فقط از فروشگاه' })).toBeNull()
    expect(screen.getByRole('button', { name: 'غیرفعال کردن' })).toBeTruthy()
    expect(deletes()).toEqual([{ note: '' }])
    expect(onDeleted).not.toHaveBeenCalled()
    // Back on the operation it was asked of.
    await until(() => expect(document.activeElement?.textContent).toBe('حذف سرویس'))
  })
})

/** A service a customer added by its link, a client made by hand on its panel: no plan until a renewal gives it one. */
const CLAIMED = claimedServiceRow()
const KEEP_CLIENT = 'حذف فقط از فروشگاه (کلاینت روی پنل می‌ماند)'

describe('a service added by its link', () => {
  it('may be deleted from the shop alone, its client kept on the panel for the customer it belongs to to send the link again', async () => {
    server().on('POST', `/api/admin/subscriptions/${SERVICE.id}/delete`, noContent)
    const { onDeleted, onClose } = show(CLAIMED)

    fireEvent.click(await screen.findByRole('button', { name: 'حذف سرویس' }))
    expect(screen.getByText(/صاحبش می‌تواند با فرستادن لینک دوباره اضافه‌اش کند/)).toBeTruthy()
    // A running one's customer hears of it, with the note, as from the delete itself.
    expect(screen.getByRole('button', { name: KEEP_CLIENT, description: /اگر سرویس فعال باشد، مشتری با توضیح شما از حذفش خبردار می‌شود/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'حذف سرویس' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: KEEP_CLIENT }))

    await until(() => expect(onDeleted).toHaveBeenCalledWith(CLAIMED))
    expect(deletes()).toEqual([{ note: '', leave_panel: true }])
    expect(onClose).toHaveBeenCalled()
    expect(toast.success).toHaveBeenCalledWith('سرویس amir_1 فقط از فروشگاه حذف شد؛ کلاینتش روی سرور «آلمان» ماند تا صاحبش با لینکش اضافه‌اش کند')
    expect(toast.warning).not.toHaveBeenCalled()
  })

  it('is deleted from its panel too by the delete itself', async () => {
    server().on('POST', `/api/admin/subscriptions/${SERVICE.id}/delete`, noContent)
    const { onDeleted } = show(CLAIMED)

    fireEvent.click(await screen.findByRole('button', { name: 'حذف سرویس' }))
    fireEvent.click(screen.getByRole('button', { name: 'حذف سرویس' }))

    await until(() => expect(onDeleted).toHaveBeenCalledWith(CLAIMED))
    expect(deletes()).toEqual([{ note: '' }])
  })

  it('is the only service whose delete offers to keep its client', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: 'حذف سرویس' }))

    expect(screen.getByRole('button', { name: 'برگشت' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: KEEP_CLIENT })).toBeNull()
    expect(screen.queryByText(/صاحبش می‌تواند/)).toBeNull()
  })

  it('offers no delete from the shop alone in an agent’s shop — leaving a client on a working panel is the main shop’s alone', async () => {
    show(CLAIMED, { session: AGENT_SHOP })

    fireEvent.click(await screen.findByRole('button', { name: 'حذف سرویس' }))

    expect(screen.getByRole('button', { name: 'برگشت' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: KEEP_CLIENT })).toBeNull()
    expect(screen.queryByText(/صاحبش می‌تواند/)).toBeNull()
  })
})

/** The modal as a list page holds it: the row an operation hands back is the one it shows. */
function Following({ first }: { first: SubscriptionRow }) {
  const [subscription, setSubscription] = useState(first)
  return <SubscriptionModal subscription={subscription} {...props()} onChanged={setSubscription} />
}

describe('an operation that takes its own button away', () => {
  it('leaves the focus on what the service offers now — never on the document', async () => {
    const gone = subscriptionRow({ status: 'deleted', actions: { sync: false, extend: false, enable: false, disable: false, move: false, transfer: false, delete: true } })
    server().on('POST', `/api/admin/subscriptions/${SERVICE.id}/sync`, json({ subscription: gone }))
    render(<Following first={SERVICE} />, { wrapper: signedIn().wrapper })
    const sync = await screen.findByRole('button', { name: 'به‌روزرسانی از پنل' })

    sync.focus()
    fireEvent.click(sync)

    await until(() => expect(screen.queryByRole('button', { name: 'به‌روزرسانی از پنل' })).toBeNull())
    expect(document.activeElement?.textContent).toBe('حذف سرویس')
  })
})

describe('a service whose link no longer works', () => {
  it('keeps the link for what it was, says it is dead, and offers nothing to copy — its client gone from the panel', async () => {
    show(subscriptionRow({ status: 'deleted' }))

    expect(await screen.findByText('https://sub.example.com/s/Zx81')).toBeTruthy()
    expect(screen.getByText('این لینک دیگر کار نمی‌کند.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'کپی' })).toBeNull()
  })

  it('— the service deleted from the shop meanwhile', async () => {
    show(SERVICE, { gone: true })

    expect(await screen.findByText('این لینک دیگر کار نمی‌کند.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'کپی' })).toBeNull()
  })

  it('is copied while it works', async () => {
    show()

    expect(await screen.findByRole('button', { name: 'کپی' })).toBeTruthy()
    expect(screen.queryByText('این لینک دیگر کار نمی‌کند.')).toBeNull()
  })
})

/** What a fact of the modal says, by its label. */
const fact = (label: string) => screen.getByText(label).nextElementSibling?.textContent

describe('its facts', () => {
  it('name the devices it may connect at once in one word, «هم‌زمان»', async () => {
    show()

    expect(await screen.findByText('کاربر هم‌زمان')).toBeTruthy()
    expect(fact('کاربر هم‌زمان')).toBe('۲ کاربر')
  })

  it('say from when the device limit a renewal queued behind the period in use holds — the devices bought last until then', async () => {
    const from = '2026-10-25T10:00:00Z'
    show(subscriptionRow({ ip_limit: 3, next_devices: { from, devices: 1 } }))

    expect(await screen.findByText('کاربر هم‌زمان')).toBeTruthy()
    expect(fact('کاربر هم‌زمان')).toBe(`۳ کاربراز ${formatDate(from, { dateStyle: 'medium' })}: ۱ کاربر`)
    // Its traffic counted at once: no next period beside it.
    expect(screen.queryByText('دوره بعدی')).toBeNull()
  })

  it('show an hourly plan’s term in hours, still waiting for the first connection', async () => {
    show(subscriptionRow({ duration_hours: 6, starts_at: null, expires_at: null }))

    expect(await screen.findByText('در انتظار اولین اتصال')).toBeTruthy()
    expect(screen.getByText('۶ ساعت از اولین اتصال')).toBeTruthy()
    expect(fact('مدت')).toBe('۶ ساعت')
  })

  it('say the hours left once less than a day is', async () => {
    show(subscriptionRow({ duration_hours: 6, expires_at: new Date(Date.now() + 3 * 3_600_000).toISOString() }))

    expect(await screen.findByText('۳ ساعت مانده')).toBeTruthy()
  })

  it('name a sold service’s plan, and whether it renews itself', async () => {
    show(subscriptionRow({ auto_renew: true }))

    expect(await screen.findByText('تمدید خودکار')).toBeTruthy()
    expect(fact('تمدید خودکار')).toBe('روشن')
    expect(fact('پلن')).toBe('طلایی')
  })

  it('say when an ended service goes by itself, and how long is left', async () => {
    const at = new Date(Date.now() + 10 * 3_600_000).toISOString()
    show(subscriptionRow({ status: 'expired', ended_at: new Date(Date.now() - 2 * 3_600_000).toISOString(), removes_at: at }))

    expect(await screen.findByText('حذف خودکار')).toBeTruthy()
    expect(fact('حذف خودکار')).toBe(`${formatDate(at)}۱۰ ساعت مانده`)
  })

  it('say a removal whose moment passed waits its turn', async () => {
    show(subscriptionRow({ status: 'expired', ended_at: new Date(Date.now() - 30 * 3_600_000).toISOString(), removes_at: new Date(Date.now() - 60_000).toISOString() }))
    expect(await screen.findByText('در نوبت حذف')).toBeTruthy()
  })

  it('say nothing of a removal while no rule removes the service', async () => {
    show(subscriptionRow({ status: 'expired', ended_at: new Date(Date.now() - 2 * 3_600_000).toISOString() }))

    expect(await screen.findByText('پایان')).toBeTruthy()
    expect(screen.queryByText('حذف خودکار')).toBeNull()
  })

  it('say when a service added by its link was claimed, and that the customer picks its plan when renewing — not that it was lost', async () => {
    show(CLAIMED)

    expect(await screen.findByText('افزودن')).toBeTruthy()
    expect(fact('افزودن')).toBe(`افزوده‌شده با لینک مشتری در ${formatDate(CLAIMED_AT)}`)
    // No «first renewal»: a renewed one whose plan was deleted since says the same.
    expect(fact('پلن')).toBe('بدون پلنمشتری هنگام تمدید یکی از پلن‌هایی را که روی سرور این سرویس فروخته می‌شوند انتخاب می‌کند.')
    expect(screen.queryByText('پلن حذف شده')).toBeNull()
    expect(screen.queryByText('خرید')).toBeNull()
  })

  it('say when a sold one was bought', async () => {
    show()

    expect(await screen.findByText('خرید')).toBeTruthy()
    expect(screen.queryByText('افزودن')).toBeNull()
  })

  it('say a service its customer built is one, as the bot does — no plan was deleted from under it', async () => {
    show(subscriptionRow({ plan: null }))

    expect(await screen.findByText('خرید')).toBeTruthy()
    expect(fact('پلن')).toBe('سرویس دلخواه')
    expect(screen.queryByText('پلن حذف شده')).toBeNull()
  })

  it('say a free test is one, with no plan and no auto-renewal — a test is never renewed', async () => {
    show(subscriptionRow({ is_test: true, plan: null, duration_hours: 24 }))

    expect(await screen.findByText('اکانت تست رایگان')).toBeTruthy()
    expect(fact('پلن')).toBe('اکانت تست رایگان')
    expect(screen.queryByText('پلن حذف شده')).toBeNull()
    expect(screen.queryByText('تمدید خودکار')).toBeNull()
  })
})
