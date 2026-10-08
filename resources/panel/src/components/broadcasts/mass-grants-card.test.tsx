import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { MassGrantsCard } from '@/components/broadcasts/mass-grants-card'
import { LeaveQuestion } from '@/components/leave-question'
import type { MassGrantsResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * A new mass gift (components/broadcasts/mass-grants-card): a gift is made — nothing saved to go back to, no revert —,
 * and what is unsaved is what it would send: a server picked for one server's services, then everyone's again, is none
 * of it.
 */

const READ: MassGrantsResponse = {
  grants: [],
  audience: { all: { running: 12, unstarted: 2 }, agents: { running: 3, unstarted: 0 }, servers: [{ id: 1, name: 'آلمان', running: 7, unstarted: 1 }] },
}

/** The option `option` picked in the select labelled `label`. */
function pick(label: string, option: string | RegExp) {
  fireEvent.keyDown(screen.getByRole('combobox', { name: label }), { key: 'Enter' })
  fireEvent.click(screen.getByRole('option', { name: option }))
}

describe('a new mass gift', () => {
  it('offers no revert, and leaves without a word once what it would send is back as it opened', async () => {
    server().on('GET', '/api/admin/mass-grants', json(READ))
    render(
      <>
        <LeaveQuestion />
        <MassGrantsCard />
      </>,
      { wrapper: providers().wrapper },
    )
    const add = await screen.findByRole('button', { name: 'هدیه جدید' })
    await until(() => expect(add.hasAttribute('disabled')).toBe(false))
    fireEvent.click(add)
    expect(screen.queryByRole('button', { name: 'بازگردانی تغییرات' })).toBeNull()

    pick('به چه سرویس‌هایی', 'سرویس‌های یک سرور')
    pick('سرور', /آلمان/)
    pick('به چه سرویس‌هایی', 'همه سرویس‌ها')
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))

    expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull()
    await until(() => expect(screen.queryByRole('dialog', { name: 'هدیه همگانی' })).toBeNull())
  })
})
