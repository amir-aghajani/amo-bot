import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ApproveModal } from '@/components/agency/approve-modal'
import { LeaveQuestion } from '@/components/leave-question'
import { userLabel } from '@/components/user-identity'
import type { AgencyLevelsResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { agencyRequestRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * Approving an agency request (components/agency/approve-modal): it starts from the program's terms, and what was typed
 * over them is asked about before the dialog drops it — a credit typed again as it was, with its thousands set apart,
 * being no change.
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
})
