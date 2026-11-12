import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import ServiceScreen from '@/apps/app/screens/services/detail'
import * as app from '@/apps/app/test/app'
import { API, service } from '@/apps/app/test/rows'
import { Toaster } from '@/apps/app/ui/toaster'
import type { StoreSubscription } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server, withHeaders } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * A service's page (screens/services/detail): its rings, read from its server as it opens (whether it is connected),
 * its link to copy with the shop's QR card and the apps to add it to; each action only while the service and the shop
 * allow it — renaming it (`PATCH label`), its «تمدید خودکار» (`PATCH auto_renew`), a new link after the customer
 * confirms, a read now that may be refused for a while, the renewal on Telegram's main button. A panel's preview shows
 * its last copy, asking its server nothing by itself.
 */

const SERVICE = `${API}/subscriptions/9`

/** The page of `subscription`, its server read as it opens (connected now), its QR card drawn. */
function open(subscription: StoreSubscription = service(), shop = app.shop()) {
  server()
    .on('GET', SERVICE, json({ subscription }))
    .on('POST', `${SERVICE}/refresh`, json({ subscription: { ...subscription, presence: { online: true, last_online_at: null } } }))
    .on('GET', `${SERVICE}/qr`, { kind: 'response', status: 200, body: 'JPEG', type: 'image/jpeg' })
  render(app.screen(<ServiceScreen />, { at: '/services/9', route: '/services/:id', shop }))
}

