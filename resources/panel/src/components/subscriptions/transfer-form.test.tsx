import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import { userLabel } from '@/components/user-identity'
import type { SubscriptionRow, SubscriptionTransferRecipientResponse, UserRef } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { subscriptionRow } from '@/test/rows'
import { json, refusal, server, type Answer, type SentRequest } from '@/test/server'

/*
 * «انتقال به مشتری دیگر» in a service's dialog (components/subscriptions/transfer-form): the new customer's Telegram id,
 * and the customer of the shop it names shown as it is typed — the server's own lookup, asked once typing pauses —
 * before anything is sent; an id the server refuses (nobody of the shop, the service's own customer…) sends nothing, its
 * words under the id; a refusal of the service's state beside it; what it sends, the dialog following the service with
 * its new customer; a refusal of the transfer under the id; no such operation for a service that may not go.
 */

const SERVICE = subscriptionRow()
const URL = `/api/admin/subscriptions/${SERVICE.id}/transfer`
const SARA: UserRef = { id: 20, name: 'سارا احمدی', username: 'sara', telegram_id: 7070, email: null }
const UNKNOWN = 'مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست؛ صاحب این شناسه باید یک بار ربات را استارت کند.'
const SAME = 'این سرویس همین حالا مال همین حساب است.'

/** The server's lookup answers each id by `answers` (a recipient, or a refusal), the ids it was asked kept. */
function lookup(answers: Record<string, UserRef | Answer>) {
  const asked: string[] = []
  server().on('GET', URL, (request: SentRequest) => {
    const id = request.query.get('telegram_id') ?? ''
    asked.push(id)
    const answer = answers[id] ?? refusal(422, UNKNOWN, { telegram_id: [UNKNOWN] })
    return 'kind' in answer ? answer : json({ recipient: answer } satisfies SubscriptionTransferRecipientResponse)
  })
  return asked
}

/** The service's dialog, its «انتقال به مشتری دیگر» pressed: the form on its strip. */
async function transfer(subscription: SubscriptionRow = SERVICE) {
  const props = { onClose: vi.fn(), onChanged: vi.fn(), onDeleted: vi.fn(), onStale: vi.fn(), onMove: vi.fn() }
  render(<SubscriptionModal subscription={subscription} {...props} />, { wrapper: signedIn().wrapper })
  fireEvent.click(await screen.findByRole('button', { name: 'انتقال به مشتری دیگر' }))
  await screen.findByLabelText('شناسه عددی تلگرام مشتری جدید')
  return props
}

const idField = () => screen.getByLabelText('شناسه عددی تلگرام مشتری جدید')
const type = (id: string) => fireEvent.change(idField(), { target: { value: id } })

/** The strip's own submit (the operation's button is gone while its strip is open). */
const submit = () => screen.getByRole('button', { name: 'انتقال به مشتری دیگر' })

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('handing a service to another customer', () => {
  it('shows who the server says the id names, sends it once confirmed, and the dialog follows the service with its new customer', async () => {
    const asked = lookup({ '7070': SARA })
    const fresh = subscriptionRow({ user: SARA, auto_renew: false })
    server().on('POST', URL, json({ subscription: fresh }))
    const { onChanged } = await transfer()
    expect(screen.getByText(/زمان و حجم به حساب مشتری دیگری می‌رود/)).toBeTruthy()

    type('۷')
    type('۷۰۷۰')

    expect(await screen.findByText('سارا احمدی')).toBeTruthy()
    expect(screen.getByText(`سرویس به ${userLabel(SARA)} منتقل می‌شود.`)).toBeTruthy()
    expect(asked).toEqual(['7070'])
    fireEvent.click(submit())

    await until(() => expect(onChanged).toHaveBeenCalledWith(fresh))
    expect(
      server()
        .sent('POST', URL)
        .map((request) => request.body),
    ).toEqual([{ telegram_id: '7070' }])
    expect(toast.success).toHaveBeenCalledWith(`سرویس amir_1 به ${userLabel(SARA)} منتقل شد`)
  })

  it('sends nothing for an id the server refuses, its words under the id — the id typed next judged again', async () => {
    lookup({ [String(SERVICE.user.telegram_id)]: refusal(422, SAME, { telegram_id: [SAME] }) })
    await transfer()

    type('8080')

    expect(await screen.findByText(UNKNOWN)).toBeTruthy()
    expect(idField().getAttribute('aria-invalid')).toBe('true')
    expect(screen.queryByRole('status')).toBeNull()
    expect(submit().getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(submit())
    expect(server().sent('POST', URL)).toHaveLength(0)

    type(String(SERVICE.user.telegram_id))

    expect(await screen.findByText(SAME)).toBeTruthy()
    expect(screen.queryByText(UNKNOWN)).toBeNull()
    expect(submit().getAttribute('aria-disabled')).toBe('true')
  })

  it('says beside the id why the service itself may not go', async () => {
    const gone = 'کلاینت این سرویس دیگر روی سرور نیست؛ این سرویس منتقل نمی‌شود.'
    lookup({ '7070': refusal(422, gone, { status: [gone] }) })
    await transfer()

    type('7070')

    expect(await screen.findByText(gone)).toBeTruthy()
    expect(screen.getByRole('status').textContent).toBe(gone)
    expect(idField().getAttribute('aria-invalid')).toBe('false')
    expect(submit().getAttribute('aria-disabled')).toBe('true')
  })

  it('shows a refusal of the transfer under the id', async () => {
    lookup({ '7070': SARA })
    const banned = 'این حساب مسدود است و سرویسی به آن منتقل نمی‌شود.'
    server().on('POST', URL, refusal(422, banned, { telegram_id: [banned] }))
    const { onChanged } = await transfer()

    type('7070')
    await screen.findByText('سارا احمدی')
    fireEvent.click(submit())

    expect(await screen.findByText(banned)).toBeTruthy()
    expect(onChanged).not.toHaveBeenCalled()
  })

  it('is no operation of a service that may not go', async () => {
    const test = subscriptionRow({ is_test: true, plan: null, actions: { ...SERVICE.actions, transfer: false } })
    render(<SubscriptionModal subscription={test} onClose={vi.fn()} onChanged={vi.fn()} onDeleted={vi.fn()} onStale={vi.fn()} onMove={vi.fn()} />, { wrapper: signedIn().wrapper })

    expect(await screen.findByRole('button', { name: 'حذف سرویس' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'انتقال به مشتری دیگر' })).toBeNull()
  })
})
