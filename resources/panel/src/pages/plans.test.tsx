import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { PlansResponse } from '@/lib/api-types'
import { PlansPage } from '@/pages/plans'
import { providers, until } from '@/test/render'
import { planRow } from '@/test/rows'
import { json, noContent, refusal, server } from '@/test/server'

/*
 * The plans list (pages/plans) flags a plan the bot does not show — by the server's judgement, the one the bot's shop
 * makes (`unsellable_reason`) —, the reason behind a press of the flag; a plan the bot shows carries none. A plan with
 * history — an order or a service — is not offered a delete the server would refuse, and the header counts the plans
 * only once the list was read.
 */

const NO_SERVER = 'این پلن روی هیچ سروری نیست؛ از فرم پلن یک سرور به آن اضافه کنید.'

/** A plan nothing was ever sold on: it may go. */
const UNUSED = { active_subscriptions: 0, sales: 0, orders: 0, subscriptions: 0 }

function showPlans(plans: PlansResponse['plans']) {
  server().on('GET', '/api/admin/plans', json({ plans } satisfies PlansResponse))
  render(<PlansPage />, { wrapper: providers().wrapper })
}

const openDialog = () => within(document.querySelector<HTMLElement>('dialog[open]') ?? document.body)

async function pressDelete(name: string) {
  fireEvent.pointerDown(await screen.findByRole('button', { name: `عملیات ${name}` }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))
}

describe('the plans list', () => {
  it('flags a plan the bot does not show, saying why when pressed', async () => {
    showPlans([planRow({ id: 1, name: 'یک‌ماهه', servers: [], unsellable_reason: NO_SERVER }), planRow()])

    expect(await screen.findAllByRole('button', { name: 'در ربات دیده نمی‌شود' })).toHaveLength(1)

    fireEvent.click(screen.getByRole('button', { name: 'در ربات دیده نمی‌شود' }))
    expect(await screen.findByText(NO_SERVER)).toBeTruthy()
  })

  it('offers no delete for a plan orders or services were made with: it stays, switched off if need be', async () => {
    showPlans([planRow({ counts: { active_subscriptions: 0, sales: 0, orders: 2, subscriptions: 0 } })])

    await pressDelete('طلایی')

    expect(await openDialog().findByText(/با «طلایی» ۲ سفارش ثبت شده و برای حفظ تاریخچه حذف نمی‌شود/)).toBeTruthy()
    expect(openDialog().queryByRole('button', { name: 'حذف' })).toBeNull()
  })

  it('names every kind of history the plan has', async () => {
    showPlans([planRow()])

    await pressDelete('طلایی')

    expect(await openDialog().findByText(/۷ سفارش و ۵ اشتراک ثبت شده/)).toBeTruthy()
  })

  it('asks before deleting a plan nothing was sold on, and deletes it', async () => {
    showPlans([planRow({ counts: UNUSED })])
    server().on('DELETE', '/api/admin/plans/3', noContent)

    await pressDelete('طلایی')
    fireEvent.click(await openDialog().findByRole('button', { name: 'حذف' }))

    await until(() => expect(server().sent('DELETE', '/api/admin/plans/3')).toHaveLength(1))
    await until(() => expect(screen.queryByRole('button', { name: 'طلایی' })).toBeNull())
  })

  it('counts the plans only once the list was read: a failed read knows no number', async () => {
    server().on('GET', '/api/admin/plans', refusal(500, 'خطای سرور'))
    render(<PlansPage />, { wrapper: providers().wrapper })

    expect(await screen.findByRole('button', { name: 'تلاش دوباره' })).toBeTruthy()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('پلن‌ها')
  })

  it('counts the plans it read', async () => {
    showPlans([planRow()])

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('پلن‌ها۱'))
  })
})