describe('a service’s page', () => {
  it('draws its rings, reads it now (connected), and shows its link to copy and its QR card large', async () => {
    open(service({ label: 'سرویس خانه' }))

    await screen.findByRole('heading', { name: 'سرویس خانه' })
    expect(screen.getByText('ali_1')).toBeTruthy()
    expect(screen.getByLabelText('حجم: ۶۰ گیگابایت مانده')).toBeTruthy()
    expect(screen.getByLabelText('زمان: ۱۰ روز مانده')).toBeTruthy()
    await screen.findByText('آنلاین')
    expect(screen.getByRole('button', { name: /https:\/\/sub\.example\.net\/sub\/3d9e6b1f0a72/ })).toBeTruthy()

    fireEvent.click(await screen.findByRole('button', { name: 'نمایش بزرگ QR' }))
    expect(screen.getByRole('dialog', { name: undefined }).querySelector('img')?.getAttribute('src')).toMatch(/^blob:/)
  })

  it('hands its link to the apps by their own addresses', async () => {
    open()

    const link = 'https://sub.example.net/sub/3d9e6b1f0a72'
    await screen.findByRole('link', { name: 'افزودن به Happ' })
    expect(screen.getByRole('link', { name: 'افزودن به Happ' }).getAttribute('href')).toBe(`happ://add/${link}`)
    expect(screen.getByRole('link', { name: 'افزودن به v2rayNG' }).getAttribute('href')).toBe(`v2rayng://install-sub?url=${encodeURIComponent(link)}#ali_1`)
    expect(screen.getByRole('link', { name: 'افزودن به Hiddify' }).getAttribute('href')).toBe(`hiddify://import/${link}#ali_1`)
    expect(screen.getByRole('link', { name: 'افزودن به Streisand' }).getAttribute('href')).toBe(`streisand://import/${link}#ali_1`)

    // v2rayN has no such address: its link to copy, and the steps.
    fireEvent.click(screen.getByRole('button', { name: /v2rayN/ }))
    expect(await screen.findByText(/تنظیمات گروه اشتراک/)).toBeTruthy()
  })

  it('inside Telegram, opens an app’s bridge page — which says itself when the app did not open', async () => {
    const { app: client } = app.telegram()
    open()
    await screen.findByText('آنلاین')

    vi.useFakeTimers()
    fireEvent.click(screen.getByRole('link', { name: 'افزودن به Happ' }))
    act(() => vi.advanceTimersByTime(3000))

    expect(client.openLink).toHaveBeenCalledWith(expect.stringContaining('/open.html#'))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(screen.queryByText(/باز نشد/)).toBeNull()
  })

  it('offers what the service allows, the renewal on Telegram’s main button', async () => {
    const { mainButton } = app.telegram()
    open()

    await screen.findByText('آنلاین')
    expect(screen.getByRole('switch', { name: /تمدید خودکار/ }).getAttribute('aria-checked')).toBe('false')
    expect(screen.getByRole('button', { name: /تغییر لینک/ })).toBeTruthy()
    expect(screen.getByRole('link', { name: /خرید حجم، زمان یا کاربر اضافه/ }).getAttribute('href')).toBe('/services/9/extras')
    expect(screen.getByRole('link', { name: /انتقال سرویس/ }).getAttribute('href')).toBe('/services/9/transfer')
    expect(screen.getByRole('link', { name: /پشتیبانی این سرویس/ }).getAttribute('href')).toBe('/support/new?subscription=9')
    expect(mainButton.isVisible).toBe(true)
    expect(mainButton.params.text).toBe('تمدید سرویس')
  })

  it('hides what it does not allow', async () => {
    const { mainButton } = app.telegram()
    open(
      service({
        is_test: true,
        renewable: false,
        picks_plan: false,
        auto_renew: { on: false, offered: false, days_before: 2 },
        link_rotation: false,
        extras: { traffic: false, time: false, devices: false },
      }),
    )

    await screen.findByText('آنلاین')
    expect(screen.queryByRole('switch')).toBeNull()
    expect(screen.queryByRole('button', { name: /تغییر لینک/ })).toBeNull()
    expect(screen.queryByRole('link', { name: /اضافه/ })).toBeNull()
    expect(screen.queryByRole('link', { name: /انتقال سرویس/ })).toBeNull()
    expect(mainButton.isVisible).toBe(false)
  })

  it('names the service: the label typed is PATCHed, and the page shows it', async () => {
    open()
    server().on('PATCH', SERVICE, json({ subscription: service({ label: 'سرویس خانه' }) }))

    fireEvent.click(await screen.findByRole('button', { name: /نام دلخواه/ }))
    fireEvent.change(screen.getByLabelText('نام'), { target: { value: '  سرویس خانه ' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await screen.findByRole('heading', { name: 'سرویس خانه' })
    expect(
      server()
        .sent('PATCH', SERVICE)
        .map((request) => request.body),
    ).toEqual([{ label: 'سرویس خانه' }])
  })

  it('drops its name: the label PATCHed as null, the server’s name shown again', async () => {
    open(service({ label: 'سرویس خانه' }))
    server().on('PATCH', SERVICE, json({ subscription: service() }))

    fireEvent.click(await screen.findByRole('button', { name: /نام دلخواه/ }))
    fireEvent.click(screen.getByRole('button', { name: 'حذف نام دلخواه' }))

    await screen.findByRole('heading', { name: 'ali_1' })
    expect(server().sent('PATCH', SERVICE)[0]?.body).toEqual({ label: null })
  })

  it('opens the name sheet afresh after the name was dropped', async () => {
    open(service({ label: 'سرویس خانه' }))
    server().on('PATCH', SERVICE, json({ subscription: service() }))

    fireEvent.click(await screen.findByRole('button', { name: /نام دلخواه/ }))
    fireEvent.click(screen.getByRole('button', { name: 'حذف نام دلخواه' }))
    await screen.findByRole('heading', { name: 'ali_1' })

    fireEvent.click(screen.getByRole('button', { name: /نام دلخواه/ }))
    expect((screen.getByLabelText('نام') as HTMLInputElement).value).toBe('')
    expect(screen.queryByRole('button', { name: 'حذف نام دلخواه' })).toBeNull()
  })

  it('sends one change of the name at a time', async () => {
    open(service({ label: 'سرویس خانه' }))
    const patch = server().hold('PATCH', SERVICE)

    fireEvent.click(await screen.findByRole('button', { name: /نام دلخواه/ }))
    fireEvent.change(screen.getByLabelText('نام'), { target: { value: 'خانه' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
    await until(() => expect(patch.waiting).toBe(1))

    const drop = screen.getByRole('button', { name: 'حذف نام دلخواه' }) as HTMLButtonElement
    expect(drop.disabled).toBe(true)
    fireEvent.click(drop)
    fireEvent.submit(screen.getByLabelText('نام').closest('form') as HTMLFormElement)
    expect(server().sent('PATCH', SERVICE)).toHaveLength(1)

    patch.answer(json({ subscription: service({ label: 'خانه' }) }))
    await screen.findByRole('heading', { name: 'خانه' })
  })

  it('keeps the page when the service read again fails, and reads its server no second time', async () => {
    open()
    await screen.findByText('آنلاین')

    server().on('GET', SERVICE, refusal(500, 'خطای سرور'))
    await queryClient.refetchQueries({ queryKey: keys.subscription(9), exact: true }).catch(() => undefined)

    expect(screen.getByLabelText('حجم: ۶۰ گیگابایت مانده')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
    expect(server().sent('POST', `${SERVICE}/refresh`)).toHaveLength(1)
  })

  it('has every list of the services read again after a change — not its own page', async () => {
    open()
    server().on('PATCH', SERVICE, json({ subscription: service({ auto_renew: { on: true, offered: true, days_before: 2 } }) }))
    await screen.findByText('آنلاین')
    const lists = [
      [...keys.subscriptions, 'home', 'active'],
      [...keys.subscriptions, 'support-picker'],
      [...keys.subscriptions, 'pages', 'all'],
    ]
    for (const list of lists) queryClient.setQueryData(list, { subscriptions: [] })

    fireEvent.click(screen.getByRole('switch', { name: /تمدید خودکار/ }))

    await until(() => expect(lists.map((list) => queryClient.getQueryState(list)?.isInvalidated)).toEqual([true, true, true]))
    expect(queryClient.getQueryState(keys.subscription(9))?.isInvalidated).toBe(false)
  })

  it('goes back where the customer came from — not to a new entry of the services', async () => {
    const { backButton } = app.telegram()
    window.history.replaceState({ idx: 1 }, '')
    open()
    await screen.findByText('آنلاین')

    backButton.press()

    // Back in the app's history (its one entry here): the page stays, where «/services» would have taken its place.
    expect(screen.getByLabelText('حجم: ۶۰ گیگابایت مانده')).toBeTruthy()
  })

  it('a refused name is said under its field', async () => {
    open()
    server().on('PATCH', SERVICE, refusal(422, 'نام حداکثر ۳۲ حرف است.', { label: ['نام حداکثر ۳۲ حرف است.'] }))

    fireEvent.click(await screen.findByRole('button', { name: /نام دلخواه/ }))
    fireEvent.change(screen.getByLabelText('نام'), { target: { value: 'x' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    expect((await screen.findByRole('alert')).textContent).toBe('نام حداکثر ۳۲ حرف است.')
  })

  it('switches «تمدید خودکار» on', async () => {
    open()
    server().on('PATCH', SERVICE, json({ subscription: service({ auto_renew: { on: true, offered: true, days_before: 2 } }) }))

    fireEvent.click(await screen.findByRole('switch', { name: /تمدید خودکار/ }))

    await until(() => expect(screen.getByRole('switch', { name: /تمدید خودکار/ }).getAttribute('aria-checked')).toBe('true'))
    expect(server().sent('PATCH', SERVICE)[0]?.body).toEqual({ auto_renew: true })
  })

  it('changes its link once the customer confirms in Telegram’s box', async () => {
    const answers = [false, true]
    const confirm = vi.fn((_message: string, callback?: (confirmed: boolean) => void) => callback?.(answers.shift() ?? false))
    app.telegram({ showConfirm: confirm })
    open()
    server().on('POST', `${SERVICE}/rotate-link`, json({ subscription: service({ link: 'https://sub.example.net/sub/new0link' }) }))

    fireEvent.click(await screen.findByRole('button', { name: /تغییر لینک/ }))
    await until(() => expect(confirm).toHaveBeenCalledTimes(1))
    expect(server().sent('POST', `${SERVICE}/rotate-link`)).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: /تغییر لینک/ }))
    await screen.findByRole('button', { name: /new0link/ })
    expect(server().sent('POST', `${SERVICE}/rotate-link`)).toHaveLength(1)
  })

  it('a read now refused for too many rests the button', async () => {
    open()
    await screen.findByText('آنلاین')
    server().on('POST', `${SERVICE}/refresh`, withHeaders(refusal(429, 'اطلاعات سرویس را زیاد به‌روز کرده‌اید؛ یک دقیقه دیگر دوباره تلاش کنید.'), { 'Retry-After': '60' }))

    fireEvent.click(screen.getByRole('button', { name: /به‌روزرسانی از سرور/ }))

    await screen.findByText('کمی بعد دوباره امتحان کنید')
    expect((screen.getByRole('button', { name: /به‌روزرسانی از سرور/ }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('keeps its last copy when its server cannot be read now', async () => {
    server()
      .on('GET', SERVICE, json({ subscription: service() }))
      .on('POST', `${SERVICE}/refresh`, refusal(502, 'اطلاعات این سرویس الان از سرور خوانده نشد؛ کمی بعد دوباره تلاش کنید.'))
      .on('GET', `${SERVICE}/qr`, { kind: 'response', status: 200, body: 'JPEG', type: 'image/jpeg' })
    render(app.screen(<ServiceScreen />, { at: '/services/9', route: '/services/:id' }))

    await screen.findByText(/اطلاعات این سرویس الان از سرور خوانده نشد؛ کمی بعد دوباره تلاش کنید. اطلاعات زیر مربوط به ۵ دقیقه قبل است./)
    expect(screen.getByLabelText('حجم: ۶۰ گیگابایت مانده')).toBeTruthy()
  })

  it('in a panel’s preview, shows its last copy without asking its server, and no word of a failed read', async () => {
    app.preview()
    server()
      .on('GET', SERVICE, json({ subscription: service() }))
      .on('GET', `${SERVICE}/qr`, { kind: 'response', status: 200, body: 'JPEG', type: 'image/jpeg' })
    render(app.screen(<ServiceScreen />, { at: '/services/9', route: '/services/:id' }))

    await screen.findByText('به‌روزرسانی: ۵ دقیقه قبل')
    expect(screen.getByLabelText('حجم: ۶۰ گیگابایت مانده')).toBeTruthy()
    await screen.findByRole('button', { name: 'نمایش بزرگ QR' })
    expect(server().sent('POST', `${SERVICE}/refresh`)).toHaveLength(0)
    expect(screen.queryByText(/خوانده نشد/)).toBeNull()
  })

  it('in a panel’s preview, a read the customer asks for is refused in the shop’s words — no more', async () => {
    app.preview()
    server()
      .on('GET', SERVICE, json({ subscription: service() }))
      .on('GET', `${SERVICE}/qr`, { kind: 'response', status: 200, body: 'JPEG', type: 'image/jpeg' })
      .on('POST', `${SERVICE}/refresh`, refusal(403, 'پیش‌نمایش فقط برای دیدن است؛ این کار را مشتری خودش انجام می‌دهد.'))
    render(
      <>
        {app.screen(<ServiceScreen />, { at: '/services/9', route: '/services/:id' })}
        <Toaster />
      </>,
    )

    fireEvent.click(await screen.findByRole('button', { name: /به‌روزرسانی از سرور/ }))

    expect(await screen.findByText('پیش‌نمایش فقط برای دیدن است؛ این کار را مشتری خودش انجام می‌دهد.')).toBeTruthy()
    expect(server().sent('POST', `${SERVICE}/refresh`)).toHaveLength(1)
    expect(screen.getByLabelText('حجم: ۶۰ گیگابایت مانده')).toBeTruthy()
    expect((screen.getByRole('button', { name: /به‌روزرسانی از سرور/ }) as HTMLButtonElement).disabled).toBe(false)
  })

  it('a service not the customer’s says so', async () => {
    server().on('GET', SERVICE, refusal(404, 'پیدا نشد.'))
    render(app.screen(<ServiceScreen />, { at: '/services/9', route: '/services/:id' }))

    await screen.findByText('این سرویس در حساب شما نیست')
  })
})
