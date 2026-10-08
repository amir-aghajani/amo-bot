import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { LeaveQuestion } from '@/components/leave-question'
import { AddServerModal } from '@/components/servers/add-server-modal'
import type { ServerDriversResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { serverDriverRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * Adding a server (components/servers/add-server-modal): the connectors the API describes to pick from, each drawn by
 * what its description says of it — its two letters, who makes the panel, its pages —, then a server's form on the one
 * picked, its notes above it: a new server's, with nothing saved to go back to — no revert —, whose way back to the
 * connectors asks first only while something typed would go with it.
 */

function open() {
  server().on('GET', '/api/admin/servers/drivers', json({ drivers: [serverDriverRow()] } satisfies ServerDriversResponse))
  render(
    <>
      <LeaveQuestion />
      <AddServerModal open onClose={vi.fn()} onCreated={vi.fn()} />
    </>,
    { wrapper: providers().wrapper },
  )
}

/** The add dialog (the question of unsaved changes is another, over it). */
const dialog = () => within(screen.getAllByRole('dialog').find((element) => element.textContent?.includes('افزودن سرور')) ?? document.body)

/** The unsaved-changes question, while it is asked. */
const question = () => screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })

describe('adding a server', () => {
  it('offers the connectors the API describes, each by what its description says of it', async () => {
    open()

    const card = await dialog().findByRole('button', { name: /3X-UI/ })
    expect(card.hasAttribute('disabled')).toBe(false)
    expect(within(card).getByText('3X')).toBeTruthy()
    expect(within(card).getByText('MHSanaei')).toBeTruthy()
    expect(dialog().getByRole('link', { name: 'github.com/MHSanaei/3x-ui' }).getAttribute('href')).toBe('https://github.com/MHSanaei/3x-ui')
  })

  it('opens a server’s form on the connector picked, its notes above it', async () => {
    open()

    fireEvent.click(await dialog().findByRole('button', { name: /3X-UI/ }))

    expect(dialog().getByText('افزودن سرور 3X-UI')).toBeTruthy()
    expect(dialog().getByText('فقط نسخه 3 و بالاتر پشتیبانی می‌شود.')).toBeTruthy()
    expect(dialog().getByLabelText('نام سرور')).toBeTruthy()
    expect(dialog().getByRole('radiogroup', { name: 'احراز هویت' })).toBeTruthy()
    expect(dialog().queryByRole('button', { name: 'بازگردانی تغییرات' })).toBeNull()
  })

  it('goes back to the connectors at once while nothing is typed — or typed and taken back —, and asks first otherwise', async () => {
    open()
    fireEvent.click(await dialog().findByRole('button', { name: /3X-UI/ }))
    fireEvent.change(dialog().getByLabelText('نام سرور'), { target: { value: 'آلمان' } })
    fireEvent.change(dialog().getByLabelText('نام سرور'), { target: { value: '' } })

    fireEvent.click(dialog().getByRole('button', { name: 'بازگشت به انتخاب' }))
    expect(question()).toBeNull()
    expect(await dialog().findByText('پنل این سرور با کدام نرم‌افزار مدیریت می‌شود؟')).toBeTruthy()

    fireEvent.click(await dialog().findByRole('button', { name: /3X-UI/ }))
    fireEvent.change(dialog().getByLabelText('نام سرور'), { target: { value: 'آلمان' } })
    fireEvent.click(dialog().getByRole('button', { name: 'بازگشت به انتخاب' }))

    const asked = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
    fireEvent.click(within(asked).getByRole('button', { name: 'ماندن' }))
    await until(() => expect(question()).toBeNull())
    expect((dialog().getByLabelText('نام سرور') as HTMLInputElement).value).toBe('آلمان')
  })
})
