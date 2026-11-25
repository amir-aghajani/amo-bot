import { QueryClient } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { ApproveModal } from '@/components/agency/approve-modal'
import { LeaveQuestion } from '@/components/leave-question'
import { userLabel } from '@/components/user-identity'
import type { AgencyLevelsResponse, AgencyRequestResponse, AgencyRequestRow } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { agencyRequestRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * Approving an agency request (components/agency/approve-modal): it starts from the program's terms, and what was typed
 * over them is asked about before the dialog drops it — a credit typed again as it was, with its thousands set apart,
 * being no change. A renewal asks only for the agent's new end: the program's term's end to start from, sent as the
 * field shows it, to the hour — or none at all, only with «بدون پایان» ticked, which the submit says before it is
 * pressed; a blank day holds it —, a refusal of it under its field. An agent no more is said so, the submit held; a
 * request decided meanwhile reads the requests list again.
 */

const REQUEST = agencyRequestRow()

/** The approval of REQUEST, the program's credit 500٬000 Toman, no end, no minimum; and the unsaved-changes question. */
function show() {
  server().on('GET', '/api/admin/agency/levels', json({ levels: [] } satisfies AgencyLevelsResponse))
  const onClose = vi.fn()
  render(
    <>
      <LeaveQuestion />
      <ApproveModal request={REQUEST} defaultCredit="500000.00" defaultLevel={null} defaultTermDays={0} defaultMinimum="0.00" onClose={onClose} onDone={vi.fn()} />
    </>,
    { wrapper: providers().wrapper },
  )
  return onClose
}

const dialog = () => within(screen.getByRole('dialog', { name: `نمایندگی ${userLabel(REQUEST.user)}` }))
const cancel = () => fireEvent.click(dialog().getByRole('button', { name: 'انصراف' }))

describe('approving a request', () => {
  it('closes at once with the program’s terms as they were — the credit typed again with its thousands set apart too', () => {
    const onClose = show()

    fireEvent.change(dialog().getByLabelText('اعتبار (تومان)'), { target: { value: '۵۰۰٬۰۰۰' } })
    cancel()

    expect(onClose).toHaveBeenCalledOnce()
    expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull()
  })

  it('asks before it drops a credit typed over the program’s', async () => {
    const onClose = show()

    fireEvent.change(dialog().getByLabelText('اعتبار (تومان)'), { target: { value: '200000' } })
    cancel()

    const question = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
    expect(onClose).not.toHaveBeenCalled()
    fireEvent.click(within(question).getByRole('button', { name: 'رها کردن تغییرات' }))
    await until(() => expect(onClose).toHaveBeenCalledOnce())
  })

  it('decided meanwhile, says so and reads the requests list again — the row’s buttons go', async () => {
    const invalidated = vi.spyOn(QueryClient.prototype, 'invalidateQueries')
    const message = 'این درخواست قبلا بررسی شده است.'
    show()
    server().on('POST', `/api/admin/agency/requests/${REQUEST.id}/approve`, refusal(422, message, { status: [message] }))

    fireEvent.click(dialog().getByRole('button', { name: 'تایید و نماینده کردن' }))

    expect(await dialog().findByText(message)).toBeTruthy()
    expect(invalidated).toHaveBeenCalledWith({ queryKey: queryKeys.agencyRequests })
  })
})

/** Agent رضا's renewal request: their end passed on 1 Mehr, the program's term giving 20 Dey 1405, 20:30. */
const RENEWAL = agencyRequestRow({ id: 9, kind: 'renewal', agent: true, expires_at: '2026-09-23T10:00:00Z', term_end: '2027-01-10T20:30:00Z' })

/** The approval of `request`, a renewal; the server answering it as approved with `extended_to`. */
function renew(request: AgencyRequestRow, extendedTo: string | null = '2027-01-10T20:00:00Z') {
  vi.spyOn(toast, 'success')
  server().on(
    'POST',
    `/api/admin/agency/requests/${request.id}/approve`,
    json({ request: { ...request, status: 'approved', extended_to: extendedTo, actions: { approve: false, reject: false } } } satisfies AgencyRequestResponse),
  )
  const onDone = vi.fn()
  render(<ApproveModal request={request} defaultCredit="500000.00" defaultLevel={null} defaultTermDays={30} defaultMinimum="0.00" onClose={vi.fn()} onDone={onDone} />, {
    wrapper: providers().wrapper,
  })
  return { form: within(screen.getByRole('dialog', { name: `تمدید نمایندگی ${userLabel(request.user)}` })), onDone }
}

const sent = (id: number) => server().sent('POST', `/api/admin/agency/requests/${id}/approve`)

describe('approving a renewal', () => {
  it('starts from the program’s term’s end, says the agent’s end passed, and sends the end as the field shows it — no level, no credit', async () => {
    const { form, onDone } = renew(RENEWAL)

    expect(form.getByText(/\(گذشته است\)$/)).toBeTruthy()
    expect(form.queryByLabelText('سطح')).toBeNull()
    expect(form.queryByLabelText('اعتبار (تومان)')).toBeNull()
    expect((form.getByLabelText('پایان تازه نمایندگی') as HTMLInputElement).value).toBe('1405/10/20')
    fireEvent.click(form.getByRole('button', { name: 'تایید تمدید' }))

    await until(() => expect(onDone).toHaveBeenCalledOnce())
    expect(sent(9).map((request) => request.body)).toEqual([{ expires_at: '2027-01-10T20:00' }])
    expect(toast.success).toHaveBeenCalledWith(expect.stringMatching(new RegExp(`^نمایندگی ${userLabel(RENEWAL.user)} تا .+ تمدید شد$`)))
  })

  it('gives no end at all with «بدون پایان» ticked, the submit saying so', async () => {
    const { form, onDone } = renew(RENEWAL, null)

    fireEvent.click(form.getByRole('checkbox', { name: 'بدون پایان' }))
    expect(form.queryByLabelText('پایان تازه نمایندگی')).toBeNull()
    fireEvent.click(form.getByRole('button', { name: 'تایید بدون پایان' }))

    await until(() => expect(onDone).toHaveBeenCalledOnce())
    expect(sent(9).map((request) => request.body)).toEqual([{ expires_at: null }])
    expect(toast.success).toHaveBeenCalledWith(`نمایندگی ${userLabel(RENEWAL.user)} بدون پایان شد`)
  })

  it('without a term in the program starts blank — nothing chosen: the submit held, never «no end» — and shows a refusal of the end typed under its field alone', async () => {
    const request = { ...RENEWAL, term_end: null }
    const message = 'تاریخ پایان تازه باید بعد از همین حالا باشد.'
    const { form } = renew(request)
    server().on('POST', '/api/admin/agency/requests/9/approve', refusal(422, message, { expires_at: [message] }))

    expect((form.getByLabelText('پایان تازه نمایندگی') as HTMLInputElement).value).toBe('')
    expect(form.getByText(/در تنظیمات نمایندگی مدتی برای نمایندگی تعیین نشده است/)).toBeTruthy()
    expect(form.getByText('روز پایان تازه را بنویسید، یا «بدون پایان» را بزنید.')).toBeTruthy()
    expect(form.queryByRole('button', { name: 'تایید بدون پایان' })).toBeNull()
    const submit = form.getByRole('button', { name: 'تایید تمدید' })
    expect(submit.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(submit)
    // Enter in the field too: the form itself sends nothing.
    fireEvent.submit(submit.closest('form') as HTMLFormElement)
    expect(sent(9)).toEqual([])

    fireEvent.change(form.getByLabelText('پایان تازه نمایندگی'), { target: { value: '1405/08/01' } })
    expect(submit.getAttribute('aria-disabled')).toBeNull()
    fireEvent.click(submit)

    expect(await form.findByText(message)).toBeTruthy()
    expect(form.getAllByText(message)).toHaveLength(1)
    // A day typed where the field was blank ends at its last hour, as the agent's own end field does.
    expect(sent(9).map((body) => body.body)).toEqual([{ expires_at: '2026-10-23T23:00' }])
  })

  it('held again once the day offered is cleared — no end only with «بدون پایان» ticked', async () => {
    const { form, onDone } = renew(RENEWAL, null)

    fireEvent.change(form.getByLabelText('پایان تازه نمایندگی'), { target: { value: '' } })
    expect(form.getByRole('button', { name: 'تایید تمدید' }).getAttribute('aria-disabled')).toBe('true')

    fireEvent.click(form.getByRole('checkbox', { name: 'بدون پایان' }))
    const submit = form.getByRole('button', { name: 'تایید بدون پایان' })
    expect(submit.getAttribute('aria-disabled')).toBeNull()
    fireEvent.click(submit)

    await until(() => expect(onDone).toHaveBeenCalledOnce())
    expect(sent(9).map((request) => request.body)).toEqual([{ expires_at: null }])
  })

  it('of an agent no more says so, shows no end, and holds the submit', () => {
    const { form } = renew({ ...RENEWAL, agent: false, expires_at: null, term_end: null })

    expect(form.getByText(`${userLabel(RENEWAL.user)} دیگر نماینده نیست و نمایندگی‌ای برای تمدید ندارد؛ درخواست را رد کنید.`)).toBeTruthy()
    expect(form.queryByText('پایان فعلی')).toBeNull()
    fireEvent.click(form.getByRole('checkbox', { name: 'بدون پایان' }))
    const submit = form.getByRole('button', { name: 'تایید بدون پایان' })
    expect(submit.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(submit)
    expect(sent(9)).toEqual([])
  })

  it('decided meanwhile, says so and reads the requests list again — the row’s buttons go', async () => {
    const invalidated = vi.spyOn(QueryClient.prototype, 'invalidateQueries')
    const message = 'این درخواست قبلا بررسی شده است.'
    const { form } = renew(RENEWAL)
    server().on('POST', '/api/admin/agency/requests/9/approve', refusal(422, message, { status: [message] }))

    fireEvent.click(form.getByRole('button', { name: 'تایید تمدید' }))

    expect(await form.findByText(message)).toBeTruthy()
    expect(invalidated).toHaveBeenCalledWith({ queryKey: queryKeys.agencyRequests })
  })
})
