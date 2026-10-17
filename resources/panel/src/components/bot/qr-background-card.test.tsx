import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { QrBackgroundCard } from '@/components/bot/qr-background-card'
import type { QrBackgroundResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { qrBackgroundRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The QR code's background (components/bot/qr-background-card): the largest upload it takes said before one is refused —
 * the server's own limit —, and a picture's size in pixels written as a measure of the file: Latin digits, no separators.
 * Going back to the shipped picture deletes the uploaded one, so it asks first — a refusal said in the question —; it
 * holds both buttons while it runs, and hands the focus to the upload once the button that asked goes with the picture.
 */

/** The background the shop ships: a 1024×1024 JPEG, uploads up to 5 MB. */
const SHIPPED = qrBackgroundRow()

describe('the QR background card', () => {
  it('says the largest upload it takes, and the picture’s size in Latin digits', () => {
    render(<QrBackgroundCard background={SHIPPED} />, { wrapper: providers().wrapper })

    expect(screen.getByText(/حداکثر ۵ مگابایت/)).toBeTruthy()
    expect(screen.getAllByText('1024×1024').length).toBe(2)
    expect(document.body.textContent).not.toContain('۱٬۰۲۴')
  })

  it('names the formats it takes as the server does, each set apart so they read in order right to left', () => {
    render(<QrBackgroundCard background={SHIPPED} />, { wrapper: providers().wrapper })

    const hint = screen.getByText(/حداکثر ۵ مگابایت/)
    expect(hint.textContent).toMatch(/^JPG، PNG یا WebP، /)
    expect([...hint.querySelectorAll('bdi')].map((format) => [format.textContent, format.getAttribute('dir')])).toEqual([
      ['JPG', 'ltr'],
      ['PNG', 'ltr'],
      ['WebP', 'ltr'],
    ])
  })

  it('draws the picture in use from its address — a new one when it changes, in the shop the tab shows', () => {
    render(<QrBackgroundCard background={SHIPPED} />, { wrapper: providers().wrapper })

    expect(screen.getByRole('img', { name: 'پس‌زمینه فعلی کد QR' }).getAttribute('src')).toBe('/api/admin/bot/qr-background?v=1759000000&shop=1')
  })

  it('asks before it deletes the uploaded picture — cancelled, nothing is sent', async () => {
    render(<QrBackgroundCard background={qrBackgroundRow({ custom: true })} />, { wrapper: providers().wrapper })

    fireEvent.click(screen.getByRole('button', { name: 'بازگشت به پیش‌فرض' }))
    const question = within(await screen.findByRole('dialog', { name: 'بازگشت به پس‌زمینه پیش‌فرض' }))
    expect(question.getByText(/تصویر آپلودشده حذف می‌شود/)).toBeTruthy()
    fireEvent.click(question.getByRole('button', { name: 'انصراف' }))

    await until(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(server().sent('DELETE', '/api/admin/bot/qr-background')).toHaveLength(0)
  })

  it('goes back to the shipped picture once confirmed — its question waiting meanwhile —, the focus then on the upload', async () => {
    const reset = server().hold('DELETE', '/api/admin/bot/qr-background')
    render(<QrBackgroundCard background={qrBackgroundRow({ custom: true })} />, { wrapper: providers().wrapper })
    const back = screen.getByRole('button', { name: 'بازگشت به پیش‌فرض' }) as HTMLButtonElement

    back.focus()
    fireEvent.click(back)
    const question = within(await screen.findByRole('dialog', { name: 'بازگشت به پس‌زمینه پیش‌فرض' }))
    const confirm = question.getByRole('button', { name: 'بازگشت به پیش‌فرض' })
    fireEvent.click(confirm)
    await until(() => expect(reset.waiting).toBe(1))

    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect((question.getByRole('button', { name: 'انصراف' }) as HTMLButtonElement).disabled).toBe(true)
    for (const button of [back, screen.getByRole('button', { name: 'آپلود تصویر جدید', hidden: true }) as HTMLButtonElement]) {
      expect([button.disabled, button.getAttribute('aria-disabled')]).toEqual([false, 'true'])
    }

    reset.answer(json({ qr_background: SHIPPED } satisfies QrBackgroundResponse))
    await until(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'آپلود تصویر جدید' })))
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('says a refused way back in its question, the card saying nothing of it', async () => {
    server().on('DELETE', '/api/admin/bot/qr-background', refusal(409, 'پس‌زمینه همین حالا عوض شد؛ صفحه را دوباره باز کنید.'))
    render(<QrBackgroundCard background={qrBackgroundRow({ custom: true })} />, { wrapper: providers().wrapper })

    fireEvent.click(screen.getByRole('button', { name: 'بازگشت به پیش‌فرض' }))
    const question = within(await screen.findByRole('dialog', { name: 'بازگشت به پس‌زمینه پیش‌فرض' }))
    fireEvent.click(question.getByRole('button', { name: 'بازگشت به پیش‌فرض' }))

    expect((await question.findByRole('alert')).textContent).toContain('پس‌زمینه همین حالا عوض شد')
    fireEvent.click(question.getByRole('button', { name: 'انصراف' }))
    // Once the question has faded out, nothing on the card says it.
    await until(() => expect(screen.queryByText(/پس‌زمینه همین حالا عوض شد/)).toBeNull())
  })
})
