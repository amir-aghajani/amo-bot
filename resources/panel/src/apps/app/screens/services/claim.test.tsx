import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import ClaimScreen from '@/apps/app/screens/services/claim'
import * as app from '@/apps/app/test/app'
import { API, service } from '@/apps/app/test/rows'
import { until } from '@/test/render'
import { json, refusal, server, type Answer } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «افزودن سرویس با لینک» (screens/services/claim): the text pasted sent as it is, the service added and its page opened;
 * a refusal in the shop's words under the field; off while the shop takes none; the shop that could not be read said so,
 * to read again.
 */

function open(shop = app.shop()) {
  render(app.screen(<ClaimScreen />, { at: '/services/claim', shop }))
}

describe('adding a service by its link', () => {
  it('sends the text pasted and opens the service added', async () => {
    const added = service({ id: 12, plan: null, claimed_at: '2026-10-27T10:00:00+00:00' })
    server().on('POST', `${API}/subscriptions/claim`, json({ subscription: added }, 201))
    open()

    fireEvent.change(screen.getByLabelText('لینک یا کانفیگ سرویس'), { target: { value: ' سرویسم این است: https://sub.example.net/sub/5b7d2c9e1f04 ' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن سرویس' }))

    await until(() => expect(queryClient.getQueryData(keys.subscription(12))).toEqual({ subscription: added }))
    expect(server().sent('POST', `${API}/subscriptions/claim`)[0]?.body).toEqual({ link: 'سرویسم این است: https://sub.example.net/sub/5b7d2c9e1f04' })
    await until(() => expect(screen.queryByLabelText('لینک یا کانفیگ سرویس')).toBeNull())
  })

  it('has every list of the services read again: home’s and a ticket’s picker too', async () => {
    const added = service({ id: 12, plan: null, claimed_at: '2026-10-27T10:00:00+00:00' })
    server().on('POST', `${API}/subscriptions/claim`, json({ subscription: added }, 201))
    open()
    const lists = [
      [...keys.subscriptions, 'home', 'any'],
      [...keys.subscriptions, 'support-picker'],
      [...keys.subscriptions, 'pages', 'all'],
    ]
    for (const list of lists) queryClient.setQueryData(list, { subscriptions: [] })

    fireEvent.change(screen.getByLabelText('لینک یا کانفیگ سرویس'), { target: { value: 'https://sub.example.net/sub/5b7d2c9e1f04' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن سرویس' }))

    await until(() => expect(lists.map((list) => queryClient.getQueryState(list)?.isInvalidated)).toEqual([true, true, true]))
    expect(queryClient.getQueryState(keys.subscription(12))?.isInvalidated).toBe(false)
  })

  it('says a refusal under the field', async () => {
    const words = 'این سرویس در حساب مشتری دیگری است؛ اگر سرویس شماست با پشتیبانی تماس بگیرید.'
    server().on('POST', `${API}/subscriptions/claim`, refusal(409, words, { link: [words] }))
    open()

    fireEvent.change(screen.getByLabelText('لینک یا کانفیگ سرویس'), { target: { value: 'vless://abc@host:443' } })
    fireEvent.click(screen.getByRole('button', { name: 'افزودن سرویس' }))

    expect((await screen.findByRole('alert')).textContent).toBe(words)
  })

  it('is not offered while the shop takes none', () => {
    open(app.shop({ shop: { ...app.shop().shop, service_claims: false } }))

    expect(screen.getByText('افزودن سرویس با لینک فعلا فعال نیست')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'افزودن سرویس' })).toBeNull()
  })

  it('says the shop could not be read, and reads it again', async () => {
    const answers: Answer[] = [refusal(500, 'خطای داخلی فروشگاه.'), json(app.shop())]
    server().on('GET', `${API}`, () => answers.shift() ?? json(app.shop()))
    render(app.screen(<ClaimScreen />, { at: '/services/claim', shop: null }))

    expect((await screen.findByRole('alert')).textContent).toContain('خطای داخلی فروشگاه.')
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    expect(await screen.findByLabelText('لینک یا کانفیگ سرویس')).toBeTruthy()
  })
})
