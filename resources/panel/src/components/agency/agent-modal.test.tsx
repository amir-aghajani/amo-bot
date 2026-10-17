import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AgentModal } from '@/components/agency/agent-modal'
import type { AgencyLevelsResponse, AgentChangedResponse, AgentRow } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { agencyStanding } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * An agent's level and credit (components/agency/agent-modal): saved, the toast says whether the agent was told their new
 * terms — and terms the server finds as they were tell the agent nothing, which the toast says too. Nothing changed,
 * there is nothing to save: the save waits for a change. With a shop, their end (a Jalali day on the shop's clock) and
 * monthly minimum are changed with them.
 */

/** Agent 21 «رضا» on level «طلایی», without credit. */
const REZA: AgentRow = {
  id: 21,
  user: { id: 21, name: 'رضا', username: 'reza', telegram_id: 555_000, email: null },
  status: 'active',
  level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
  credit_limit: '0.00',
  balance: '0.00',
  bot: null,
  counts: { customers: 0, sold: 0, active: 0 },
  standing: null,
  hidden: { servers: 0, plans: 0 },
}

/** The agent's dialog, its credit typed as `credit`, saved — the server answering with `delivery`. */
function save(credit: string, delivery: AgentChangedResponse['delivery']) {
  server()
    .on(
      'GET',
      '/api/admin/agency/levels',
      json({
        levels: [{ id: 1, name: 'طلایی', price_per_gb: '3000.00', sort: 1, counts: { agents: 1 }, created_at: '2026-09-01T10:00:00Z', updated_at: '2026-09-01T10:00:00Z' }],
      } satisfies AgencyLevelsResponse),
    )
    .on('PUT', '/api/admin/agency/agents/21', json({ agent: REZA, delivery } satisfies AgentChangedResponse))
  render(<AgentModal agent={REZA} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })
  fireEvent.change(screen.getByLabelText('اعتبار (تومان)'), { target: { value: credit } })
  fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
  vi.spyOn(toast, 'warning')
})

describe('an agent’s terms saved', () => {
  it('say the agent was notified', async () => {
    save('500000', 'told')
    await until(() => expect(toast.success).toHaveBeenCalledWith('نمایندگی به‌روز شد و به نماینده خبر داده شد'))
  })

  it('found by the server as they already were (set so meanwhile), say nothing changed and no notice was sent', async () => {
    save('500000', null)
    await until(() => expect(toast.success).toHaveBeenCalledWith('شرایط نماینده تغییری نکرد'))
    expect(toast.warning).not.toHaveBeenCalled()
  })

  it.each(['0', '0.00', '۰'])('wait for a change: typed back as they were (%s), there is nothing to save nor to revert', (credit) => {
    save(credit, 'told')
    const submit = screen.getByRole('button', { name: 'ذخیره تغییرات' })
    expect(submit.getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByRole('button', { name: 'بازگردانی تغییرات' }).getAttribute('aria-disabled')).toBe('true')
    expect(server().sent('PUT', '/api/admin/agency/agents/21')).toEqual([])
  })

  it('read as the server reads them: a credit and a minimum typed with their thousands set apart are no change', () => {
    const agent: AgentRow = { ...REZA, credit_limit: '500000.00', standing: agencyStanding({ monthly_minimum: '1000000.00' }) }
    server().on('GET', '/api/admin/agency/levels', json({ levels: [] } satisfies AgencyLevelsResponse))
    render(<AgentModal agent={agent} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })
    const submit = screen.getByRole('button', { name: 'ذخیره تغییرات' })

    fireEvent.change(screen.getByLabelText('اعتبار (تومان)'), { target: { value: '500,000' } })
    fireEvent.change(screen.getByLabelText('کف خرید ماهانه (تومان)'), { target: { value: '۱٬۰۰۰٬۰۰۰' } })
    expect(submit.getAttribute('aria-disabled')).toBe('true')

    fireEvent.change(screen.getByLabelText('کف خرید ماهانه (تومان)'), { target: { value: '900000' } })
    expect(submit.getAttribute('aria-disabled')).toBeNull()
  })
})

describe('an agent’s terms of selling', () => {
  it('are shown and changed with their level and credit: an end on the shop’s clock, a monthly minimum', async () => {
    const agent: AgentRow = { ...REZA, standing: agencyStanding({ monthly_minimum: '500000.00' }) }
    server()
      .on(
        'GET',
        '/api/admin/agency/levels',
        json({
          levels: [{ id: 1, name: 'طلایی', price_per_gb: '3000.00', sort: 1, counts: { agents: 1 }, created_at: '2026-09-01T10:00:00Z', updated_at: '2026-09-01T10:00:00Z' }],
        } satisfies AgencyLevelsResponse),
      )
      .on('PUT', '/api/admin/agency/agents/21', json({ agent, delivery: 'told' } satisfies AgentChangedResponse))
    render(<AgentModal agent={agent} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    expect(screen.getAllByText('بدون پایان')).toHaveLength(2) // the fact, and the field's hint
    expect((screen.getByLabelText('کف خرید ماهانه (تومان)') as HTMLInputElement).value).toBe('500000')
    // A day typed where there was no end ends at its last hour, as a discount's end does — not at its start.
    fireEvent.change(screen.getByLabelText('پایان نمایندگی'), { target: { value: '1405/08/10' } })
    expect(screen.getByText(/۱۴۰۵، ساعت ۲۳:۰۰$/)).toBeTruthy()
    fireEvent.change(screen.getByLabelText('کف خرید ماهانه (تومان)'), { target: { value: '0' } })
    expect(screen.getByText('0: بدون کف خرید.')).toBeTruthy() // none is 0, as in the program's own field
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/agency/agents/21')).toHaveLength(1))
    expect(server().sent('PUT', '/api/admin/agency/agents/21')[0]?.body).toEqual({ level_id: '1', credit_limit: '0', expires_at: '2026-11-01T23:00', monthly_minimum: '0' })
  })

  it('go only as changed: an end kept to the minute is not moved to the hour the field shows', async () => {
    const agent: AgentRow = { ...REZA, standing: agencyStanding({ expires_at: '2026-11-01T12:34:56Z', monthly_minimum: '500000.00' }) }
    server()
      .on(
        'GET',
        '/api/admin/agency/levels',
        json({
          levels: [{ id: 1, name: 'طلایی', price_per_gb: '3000.00', sort: 1, counts: { agents: 1 }, created_at: '2026-09-01T10:00:00Z', updated_at: '2026-09-01T10:00:00Z' }],
        } satisfies AgencyLevelsResponse),
      )
      .on('PUT', '/api/admin/agency/agents/21', json({ agent, delivery: 'told' } satisfies AgentChangedResponse))
    render(<AgentModal agent={agent} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByLabelText('اعتبار (تومان)'), { target: { value: '200000' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/agency/agents/21')).toHaveLength(1))
    expect(server().sent('PUT', '/api/admin/agency/agents/21')[0]?.body).toEqual({ level_id: '1', credit_limit: '200000' })
  })

  it('are not asked of an agent without a shop', () => {
    save('0', 'told')
    expect(screen.queryByLabelText('پایان نمایندگی')).toBeNull()
  })
})
