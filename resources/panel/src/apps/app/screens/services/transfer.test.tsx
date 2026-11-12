import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import TransferScreen from '@/apps/app/screens/services/transfer'
import * as app from '@/apps/app/test/app'
import { API, service } from '@/apps/app/test/rows'
import { until } from '@/test/render'
import { json, refusal, server, withHeaders } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «انتقال سرویس» (screens/services/transfer): the recipient looked up by the Telegram id typed (Persian digits too) and
 * shown to confirm, then the service handed over — asked for a recent sign-in first, which the launch data proves —, and
 * the customer back at their services; a refusal of the id under its field, gone once the id is changed; off while the
 * shop offers none.
 */

const SERVICE = `${API}/subscriptions/9`
const SARA = { recipient: { name: 'Sara', username: 'sara' } }

function open(shop = app.shop()) {
  server()
    .on('GET', SERVICE, json({ subscription: service() }))
    .on('GET', `${API}/me`, json(app.account()))
  render(app.screen(<TransferScreen />, { at: '/services/9/transfer', route: '/services/:id/transfer', shop }))
}

describe('handing a service on', () => {
  it('shows the recipient to confirm, then hands it over once the launch data proves a recent sign-in', async () => {
    const { app: telegram, mainButton } = app.telegram()
    let handed = 0
    server()
      .on('GET', `${SERVICE}/transfer`, json(SARA))
      .on('POST', `${SERVICE}/transfer`, () =>
        ++handed === 1 ? withHeaders(refusal(403, 'برای این کار دوباره وارد شوید.'), { 'WWW-Authenticate': 'Bearer error="insufficient_user_authentication", max_age=900' }) : json(SARA),
      )
      .on('POST', `${API}/me/reauthenticate`, json({ expires_in: 900 }))
    open()

    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '۷۰۷۰' } })
    mainButton.press()

    await screen.findByText('Sara')
    expect(screen.getByText('@sara')).toBeTruthy()
    expect(server().sent('GET', `${SERVICE}/transfer`)[0]?.query.get('telegram_id')).toBe('7070')
    await until(() => expect(mainButton.params.text).toBe('تایید انتقال'))
    mainButton.press()

    await until(() => expect(server().sent('POST', `${SERVICE}/transfer`)).toHaveLength(2))
    expect(server().sent('POST', `${API}/me/reauthenticate`)[0]?.body).toEqual({ method: 'mini_app', init_data: telegram.initData })
    expect(
      server()
        .sent('POST', `${SERVICE}/transfer`)
        .map((request) => request.body),
    ).toEqual([{ telegram_id: '7070' }, { telegram_id: '7070' }])
    await until(() => expect(screen.queryByText('Sara')).toBeNull())
  })

  it('in a panel’s preview, says the shop’s refusal as it is: no recent sign-in is asked, nor tried', async () => {
    const { mainButton } = app.preview()
    server()
      .on('GET', `${SERVICE}/transfer`, json(SARA))
      .on('POST', `${SERVICE}/transfer`, withHeaders(refusal(403, 'پیش‌نمایش فقط برای دیدن است.'), { 'WWW-Authenticate': 'Bearer error="insufficient_user_authentication"' }))
    open()

    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '7070' } })
    mainButton.press()
    await screen.findByText('Sara')
    await until(() => expect(mainButton.params.text).toBe('تایید انتقال'))
    mainButton.press()

    expect(await screen.findByText('پیش‌نمایش فقط برای دیدن است.')).toBeTruthy()
    expect(server().sent('POST', `${SERVICE}/transfer`)).toHaveLength(1)
    expect(server().sent('POST', `${API}/me/reauthenticate`)).toHaveLength(0)
  })

  it('a refused id is said under its field', async () => {
    server().on('GET', `${SERVICE}/transfer`, refusal(422, 'مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست.', { telegram_id: ['مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست.'] }))
    open()

    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '123' } })
    fireEvent.click(screen.getByRole('button', { name: 'ادامه' }))

    expect((await screen.findByRole('alert')).textContent).toBe('مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست.')
    fireEvent.change(screen.getByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '1234' } })
    expect(screen.queryByRole('alert')).toBeNull()
    expect(screen.queryByText('مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست.')).toBeNull()
  })

  it('keeps a long Telegram name in the recipient card, off the main button', async () => {
    const { mainButton } = app.telegram()
    const name = 'ن'.repeat(128)
    server().on('GET', `${SERVICE}/transfer`, json({ recipient: { name, username: null } }))
    open()

    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '7070' } })
    mainButton.press()

    expect(await screen.findByText(name)).toBeTruthy()
    await until(() => expect(mainButton.params.text).toBe('تایید انتقال'))
  })

  it('is not offered while the shop offers none', async () => {
    open(app.shop({ shop: { ...app.shop().shop, service_transfers: false } }))

    await screen.findByText('انتقال سرویس در این فروشگاه فعال نیست')
  })
  it('keeps the id typed when the service read again fails', async () => {
    open()
    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '7070' } })

    server().on('GET', SERVICE, refusal(500, 'خطای سرور'))
    await queryClient.refetchQueries({ queryKey: keys.subscription(9), exact: true }).catch(() => undefined)

    expect((screen.getByLabelText('شناسه عددی تلگرام گیرنده') as HTMLInputElement).value).toBe('7070')
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })
})
