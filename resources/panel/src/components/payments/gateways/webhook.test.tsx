import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { MethodForm } from '@/components/payments/gateways/method-form'
import type { DriverDescription, GatewayWebhookState, PaymentMethodRow, PaymentMethodWebhookResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { cubePayDriverRow, cubePayMethodRow, rialitDriverRow, rialitMethodRow, webhookState } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «وب‌هوک ریالیت» in a Rialit method's view (components/payments/gateways/webhook): where Rialit sends its webhook,
 * read as the view opens — the shop's address, none (registered here), another site's (replaced here, asked first in
 * place: that site stops getting them, and Rialit tells the account's owner), or not read (why, and ask again) —, with
 * the shop's address to paste by hand until it is the shop's; a method of another gateway shows none of it.
 */

const WEBHOOK = '/api/admin/payment-methods/7/webhook'
const OTHER = 'https://old-bot.example/rialit/hook'

/** Rialit's word on its webhook, as `GET` and `POST …/webhook` answer it. */
const said = (webhook: GatewayWebhookState) => json({ webhook } satisfies PaymentMethodWebhookResponse)

/** A method's form: of `driver`, editing `method` when given. */
function form(driver: DriverDescription, method?: PaymentMethodRow) {
  render(<MethodForm driver={driver} method={method} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: providers().wrapper })
}

/** Rialit method 7's form, Rialit's webhook read as `webhook`. */
function view(webhook: GatewayWebhookState) {
  server().on('GET', WEBHOOK, said(webhook))
  form(rialitDriverRow(), rialitMethodRow())
}

const block = () => within(screen.getByRole('region', { name: 'وب‌هوک ریالیت' }))
const shown = (status: string) => until(() => expect(block().getByText(status)).toBeTruthy())

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('a Rialit method’s webhook', () => {
  it('says the shop’s address is registered: nothing to do, nothing to paste', async () => {
    view(webhookState())

    await shown('آدرس فروشگاه ثبت است')
    expect(block().queryByRole('button')).toBeNull()
    expect(server().sent('GET', WEBHOOK)).toHaveLength(1)
  })

  it('registers the shop’s address where Rialit has none, and says so', async () => {
    const registered = 'آدرس وب‌هوک فروشگاه در ریالیت ثبت شد؛ ریالیت به صاحب حساب خبر داد.'
    view(webhookState({ state: 'none', url: null }))
    server().on('POST', WEBHOOK, said(webhookState({ message: registered })))

    await shown('ثبت نشده')
    // The way by hand meanwhile: the shop's address, left to right, to copy.
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    expect(block().getByText('https://shop.example/payments/webhooks/rialit').getAttribute('dir')).toBe('ltr')
    fireEvent.click(block().getByRole('button', { name: 'کپی آدرس وب‌هوک فروشگاه' }))
    expect(write).toHaveBeenCalledWith('https://shop.example/payments/webhooks/rialit')

    const register = block().getByRole('button', { name: 'ثبت آدرس فروشگاه' })
    register.focus()
    fireEvent.click(register)

    await shown('آدرس فروشگاه ثبت است')
    expect(server().sent('POST', WEBHOOK)[0]?.body).toEqual({ replace: false })
    expect(toast.success).toHaveBeenCalledWith(registered)
    // Said once, in the toast: the block keeps the state; the button pressed is gone, the block has the focus.
    expect(block().queryByText(registered)).toBeNull()
    expect(block().queryByRole('button', { name: 'کپی آدرس وب‌هوک فروشگاه' })).toBeNull()
    expect(document.activeElement).toBe(screen.getByRole('region', { name: 'وب‌هوک ریالیت' }))
  })

  it('says why Rialit refused the shop’s address, the button still there', async () => {
    const refused = 'آدرس سایت فروشگاه با https شروع نمی‌شود و ریالیت آن را نمی‌پذیرد.'
    view(webhookState({ state: 'none', url: null }))
    server().on('POST', WEBHOOK, said(webhookState({ state: 'none', url: null, message: refused })))

    await shown('ثبت نشده')
    fireEvent.click(block().getByRole('button', { name: 'ثبت آدرس فروشگاه' }))

    expect(await block().findByText(refused)).toBeTruthy()
    expect(block().getByText('ثبت نشده')).toBeTruthy()
    expect(block().getByRole('button', { name: 'ثبت آدرس فروشگاه' })).toBeTruthy()
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('shows another site’s address left to right, and replaces it only once asked, saying what that does', async () => {
    view(webhookState({ state: 'elsewhere', url: OTHER }))
    server().on('POST', WEBHOOK, said(webhookState({ message: 'آدرس فروشگاه جای آدرس قبلی در ریالیت ثبت شد.' })))

    await shown('به آدرس دیگری می‌رود')
    expect(block().getByText(OTHER).getAttribute('dir')).toBe('ltr')

    // Asked in place, cancel first: nothing sent, and the focus back on the button.
    fireEvent.click(block().getByRole('button', { name: 'جایگزین کردن با آدرس فروشگاه' }))
    expect(block().getByText(/دیگر وب‌هوک‌های ریالیت را نمی‌گیرد/)).toBeTruthy()
    expect(block().getByText(/در تلگرام و ایمیل به صاحب حساب/)).toBeTruthy()
    expect(document.activeElement).toBe(block().getByRole('button', { name: 'انصراف' }))
    fireEvent.click(block().getByRole('button', { name: 'انصراف' }))
    await until(() => expect(document.activeElement).toBe(block().getByRole('button', { name: 'جایگزین کردن با آدرس فروشگاه' })))
    expect(server().sent('POST', WEBHOOK)).toHaveLength(0)

    fireEvent.click(block().getByRole('button', { name: 'جایگزین کردن با آدرس فروشگاه' }))
    fireEvent.click(block().getByRole('button', { name: 'جایگزین کردن' }))

    await shown('آدرس فروشگاه ثبت است')
    expect(server().sent('POST', WEBHOOK)[0]?.body).toEqual({ replace: true })
    expect(toast.success).toHaveBeenCalledWith('آدرس فروشگاه جای آدرس قبلی در ریالیت ثبت شد.')
    expect(block().queryByText(OTHER)).toBeNull()
  })

  it('says why a replacement did not go through — too many changes this hour —, the other address still shown', async () => {
    const limited = 'ریالیت در هر ساعت حداکثر ۲۰ تغییر امنیتی می‌پذیرد؛ کمی بعد دوباره امتحان کنید.'
    view(webhookState({ state: 'elsewhere', url: OTHER }))
    server().on('POST', WEBHOOK, said(webhookState({ state: 'elsewhere', url: OTHER, message: limited })))

    await shown('به آدرس دیگری می‌رود')
    fireEvent.click(block().getByRole('button', { name: 'جایگزین کردن با آدرس فروشگاه' }))
    fireEvent.click(block().getByRole('button', { name: 'جایگزین کردن' }))

    expect(await block().findByText(limited)).toBeTruthy()
    expect(block().getByText(OTHER)).toBeTruthy()
    await until(() => expect(document.activeElement).toBe(block().getByRole('button', { name: 'جایگزین کردن با آدرس فروشگاه' })))
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('keeps the confirm open with the panel’s own failure, to try again', async () => {
    view(webhookState({ state: 'elsewhere', url: OTHER }))
    server().on('POST', WEBHOOK, refusal(500, 'خطای سرور.'))

    await shown('به آدرس دیگری می‌رود')
    fireEvent.click(block().getByRole('button', { name: 'جایگزین کردن با آدرس فروشگاه' }))
    fireEvent.click(block().getByRole('button', { name: 'جایگزین کردن' }))

    await until(() => expect(block().getByRole('alert')).toBeTruthy())
    expect(block().getByRole('button', { name: 'جایگزین کردن' })).toBeTruthy()
  })

  it('says why Rialit could not be read, and asks again', async () => {
    const unreachable = 'ریالیت جواب نداد؛ کمی بعد دوباره امتحان کنید.'
    view(webhookState({ state: 'unknown', url: null, message: unreachable }))

    await shown('معلوم نیست')
    expect(block().getByText(unreachable)).toBeTruthy()
    // The way by hand stays offered.
    expect(block().getByRole('button', { name: 'کپی آدرس وب‌هوک فروشگاه' })).toBeTruthy()

    server().on('GET', WEBHOOK, said(webhookState()))
    const retry = block().getByRole('button', { name: 'دوباره امتحان' })
    retry.focus()
    fireEvent.click(retry)

    await shown('آدرس فروشگاه ثبت است')
    expect(server().sent('GET', WEBHOOK)).toHaveLength(2)
    expect(block().queryByText(unreachable)).toBeNull()
    expect(document.activeElement).toBe(screen.getByRole('region', { name: 'وب‌هوک ریالیت' }))
  })

  it('says the panel could not read it, with a retry', async () => {
    server().on('GET', WEBHOOK, refusal(500, 'خطای سرور.'))
    form(rialitDriverRow(), rialitMethodRow())

    expect(await block().findByText(/وضعیت وب‌هوک ریالیت بارگذاری نشد/)).toBeTruthy()
    server().on('GET', WEBHOOK, said(webhookState()))
    fireEvent.click(block().getByRole('button', { name: /دوباره/ }))

    await shown('آدرس فروشگاه ثبت است')
  })
})

describe('a method of another gateway', () => {
  it('says nothing of a webhook and asks nothing of it', () => {
    form(cubePayDriverRow(), cubePayMethodRow())

    expect(screen.queryByRole('region', { name: /وب‌هوک/ })).toBeNull()
    expect(server().requests).toHaveLength(0)
  })

  it('nor does a new Rialit method, until it is saved', () => {
    form(rialitDriverRow())

    expect(screen.queryByRole('region', { name: /وب‌هوک/ })).toBeNull()
    expect(server().requests).toHaveLength(0)
  })
})
