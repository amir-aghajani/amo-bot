import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { MakeCodesModal } from '@/components/gift-codes/make-codes-modal'
import { formatDay } from '@/lib/format'
import { addDays, today } from '@/lib/jalali'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * «ساخت کدها» (components/gift-codes/make-codes-modal): the codes' life is typed in days from today and sent as their
 * last day — a life that is no whole number of days is refused here, never sent as another day, and the server's
 * refusal of the day is said under the field it was typed in.
 */

/** The days from today to the last day the server keeps a code's end (2038-01-17: MySQL's timestamps end two days on). */
const DAYS_LEFT = Math.round((Date.parse('2038-01-17') - Date.parse(today())) / 86_400_000)

const NOT_A_LIFE = `تعداد روز را با عدد بنویسید، بین 0 تا ${DAYS_LEFT}.`

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

function open() {
  render(<MakeCodesModal open onClose={() => undefined} />, { wrapper: providers().wrapper })
  type('مبلغ هر کد (تومان)', '20000')
  type('نام سری', 'یلدا')
}

const make = () => fireEvent.submit(screen.getByLabelText('نام سری').closest('form') as HTMLFormElement)

describe('the codes’ life', () => {
  it('is sent as the last day it comes to, said under it', async () => {
    vi.spyOn(toast, 'success')
    server().on('POST', '/api/admin/gift-codes', json({ batch: 'یلدا', codes: ['YALDA2345678AB'] }, 201))
    open()

    type(/^مهلت استفاده/, '۳۰')
    expect(screen.getByText(`تا پایان ${formatDay(addDays(today(), 30), { dateStyle: 'long' })} استفاده می‌شوند.`)).toBeTruthy()
    make()

    await until(() => expect(server().sent('POST', '/api/admin/gift-codes')[0]?.body).toMatchObject({ expires_on: addDays(today(), 30) }))
  })

  it('reaches as far as the last day the server keeps', async () => {
    server().on('POST', '/api/admin/gift-codes', json({ batch: 'یلدا', codes: ['YALDA2345678AB'] }, 201))
    open()

    type(/^مهلت استفاده/, String(DAYS_LEFT))
    make()

    await until(() => expect(server().sent('POST', '/api/admin/gift-codes')[0]?.body).toMatchObject({ expires_on: '2038-01-17' }))
  })

  it('that is no whole number of days is refused before anything is sent — never made a day of its own', async () => {
    open()

    for (const typed of ['سی', '3.5', '-1', String(DAYS_LEFT + 1), '36500', '999999999']) {
      type(/^مهلت استفاده/, typed)
      expect(screen.getByText(NOT_A_LIFE)).toBeTruthy()
      make()
      await until(() => expect(screen.getByLabelText(/^مهلت استفاده/).getAttribute('aria-invalid')).toBe('true'))
    }

    expect(server().sent('POST', '/api/admin/gift-codes')).toEqual([])
  })

  it('refused by the server is said under its field alone, and goes as the field is typed in', async () => {
    const past = 'تاریخ پایان نمی‌تواند گذشته باشد.'
    server().on('POST', '/api/admin/gift-codes', refusal(422, past, { expires_on: [past] }))
    open()

    type(/^مهلت استفاده/, '5')
    make()

    expect(await screen.findByText(past)).toBeTruthy()
    expect(screen.getAllByText(past)).toHaveLength(1)
    type(/^مهلت استفاده/, '6')
    expect(screen.queryByText(past)).toBeNull()
  })
})
