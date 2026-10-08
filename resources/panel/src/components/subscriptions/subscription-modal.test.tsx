import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import type { Session, SubscriptionRow } from '@/lib/api-types'
import { AGENT_SHOP, OWNER, signedIn, until } from '@/test/render'
import { subscriptionRow } from '@/test/rows'
import { json, noContent, refusal, server, type Answer, type SentRequest } from '@/test/server'

/*
 * A service's modal (components/subscriptions/subscription-modal). Deleting a service whose panel does not take the
 * delete: the modal asks whether to delete it in the shop alone, its client left on the panel — the owner's to remove,
 * in an agent's shop support's; the server may refuse that too (an agent's shop while that panel answers), and the modal
 * goes back to the delete, saying why — never a button that silently does nothing. A link that no longer works is said
 * dead, with nothing to copy. An operation that took its own button away leaves the focus on what the service offers now.
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

  it('goes back to the delete in the server’s words when the server will not leave the client on the panel', async () => {
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

/** The modal as a list page holds it: the row an operation hands back is the one it shows. */
function Following({ first }: { first: SubscriptionRow }) {
  const [subscription, setSubscription] = useState(first)
  return <SubscriptionModal subscription={subscription} {...props()} onChanged={setSubscription} />
}

describe('an operation that takes its own button away', () => {
  it('leaves the focus on what the service offers now — never on the document', async () => {
    const gone = subscriptionRow({ status: 'deleted', actions: { sync: false, extend: false, enable: false, disable: false, move: false, delete: true } })
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

describe('its facts', () => {
  it('name the devices it may connect at once in one word, «هم‌زمان»', async () => {
    show()

    expect(await screen.findByText('دستگاه هم‌زمان')).toBeTruthy()
  })
})
