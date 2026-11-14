import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { MessageDialog } from '@/components/customer/message-dialog'
import type { UserMessageResponse, UserRow } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow, userRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «ارسال پیام» on a customer's page (components/customer/message-dialog): the words as typed, line breaks kept, sent
 * through the shop's bot; what came of it in Telegram (or by email) said once it went, the dialog closed; a refusal
 * under the field, the dialog open; nothing to send while the text is empty or past the server's limit; and whether
 * the message comes under the bot's heading, as the bot settings say.
 */

const CUSTOMER = userRow()
const URL = `/api/admin/users/${CUSTOMER.id}/message`
const SETTINGS = '/api/admin/bot/settings'

/** The dialog open for `customer`, the bot settings read with the heading `header` — or, `null`, not read yet. */
async function open({ header = true, customer = CUSTOMER }: { header?: boolean | null; customer?: UserRow } = {}) {
  if (header === null) server().hold('GET', SETTINGS)
  else server().on('GET', SETTINGS, json(botSettingsResponse(botSettingsRow({ support_header: header }))))
  const onClose = vi.fn()
  render(<MessageDialog customer={customer} onClose={onClose} />, { wrapper: signedIn().wrapper })
  await screen.findByLabelText('متن پیام')
  return { onClose }
}

const type = (text: string) => fireEvent.change(screen.getByLabelText('متن پیام'), { target: { value: text } })

const sendButton = () => screen.getByRole<HTMLButtonElement>('button', { name: 'ارسال پیام' })

/** The server answers the message with `delivery`. */
const answers = (delivery: UserMessageResponse['delivery']) => server().on('POST', URL, json({ delivery } satisfies UserMessageResponse))

beforeEach(() => {
  vi.spyOn(toast, 'success')
  vi.spyOn(toast, 'warning')
})

describe('a message, sent', () => {
  it('goes as typed, line breaks kept, and says it reached them in Telegram', async () => {
    answers('told')
    const { onClose } = await open()

    type('سلام\nسرویس شما تمدید شد.')
    fireEvent.click(sendButton())

    await until(() => expect(onClose).toHaveBeenCalled())
    expect(server().sent('POST', URL)[0]?.body).toEqual({ text: 'سلام\nسرویس شما تمدید شد.' })
    expect(toast.success).toHaveBeenCalledWith('پیام در تلگرام به کاربر رسید.')
  })

  it('says it went by email when Telegram could not reach them', async () => {
    answers('emailed')
    const { onClose } = await open()

    type('سلام')
    fireEvent.click(sendButton())

    await until(() => expect(onClose).toHaveBeenCalled())
    expect(toast.success).toHaveBeenCalledWith('کاربر در تلگرام در دسترس نبود؛ پیام به ایمیلش رفت.')
  })

  it('says it went by email, no Telegram named, for one who has none: email is their way', async () => {
    answers('emailed')
    const { onClose } = await open({ customer: userRow({ telegram_id: null, email: 'ali@example.com' }) })

    type('سلام')
    fireEvent.click(sendButton())

    await until(() => expect(onClose).toHaveBeenCalled())
    expect(toast.success).toHaveBeenCalledWith('پیام به ایمیل کاربر رفت.')
  })

  it('warns when they blocked the bot: it stays on their website alone', async () => {
    answers('turned_away')
    const { onClose } = await open()

    type('سلام')
    fireEvent.click(sendButton())

    await until(() => expect(onClose).toHaveBeenCalled())
    expect(toast.warning).toHaveBeenCalledWith('کاربر ربات را بلاک کرده؛ پیام در تلگرام به او نرسید و فقط در وب‌سایتش می‌ماند.')
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('says a refusal under the field, and stays open', async () => {
    const tooLong = 'متن پیام حداکثر 3500 کاراکتر است.'
    server().on('POST', URL, refusal(422, tooLong, { text: [tooLong] }))
    const { onClose } = await open()

    type('سلام')
    fireEvent.click(sendButton())

    expect(await screen.findByText(tooLong)).toBeTruthy()
    expect(screen.getByLabelText('متن پیام').getAttribute('aria-invalid')).toBe('true')
    expect(onClose).not.toHaveBeenCalled()
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('the send button', () => {
  it('waits for words: held while empty or blank, and past the server’s limit', async () => {
    await open()
    expect(sendButton().disabled).toBe(true)

    type('   \n ')
    expect(sendButton().disabled).toBe(true)

    type('سلام')
    expect(sendButton().disabled).toBe(false)

    // Typed, the field stops at the limit; a value set past it (pasted by a script) is not sent either.
    type('a'.repeat(3501))
    expect(sendButton().disabled).toBe(true)
    expect(screen.getByText('۳٬۵۰۱ از ۳٬۵۰۰ کاراکتر')).toBeTruthy()
  })
})

describe('the heading', () => {
  it('says the message comes under the bot’s heading while the bot settings have it on', async () => {
    await open({ header: true })

    expect(await screen.findByText(/در تلگرام زیر عنوان «📩 پیام از طرف پشتیبانی:» می‌رسد/)).toBeTruthy()
  })

  it('says the words go alone while it is off', async () => {
    await open({ header: false })

    expect(await screen.findByText(/در تلگرام بدون عنوان، فقط خود متن می‌رسد/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'تنظیمات ربات › عمومی' }).getAttribute('href')).toBe('/bot-settings/general')
  })

  it('says both, with where it is switched, until the bot settings are read', async () => {
    await open({ header: null })

    expect(screen.getByText(/در تلگرام زیر «📩 پیام از طرف پشتیبانی:» می‌رسد؛ خاموش، فقط خود متن/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'تنظیمات ربات › عمومی' })).toBeTruthy()
  })
})
